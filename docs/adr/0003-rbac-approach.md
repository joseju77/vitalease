# ADR-0003: Usar spatie/laravel-permission para RBAC

* Estado: Aceptada

## Contexto

La aplicación requiere control de acceso basado en roles y permisos para gestionar qué usuarios pueden realizar qué
acciones (por ejemplo, gestionar pacientes, consultas y otros usuarios). Se necesita una forma robusta de almacenar,
asignar y verificar roles y permisos que se integre limpiamente con las primitivas de autorización de Laravel (Gates
y Policies).

## Decisión

Se usará el paquete `spatie/laravel-permission` para gestionar roles y permisos.

## Alternativas consideradas

- Tablas y lógica de RBAC personalizadas, construidas desde cero.

## Consecuencias

### Positivas

- Los roles y permisos se gestionan a través de un paquete maduro, bien documentado y con una comunidad activa, lo
  que reduce la carga de mantenimiento.
- Las verificaciones de autorización se integran de forma natural con los Gates y Policies nativos de Laravel
  (`can`, `@can`, middleware).
- Se evita reinventar problemas ya bien resueltos, como el caché de permisos y la asignación de roles/permisos
  muchos-a-muchos.

### Negativas

- El proyecto adquiere una dependencia del esquema y la ruta de actualización del paquete.
- Las migraciones y actualizaciones deben seguirse cuando el paquete se actualiza.

### Compromisos aceptados

- Se prioriza la madurez y el soporte de un paquete establecido sobre el control total de una solución propia.
