# Arquitectura distribuida y resiliencia

Este documento describe el despliegue de producción de VitalEase en Docker Swarm que se agregó en la Etapa 9: qué
servicios existen, cuáles corren como procesos independientes, cómo se comunican, qué ocurre cuando una dependencia
falla y cómo se despliega, escala, respalda y restaura. Las decisiones y sus alternativas están en
[ADR-0012](../adr/0012-despliegue-swarm-de-un-nodo-y-resiliencia.md); la metodología y los datos medidos, en la
[evaluación](../evaluation/README.md).

En resumen:

- Hay **un solo servidor físico** y un Swarm de **un solo nodo**, con tráfico en **HTTP sin TLS**. La distribución es
  **lógica**: servicios independientes y replicados, descubrimiento por DNS, balanceo por IP virtual y reinicio
  automático de tareas. **No es distribución física**: si el servidor se pierde, todo el sistema se pierde.
- El stack está preparado para varios nodos (restricciones de ubicación, sin rutas del anfitrión en servicios
  replicados) pero **no está desplegado ni probado en varios nodos**.
- PostgreSQL, Redis y Meilisearch corren en una sola réplica. Su falla se maneja con degradación (Meilisearch) o con una
  página 503 (PostgreSQL y Redis), no con replicación.
- El entorno de desarrollo (`docker-compose.dev.yml`) no cambia.

## Topología desplegada

Esta es la topología que existe y se midió.

```
 Cliente (HTTP, puerto 80, publicado en modo host)
        │
        ▼
 ┌──────────────┐   FastCGI app:9000 (IP virtual)   ┌─────────────────────────────┐
 │ nginx  ×1    │ ────────────────────────────────▶ │ app ×3 (php-fpm)            │
 │ :80 público  │                                   └──────┬───────┬───────┬──────┘
 │ :8081 interno│ (/health, /nginx-health)                  │       │       │
 └──────────────┘                                  PostgreSQL│  Redis│   HTTP│
                                                           ▼       ▼       ▼
 red overlay `internal` (10.20.0.0/24)             ┌─────────┐ ┌───────┐ ┌─────────────┐
                                                   │postgres │ │ redis │ │ meilisearch │
   worker ×2   (queue:work redis) ───────────────▶ │   ×1    │ │  ×1   │ │     ×1      │
   scheduler ×1 (schedule:work) ─────────────────▶ │ pgdata  │ │ AOF   │ │ meilidata   │
   backup ×1   (crond + pg_dump) ────────────────▶ └─────────┘ └───────┘ └─────────────┘
                                                      volumen `backups` (en el mismo servidor)
```

- Solo `nginx` publica un puerto (80). El listener 8081 de `nginx` (`/nginx-health` y `/health`) no se publica; en el
  puerto público `/health` responde 404.
- Todo corre en el mismo nodo manager. Los volúmenes `pgdata`, `redisdata`, `meilidata` y `backups` son locales.
- Tareas de una sola ejecución, lanzadas con Make: `migrate`, `seed` y `reindex`.

### Procesos independientes

| Servicio | Réplicas | Proceso | Imagen | Función |
| --- | --- | --- | --- | --- |
| `nginx` | 1 | `nginx` | `vitalease/nginx` (activos compilados dentro) | Único punto de entrada, FastCGI hacia `app` |
| `app` | 3 | `php-fpm` | `vitalease/app` | Atiende las peticiones de la aplicación |
| `worker` | 2 | `php artisan queue:work redis` | `vitalease/app` | Indexación de Scout en la cola |
| `scheduler` | 1 | `php artisan schedule:work` | `vitalease/app` | Poda de trabajos fallidos, latido |
| `postgres` | 1 | `postgres` | `postgres:18.6-alpine3.24` | Base de datos |
| `redis` | 1 | `redis-server` | `redis:8.10.1-alpine3.23` | Sesión, caché y cola (AOF) |
| `meilisearch` | 1 | `meilisearch` | `getmeili/meilisearch:v1.54.0` | Búsqueda |
| `backup` | 1 | `crond` | `postgres:18.6-alpine3.24` | Volcados programados |
| trabajos `migrate`, `seed`, `reindex` | una ejecución | `php artisan ...` | `vitalease/app` (`seed` usa `vitalease/seeder`) | Operaciones puntuales |

La misma imagen `vitalease/app` sirve a `app`, `worker`, `scheduler` y los trabajos, con comandos distintos. La imagen
no contiene xdebug, sshd, node ni supervisord y corre como usuario sin privilegios. La imagen `vitalease/seeder` es la
de la aplicación más las dependencias de desarrollo (Faker) y solo se usa en el trabajo de datos de demostración.

### Comunicación y manejo de fallas

| Origen → destino | Protocolo | Tiempo de espera | Si falla |
| --- | --- | --- | --- |
| cliente → `nginx` | HTTP | — | El cliente recibe un error de conexión |
| `nginx` → `app` | FastCGI hacia `app:9000` (IP virtual de Swarm) | `fastcgi_read_timeout` 60 s | Según la configuración, `nginx` arranca sin `app` y responde 502 mientras no haya tareas (no medido) |
| `app` → PostgreSQL | Protocolo de PostgreSQL | Conexión 5 s (`DB_CONNECT_TIMEOUT`) | Página 503 con `Retry-After: 30` |
| `app` → Redis | Redis (phpredis) | Conexión 2 s, lectura 5 s | Página 503 con `Retry-After: 30` |
| `app` → Meilisearch | HTTP | Conexión 1 s, total 3 s | Búsqueda en PostgreSQL con `ILIKE` y aviso en pantalla |
| `worker`/`scheduler` → Meilisearch | HTTP | Conexión 2 s, total 15 s | El trabajo se reintenta |
| `worker` → Redis | Redis | Conexión 2 s, lectura 5 s | Con Redis caído, `docker-stats.csv` de la corrida 03c muestra 6 contenedores `worker` distintos (2 en las demás): reinicios por la política de Swarm, no verificados en los registros |

- **Búsqueda.** Pacientes, medicamentos y el buscador del tablero pasan por una sola abstracción. Si Meilisearch no
  responde (error de conexión, tiempo agotado, respuesta no JSON, HTTP 5xx o `index_not_found`) se consulta PostgreSQL
  con los mismos filtros, orden y paginación, y se muestra un aviso. Los errores 4xx no se ocultan. Limitaciones del
  respaldo: no tolera errores de tecleo, no normaliza acentos, no ordena por relevancia (el tablero ordena por apellido,
  nombre e id) y limita la consulta a 5 términos.
- **Dependencias críticas.** Una excepción de conexión a PostgreSQL (SQLSTATE `08*`, `57P01`, `57P02`, `57P03`, `53300`,
  conexión perdida) o a Redis produce HTTP 503 con la página «Servicio temporalmente no disponible» (o JSON con
  `message`). Otros errores de base de datos siguen siendo 500.
- **Indexación.** Con `SCOUT_QUEUE=true`, los cambios se indexan en la cola de Redis después de confirmar la
  transacción, con 15 intentos y espera creciente (5, 15, 30, 60, 120 y 300 s, repitiendo el último), unos 49 minutos;
  después pasan a `failed_jobs`. `make swarm-reindex` reimporta los índices y es idempotente.
- **Salud.** `/up` (pública) solo comprueba que el proceso responde. `/health` (solo por el listener interno 8081)
  informa base de datos y Redis (críticas, 503 si fallan) y Meilisearch, cola y planificador (no críticas, `degraded`
  con 200).

## Topología multinodo futura (no implementada)

> Esta sección es una propuesta. **No está implementada, desplegada ni medida.** Se incluye para mostrar hacia dónde
> podría crecer el diseño y qué cambiaría; nada de lo siguiente existe en este repositorio.

Con más de un servidor se podría:

- Unir nodos adicionales al Swarm y repartir las réplicas de `app` y `worker` entre ellos (el stack ya no depende de
  rutas del anfitrión para esos servicios).
- Publicar `nginx` en más de un nodo detrás de un balanceador externo, lo que eliminaría el punto único de entrada. El
  modo de publicación `host` actual obliga a una réplica por nodo; habría que revisar cómo se conserva la IP del cliente.
- Replicar los servicios con estado (PostgreSQL con réplica en espera, Redis con Sentinel o clúster, Meilisearch con
  copia) y sacar los volúmenes del disco local (almacenamiento compartido o replicación). Hoy cada uno es una sola
  réplica anclada al nodo manager.
- Copiar los respaldos fuera del servidor.
- Terminar TLS (ver más abajo cómo agregar un certificado).

Ninguno de estos cambios se ha diseñado en detalle ni se ha probado.

## Matriz de fallas

Fuente: experimento 3 (`docs/evaluation/results/03*`). Carga constante de 20 usuarios virtuales durante 6 minutos con
la mezcla de operaciones de la evaluación (30 % búsqueda de pacientes, 20 % detalle de paciente, 20 % visualización de
consultas, 15 % creación de consultas, 10 % inventario, 5 % proyección de demanda), 1 s de espera entre iteraciones, y la
falla inyectada a los 2 minutos aproximadamente. Los horarios son UTC. «Peticiones fallidas» es
`http_req_failed.passes` de k6 (incluye respuestas 503 y peticiones que no llegaron a `nginx`).

| Falla inyectada | Peticiones fallidas | Qué siguió funcionando | Qué falló | Recuperación observada |
| --- | --- | --- | --- | --- |
| `docker kill` de una tarea `app` | 0 de 12 755 | Todas las operaciones (las 12 755 peticiones registradas por `nginx` fueron 200, 302 o 422) | Nada observado | Swarm tenía de nuevo las 3 réplicas en ejecución 12 s después de matar la tarea |
| Meilisearch a 0 réplicas durante 60 s | 0 de 12 708 | Todas las operaciones, incluida la búsqueda: en la ventana de 60 s, las 2054 peticiones fueron 200 (1900), 302 (141) o 422 (13) | `/health` pasó a `degraded` | `/health` volvió a `ok` 6 s después de restablecer el servicio; el evento «recovered» de Swarm quedó 27 s después |
| Redis a 0 réplicas durante 60 s | 2300 de 12 746 (503) | `/up` (liveness) respondió 200 en todo momento | Todas las páginas autenticadas: desde la primera respuesta 503 hasta restablecer el servicio, las 2080 peticiones fueron 503 | Las páginas respondieron 200 de nuevo 6 s después de restablecer; `/health` volvió a `ok` a los 7 s. No hubo reinicio de sesión (ver nota) |
| PostgreSQL a 0 réplicas durante 60 s | 2014 de 12 492 (503) | `/up` respondió 200 en todo momento | Todas las páginas autenticadas: las 1789 peticiones desde la primera respuesta 503 hasta restablecer el servicio fueron 503 | `/health` volvió a `ok` a los 7 s; ver nota sobre dos 503 posteriores |
| Reinicio forzado de `nginx` (`docker service update --force`) | 48 de 12 726 | Todo antes y después del reinicio | Peticiones que llegaron mientras `nginx` no atendía (solo visibles como errores de k6) | Una muestra de 1 s (20:46:02) sin respuesta en `/up` ni en `/health`; respuesta normal en la siguiente; evento «recovered» a los 29 s |

Notas:

- **Meilisearch.** Que las peticiones siguieran respondiendo 200 y que `/health` indicara `degraded` coincide con el
  respaldo de búsqueda en PostgreSQL; el registro de `nginx` no permite distinguir por sí solo qué motor atendió cada
  búsqueda. No se midió el vaciado de la cola de indexación tras el corte (no se hicieron escrituras indexables
  dirigidas durante el experimento).
- **Redis.** Las primeras respuestas 503 aparecieron 3 s después de ordenar el escalado a 0; en ese intervalo hubo 52
  peticiones (50 con 200 y 2 con 302). Tras restablecer el servicio no hubo ráfaga de redirecciones a `/login`: las 84
  peticiones a `/login` del experimento ocurrieron antes de la inyección y, después, los únicos 302 corresponden a
  `POST /consultations` aceptados. Es coherente con la persistencia AOF de Redis, pero ese mecanismo no se probó por
  separado.
- **PostgreSQL.** Las primeras respuestas 503 aparecieron 6 s después de ordenar el escalado a 0. Durante unos
  segundos `/health` no respondió (código 000, de 20:39:51 a 20:39:54) y después respondió 503 hasta 20:40:56. Después
  de restablecer el servicio hubo 223 respuestas 503 en los primeros 7 s y dos más aisladas: una a los 7 s
  (20:40:57) y otra a los 36 s (20:41:26) tras restablecer, aunque `/health` ya indicaba `ok`. **La causa no se
  investigó**; es una limitación conocida y un seguimiento pendiente.
- **Latencia durante la falla.** Los percentiles de toda la corrida (6 minutos, no solo la ventana de falla) están en la
  [evaluación](../evaluation/README.md#experimento-3-inyección-de-fallas-bajo-carga-estable). El máximo de PostgreSQL fue
  6116,84 ms y el de Redis 3011,7 ms.
- **No medido.** No se probó la caída de `worker`, `scheduler` ni `backup`, ni la pérdida del servidor completo.

## Despliegue, escalado, respaldo y restauración

Todas las órdenes `swarm-*` se ejecutan en el servidor de Swarm desde la raíz del repositorio. `make swarm-*` carga
`docker/swarm/swarm.env` (no versionado).

### Primer despliegue

1. `make swarm-init`: inicia el Swarm de un nodo (no hace nada si ya está activo; `ADVERTISE_ADDR=<ip>` si hace falta).
2. `cp docker/swarm/swarm.env.example docker/swarm/swarm.env` y ajustar valores. `APP_URL` es obligatorio y
   **`APP_LOCALE=es` es necesario**: solo existe el idioma `es` y, sin él, la aplicación responde con claves de
   traducción y la creación de consultas falla con 500.
3. `make swarm-secrets`: genera los archivos faltantes en `docker/swarm/secrets/` (ignorados por Git) y crea los
   secretos de Docker (`app_key`, `db_password`, `redis_password`, `meili_master_key`).
4. `make swarm-build`: construye `vitalease/app`, `vitalease/nginx` y `vitalease/seeder` con la etiqueta `IMAGE_TAG` (o
   el SHA corto de Git).
5. `make swarm-deploy`: despliega `docker/swarm/stack.yml` y `docker/swarm/stack.backup.yml` con
   `--resolve-image never` (usa las imágenes locales).
6. `make swarm-migrate`: trabajo de una sola ejecución que ejecuta `migrate --force` y `scout:sync-index-settings`.
   Las migraciones nunca se ejecutan al arrancar una réplica.
7. `make swarm-seed-demo SEED_ARGS="--users=100 --patients=10000 --consultations=100000"`: datos de demostración desde la
   imagen `vitalease/seeder` (usa `demo:seed --force`). Después, `make swarm-reindex` reimporta pacientes y medicamentos
   en Meilisearch.
8. `make swarm-ps` muestra servicios y tareas; `make swarm-health` imprime el informe de `/health` (consulta el listener
   interno desde dentro del contenedor de `nginx`).

Los datos de demostración son sintéticos. Una actualización posterior repite `make swarm-build` y `make swarm-deploy`; las
actualizaciones de `app` y `worker` son continuas (paralelismo 1, `start-first`, reversión si falla).

### Escalado y operación

- Escalar: `make swarm-scale SERVICE_NAME=app REPLICAS=3` (por ejemplo, 1 o 3 réplicas de `app`; los experimentos
  compararon ambas).
- Registros: `make swarm-logs SERVICE_NAME=app`.
- Base de datos: `make swarm-sql` abre `psql` en la tarea de PostgreSQL.
- Retirar el stack: `make swarm-rm` (los volúmenes y los secretos se conservan).
- `scheduler` debe permanecer en 1 réplica: una segunda ejecutaría dos veces las tareas programadas.

### Respaldo y restauración

- Respaldo automático: el servicio `backup` ejecuta a diario a las 09:00 UTC (03:00 en UTC-06:00) un `pg_dump` en formato
  personalizado hacia el volumen `backups` y conserva los 7 más recientes (los archivos se llaman
  `vitalease_<fecha>.dump`).
- Respaldo inmediato: `make swarm-backup` (mismo script que el planificador).
- Verificación de restauración: `make swarm-restore-verify` restaura el volcado más reciente (o el que indique
  `FILE=/backups/<archivo>.dump`) en la base temporal `vitalease_restore_check`, compara el conteo de filas de seis tablas
  (`users`, `patients`, `medical_consultations`, `medications`, `inventory_movements`, `audits`) contra la base viva,
  sale con código distinto de cero si hay diferencias y elimina la base temporal. No toca la base viva y debe ejecutarse sin
  carga de escritura. El resultado medido está en la [evaluación](../evaluation/README.md#experimento-4-respaldo-y-restauración).
- Restaurar sobre la base viva es un procedimiento manual que **no está automatizado ni se probó en esta etapa**: en
  esencia, detener `app`, `worker` y `scheduler`, ejecutar `pg_restore` con el volcado elegido dentro del contenedor
  `backup` y volver a levantar los servicios. El índice de Meilisearch no forma parte del volcado; después hay que
  ejecutar `make swarm-reindex`.
- Alcance: los volcados viven en un volumen del mismo servidor. No hay copia externa, cifrado, restauración a un punto en
  el tiempo ni alertas.

### Solo HTTP y cómo agregar un certificado

El stack publica el puerto 80 sin TLS y fija `SESSION_SECURE_COOKIE=false`, porque una cookie marcada como segura nunca
se devolvería por HTTP. **No hay HTTPS desplegado.** Para agregarlo habría que: crear un certificado y su clave como
secretos de Docker, montarlos en `nginx` y agregar un `server` en el puerto 443 (publicado también en modo `host`) con
`ssl_certificate` y `ssl_certificate_key`, cambiar `APP_URL` a `https://` y establecer `SESSION_SECURE_COOKIE=true`. Esto
no se implementó ni se probó.

## Datos de demostración y herramientas de carga

- `make swarm-sample`, `make swarm-inject` y `make swarm-sql-check` toman muestras de `docker stats` y de salud, inyectan
  fallas y verifican las invariantes de existencias en el servidor.
- `make load-run SCRIPT=... BASE_URL=http://<servidor>` ejecuta k6 (`grafana/k6:2.3.0`) desde otra máquina de la red. Los
  scripts están en `tests/Load/`.
