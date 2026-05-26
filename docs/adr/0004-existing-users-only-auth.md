# ADR-0004: Login solo para usuarios existentes (password y Google)

* Estado: Aceptada

## Contexto

La aplicación necesita autenticación por password y por Google (OAuth) para que el personal médico acceda a la
plataforma. La gestión de usuarios, roles y permisos (alta de cuentas) es responsabilidad de una etapa posterior del
proyecto, todavía no implementada.

El login con Google se ofrece únicamente como una facilidad de acceso: la mayoría (si no la totalidad) de las cuentas de
los usuarios son cuentas institucionales de Google pertenecientes a la misma universidad, por lo que permite iniciar
sesión sin necesidad de gestionar otra contraseña. No responde a un requisito de aprovisionamiento ni de identidad
externa más allá de esa conveniencia.

Sin una decisión explícita, un flujo de login con OAuth suele crear una cuenta nueva automáticamente cuando el correo no
existe (comportamiento por defecto de muchos paquetes de "social login"). Eso adelantaría trabajo de gestión de usuarios
que aún no está diseñado (roles, permisos, validaciones de negocio) y podría dar acceso a la aplicación a personas sin
una cuenta aprobada.

## Decisión

Tanto el login por password como el login por Google autentican exclusivamente usuarios ya existentes en la base de
datos. Ninguno de los dos flujos crea una cuenta nueva:

- Password: se valida contra un usuario ya existente; credenciales que no correspondan a un usuario existente fallan
  como cualquier intento de login inválido.
- Google: se busca al usuario existente por email; si el email no corresponde a ningún usuario, el login se rechaza en
  lugar de crear la cuenta.
- En ambos casos, además de existir, el usuario debe tener `has_access = true`; un usuario existente sin acceso también
  es rechazado.

La creación de cuentas queda fuera de esta etapa y se abordará en la etapa de gestión de usuarios, roles y permisos.

## Alternativas consideradas

- Aprovisionamiento automático de cuentas al hacer login con Google (crear el usuario si el email no existe).

## Consecuencias

### Positivas

- Ninguna cuenta se crea fuera del proceso de gestión de usuarios, evitando accesos no controlados a través del flujo de
  login.
- Reduce la fricción de acceso para el personal, que ya usa cuentas institucionales de Google, sin exigir una contraseña
  adicional.
- El alcance de esta etapa queda acotado a autenticación, sin absorber decisiones de diseño de la etapa de
  usuarios/roles/permisos.

### Negativas

- Un usuario con cuenta de Google válida, pero sin registro previo en la aplicación no puede entrar y debe esperar a que
  se le dé de alta en la etapa correspondiente.

### Compromisos aceptados

- Se prioriza el control de acceso y la separación de responsabilidades entre etapas sobre la comodidad de un
  aprovisionamiento automático de cuentas.
