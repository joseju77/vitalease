# Raw experiment results

Raw, unedited output of the load, concurrency, failure-injection and
backup/restore experiments run against the single-node Docker Swarm production
stack. The analysis lives in `docs/evaluation/README.md`; this folder only
holds the measured data and how it was produced.

All timestamps inside the files are UTC and are the real run times
(2026-10-25, 19:23–20:51 UTC).

## Environment

| | Swarm server (system under test) | Load generator (k6) |
|---|---|---|
| CPU | Intel Core i5-9600K, 6 cores / 6 threads | Intel Core i7-1255U, 12 threads |
| RAM | 32 GB (30 GiB usable) | 46 GiB |
| Disk | NVMe SSD (WD PC SN740 512 GB) | — |
| OS | Ubuntu 26.04.1 LTS, kernel 7.0.0-38 | openSUSE Tumbleweed |
| Docker | 29.9.2, Swarm mode, single node | 29.7.2-ce |
| Network | LAN, wired | Same LAN, Wi-Fi |

- One physical server: the distribution is logical (independent replicated
  services on one node), not physical.
- k6 ran on a separate machine so load generation never competed with the
  stack for CPU. `client-vmstat.csv` records the generator's CPU in every run.
- Plain HTTP on port 80 (nginx, host-mode publish). No TLS.

## Software under test

- Branch `stage/09-distributed-resilience`. Images `vitalease/app:980b55a` and
  `vitalease/nginx:980b55a` built with `make swarm-build`; the stack deployed
  with `make swarm-deploy` (`docker/swarm/stack.yml` + `stack.backup.yml`).
- Commit `d590802` only changes the load tooling (`tests/Load/bin`); the
  application and images are identical to `980b55a`. Failure runs (03*) used
  the `d590802` tooling.
- Resource limits from `docker/swarm/stack.yml`: app 1.0 CPU / 640M per
  replica, worker 0.5 / 256M (×2), postgres 1.5 / 2G, meilisearch 1.0 / 1G,
  redis 0.5 / 384M, nginx 0.5 / 128M, scheduler 0.25 / 192M. PHP-FPM
  `pm.max_children = 10` per app replica.
- Images: postgres 18.6-alpine3.24, redis 8.10.1-alpine3.23,
  getmeili/meilisearch v1.54.0, grafana/k6 2.3.0.

## Dataset

Seeded once with `make swarm-seed-demo SEED_ARGS="--users=100 --patients=10000
--consultations=100000"` (18 min 16 s), then `make swarm-reindex`.
Before every run the database was restored from a `pg_dump -Fc` snapshot taken
right after seeding, so every run starts from identical data and stock:
100 users, 10 000 patients, 100 079 consultations, 39 medications,
205 238 inventory movements (79 consultations come from a pre-experiment smoke
test).

## Experiments

App replicas are 3 unless stated otherwise. k6 parameters are the exact `-e`
values passed to `make load-run`.

| Folder | Script | Parameters | What it measures |
|---|---|---|---|
| `01-scaling-app1` | `experiments/scaling.js` | app ×1, `VU_STEPS=5,25,50,100 STEP_DURATION=2m RAMP=15s` | Latency, throughput and errors of the mixed workload with one app replica |
| `01-scaling-app3` | same | app ×3, same parameters | Same workload with three app replicas |
| `02a-concurrency-dispense` | `experiments/concurrency-dispense.js` | `STOCK=200 DISPENSE_QUANTITY=1 VUS=50 DURATION=60s` | Concurrent dispensation of one medication with limited stock |
| `02b-concurrency-adjust` | `experiments/concurrency-adjust.js` | `STOCK=200 DISPENSE_VUS=30 ADJUST_VUS=30 DURATION=60s` | Dispensations and stock adjustments racing on one medication |
| `03a-failure-kill-app` | `experiments/steady.js` | `STEADY_VUS=20 DURATION=6m`; `docker kill` of one app task at ~2 min | Self-healing of a replicated service |
| `03b-failure-meilisearch` | same | meilisearch scaled to 0 at ~2 min, back to 1 after 60 s | Search fallback while the engine is down |
| `03c-failure-redis` | same | redis scaled to 0 at ~2 min, back to 1 after 60 s | Behaviour without the session/cache/queue store |
| `03d-failure-postgres` | same | postgres scaled to 0 at ~2 min, back to 1 after 60 s | Behaviour without the database |
| `03e-failure-restart-nginx` | same | `docker service update --force` of nginx at ~2 min | Restart of the only published entry point |
| `04-backup-restore` | `make swarm-backup`, `make swarm-restore-verify` | — | Dump duration and size, restore into a scratch DB and row-count comparison |

Concurrency runs were followed by `make swarm-sql-check`
(`tests/Load/sql/stock-consistency.sql`).

## Files

| File | Machine | Content |
|---|---|---|
| `k6-summary.json` | client | `digest` (percentiles, throughput, error rate, counters) and `raw` (full k6 metrics) |
| `k6-stdout.txt` | client | k6 console output |
| `client-vmstat.csv` | client | Load generator CPU and free memory, 1 s |
| `docker-stats.csv` | server | `docker stats` per container, about every 2 s |
| `health.csv` | server | Public `/up` and internal `/health` polled every second |
| `events.csv` | server | Injected failure and recovery timestamps (03*) |
| `nginx-access.log.gz` | server | nginx access log of the run (03*), gzip |
| `sql-check.txt`, `sql-check-vars.txt` | server / client | Stock invariant check and its input (02*) |

## Measurement notes

- `digest.error_rate` in `k6-summary.json` is rounded to two decimals; the exact
  counts are in `raw.metrics.http_req_failed` (`passes` = failed requests).
- In `01-*` and `02-*`, the `health_status` / `health_overall` columns of
  `health.csv` were recorded with the poller before commit `d590802`, which
  misparsed the response (spurious `000,unreachable` rows and the status of the
  last check instead of the overall one). Those columns are not used; the `/up`
  columns are valid. `03-*` use the fixed poller.
- `events.csv` `recovered` means every desired replica is running again
  (Swarm task state); the application-level recovery is read from `health.csv`
  and `nginx-access.log.gz`.
- `nginx-access.log.gz` only records requests that reached nginx; requests
  refused while nginx itself restarted appear only as k6 errors.

## Discarded runs

Not included, kept out of the analysis:

- A first `01-scaling-app1` run: every consultation creation returned 500
  because the stack did not set `APP_LOCALE` (fixed in `980b55a`).
- A second `01-scaling-app1` run on images `d3531b1` with the locale fix applied
  by hand: valid (29.76 req/s, p95 2306 ms) but repeated on `980b55a` for exact
  traceability.
- A first series of the five failure runs: k6 data was valid, but `health.csv`
  and the `kill-app` recovery event were affected by the tooling defects fixed
  in `d590802`, so the series was repeated.
