# Usuarios, roles y permisos

- Gestión de usuarios y control de acceso basado en `spatie/laravel-permission` (ver [ADR-0003](../adr/0003-rbac-approach.md)).
- No hay auto-registro: la única forma de crear una cuenta es que un administrador la cree desde esta gestión (ver
  [ADR-0004](../adr/0004-existing-users-only-auth.md) y [autenticación](authentication.md)).
- Varias decisiones de esta etapa se desvían del plan original; están documentadas en
  [ADR-0005](../adr/0005-permission-taxonomy-and-role-catalog.md).

## Catálogo de permisos

Los permisos son un catálogo **cerrado**, definido en el enum `App\Enums\Permission` (19 casos). Cualquier nombre de
permiso fuera de este enum se rechaza en la validación (`Rule::enum`):

| Permiso                | Descripción                                |
|-------------------------|---------------------------------------------|
| `users.manage`          | Gestionar usuarios (alta, edición, acceso).  |
| `roles.manage`          | Gestionar el catálogo de roles.              |
| `reports.generate`      | Generar reportes.                            |
| `patients.view`         | Ver pacientes.                               |
| `patients.create`       | Crear pacientes.                             |
| `patients.update`       | Editar pacientes.                            |
| `patients.disable`      | Deshabilitar y volver a habilitar pacientes. |
| `patients.audit`        | Consultar el historial de cambios de un paciente. |
| `consultations.view`    | Ver consultas.                               |
| `consultations.create`  | Crear consultas.                             |
| `consultations.update`  | Editar consultas.                            |
| `consultations.void`    | Anular y restaurar las consultas propias.    |
| `consultations.void-any`| Anular y restaurar la consulta de cualquier médico; también permite ver las anuladas. |
| `consultations.view-voided` | Ver las consultas anuladas de cualquier médico. |
| `consultations.audit`   | Consultar el historial de cambios de una consulta. |
| `inventory.view`        | Consultar el inventario de medicamentos.     |
| `inventory.create`      | Dar de alta medicamentos y registrar entradas. |
| `inventory.update`      | Editar medicamentos y registrar ajustes.     |
| `inventory.delete`      | Eliminar medicamentos sin movimientos.       |

`inventory` usa granularidad CRUD (cuatro permisos) en vez de un único permiso `*.manage` por módulo.
`patients` conserva ver, crear y editar, pero **no tiene permiso de eliminación**: un paciente nunca se borra, se
deshabilita (`patients.disable`, que también lo vuelve a habilitar) y `patients.audit` da acceso a su historial de
cambios. El antiguo permiso `patients.delete` se renombró a `patients.disable`. `patients.create` sigue definido pero
sin uso, porque el registro de pacientes es público; `patients.update` se aplica desde la Etapa 8.
Ver [gestión de pacientes](patient-management.md#permisos) para las reglas de cada uno. `consultations` conserva ver,
crear y editar, pero **no tiene permiso de eliminación**: una
consulta nunca se borra, se anula (`consultations.void`), y tres permisos adicionales cubren anular la de cualquier
médico (`consultations.void-any`), ver las anuladas (`consultations.view-voided`) y leer su historial de cambios
(`consultations.audit`). El antiguo permiso `consultations.delete` se renombró a `consultations.void`. Ver
[consultas médicas](medical-consultations.md#autorización) para las reglas de cada uno. `users.manage`,
`roles.manage` y `reports.generate` son permisos únicos, sin granularidad adicional. El seeder
(`RolePermissionSeeder`) crea los 19 permisos a partir de `Permission::cases()`, así que el enum es siempre la fuente de
verdad — nunca se comparan cadenas sueltas.

### Dependencias entre permisos

Algunos permisos solo tienen sentido junto con otros. `Permission::requires()` declara las dependencias directas (ver
[ADR-0011](../adr/0011-dependencias-entre-permisos.md)):

| Permiso | Requiere |
|---------|----------|
| `patients.create`, `patients.update`, `patients.disable`, `patients.audit` | `patients.view` |
| `consultations.view` | `patients.view` |
| `consultations.create`, `consultations.update`, `consultations.void`, `consultations.view-voided`, `consultations.audit` | `consultations.view` |
| `consultations.void-any` | `consultations.void` |
| `inventory.create`, `inventory.update`, `inventory.delete` | `inventory.view` |

Las dependencias son transitivas: `consultations.void-any` también requiere `consultations.view` y `patients.view`.

- **Al asignar.** Crear o editar un rol, o asignar permisos a un usuario, responde 422 con un error en `permissions`
  por cada dependencia faltante. El servidor no agrega permisos por su cuenta.
- **Usuarios.** Una dependencia queda cubierta por un permiso directo o por un permiso del rol asignado.
- **Políticas.** No cambian: cada una sigue comprobando un solo permiso.

## Catálogo de roles

A diferencia de los permisos, el catálogo de roles **no** es un enum: los roles son filas de la tabla `roles`
(`spatie/laravel-permission`) y se validan contra esa tabla (`Rule::exists('roles', 'name')`), no contra una lista
cerrada en código. Esto permite crear, editar y eliminar roles desde la aplicación sin tocar código.

Cualquier usuario con el permiso `roles.manage` puede administrar el catálogo desde la página de roles:

- Crear un rol con un nombre y un subconjunto de permisos del catálogo.
- Editar el nombre y los permisos (`role_has_permissions`) de un rol existente.
- Eliminar un rol.

**El rol `super-admin` está protegido de forma explícita.** No puede renombrarse ni eliminarse desde esta UI, ni
siquiera por otro usuario con `roles.manage`: `RolePolicy::update()`/`RolePolicy::delete()` devuelven `false` de forma
incondicional cuando el rol es `super-admin`, y `RoleController::update()`/`RoleController::destroy()` repiten la
misma comprobación como defensa en profundidad. La razón es que el acceso de `super-admin` no depende de sus
permisos asignados (ver más abajo), sino de que su nombre coincida literalmente con la cadena `'super-admin'` en el
bypass registrado en `AppServiceProvider`; renombrarlo o borrarlo rompería ese bypass en silencio.

## Roles de demostración

`php artisan demo:seed` (`make seed-demo`) crea, además de `super-admin`, estos roles y usuarios fijos (los usuarios
`medicoN@vitalease.test` adicionales comparten el rol Médico):

| Rol                    | Permisos                                                                                                              | Usuario demo                                                  |
|------------------------|-----------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------|
| `Médico`               | `consultations.view`, `consultations.create`, `consultations.update`, `consultations.void`, `patients.view`, `patients.update` | `medico.demo@vitalease.test`                                  |
| `Administrador`        | `users.manage`, `roles.manage`                                                                                        | `administrador@vitalease.test`                                |
| `Coordinación médica`  | `consultations.view`, `consultations.view-voided`, `consultations.void`, `consultations.void-any`, `consultations.audit`, `patients.view`, `patients.update`, `patients.disable`, `patients.audit` | `coordinacion@vitalease.test` |

Coordinación médica puede ver y anular o restaurar cualquier consulta y leer su historial, pero no crearlas ni
editarlas; tiene `consultations.void` porque `consultations.void-any` lo requiere. El Médico solo anula y restaura las suyas y no ve el historial de cambios. En pacientes, el Médico busca,
ve y edita; Coordinación médica además deshabilita, vuelve a habilitar y lee el historial de cambios (ver
[gestión de pacientes](patient-management.md)). El usuario `super-admin` es
`superadmin@vitalease.test`.

## El rol `super-admin`

`super-admin` es el único rol sembrado por defecto (`RolePermissionSeeder`). No tiene ningún permiso asignado
directamente — su fila en `role_has_permissions` está vacía. Su acceso total viene de un callback registrado en
`AppServiceProvider::boot()`:

```php
Gate::before(function (User $user, string $ability): ?bool {
    return $user->hasRole('super-admin') ? true : null;
});
```

Este callback se ejecuta antes de cualquier Policy, para toda comprobación de autorización (`can()`, middleware
`can:`, etc.). Si el usuario tiene el rol `super-admin`, la respuesta es `true` y la Policy correspondiente ni
siquiera se ejecuta; para cualquier otro usuario devuelve `null`, dejando que la comprobación normal (`UserPolicy`,
`RolePolicy`) siga su curso. Devolver `null` en vez de `false` es intencional: cualquier otro valor distinto de
`null` detendría la cadena de Gate y denegaría el acceso a todos los demás usuarios.

Por esto, inspeccionar la tabla `role_has_permissions` nunca muestra al `super-admin` con ningún permiso — su acceso
es completamente invisible ahí, y eso no es un defecto.

## Creación y gestión de usuarios

- Solo un administrador (con `users.manage`) puede crear usuarios; no existe registro público.
- Al crear un usuario, el administrador define su contraseña directamente; no hay invitación por correo ni rotación
  forzada.
- Todo usuario nuevo se crea con `has_access = true`.
- El toggle `has_access` solo bloquea el **siguiente** intento de login (por password o por Google); no invalida una
  sesión ya activa.
- Un administrador puede asignar roles y permisos directos a cualquier usuario, incluidos ambos arreglos vacíos (un
  usuario puede válidamente no tener ningún rol ni permiso directo). Roles y permisos directos se combinan de forma
  aditiva (semántica estándar de `spatie/laravel-permission`); no existe un "denegar" que anule un permiso otorgado
  por un rol.
- No hay guardas de autoprotección: un `super-admin` puede revocarse su propio permiso `users.manage` o deshabilitar
  su propia cuenta sin que la aplicación lo impida.

## Límite de autorización

Toda ruta de gestión (usuarios y roles) exige el permiso correspondiente mediante middleware `can:` sobre
`UserPolicy`/`RolePolicy`. Un usuario sin el permiso requerido recibe **HTTP 403**, nunca un redirect — el redirect a
login solo ocurre para un usuario sin autenticar (`auth` middleware).

## Interfaz

- Las páginas de usuarios y roles usan diálogos de shadcn-vue para crear/editar (formulario de usuario, asignación de
  roles/permisos, formulario de rol) y confirmación de eliminación.
- En los diálogos de rol y de permisos de usuario, marcar un permiso marca también sus requisitos y desmarcar uno
  desmarca todo lo que depende de él. Cada permiso con requisitos muestra una indicación como «Requiere: Pacientes:
  Ver». La lógica es compartida (`resources/js/lib/permissionDependencies.ts`) y usa la prop `permissionDependencies`.
- En el diálogo de permisos de usuario, los permisos del rol seleccionado (prop `rolePermissions`) cuentan como
  requisitos cubiertos: no se marcan como permisos directos y la indicación los señala como «incluido en el rol». Al
  desmarcar, la cascada solo retira permisos directos que se quedan sin requisito. Cambiar el rol no reajusta los
  permisos directos.
- Si el servidor rechaza la asignación por una dependencia faltante, ambos diálogos muestran el error debajo de los
  permisos.
- La barra lateral (`AppSidebar`) muestra la navegación de "Usuarios" y "Roles" solo si el usuario tiene el permiso
  correspondiente.
- Las notificaciones de éxito/error usan Sonner.
- Como el `super-admin` no tiene permisos directos, las props Inertia compartidas (`auth.permissions`) estarían
  vacías para él; para evitar que la UI le oculte todo, se comparte también `auth.is_super_admin`, y el composable
  `usePermissions().can()` devuelve `true` sin más comprobaciones cuando ese flag está activo. Esto es solo para la
  interfaz — el límite real de autorización sigue siendo del servidor.
