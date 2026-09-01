# VitalEase

Plataforma para la gestión del área médica de CUCEI: alta de pacientes, consultas médicas, y administración de
usuarios, roles y permisos.

## Stack

- **Backend**: Laravel 13 (PHP 8.5), arquitectura MVC simple.
- **Frontend**: Vue 3 + Inertia.js, Vite, Tailwind CSS 4, componentes base de shadcn/vue.
- **Base de datos**: PostgreSQL 18.
- **Caché / sesiones**: Redis.
- **Entorno de desarrollo**: Docker (nginx + PHP-FPM + PostgreSQL + Redis).

Las decisiones de arquitectura y sus alternativas consideradas están documentadas en [`docs/adr/`](docs/adr/).
La documentación de cada feature vive en [`docs/features/`](docs/features/).

## Requisitos previos

- [Docker](https://docs.docker.com/get-docker/) y Docker Compose (incluido en versiones recientes de Docker).
- Una llave pública SSH en `~/.ssh/id_ed25519.pub` (o cualquier otra ruta, ver [Variables de entorno](#variables-de-entorno)) — se usa para acceso remoto al contenedor de la aplicación (debugging, no requiere contraseña).

PHP, Composer y Node **no son necesarios en tu máquina**: todo corre dentro de los contenedores.

## Instalación

```bash
git clone <url-del-repositorio>
cd vitalease

cp .env.example .env   # ajusta variables si es necesario (ver más abajo)

make setup              # levanta los contenedores, instala dependencias,
                         # genera la app key y corre las migraciones
```

La aplicación queda disponible en <http://localhost>.

## Variables de entorno

`.env.example` ya trae valores de desarrollo funcionales (Postgres y Redis apuntando a los servicios de Docker). Si
necesitas ajustar algo:

- `SSH_PUBLIC_KEY_PATH`: ruta a tu llave pública SSH en el host, si no usas `~/.ssh/id_ed25519.pub`. Se monta como
  `authorized_keys` dentro del contenedor de la aplicación (puerto `2222`).
- `DB_*` / `REDIS_HOST`: ya apuntan a los nombres de servicio del `docker-compose.dev.yml` (`postgres`, `redis`); no
  deberían necesitar cambios en desarrollo local.

## Comandos disponibles

Todos los comandos de desarrollo se ejecutan a través del `Makefile`. Ver la lista completa con:

```bash
make help
```

Los más usados:

| Comando | Qué hace |
|---|---|
| `make up` / `make down` | Levanta / detiene los contenedores |
| `make setup` | Instalación inicial completa (ver arriba) |
| `make laravel-shell` | Abre una shell dentro del contenedor de la aplicación |
| `make postgres-shell` / `make redis-shell` | Shell en los contenedores de Postgres / Redis |
| `make lint` | Corre ESLint, Prettier y Pint en modo verificación |
| `make lint-fix` | Corrige automáticamente los problemas de lint/formato |
| `make test` | Corre la suite de tests del backend (Pest) |
| `make test-frontend` | Corre la suite de tests del frontend (Vitest) |
| `make test-all` | Corre ambas suites |
| `make seed-demo` | Carga datos de demostración (ver [Datos de demostración](#datos-de-demostración)) |

## Datos de demostración

Para tener la aplicación poblada con datos realistas (usuarios, pacientes y consultas médicas en español), sobre una
base de datos recién creada:

```bash
make seed-demo
```

Por defecto crea 8 usuarios, 80 pacientes, un catálogo de medicamentos con su existencia inicial y 600 consultas (con
sus líneas de tratamiento) repartidas en los últimos 365 días. Las cantidades se pueden ajustar ejecutando el comando
de Artisan directamente (desde `make laravel-shell`):

```bash
php artisan demo:seed --users=8 --patients=80 --consultations=600
```

- `--users` incluye las 3 cuentas fijas (superadministrador, médico demo y administrador), por lo que el mínimo es 3;
  el resto se crean como médicos.
- El comando carga primero los catálogos, luego los datos de demostración (incluyendo el catálogo de medicamentos y
  su historial de movimientos de inventario) y al final importa pacientes y medicamentos al índice de búsqueda.
- No es idempotente: se niega a ejecutarse si los datos de demostración ya existen. Para volver a cargarlos, recrea la
  base de datos (`php artisan migrate:fresh --seed`) antes de ejecutarlo de nuevo.
- Se niega a ejecutarse en producción.

Cuentas de acceso (**solo para demostración**, contraseña `password` en todas):

| Correo                                                | Rol                                 |
|-------------------------------------------------------|-------------------------------------|
| `superadmin@vitalease.test`                           | Superadministrador (acceso total)   |
| `medico.demo@vitalease.test`                          | Médico demo (pacientes y consultas) |
| `medico1@vitalease.test`, `medico2@vitalease.test`, … | Médico                              |
| `administrador@vitalease.test`                        | Administrador (usuarios y roles)    |

## Desarrollo

- El servidor de Vite (hot module reload) se levanta automáticamente dentro del contenedor de la aplicación y queda
  expuesto en `http://localhost:5173`.
- Los hooks de pre-commit (Husky + lint-staged) corren automáticamente al hacer `git commit`, aplicando ESLint/
  Prettier/Pint solo sobre los archivos en stage.
- Para acceso SSH al contenedor de la aplicación (por ejemplo, para debugging remoto desde el IDE): `ssh -p 2222 www-data@localhost`, usando la llave configurada en `SSH_PUBLIC_KEY_PATH`.
