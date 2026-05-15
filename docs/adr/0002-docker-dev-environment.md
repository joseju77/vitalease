# ADR-0002: Usar Docker para el ambiente de desarrollo

* Estado: Aceptada

## Contexto

VitalEase requiere un ambiente de desarrollo reproducible para Laravel, frontend y servicios relacionados.

El proyecto debe reducir diferencias entre entornos locales y facilitar que nuevas configuraciones se levanten de forma consistente.

Además del backend y la base de datos, la aplicación necesita un backend de sesiones y caché. Usar el driver de archivos o de base de datos para esto es viable, pero no refleja cómo se comportará la aplicación en un entorno con más de una instancia de la aplicación corriendo.

## Decisión

Se mantendrá un ambiente de desarrollo basado en Docker y `docker-compose.dev.yml`, con los servicios `nginx` (proxy), `laravel` (PHP-FPM), `postgres` (base de datos) y `redis` (sesiones y caché de la aplicación).

Esta decisión se toma por la experiencia previa del equipo con Docker, por la necesidad de controlar explícitamente los servicios del entorno de desarrollo, y porque Redis como backend de sesiones/caché refleja mejor el comportamiento esperado en producción que los drivers de archivo o base de datos.

## Alternativas consideradas

- Configuración local manual en cada equipo.
- Laravel Sail como wrapper oficial de Docker para Laravel.
- Driver de sesiones/caché basado en archivos o en la base de datos, en lugar de Redis.

## Consecuencias

### Positivas

- El ambiente local es más reproducible.
- Disminuye la dependencia de versiones instaladas directamente en el sistema operativo.
- Permite control explícito sobre servicios, imágenes y configuración.
- Facilita alinear el entorno de desarrollo con las necesidades reales del proyecto.
- El comportamiento de sesiones y caché en desarrollo se mantiene consistente con el de producción.

### Negativas

- Requiere mantener archivos Docker y documentación de comandos.
- Puede aumentar el consumo local de recursos.
- Los errores de entorno pueden requerir conocimiento de Docker para diagnosticarse.
- Un servicio adicional (Redis) que hay que levantar y mantener, incluso en etapas donde su uso aún es mínimo.

### Compromisos aceptados

- Se acepta mantener configuración Docker propia a cambio de mayor control sobre los servicios.
- Se descarta Laravel Sail como opción principal porque abstrae parte de la configuración que el proyecto necesita controlar.
- Se prioriza la paridad con producción en sesiones/caché sobre la simplicidad de no tener un servicio adicional.
