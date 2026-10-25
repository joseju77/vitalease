# Evaluación de carga, concurrencia, fallas y respaldos

Este documento resume la evaluación del despliegue de producción en Docker Swarm de un solo nodo (Etapa 9). Los datos
sin procesar están en [`results/`](results/README.md) y todas las cifras de este documento salen de esos archivos
(`k6-summary.json`, `docker-stats.csv`, `health.csv`, `events.csv`, `nginx-access.log.gz`, `sql-check.txt` y los textos
de `04-backup-restore`). La arquitectura y las decisiones están en
[distributed-architecture.md](../features/distributed-architecture.md) y en
[ADR-0012](../adr/0012-despliegue-swarm-de-un-nodo-y-resiliencia.md).

Alcance y límites, dichos desde el principio:

- Todo se midió en **un solo servidor físico**, con un Swarm de **un solo nodo** y tráfico **HTTP sin TLS**. La
  distribución es lógica, no física. No se midió ningún despliegue multinodo.
- Los resultados valen para este hardware, estos límites de recursos y estos datos sintéticos. Cada configuración se
  midió una vez (salvo una repetición de la corrida de una réplica, ver más abajo).
- Un resultado es lo que dicen los archivos; las explicaciones causales se marcan como hipótesis o como no investigadas.

La evaluación de la proyección de demanda (Etapa 10), que no mide carga ni fallas sino el error de pronóstico con
recetas del NHS de Inglaterra y series sintéticas, está en [forecast.md](forecast.md).

## Entorno

| | Servidor del Swarm (sistema bajo prueba) | Generador de carga (k6) |
| --- | --- | --- |
| CPU | Intel Core i5-9600K, 6 núcleos / 6 hilos | Intel Core i7-1255U, 12 hilos |
| RAM | 32 GB (30 GiB utilizables) | 46 GiB |
| Disco | NVMe (WD PC SN740 512 GB) | — |
| Sistema | Ubuntu 26.04.1 LTS, kernel 7.0.0-38 | openSUSE Tumbleweed |
| Docker | 29.9.2, modo Swarm, un solo nodo | 29.7.2-ce |
| Red | LAN, cableada | La misma LAN, Wi-Fi |

- k6 (`grafana/k6:2.3.0`) corrió en una máquina distinta para no competir por CPU con el stack. La CPU del generador
  (100 menos el porcentaje inactivo de `client-vmstat.csv`) tuvo un promedio de 1,69 % en el escalado con una réplica
  (muestras de 1 s, máximo 14 %) y de 1,70 % con tres réplicas (máximo 23 %); en las pruebas de concurrencia, 2,31 % y
  2,27 % (máximos 10 % y 11 %). La CPU del generador no fue el límite de las corridas.
- Rama `stage/09-distributed-resilience`; imágenes `vitalease/app:980b55a` y `vitalease/nginx:980b55a`. El commit
  `d590802` solo cambia las herramientas de carga (`tests/Load/bin`); las series de fallas usaron esas herramientas.
- Imágenes: PostgreSQL 18.6-alpine3.24, Redis 8.10.1-alpine3.23, Meilisearch v1.54.0.
- **Límites de recursos.** El plan original suponía un servidor de 4 núcleos y 8 GB y los límites del stack se
  dimensionaron para ese equipo. Los experimentos se ejecutaron en el servidor de 6 núcleos y 32 GB **con esos mismos
  límites**: `app` 1,0 CPU y 640 MB por réplica, `worker` 0,5 CPU y 256 MB (×2), PostgreSQL 1,5 CPU y 2 GB, Meilisearch
  1,0 CPU y 1 GB, Redis 0,5 CPU y 384 MB, `nginx` 0,5 CPU y 128 MB, `scheduler` 0,25 CPU y 192 MB y `backup` 0,5 CPU y
  256 MB (`docker/swarm/stack.yml` y `stack.backup.yml`). La suma de los topes de CPU con tres réplicas de `app` es
  8,25 (7,75 sin `backup`), mayor que los 6 núcleos físicos: los topes son por tarea, no una partición.
  PHP-FPM usa `pm.max_children = 10` por réplica de `app`.

## Metodología

- **Herramienta.** Scripts de k6 en `tests/Load/` (sesión con CSRF/XSRF de Inertia, una sola autenticación por usuario
  virtual y medida aparte, `Accept: application/json` en las escrituras para contar los 422). Los resúmenes se exportan
  con `handleSummary`: `digest` (percentiles y contadores de las operaciones medidas, sin los inicios de sesión ni la
  preparación) y `raw` (métricas completas de k6).
- **Mezcla de operaciones** (pesos relativos): búsqueda de pacientes 30, detalle de paciente 20, visualización de
  consultas 20, creación de consulta con dispensación 15, inventario 10, proyección de demanda 5. Espera de 1 s entre
  iteraciones.
- **Datos.** Sembrados con `make swarm-seed-demo SEED_ARGS="--users=100 --patients=10000 --consultations=100000"`
  (18 min 16 s) y `make swarm-reindex`. Antes de cada corrida la base de datos se restauró desde un volcado tomado justo
  después de sembrar, de modo que cada corrida parte de los mismos datos y existencias: 100 usuarios, 10 000
  pacientes, 100 079 consultas, 39 medicamentos y 205 238 movimientos de inventario (79 consultas provienen de una
  prueba previa). Son datos sintéticos.
- **Muestreo en el servidor.** `docker stats` por contenedor aproximadamente cada 3 s (178 muestras en 9 min 4 s en el
  escalado con una réplica) y consulta de `/up` y `/health` cada segundo (`health.csv`). Los eventos de inyección se
  registran en `events.csv`; el registro de acceso de `nginx` se guardó en las series de fallas.
- **Cómo se leen los números.** El porcentaje de CPU de `docker stats` se expresa sobre un núcleo (100 % = 1 CPU). Las
  peticiones fallidas son `raw.metrics.http_req_failed.values.passes` (el `error_rate` del `digest` está redondeado a dos
  decimales). Las respuestas 422 de reglas de negocio (existencia insuficiente) están declaradas como esperadas y no
  cuentan como fallidas. `digest.requests` cuenta solo operaciones medidas; `http_reqs` incluye además inicios de sesión
  y preparación.

## Experimento 1: escalado de `app` (1 réplica contra 3)

Escenario `experiments/scaling.js`: pasos de 5, 25, 50 y 100 usuarios virtuales, cada uno con una rampa de 15 s y una
meseta de 2 min (9 minutos en total). `k6-summary.json` agrega toda la corrida: **no hay percentiles por paso**. Para los
pasos solo se dispone de la CPU de `docker-stats.csv` por intervalos de tiempo.

| | `app` ×1 | `app` ×3 |
| --- | --- | --- |
| Operaciones medidas | 16 365 | 29 847 |
| Iteraciones | 8611 | 15 758 |
| Operaciones por segundo (promedio de la corrida) | 29,95 | 54,81 |
| Peticiones fallidas | 0 de 16 816 | 0 de 30 302 |
| Latencia p50 (ms) | 627,05 | 68,68 |
| p90 (ms) | 2117,91 | 736,03 |
| p95 (ms) | 2305,18 | 924,87 |
| p99 (ms) | 3281,59 | 1533,65 |
| Promedio / máximo (ms) | 883,43 / 4487,52 | 257,87 / 2664,22 |
| Consultas creadas / rechazadas por existencia (422) | 1113 / 172 | 1780 / 570 |
| Inicio de sesión p50 / p95 (ms) | 2783,5 / 7639,1 | 466 / 1693,65 |

Con tres réplicas el rendimiento promedio fue 1,83 veces el de una réplica y el p95 pasó de 2305,18 ms a 924,87 ms. La
corrida con una réplica se repitió sobre otro commit de las imágenes (`d3531b1`, con el idioma aplicado a mano):
29,76 operaciones por segundo y p95 de 2306 ms, según `results/README.md`, lo que da una idea de la repetibilidad para
esa configuración.

Percentiles por operación (de `digest.latency_ms_by_name`):

| Operación | p50 ×1 (ms) | p95 ×1 (ms) | p50 ×3 (ms) | p95 ×3 (ms) |
| --- | --- | --- | --- | --- |
| `consultations.create-form` | 690,36 | 2396,89 | 66,74 | 876,79 |
| `consultations.show` | 706,39 | 2395,98 | 78,43 | 952,84 |
| `consultations.store` | 790,31 | 2486,31 | 85,86 | 1875,28 |
| `inventory.index` | 599,34 | 2202,56 | 49,8 | 789,22 |
| `inventory.low-stock` | 595,47 | 2195,62 | 54,37 | 783,4 |
| `inventory.search` | 603,01 | 2201,89 | 54,06 | 822,98 |
| `patients.search` | 607,95 | 2285,16 | 50,34 | 787,24 |
| `patients.search-json` | 601,73 | 2209,51 | 46,85 | 746,15 |
| `patients.show` | 799,41 | 2467,99 | 92,25 | 939,54 |
| `patients.summary` | 683,34 | 2285,76 | 58,88 | 796,91 |
| `reports.demand-index` | 589,04 | 2192,52 | 50,45 | 696,78 |
| `reports.demand-projection` | 601,02 | 2209,76 | 68,54 | 861,88 |

### Uso de CPU por paso

CPU promedio por servicio (suma de las réplicas), en la parte final de la meseta de cada paso. Las ventanas son
aproximadas: se obtuvieron restando 540 s a `generated_at` del resumen de k6 para estimar el inicio de la rampa, y se
tomaron los últimos ~85 s de cada meseta de 135 s (rampa más meseta).

| Paso (usuarios virtuales) | `app` ×1 | PostgreSQL ×1 | `app` ×3 | PostgreSQL ×3 |
| --- | --- | --- | --- | --- |
| 5 | 21 % | 15 % | 21 % | 16 % |
| 25 | 93 % | 66 % | 102 % | 69 % |
| 50 | 100 % | 69 % | 205 % | 139 % |
| 100 | 100 % | 66 % | 227 % | 151 % |

- Con una réplica, `app` llega a 100 % de su único núcleo asignado desde el paso de 50 usuarios (máximo registrado en la
  corrida: 105,8 %).
- Con tres réplicas, la suma de `app` llega a 227 % en el paso de 100 usuarios (máximo de la corrida 300,7 %, con un
  límite de 300 %) y PostgreSQL a 151 % con un límite de 150 %: a 100 usuarios, **tanto `app` como PostgreSQL están cerca
  de su tope**. Por eso agregar réplicas de `app` no basta para seguir escalando: PostgreSQL es el siguiente cuello de
  botella en esta configuración.
- Meilisearch, Redis y `nginx` estuvieron entre 1 % y 6 % en las mesetas (promedio de toda la corrida: Meilisearch 2,4 %
  y 3,9 %; Redis 2,0 % y 3,2 %; `nginx` 2,8 % y 4,0 % con una y tres réplicas).

### Memoria

| | `app` ×1 | `app` ×3 |
| --- | --- | --- |
| `app`, máximo por réplica (MiB; límite 640) | 78,9 | 78,1 / 77,9 / 77,7 |
| PostgreSQL, máximo (MiB; límite 2048) | 412,9 | 456,0 |
| Meilisearch, máximo (MiB) | 151,9 | 149,3 |
| Suma de todos los servicios, máximo (MiB) | 767,9 | 966,5 |

## Experimento 2: concurrencia sobre una misma existencia

Dos escenarios sobre un medicamento con existencia limitada a 200 unidades (preparada por el endpoint de ajustes, sin
escrituras directas en SQL). Después de cada corrida, `make swarm-sql-check` ejecuta
`tests/Load/sql/stock-consistency.sql`; el resultado está en `sql-check.txt`.

| | 02a: solo dispensaciones | 02b: dispensaciones y ajustes |
| --- | --- | --- |
| Parámetros | `STOCK=200 DISPENSE_QUANTITY=1 VUS=50 DURATION=60s` | `STOCK=200 DISPENSE_VUS=30 ADJUST_VUS=30 DURATION=60s` |
| Peticiones | 4048 | 5066 |
| Operaciones por segundo | 63,65 | 79,79 |
| Latencia p50 / p95 / p99 (ms) | 582,99 / 867,23 / 1101,94 | 403,81 / 1676,09 / 1899,55 |
| Dispensaciones aceptadas / rechazadas (422) | 200 / 3848 | 838 / 301 |
| Ajustes aceptados / rechazados (422) | — | 3264 / 663 |
| Unidades dispensadas / agregadas / retiradas | 200 / 0 / 0 | 838 / 1951 / 1313 |
| Existencia final | 0 | 0 |
| Movimientos en el historial del medicamento | 3673 | 7575 |
| Peticiones fallidas | 0 | 0 |
| Comprobaciones SQL | 11 de 11 PASS | 11 de 11 PASS |

- En 02a se aceptaron exactamente 200 dispensaciones (la existencia inicial) y se rechazaron 3848 con 422; la
  existencia final es 0.
- En 02b, 200 − 838 + 1951 − 1313 = 0, igual que la existencia final; las 838 dispensaciones y los 3264 ajustes aceptados
  coinciden con los movimientos registrados.
- Las comprobaciones (`sql-check.txt`) son: existencia mayor o igual a 0; ningún movimiento con `stock_after` negativo;
  existencia igual a la suma de movimientos; cadena de `stock_after` consistente (0 inconsistentes sobre 3673 y 7575
  movimientos); último `stock_after` igual a la existencia; dispensaciones aceptadas igual a movimientos de dispensación;
  ajustes aceptados igual a movimientos de ajuste; existencia inicial menos dispensado más ajustes netos igual a la
  existencia final; y, para todos los medicamentos, existencia igual a la suma de movimientos y ninguna existencia
  negativa (0 discrepancias).
- La creación de consultas se serializa por un candado asesor de PostgreSQL de alcance diario (generación del código de
  consulta). Por eso 02b también ejecuta ajustes de existencia, que solo toman el candado de fila del medicamento
  (`StockLedger::lock`) y ejercitan la concurrencia sobre las filas sin pasar por ese candado.
- CPU durante 02a: `app` ×3 promedio 175,6 % (máximo 297,0 %), PostgreSQL promedio 127,3 % (máximo 147,1 %); durante 02b:
  `app` 182,8 % (300,8 %), PostgreSQL 133,0 % (153,5 %), con `worker` en 14,4 % promedio y Meilisearch en 25,1 %, valores
  mayores que en las corridas 01, 02a y 03 (promedios de `worker` de 0,7 % a 1,4 % y de Meilisearch de 1,8 % a 3,9 %). No
  se investigó el motivo.

## Experimento 3: inyección de fallas bajo carga estable

Escenario `experiments/steady.js`: 20 usuarios virtuales durante 6 minutos (misma mezcla). La falla se inyecta a los 2
minutos con `make swarm-inject`. Todas las corridas usan `app` ×3.

### Resumen de las corridas

| Corrida | Peticiones (`http_reqs`) | Fallidas | p50 / p95 / p99 (ms) | Máximo (ms) | Operaciones por segundo |
| --- | --- | --- | --- | --- | --- |
| 03a: se mata una tarea `app` | 12 755 | 0 | 37,97 / 64,9 / 83,8 | 641,7 | 34,71 |
| 03b: Meilisearch a 0 durante 60 s | 12 708 | 0 | 39,4 / 66,05 / 101,98 | 1069,59 | 34,59 |
| 03c: Redis a 0 durante 60 s | 12 746 | 2300 (18,04 %) | 35,23 / 66,15 / 99,7 | 3011,7 | 34,69 |
| 03d: PostgreSQL a 0 durante 60 s | 12 492 | 2014 (16,12 %) | 38,92 / 71,66 / 214,3 | 6116,84 | 33,93 |
| 03e: reinicio forzado de `nginx` | 12 726 | 48 (0,38 %) | 40,06 / 71,42 / 131,67 | 783,95 | 34,63 |

Los percentiles cubren toda la corrida de 6 minutos y no solo la ventana de la falla.

### Cronología y operaciones que continuaron

Horarios UTC de `events.csv`, `health.csv` y el registro de `nginx`.

**03a, tarea `app` eliminada.** `docker kill` a las 20:21:25; Swarm tenía las 3 réplicas en ejecución a las 20:21:37 (12 s).
Las 12 755 peticiones del registro de `nginx` fueron 200 (11 681), 302 (963) o 422 (111); no hubo respuestas 5xx y
k6 no registró fallos. `/up` y `/health` respondieron 200 en todas las muestras.

**03b, Meilisearch.** Escalado a 0 a las 20:27:33 y de nuevo a 1 a las 20:28:33. En esos 60 s, las 2054 peticiones fueron
200 (1900), 302 (141) o 422 (13); entre ellas, 321 búsquedas de pacientes (`/patients`), 321 del tablero
(`/dashboard/patients/search`) y 324 de inventario, todas con 200. `/health` indicó `degraded` desde las 20:27:35 y volvió
a `ok` a las 20:28:39 (6 s después de restablecer); el evento «recovered» (réplicas en ejecución según Swarm) se registró a
las 20:29:00. El registro de `nginx` no indica qué motor atendió cada búsqueda; la ruta de respaldo en PostgreSQL es la
esperada por diseño y coincide con `degraded` y con la ausencia de errores. No se midió la puesta al día de la cola de
indexación.

**03c, Redis.** Escalado a 0 a las 20:33:42 y a 1 a las 20:34:42. Entre 20:33:42 y la primera respuesta 503 (20:33:45)
hubo 52 peticiones (50 con 200, 2 con 302). Desde la primera respuesta 503 hasta restablecer, las 2080 peticiones fueron
503; otras 220 respuestas 503 llegaron en los primeros 7 s tras restablecer (la última a las 20:34:48), para un total
de 2300 (igual a `passes` de k6). `/up` respondió 200 en todas las muestras; `/health` respondió 503 `down` desde las
20:33:44 y volvió a `ok` a las 20:34:49 (7 s). El evento «recovered» se registró a las 20:35:08. Las 84 peticiones a
`/login` ocurrieron antes de la inyección; después de recuperarse no hubo redirecciones a `/login` (los únicos 302 son
`POST /consultations` aceptados), es decir, las sesiones siguieron siendo válidas. Es coherente con la persistencia AOF
de Redis, pero ese mecanismo no se probó de forma aislada. En `docker-stats.csv` de esta corrida aparecen 6 contenedores
distintos de `worker` (2 en las demás corridas), coherente con reinicios de
los procesos de cola por la política de Swarm mientras Redis no estaba disponible; no se verificó en los registros.

**03d, PostgreSQL.** Escalado a 0 a las 20:39:50 y a 1 a las 20:40:50. Entre 20:39:50 y la primera respuesta 503
(20:39:56) hubo 54 peticiones (50 con 200, 4 con 302). Desde la primera 503 hasta restablecer, las 1789 peticiones fueron
503; otras 223 respuestas 503 llegaron en los primeros 7 s tras restablecer (hasta las 20:40:56). `/up` respondió 200 en
todas las muestras. `/health` no respondió (código 000) de 20:39:51 a 20:39:54, respondió 503 `down` hasta las 20:40:56 y
volvió a `ok` a las 20:40:57 (7 s). El evento «recovered» se registró a las 20:41:16. Después hubo dos respuestas 503
aisladas más: una a las 20:40:57 y otra a las 20:41:26 (36 s después de restablecer, en una búsqueda del tablero), aunque
`/health` ya indicaba `ok` (1789 + 223 + 2 = 2014). **La causa no se investigó**; se reporta como hecho observado y queda
como seguimiento.

**03e, `nginx`.** `docker service update --force` a las 20:45:59; evento «recovered» a las 20:46:28 (29 s). `/up` y
`/health` no respondieron en una sola muestra (20:46:02) y respondieron en la siguiente (20:46:03). Fallaron 48 de 12 726
peticiones; el registro de `nginx` contiene 12 678 peticiones de k6 (12 726 − 48), es decir, las fallidas son las que
nunca llegaron a `nginx` y solo se ven como errores de k6.

### Matriz de resultado por dependencia

| Dependencia caída | Qué continuó | Qué falló | Recuperación observada |
| --- | --- | --- | --- |
| Una tarea `app` | Todo | Nada observado | 12 s hasta tener las 3 réplicas |
| Meilisearch | Todo, incluida la búsqueda | `/health` en `degraded` | `/health` `ok` a los 6 s |
| Redis | `/up` | Todas las páginas autenticadas (503) | Páginas en 200 a los 6 s; `/health` `ok` a los 7 s |
| PostgreSQL | `/up` | Todas las páginas autenticadas (503) | `/health` `ok` a los 7 s; dos 503 aisladas después (hasta 36 s) |
| `nginx` (única réplica) | Todo antes y después | Peticiones durante el reinicio | Una muestra de 1 s sin respuesta |

## Experimento 4: respaldo y restauración

`make swarm-backup` y después `make swarm-restore-verify` (`results/04-backup-restore/`).

| Medida | Valor |
| --- | --- |
| Archivo | `vitalease_20261025T205022Z.dump` (formato personalizado) |
| Tamaño | 13 063 677 bytes (12,46 MiB) |
| Duración del respaldo | 4,548 s |
| Duración de la verificación de restauración | 3,175 s (el script informa `restore_seconds=2`) |
| Resultado | PASS, código de salida 0 |

Conteo de filas en la base viva y en la restaurada (todos coinciden):

| Tabla | Viva | Restaurada |
| --- | --- | --- |
| `users` | 100 | 100 |
| `patients` | 10 000 | 10 000 |
| `medical_consultations` | 100 079 | 100 079 |
| `medications` | 39 | 39 |
| `inventory_movements` | 205 238 | 205 238 |
| `audits` | 363 | 363 |

La restauración se hizo en la base temporal `vitalease_restore_check`, que el script elimina; no se tocó la base viva. La
restauración sobre la base viva no se midió. Los respaldos están en el mismo servidor, sin copia externa ni cifrado.

## Hallazgos durante los experimentos

Las corridas expusieron defectos que se corrigieron antes de las series que se publican:

- Faltaba `APP_LOCALE` en el stack: toda creación de consulta devolvía 500 (corregido en `980b55a`). La primera corrida
  de `app` ×1 se descartó por eso.
- El latido del planificador se leía como cadena desde Redis y `/health` marcaba el planificador como caído (corregido en
  `d3531b1`).
- La imagen de producción no incluye Faker, por lo que los datos de demostración se siembran con una imagen aparte
  (`vitalease/seeder`, `d3531b1`).
- El lector de salud y la medición de recuperación de `kill-app` tenían defectos (corregidos en `d590802`); la serie de
  fallas se repitió completa.

## Amenazas a la validez

- **Un solo servidor.** No hay tolerancia a fallas físicas: perder el servidor es una caída total. `nginx`, PostgreSQL,
  Redis y Meilisearch son una sola réplica cada uno; `nginx` es el único punto de entrada.
- **Datos sintéticos.** Generados con `demo:seed`; la distribución de nombres, consultas y existencias no es la de datos
  reales.
- **Una corrida por configuración**, salvo la repetición de `app` ×1 (29,76 y 29,95 operaciones por segundo). No hay
  intervalos de confianza.
- **Generador en Wi-Fi.** El generador se conectó por Wi-Fi a una LAN cableada; la latencia de red incluida en las
  medidas no es la de un cliente real ni la de un enlace cableado. La CPU del generador fue baja (1,69 % y 1,70 % en
  promedio en el escalado).
- **Espera de 1 s** entre iteraciones y una mezcla de operaciones fija: no representan necesariamente el uso real.
- **Estadísticas agregadas por pasos.** Los percentiles de las corridas de escalado abarcan los cuatro pasos juntos (5
  a 100 usuarios); no hay percentiles por paso.
- **Topes de CPU sobreasignados.** La suma de topes (8,25 CPU) supera los 6 núcleos del servidor; con carga alta los
  servicios compiten por CPU y los límites no son una partición.
- **Límites dimensionados para otro equipo.** Los límites se calcularon para 4 núcleos y 8 GB y se midieron en 6 núcleos y
  32 GB; los resultados no se pueden extrapolar a otro hardware ni a otros límites.
- **Candado asesor.** La creación de consultas se serializa por un candado de PostgreSQL de alcance diario; esto limita
  el rendimiento de las escrituras y condiciona la lectura de las corridas con consultas creadas.
- **Rechazos por existencia.** Una parte de las creaciones de consulta se rechaza con 422 por existencia agotada (172 y
  570 en el escalado), lo que altera la mezcla efectiva de operaciones.
- **Fallas de un solo tipo y duración.** Cada falla se inyectó una vez, durante 60 s, con 20 usuarios; no se probaron
  caídas de `worker`, `scheduler` ni `backup`, fallas simultáneas ni la pérdida del servidor.
- **Solo HTTP.** No se midió el efecto de TLS.

## Trazabilidad con las observaciones académicas

| Observación | Qué se pidió | Dónde se responde |
| --- | --- | --- |
| Página 3 | Mecanismos de comunicación entre servicios, responsabilidades y manejo de fallas de conexión; qué pasa si Meilisearch no responde, si PostgreSQL no está disponible o si se reinicia un contenedor; admitir que no había distribución | [Arquitectura](../features/distributed-architecture.md) y [ADR-0012](../adr/0012-despliegue-swarm-de-un-nodo-y-resiliencia.md); experimento 3 (03a, 03b, 03d, 03e); la distribución se declara lógica y de un solo nodo |
| Página 4 y video 00:56 | Prueba de concurrencia con varios usuarios dispensando el mismo medicamento sin existencia negativa y con movimientos consistentes | Experimento 2 (02a y 02b, `sql-check.txt`) |
| Página 7 | Distinguir servicios usados de servicios planeados (Redis); prueba de carga con tiempos de respuesta, percentiles, memoria y errores; pruebas de indisponibilidad y recuperación; no presentar una topología no implementada como operativa | Redis usado en sesión, caché y cola (ADR-0012, experimento 3, 03c); experimento 1; experimento 3; la topología multinodo se separa y se marca como no implementada |
| Página 8 | Respaldos y recuperación de datos; resultados medibles bajo carga | Experimento 4; experimentos 1 a 3 |
| Video 01:11 | Mostrar comunicación y recuperación entre servicios y aclarar qué componentes son procesos independientes | [Arquitectura](../features/distributed-architecture.md) (procesos independientes y comunicación); experimento 3 (03a, autorrecuperación) |
