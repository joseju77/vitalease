# Autenticación

- Login por password y por Google OAuth. **Ambos flujos autentican solo usuarios ya existentes** — ninguno crea cuentas
  nuevas (ver [ADR-0004](../adr/0004-existing-users-only-auth.md)). El alta de usuarios queda a cargo de la etapa de
  gestión de usuarios, roles y permisos.
- Un usuario debe tener `has_access = true` para iniciar sesión, sin importar el método usado.
- No hay "recordar sesión" ni tokens de larga duración (`remember_token`) — está fuera de alcance por decisión explícita
  del proyecto.
- Cada login exitoso (password o Google) actualiza `last_login_at` en el usuario.

## Rutas

| Método | Ruta                     | Nombre                 | Descripción               |
|--------|--------------------------|------------------------|---------------------------|
| GET    | `/login`                 | `auth.login`           | Página de login (Inertia) |
| POST   | `/login`                 | `auth.login.store`     | Login por password        |
| GET    | `/oauth/google/redirect` | `auth.google.redirect` | Redirige a Google         |
| GET    | `/oauth/google/callback` | `auth.google.callback` | Callback de Google        |
| POST   | `/logout`                | `auth.logout`          | Cierra sesión             |

## Sesión

- Driver: Redis (`SESSION_DRIVER=redis`, respaldado por `CACHE_STORE=redis`), tiempo de vida por defecto de 120 minutos
  (`SESSION_LIFETIME`).
- Al iniciar sesión se regenera el ID de sesión (`session()->regenerate()`), para evitar fijación de sesión.
- Al cerrar sesión se invalida la sesión y se regenera el token CSRF (`invalidate()` + `regenerateToken()`).
- Los intentos fallidos de login por password están limitados a 5 por minuto, usando email + IP como clave (ver
  `LoginRequest::ensureIsNotRateLimited()`). Es la única capa de rate limiting — deliberadamente no hay middleware
  `throttle` adicional en la ruta, para no duplicar el conteo.

## Google OAuth

Requiere estas variables en `.env`:

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
```

El match con el usuario es exclusivamente por email — no se guarda ningún identificador de Google en la base de datos.
