# ADR-0005: Taxonomía de permisos y catálogo de roles de la etapa de usuarios/roles/permisos

* Estado: Aceptada

## Contexto

ADR-0003 decidió usar `spatie/laravel-permission` para RBAC, pero no fijó ningún catálogo de permisos ni de roles — su
"Contexto" solo menciona gestionar pacientes, consultas y usuarios como ejemplos de prosa, sin nombrar
`patients.manage`, `admin` ni `staff`. El catálogo concreto de permisos y roles quedó pendiente para la etapa de gestión
de usuarios, roles y permisos, cuyo plan original proponía un permiso `*.manage` por módulo y dos roles sembrados
(`admin`, `staff`).

Durante el diseño e implementación de esa etapa, surgieron varios cambios en el plan original. Esta ADR registra esos
cambios como decisiones propias de esta etapa — no como una revisión de ADR-0003, que sigue vigente sin modificación.

## Decisión

1. **Permisos con granularidad CRUD para `patients` y `consultations`.** En vez de un único permiso `patients.manage`
   y un único permiso `consultations.manage`, cada módulo tiene cuatro permisos independientes: `view`, `create`,
   `update`, `delete` (por ejemplo, `patients.view`, `patients.create`, `patients.update`, `patients.delete`).
   `users.manage`, `roles.manage` y `reports.generate` siguen siendo permisos únicos, sin granularidad adicional.

2. **Un solo rol sembrado, `super-admin`, en vez de `admin` y `staff`.** El plan original sembraba dos roles. El dueño
   del producto descartó `staff` y renombró el rol restante a `super-admin`. Un usuario que no sea
   `super-admin` no tiene ningún rol por defecto; su acceso depende únicamente de los permisos directos que se le
   asignen.

3. **El catálogo de roles es dinámico (tabla de base de datos), no un enum de PHP.** A diferencia del catálogo de
   permisos, que sí es un enum cerrado (`App\Enums\Permission`), los roles son filas de la tabla `roles` de
   `spatie/laravel-permission`. No existe un `App\Enums\Role`. La validación de nombres de rol usa
   `Rule::exists('roles', 'name')` contra esa tabla, en vez de una lista cerrada en código. Esta asimetría es
   deliberada: agregar un rol nuevo en el futuro solo requiere una fila nueva (o, con la capacidad añadida en el punto
   5, crearlo desde la propia interfaz), sin ningún cambio de código; agregar un permiso nuevo sigue requiriendo un
   cambio de enum.

4. **`super-admin` obtiene acceso total mediante un `Gate::before()`, no mediante permisos otorgados directamente.**
   El rol `super-admin` no tiene ninguna fila en `role_has_permissions`. En su lugar, `AppServiceProvider::boot()`
   registra:

   ```php
   Gate::before(function (User $user, string $ability): ?bool {
       return $user->hasRole('super-admin') ? true : null;
   });
   ```

   Este callback intercepta toda comprobación de autorización antes de cualquier Policy y devuelve `true` para
   `super-admin`, sin necesidad de que el rol tenga permisos asignados. Devuelve `null` (nunca `false`) para cualquier
   otro usuario, de forma que la comprobación normal (`UserPolicy`, `RolePolicy`) siga su curso sin verse afectada. Como
   consecuencia, inspeccionar `role_has_permissions` no muestra ningún permiso para `super-admin` — su acceso es
   invisible en esa tabla, y eso es intencional, no un defecto.

5. **Capacidad añadida: gestión del propio catálogo de roles**, no contemplada en el plan original (que solo cubría
   *asignar* roles/permisos a un usuario, no administrar los roles en sí). Se agregó una página dedicada para crear,
   editar y eliminar roles, gated por un permiso dedicado y nuevo: `roles.manage` (el permiso número 11 del catálogo).
   El rol `super-admin` está protegido explícitamente contra edición y eliminación en esa UI —
   `RolePolicy::update()`/`RolePolicy::delete()` devuelven `false` de forma incondicional para él, y
   `RoleController` repite la misma comprobación como defensa en profundidad — porque su acceso depende de que su nombre
   coincida literalmente con la cadena `'super-admin'` en el `Gate::before()` del punto 4; renombrarlo o borrarlo
   rompería ese bypass en silencio, incluso para otro usuario que sí tenga `roles.manage`.

## Alternativas consideradas

- Mantener un único permiso `*.manage` por módulo para `patients`/`consultations`, como en el plan original.
- Sembrar dos roles (`admin`, `staff`) en vez de uno solo.
- Modelar el catálogo de roles como un enum de PHP (`App\Enums\Role`), simétrico al de permisos.
- Otorgar a `super-admin` los 11 permisos de forma explícita (`syncPermissions(Permission::values())`) en vez de un
  `Gate::before()`.
- No agregar gestión del catálogo de roles en esta etapa, dejándola para una etapa posterior.

## Consecuencias

### Positivas

- La granularidad CRUD permite otorgar acceso parcial a `patients`/`consultations` (por ejemplo, solo lectura) sin
  esperar a un mecanismo de permisos más fino en una etapa futura.
- Un catálogo de roles dinámico permite crear roles nuevos (incluida su gestión completa desde la UI) sin desplegar
  código.
- `Gate::before()` mantiene `UserPolicy`/`RolePolicy` simples: nunca necesitan razonar sobre `super-admin`
  explícitamente, y seguirán siendo correctas si se agregan permisos nuevos en el futuro.

### Negativas

- La validación de nombres de rol (`Rule::exists`) es más débil que la de permisos (`Rule::enum`): acepta cualquier fila
  existente en `roles`, no una lista cerrada. Solo el seeder controla qué roles se crean en la práctica, salvo los que
  se creen manualmente desde la nueva página de gestión de roles.
- El acceso de `super-admin` es invisible al inspeccionar `role_has_permissions` — quien no conozca esta decisión podría
  interpretarlo erróneamente como un rol sin acceso.
- `super-admin` es el único rol que puede llegar a tener `users.manage`/`roles.manage` mediante el bypass; no hay guarda
  alguna contra que un `super-admin` se revoque a sí mismo su propio rol o deshabilite su propia cuenta.

### Compromisos aceptados

- Se prioriza la flexibilidad de un catálogo de roles abierto y editable desde la UI sobre la seguridad adicional de una
  lista cerrada, aceptando el riesgo de nombres de rol más débilmente validados.
- Se prioriza mantener el bypass de `super-admin` fuera del sistema de permisos (más simple de razonar y de proteger)
  sobre la visibilidad de su acceso en las tablas de `spatie/laravel-permission`.
