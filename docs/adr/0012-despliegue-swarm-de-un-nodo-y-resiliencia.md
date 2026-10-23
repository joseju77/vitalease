# ADR-0012: Despliegue en Docker Swarm de un solo nodo y resiliencia ante fallas de dependencias

* Estado: Aceptada; sustituye el punto 4 de [ADR-0006](0006-busqueda-de-pacientes-con-scout-y-meilisearch.md) (indexación síncrona)

## Contexto

Hasta la Etapa 8 solo existía el entorno de desarrollo (`docker-compose.dev.yml`): una imagen con xdebug, sshd, node y
supervisord, todos los puertos publicados, sin verificaciones de salud ni políticas de reinicio. En desarrollo Redis ya
guardaba la sesión y la caché (`SESSION_DRIVER=redis`, `CACHE_STORE=redis` en `.env.example`), pero la cola era
síncrona (`QUEUE_CONNECTION=sync`): no había procesos de cola ni planificador, y la indexación de Scout se ejecutaba
dentro de la petición. Tampoco había respaldos ni herramientas de carga, y una caída de PostgreSQL o de Meilisearch
producía un error 500 genérico.

La evaluación académica previa observó, entre otros puntos, que el documento admitía no tener distribución del
procesamiento, que no describía la comunicación entre servicios ni el manejo de fallas de conexión, que mezclaba
servicios usados con servicios solo planeados (Redis), que faltaban pruebas de concurrencia sobre el inventario, pruebas
de carga con percentiles y errores, pruebas de indisponibilidad y recuperación, y respaldos con recuperación de datos.

Restricción de infraestructura: **existe un solo servidor físico**. Por eso el despliegue es un Swarm de **un solo
nodo**. La distribución que ofrece es **lógica** (servicios independientes y replicados, descubrimiento por DNS,
balanceo por IP virtual, reinicio automático de tareas) y **no física**: la pérdida del servidor es una caída total.
El stack está preparado para varios nodos (restricciones de ubicación, sin rutas del anfitrión en servicios
replicados), pero **no se ha desplegado ni probado en varios nodos** y esta decisión no lo afirma.

## Decisión

### Topología, servicios y comunicación

El stack (`docker/swarm/stack.yml` más la superposición `docker/swarm/stack.backup.yml`) define estos servicios:

| Servicio | Réplicas | Responsabilidad | Cómo se comunica |
| --- | --- | --- | --- |
| `nginx` | 1 | Único servicio con puerto publicado (80, HTTP). Sirve los activos compilados que lleva en su imagen y reenvía PHP | FastCGI hacia `app:9000` (IP virtual del servicio `app`) |
| `app` | 3 | `php-fpm` con la aplicación Laravel | Protocolo de PostgreSQL, Redis (RESP) y HTTP hacia Meilisearch |
| `worker` | 2 | `queue:work redis`: ejecuta la indexación de Scout | Redis (cola), HTTP hacia Meilisearch, PostgreSQL |
| `scheduler` | 1 | `schedule:work`: poda de trabajos fallidos y latido del planificador | Redis (caché), PostgreSQL |
| `postgres` | 1 | Base de datos relacional, volumen `pgdata` | Protocolo de PostgreSQL |
| `redis` | 1 | Sesión, caché y cola, persistencia AOF, volumen `redisdata` | Protocolo Redis con contraseña |
| `meilisearch` | 1 | Motor de búsqueda, volumen `meilidata` | HTTP con clave maestra |
| `backup` | 1 | `crond` más `pg_dump`, volumen `backups` | Protocolo de PostgreSQL |

Además hay trabajos de una sola ejecución (`migrate`, `seed`, `reindex`) que se lanzan por Make y no forman parte del
estado permanente del stack.

- Todos los servicios comparten la red overlay `internal` (`10.20.0.0/24`, subred fija). Solo `nginx` publica un puerto;
  PostgreSQL, Redis y Meilisearch no son accesibles desde fuera de la red.
- Los servicios con estado (`postgres`, `redis`, `meilisearch`, `backup`) usan volúmenes con nombre y la restricción de
  ubicación `node.role == manager`. Se ejecutan en una sola réplica: su falla se maneja con degradación y reinicio, no
  con replicación.
- Cada servicio tiene verificación de salud, política de reinicio (`condition: any`, espera 5 s), política de
  actualización y límites y reservas de recursos. Los servicios sin estado se actualizan con paralelismo 1,
  `start-first` y reversión automática; `nginx` y los servicios con estado usan `stop-first` (el puerto en modo `host`
  no puede estar enlazado por dos tareas a la vez).
- Los secretos (clave de la aplicación, contraseñas de base de datos y Redis, clave de Meilisearch) son secretos de
  Docker externos creados con `make swarm-secrets`; los roles PHP reciben variables `*_FILE`. Solo se versiona un
  ejemplo de variables de entorno (`docker/swarm/swarm.env.example`).

### Redis y sus roles

Redis pasa a ser real y **crítico**: guarda la **sesión**, la **caché** (incluida la caché de permisos y el limitador de
inicios de sesión y los candados) y la **cola** de trabajos. La sesión compartida es lo que permite que cualquier réplica
de `app` atienda a cualquier usuario.

### Decisiones

1. **Swarm de un solo nodo, distribución lógica.** Los servicios sin estado se replican (IP virtual como balanceador,
   DNS interno, reinicio de tareas). `postgres`, `redis` y `meilisearch` van en una réplica con volumen con nombre.
   Se descartó presentar la topología como multinodo o con replicación de datos, porque no existe un segundo servidor.
2. **Tiempos de espera acotados para Meilisearch.** `AppServiceProvider::register` enlaza un singleton de
   `Meilisearch\Client` que recibe un cliente `GuzzleHttp\Client` con `connect_timeout` y `timeout`. Las claves
   `scout.meilisearch.connect_timeout` y `scout.meilisearch.timeout` se leen de `MEILISEARCH_CONNECT_TIMEOUT` y
   `MEILISEARCH_TIMEOUT`: 1 s y 3 s para el servicio web, 2 s y 15 s para `worker`, `scheduler` y trabajos de una sola
   ejecución. Guzzle ya es dependencia de `laravel/framework`, por lo que no cambia `composer.json`. Se descartó
   `default_socket_timeout` (el transporte cURL lo ignora) y parchear Scout.
3. **Respaldo en PostgreSQL cuando Meilisearch no responde.**
   * Se recurre a la base de datos solo ante `CommunicationException`, `InvalidResponseBodyException` y `ApiException`
     con estado HTTP mayor o igual a 500 o con código `index_not_found`. Cualquier otro error 4xx se vuelve a lanzar,
     para no ocultar errores de programación (por ejemplo un orden inválido).
   * La consulta usa hasta 5 términos separados por espacios; todos deben coincidir (`AND`) en alguna de las columnas
     indexadas (`OR`) mediante `ILIKE '%término%'`, con `\`, `%` y `_` escapados. Pacientes: nombre, primer y segundo
     apellido y número de inscripción; medicamentos: nombre, presentación y concentración. Se conservan los filtros
     (deshabilitados, existencia baja), el orden de las ramas, 15 por página y `withQueryString`. El buscador del tablero
     devuelve como máximo 10 resultados ordenados por apellido, nombre e id (sin relevancia).
   * El respaldo **no** tolera errores de tecleo ni normaliza acentos, a diferencia de Meilisearch; esto se documenta
     como limitación.
   * Se marca la petición con `search_degraded` (prop compartida de Inertia, evaluada al renderizar), se muestra un
     aviso discreto en español y, en el endpoint JSON del tablero, se agrega el encabezado `X-Search-Degraded: 1` sin
     cambiar el cuerpo. El registro de advertencia incluye el índice y la clase de la excepción, nunca el texto buscado
     (dato personal de salud).
   * No hay interruptor de circuito ni `pg_trgm`. Se descartaron el motor `database` de Scout (busca en todas las
     claves buscables y no conserva el orden), `pg_trgm` (necesidad no medida) y capturar cualquier excepción (ocultaría
     los 400).
4. **Redis es una dependencia crítica, sin conmutación.** Un error de conexión a Redis produce la misma página 503 que
   un error de base de datos. Se fijan `REDIS_TIMEOUT=2` y `REDIS_READ_TIMEOUT=5` (segundos) en las conexiones
   `default` y `cache`. Se descartó un driver de respaldo: el de Laravel solo existe para caché y cola, reintenta el
   almacén caído en cada operación y la sesión no tiene equivalente, de modo que la sesión seguiría fallando. También se
   descartó mover las sesiones a PostgreSQL con respaldo, por la cantidad de piezas adicionales y porque contradice la
   decisión de sesión compartida en Redis.
5. **Qué errores producen 503.** Se responde 503 cuando alguna excepción de la cadena previa es `PDOException` o
   `QueryException` con SQLSTATE de clase `08`, `57P01`, `57P02`, `57P03` o `53300`; `LostConnectionException`; una
   coincidencia del `LostConnectionDetector` del framework; o `\RedisException`. El resto sigue siendo 500 (por
   ejemplo, una violación `23505`). La respuesta es una página de Inertia `errors/ServiceUnavailable` sin diseño
   autenticado ni props de autenticación (no consulta la base de datos ni Redis), o `{"message": ...}` para peticiones
   JSON, con el encabezado `Retry-After: 30`. Se agrega `PDO::ATTR_TIMEOUT` igual a `DB_CONNECT_TIMEOUT` (5 s) en la
   conexión `pgsql`. Se descartó tratar toda `QueryException` como 503 (ocultaría errores de restricciones) y
   `Inertia::handleExceptionsUsing` (vuelve a ejecutar el middleware y tocaría Redis y la base de datos otra vez).
6. **Endpoint `/health` interno.** Se registra con `withRouting(then:)` fuera del grupo `web` (sin sesión ni cookies) y
   solo se alcanza por el segundo `server` de nginx, en el puerto 8081, que **no se publica**. El puerto público
   responde 404 en `/health`. `/up` sigue siendo la sonda de vida pública y no revisa dependencias. La respuesta es
   `{status: ok|degraded|down, checks: {...}}`; devuelve 503 solo si una comprobación crítica (base de datos, Redis)
   falla; Meilisearch, la cola (pendientes y fallidos) y el planificador (latido de a lo sumo 180 s) son no críticos y
   producen `degraded` con 200. Cada comprobación informa solo la clase de la excepción. Se descartaron la lista de
   direcciones permitidas y un secreto compartido.
7. **Migraciones como trabajo de una sola ejecución.** `make swarm-migrate` invoca `docker/swarm/bin/run-job.sh`, que
   crea un servicio `replicated-job` con `--restart-condition none`, con la imagen, los secretos y el entorno del
   servicio `app` en ejecución, espera, propaga el fallo, imprime los registros y lo elimina. Ejecuta
   `php artisan migrate --force && php artisan scout:sync-index-settings`. Nunca se migra en el arranque de una réplica
   (tres réplicas competirían) ni como servicio declarado en el stack (se repetiría en cada despliegue y sin orden).
8. **Respaldos sin dependencias nuevas.** El servicio `backup` usa `postgres:18.6-alpine3.24` (misma versión de
   `pg_dump`), `crond` de busybox a las 09:00 UTC (03:00 en UTC-06:00), `pg_dump -Fc` hacia un archivo `.partial` que se
   renombra al terminar, y conserva los 7 más recientes en el volumen `backups`. Los scripts se entregan como configs
   de Swarm. `restore-verify.sh` restaura el volcado más reciente (o `FILE=`) en la base temporal
   `vitalease_restore_check` con `pg_restore --no-owner --exit-on-error`, compara el conteo de filas de `users`,
   `patients`, `medical_consultations`, `medications`, `inventory_movements` y `audits` contra la base viva, imprime
   duración y tamaño, sale con 1 si hay diferencias y elimina la base temporal. Precondición: sin carga de escritura.
   Se descartaron ejecutarlo desde el planificador de la aplicación (versión de cliente distinta y paquete extra) y
   WAL/PITR (fuera de alcance). Quedan fuera de alcance las copias externas, el cifrado y las alertas.
9. **Solo HTTP.** El stack publica el puerto 80 sin TLS y usa `SESSION_SECURE_COOKIE=false` (una cookie segura nunca se
   devolvería por HTTP). Agregar un certificado exige un `server` en el puerto 443 en nginx, el certificado y la clave
   como secretos de Docker, y `SESSION_SECURE_COOKIE=true`. No se creó un certificado autofirmado.
10. **Límites de recursos.** Los límites de memoria suman 6464 MB y las reservas 2,0 CPU y 2848 MB, dimensionados para un
    servidor de 4 núcleos y 8 GB; el límite de CPU es un tope por tarea, no una partición (la suma de los topes es 8,25
    CPU con `backup`). Los experimentos se ejecutaron en un servidor de 6 núcleos y 32 GB con estos mismos límites.
11. **Publicación de `nginx` en modo `host` y proxies de confianza.** Con el modo `ingress` la traducción de
    direcciones colapsa a todos los clientes en una sola IP; la bitácora de auditoría (`ip_address`), el limitador de
    inicios de sesión y `throttle:5,1` del autorregistro compartirían un mismo cubo. En modo `host` se conserva la IP real
    y `trustProxies(at: TRUSTED_PROXIES)` confía solo en la subred overlay fija (`10.20.0.0/24`). nginx sobrescribe
    `X-Forwarded-For` con `$remote_addr` para que un cliente no pueda falsificarlo. Consecuencia: `nginx` se actualiza
    con `stop-first`.
12. **Ordenamiento de pacientes en Meilisearch.** Al reproducir la búsqueda de pacientes contra el Meilisearch del
    entorno de desarrollo, `Patient::search('a')->orderBy('last_name')` fallaba con un 400 `invalid_search_sort`:
    «Attribute `last_name` is not sortable». Los índices de prueba usan el driver `collection`, que no lo detecta. Se
    configuran `sortableAttributes` con `last_name` y `first_name`, se ordena por apellido y luego por nombre y se
    sincroniza la configuración con `scout:sync-index-settings` (también incluido en `make swarm-migrate`). Se descartó
    quitar el ordenamiento. Es importante resolverlo aquí: un 400 no activa el respaldo (decisión 3) y rompería la
    búsqueda en el stack.
13. **Indexación en cola.** `SCOUT_QUEUE=true` solo en el entorno del Swarm (el desarrollo sigue siendo síncrono) y
    `after_commit` se mantiene. Los trabajos usan `tries` igual a `SCOUT_JOB_TRIES` (15) y espera creciente `[5, 15, 30,
    60, 120, 300]` segundos (el último valor se repite), lo que da una ventana de unos 49 minutos; después pasan a la
    tabla `failed_jobs` (migración nueva, `database-uuids`, reversible) y se recuperan con `make swarm-reindex`
    (`scout:import`, idempotente). Con `SCOUT_QUEUE=true` también la importación masiva se encola (`Searchable`).
    Esto sustituye la indexación síncrona del punto 4 de ADR-0006. Si Redis no responde al despachar, el error se trata
    como la falla de una dependencia crítica (503 después de confirmar la transacción de base de datos) y no se oculta.
14. **Planificador.** `schedule:work` ejecuta a diario `queue:prune-failed --hours=168` y cada minuto un
    `SchedulerHeartbeat` (clave de caché con TTL de 600 s) que `/health` lee. Se descartó no tener planificador porque
    el plan exige al menos una tarea programada real.
15. **Secretos en variables de entorno al arrancar.** `entrypoint.sh` convierte cada `*_FILE` en la variable homónima y
    luego ejecuta `php artisan optimize` (la configuración en caché debe incluir los valores de ejecución; `app/` no
    llama a `env()`). Se descartó `config:cache` durante la construcción de la imagen.
16. **`demo:seed --force`.** El comando mantiene su rechazo en producción y acepta `--force` para saltar ambas
    protecciones (comando y `DemoSeeder`) y lo pasa al `db:seed` interno (`ConfirmableTrait` aborta en producción si no).
    El Swarm lo usa con la imagen `vitalease/seeder`, que es la imagen de la aplicación más las dependencias de
    desarrollo (Faker), nunca desplegada como servicio. Se descartó sustituir `APP_ENV`.

## Alternativas consideradas

- Swarm de varios nodos o replicación de PostgreSQL, Redis o Meilisearch: no hay segundo servidor.
- `ingress` como modo de publicación: pierde la IP del cliente.
- Un driver de sesión o de caché con conmutación a base de datos.
- Capturar todas las excepciones de Meilisearch o todas las `QueryException`.
- Migrar en el arranque de cada réplica o declarar el trabajo en el stack.
- Un servicio de respaldo dentro de la imagen de la aplicación; WAL/PITR.
- HTTPS con certificado autofirmado.

## Consecuencias

### Positivas

- Se ejecutan procesos independientes y observables (servicio por responsabilidad), con reinicio automático de tareas.
- La búsqueda se mantiene disponible, degradada y señalizada, cuando Meilisearch no responde.
- Una caída de PostgreSQL o de Redis produce una página 503 en español con `Retry-After` y no un error 500 ni una traza.
- `/health` informa cada dependencia sin exponerse en el puerto público.
- Hay respaldos programados con verificación de restauración y resultados medidos
  (ver [evaluación](../evaluation/README.md)).

### Negativas

- Un solo servidor físico: su pérdida es una caída total. La distribución es lógica.
- PostgreSQL, Redis y Meilisearch son un único punto de falla cada uno. Sin Redis ninguna página autenticada responde
  (503); sin PostgreSQL tampoco.
- `nginx` es una sola réplica y es el único punto de entrada.
- Solo HTTP, sin TLS.
- El respaldo de búsqueda no corrige errores de tecleo ni normaliza acentos.
- La suma de los topes de CPU (8,25) supera los 6 núcleos del servidor de pruebas.
- Los respaldos viven en el mismo servidor, sin copia externa ni cifrado.

### Compromisos aceptados

- Se prefiere un 503 explícito sobre conmutar a un almacén de respaldo cuando Redis falla.
- Los límites de recursos se dimensionaron para 4 núcleos y 8 GB y se midieron en otro equipo; los resultados valen solo
  para ese hardware y esos límites.
- Tras recuperar PostgreSQL se observaron dos respuestas 503 aisladas después de que la mayoría de las peticiones ya
  respondía 200 (la última 36 s después de reactivar el servicio); la causa no se investigó y queda como seguimiento
  (ver [evaluación](../evaluation/README.md)).
