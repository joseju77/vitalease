# ADR-0011: Dependencias entre permisos

* Estado: Aceptada; modifica el punto 1 de [ADR-0005](0005-permission-taxonomy-and-role-catalog.md)

## Contexto

El catálogo de permisos (`App\Enums\Permission`) era plano: cada permiso se asignaba de forma independiente a roles y a
usuarios. Eso permitía asignaciones incoherentes, como `consultations.view` sin `patients.view` o
`consultations.void-any` sin `consultations.void`. Un usuario con esas asignaciones recibía un permiso que no podía
ejercer, porque las pantallas que lo usan parten de otra a la que no tenía acceso.

Se consideró resolver cada caso donde aparecía, por ejemplo con una condición `||` en una política o en la interfaz
que aceptara cualquiera de los dos permisos. Se descartó: reparte la regla en varios lugares, no impide la asignación
incoherente y cada caso nuevo exige otra excepción.

## Decisión

1. **Un solo mapa de dependencias, en el enum.** `Permission::requires()` declara las dependencias directas de cada
   permiso; `Permission::allRequirements()` calcula el cierre transitivo. Es la única fuente de verdad: el servidor la
   aplica y la interfaz la recibe como la prop de Inertia `permissionDependencies` (`Permission::dependencyMap()`, solo
   dependencias directas, con todos los permisos presentes aunque su lista esté vacía).
2. **Se valida al asignar, sin agregar permisos en silencio.** Crear o editar un rol y asignar permisos a un usuario
   responden 422 con un error en `permissions` por cada dependencia faltante (por ejemplo, «Consultas: Ver» requiere
   «Pacientes: Ver»). El servidor nunca completa la asignación por su cuenta.
3. **La interfaz aplica la cascada.** En los diálogos de rol y de permisos de usuario, marcar un permiso marca también
   sus requisitos transitivos, y desmarcar uno desmarca todo lo que depende de él. Cada permiso con requisitos muestra
   una indicación como «Requiere: Pacientes: Ver». El error 422 se muestra en el diálogo si llega a producirse.
4. **Para usuarios cuentan los permisos del rol.** Un requisito queda satisfecho por un permiso directo o por uno del
   rol enviado. La interfaz no agrega como permiso directo un requisito que el rol seleccionado ya otorga, y al
   desmarcar solo retira permisos directos que se quedan sin requisito. Para ello `UserController::index` comparte
   `rolePermissions` (permisos de cada rol, por nombre).
5. **Sin cambios en políticas ni en fábricas.** Las políticas siguen comprobando un solo permiso cada una y
   `UserFactory::withPermissions()` sigue otorgando exactamente los permisos indicados; la regla aplica a la asignación,
   no a la autorización.
6. **Rol de demostración.** `Coordinación médica` recibe `consultations.void`, que `consultations.void-any` requiere.

### Mapa de dependencias

| Permiso | Requiere (directo) |
| --- | --- |
| `patients.create`, `patients.update`, `patients.disable`, `patients.audit` | `patients.view` |
| `consultations.view` | `patients.view` |
| `consultations.create`, `consultations.update`, `consultations.void`, `consultations.view-voided`, `consultations.audit` | `consultations.view` |
| `consultations.void-any` | `consultations.void` |
| `inventory.create`, `inventory.update`, `inventory.delete` | `inventory.view` |
| `users.manage`, `roles.manage`, `reports.generate`, `patients.view`, `inventory.view` | — |

Por transitividad, `consultations.void-any` requiere `consultations.void`, `consultations.view` y `patients.view`.

## Alternativas consideradas

- Condiciones `||` puntuales en políticas o en la interfaz.
- Completar en el servidor, sin avisar, las dependencias faltantes.
- Validar solo en la interfaz.
- Exigir a los usuarios todos los requisitos como permisos directos, sin contar los del rol.

## Consecuencias

### Positivas

- Ninguna asignación nueva puede dejar un permiso sin sus requisitos.
- La regla vive en un solo lugar; agregar un permiso con dependencias es un cambio en `requires()`.
- La cascada y las indicaciones de la interfaz evitan la mayoría de los errores 422.

### Negativas

- Las asignaciones previas incoherentes no se corrigen solas; se rechazan la próxima vez que se editen.
- Cambiar el rol de un usuario puede dejar permisos directos sin requisito; el diálogo no reajusta la selección y el
  servidor lo rechaza con 422.

### Compromisos aceptados

- Se prefiere rechazar con 422 y mostrar el error sobre completar la asignación en silencio.
- Las políticas no verifican dependencias; dependen de que la asignación sea coherente.
