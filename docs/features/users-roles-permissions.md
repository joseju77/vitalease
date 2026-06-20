# Usuarios, roles y permisos

- Gestión de usuarios y control de acceso basado en `spatie/laravel-permission` (ver [ADR-0003](../adr/0003-rbac-approach.md)).
- No hay auto-registro: la única forma de crear una cuenta es que un administrador la cree desde esta gestión (ver
  [ADR-0004](../adr/0004-existing-users-only-auth.md) y [autenticación](authentication.md)).
- Varias decisiones de esta etapa se desvían del plan original; están documentadas en
  [ADR-0005](../adr/0005-permission-taxonomy-and-role-catalog.md).

## Catálogo de permisos

Los permisos son un catálogo **cerrado**, definido en el enum `App\Enums\Permission` (11 casos). Cualquier nombre de
permiso fuera de este enum se rechaza en la validación (`Rule::enum`):

| Permiso                | Descripción                                |
|-------------------------|---------------------------------------------|
| `users.manage`          | Gestionar usuarios (alta, edición, acceso).  |
| `roles.manage`          | Gestionar el catálogo de roles.              |
| `reports.generate`      | Generar reportes.                            |
| `patients.view`         | Ver pacientes.                               |
| `patients.create`       | Crear pacientes.                             |
| `patients.update`       | Editar pacientes.                            |
| `patients.delete`       | Eliminar pacientes.                          |
| `consultations.view`    | Ver consultas.                               |
| `consultations.create`  | Crear consultas.                             |
| `consultations.update`  | Editar consultas.                            |
| `consultations.delete`  | Eliminar consultas.                          |

`patients` y `consultations` usan granularidad CRUD (cuatro permisos independientes cada uno) en vez de un único
permiso `*.manage` por módulo. `users.manage`, `roles.manage` y `reports.generate` son permisos únicos, sin
granularidad adicional. El seeder (`RolePermissionSeeder`) crea los 11 permisos a partir de `Permission::cases()`, así
que el enum es siempre la fuente de verdad — nunca se comparan cadenas sueltas.

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
- La barra lateral (`AppSidebar`) muestra la navegación de "Usuarios" y "Roles" solo si el usuario tiene el permiso
  correspondiente.
- Las notificaciones de éxito/error usan Sonner.
- Como el `super-admin` no tiene permisos directos, las props Inertia compartidas (`auth.permissions`) estarían
  vacías para él; para evitar que la UI le oculte todo, se comparte también `auth.is_super_admin`, y el composable
  `usePermissions().can()` devuelve `true` sin más comprobaciones cuando ese flag está activo. Esto es solo para la
  interfaz — el límite real de autorización sigue siendo del servidor.
