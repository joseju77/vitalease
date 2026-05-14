# ADR-0002: Usar Docker para el ambiente de desarrollo

* Estado: Aceptada

## Contexto

VitalEase requiere un ambiente de desarrollo reproducible para Laravel, frontend y servicios relacionados.

El proyecto debe reducir diferencias entre entornos locales y facilitar que nuevas configuraciones se levanten de forma consistente.

## Decisión

Se mantendrá un ambiente de desarrollo basado en Docker y `docker-compose.dev.yml`.

Esta decisión se toma por la experiencia previa del equipo con Docker y por la necesidad de controlar explícitamente los servicios del entorno de desarrollo.

## Alternativas consideradas

- Configuración local manual en cada equipo.
- Laravel Sail como wrapper oficial de Docker para Laravel.

## Consecuencias

### Positivas

- El ambiente local es más reproducible.
- Disminuye la dependencia de versiones instaladas directamente en el sistema operativo.
- Permite control explícito sobre servicios, imágenes y configuración.
- Facilita alinear el entorno de desarrollo con las necesidades reales del proyecto.

### Negativas

- Requiere mantener archivos Docker y documentación de comandos.
- Puede aumentar el consumo local de recursos.
- Los errores de entorno pueden requerir conocimiento de Docker para diagnosticarse.

### Compromisos aceptados

- Se acepta mantener configuración Docker propia a cambio de mayor control sobre los servicios.
- Se descarta Laravel Sail como opción principal porque abstrae parte de la configuración que el proyecto necesita controlar.
