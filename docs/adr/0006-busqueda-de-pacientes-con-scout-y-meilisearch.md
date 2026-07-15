# ADR-0006: Búsqueda de pacientes con Laravel Scout y Meilisearch

* Estado: Aceptada

## Contexto

ADR-0002 fijó el ambiente Docker de desarrollo (`nginx`, `laravel`, `postgres`, `redis`) sin incluir un motor de
búsqueda, porque en la Etapa 0 ningún módulo lo necesitaba todavía. Esta etapa agrega el módulo de consultas médicas
y, junto con él, la necesidad de que el personal clínico localice rápidamente a un paciente por nombre o número de
inscripción antes de crear o consultar una consulta médica. Buscar pacientes con `LIKE`/`ILIKE` sobre la tabla
`patients` es viable para un volumen pequeño, pero no ofrece tolerancia a errores de tecleo, coincidencia por
prefijo en múltiples campos, ni un camino claro de crecimiento si el catálogo de pacientes se vuelve grande.

## Decisión

1. **Laravel Scout + Meilisearch como motor de búsqueda de `Patient`.** Se agregan las dependencias
   `laravel/scout` y `meilisearch/meilisearch-php` (únicas dependencias nuevas autorizadas para esta etapa), y un
   servicio `meilisearch` en `docker-compose.dev.yml`, con imagen fijada a un tag exacto
   (`getmeili/meilisearch:v1.54.0`, dentro de la línea v1.x que soporta el cliente PHP) — nunca `latest` — para que
   el ambiente de desarrollo sea reproducible.
2. **Solo cuatro campos indexados.** `Patient::toSearchableArray()` expone únicamente `first_name`, `last_name`,
   `second_last_name` y `enrollment_number` ("código de inscripción"). Ningún dato sensible (CURP/RFC, contacto,
   `external_enrollment`) se envía al índice de Meilisearch.
3. **Indexación solo después de confirmar la transacción (`after_commit`).** El registro de pacientes es
   transaccional; `config('scout.after_commit')` se fija en `true` para que un `INSERT` que hace rollback nunca
   llegue a ser indexado.
4. **Indexación síncrona, sin cola (`queue` en `false`).** El entorno Docker no tiene un worker de colas en
   `supervisord`, así que la sincronización con Meilisearch ocurre en la misma petición HTTP, igual que el resto de
   la escritura transaccional.
5. **Driver `collection` en pruebas.** `phpunit.xml` fija `SCOUT_DRIVER=collection`, de forma que `make test-all`
   siga siendo autocontenido y no dependa de una instancia de Meilisearch en ejecución.

## Alternativas consideradas

- Búsqueda con `ILIKE`/`pg_trgm` directamente sobre Postgres, sin un motor de búsqueda dedicado.
- El driver `database` de Scout (usa `LIKE` sobre la base de datos configurada), que evita un servicio adicional
  pero no ofrece tolerancia a errores de tecleo ni relevancia por ranking.
- Un proveedor de búsqueda administrado (Algolia, Typesense Cloud), descartado por agregar una dependencia externa
  de red y costo recurrente a un ambiente de desarrollo Docker-only.

## Consecuencias

### Positivas

- Búsqueda de pacientes con tolerancia a errores de tecleo y coincidencia por prefijo en los campos indicados, sin
  lógica de búsqueda personalizada en la aplicación.
- El indexado únicamente después del commit evita que un registro de paciente fallido o revertido aparezca en
  resultados de búsqueda.
- Las pruebas automatizadas no requieren levantar Meilisearch, gracias al driver `collection`.

### Negativas

- Se agrega un servicio Docker adicional (`meilisearch`) que hay que levantar, mantener y — en producción —
  respaldar junto con Postgres.
- Si Meilisearch no está disponible, la escritura del índice después del commit en el registro público de pacientes
  lanza una excepción no controlada: el paciente ya quedó guardado en Postgres, pero la petición responde con un
  error al usuario. Se decidió mantener este comportamiento por defecto de Scout (sin intentar silenciar o tolerar
  el fallo) en esta etapa, en vez de agregar manejo de errores adicional alrededor de la indexación.
- El catálogo de pacientes existente debe reindexarse manualmente (`scout:import`) al aplicar esta etapa; se agregó
  al target `setup` del `Makefile` para que el flujo de primer arranque quede cubierto.

### Compromisos aceptados

- Se prioriza una experiencia de búsqueda de mejor calidad sobre la simplicidad de no tener un servicio adicional
  en el ambiente Docker.
- Se acepta que una caída de Meilisearch se propague como error visible en el registro público de pacientes, en vez
  de invertir en tolerancia a fallos del índice en esta etapa.
