# Gestión de pacientes

Este documento describe la gestión de pacientes de la etapa 08: búsqueda, detalle, edición, detección de duplicados,
deshabilitación reversible, historial de cambios y permisos. El registro público de pacientes (etapa 03) sigue igual,
salvo que ahora también se audita y que sus mensajes de duplicado son genéricos.

En resumen:

- Todas las rutas de gestión están detrás de `auth` más una comprobación `can:` sobre `PatientPolicy`; el registro
  público (`/patients/register`) es la única superficie anónima.
- Un paciente **nunca se elimina**: se deshabilita con un motivo obligatorio y puede habilitarse de nuevo. No existe la
  ruta `DELETE /patients/{patient}`.
- Cada registro, edición, deshabilitación y habilitación queda en la bitácora inmutable de la etapa 07, agrupado por
  operación y atribuido a su autor (el autorregistro, a nadie).
- Un paciente deshabilitado no se edita ni recibe consultas nuevas; sus consultas existentes quedan intactas.
- Decisiones de fondo: [ADR-0010](../adr/0010-gestion-deshabilitacion-y-auditoria-de-pacientes.md).

## Rutas y páginas

`{patient}` se resuelve por `uuid` (`whereUuid`), nunca por el id numérico.

| Método | URI | Nombre | Autorización | Respuesta |
|--------|-----|--------|--------------|-----------|
| GET | `/patients` | `patients.index` | `patients.view` | Inertia `patients/Index` |
| GET | `/patients/{patient}` | `patients.show` | `patients.view` | Inertia `patients/Show` (también pacientes deshabilitados) |
| GET | `/patients/{patient}/edit` | `patients.edit` | `patients.update` y paciente activo | Inertia `patients/Edit`; **403** si está deshabilitado |
| PUT | `/patients/{patient}` | `patients.update` | `patients.update` y paciente activo | Redirect al detalle con flash |
| POST | `/patients/{patient}/disable` | `patients.disable` | `patients.disable` y paciente activo | Redirect al detalle con flash |
| POST | `/patients/{patient}/enable` | `patients.enable` | `patients.disable` y paciente deshabilitado | Redirect al detalle con flash |
| GET | `/patients/{patient}/history` | `patients.history` | `patients.audit` y `patients.view` | JSON, una página de grupos (`cursor` opcional) |

## Búsqueda y listado

| Tema | Comportamiento |
|------|----------------|
| Con texto (`q`, máx. 100) | Búsqueda con Scout (ADR-0006) sobre nombre, apellidos y número de inscripción. |
| Sin texto | Listado desde Postgres, ordenado por apellido, nombre e id. |
| Filtro de estado (`status`) | `active` (por defecto), `disabled` o `all`; se aplica sobre la columna `is_disabled` en ambos casos. |
| Paginación | 15 por página, conserva la consulta en los enlaces. |
| Campos del listado | Solo identificación: nombre completo, inscripción, número de inscripción y estado. Nunca NSS, correos ni teléfonos. |
| Indexación | Un paciente deshabilitado sigue en el índice; se reindexa después de confirmar la transacción al editar, deshabilitar y habilitar (nunca si hay rollback). |

La interfaz (`patients/Index`) ofrece el campo de búsqueda, el filtro "Activos / Deshabilitados / Todos", la insignia
"Deshabilitado" y un enlace al detalle. La entrada "Pacientes" de la barra lateral requiere `patients.view`.

## Detalle

`patients/Show` muestra todas las secciones del expediente (datos generales, contacto, contactos de emergencia,
padecimientos, otros padecimientos e historial ginecológico), las consultas del paciente que el usuario puede ver (las
anuladas siguen las reglas de [visibilidad](medical-consultations.md#visibilidad-de-las-consultas-anuladas)) y los
botones permitidos por los flags `can`: Editar, Deshabilitar, Habilitar, Nueva consulta e Historial de cambios.

Un paciente deshabilitado muestra un aviso con la fecha, el autor y el motivo, y no ofrece Editar ni Nueva consulta.

## Edición

- La página de edición apila todas las secciones del formulario de registro (no es un asistente por pasos) y envía un
  solo `PUT` con todo el agregado. Los errores del servidor se muestran en la sección correspondiente.
- Las reglas de validación son las del registro (`PatientAggregateRequest`) más las de unicidad que ignoran al propio
  paciente.
- Un solo guardado es una sola operación: transacción, bloqueo de la fila del paciente y un lote de auditoría. Si el
  paciente se deshabilitó entretanto, el servicio responde 422 con un error en `patient`, también para `super-admin`.
- Las filas hijas se concilian por **clave natural**, sin exponer identificadores:

| Sección | Cómo se identifica | Efecto de la edición |
|---------|--------------------|----------------------|
| Información de contacto | Una por paciente | Se actualiza o se crea. |
| Contactos de emergencia | Teléfono | Se agregan los nuevos, se actualizan los coincidentes y se eliminan los ausentes. Intercambiar teléfonos entre dos contactos actualiza nombre y parentesco, sin violar la unicidad. |
| Padecimientos | Tipo de padecimiento | Igual que los contactos. |
| Otros padecimientos | Una por paciente | Se actualiza, se crea o se elimina si el payload llega vacío. |
| Historial ginecológico | Una por paciente | Igual que otros padecimientos. |

- Un guardado sin cambios no escribe auditorías.
- El formulario de código postal consulta las colonias al abrir la página y conserva la colonia ya guardada.

## Duplicados

Son únicos globalmente (también entre deshabilitados): el NSS, el par inscripción y número de inscripción, y el correo
institucional. Volver a registrar a una persona deshabilitada exige habilitar antes su registro.

| Contexto | Detección | Mensaje |
|----------|-----------|---------|
| Registro público | Violación de la restricción única en la base de datos (sin reglas `unique`, para no revelar datos) | Mensaje **genérico** en el campo `registration`, igual para cualquier campo duplicado: "No fue posible completar el registro con los datos proporcionados. Si ya cuentas con un expediente, acércate a recepción para recibir apoyo." |
| Edición por el personal | Reglas `unique` que ignoran al propio paciente y, ante una carrera, la violación `23505` traducida por nombre de restricción | Mensajes **detallados** por campo: NSS, número de inscripción o correo institucional ya existen. |

Revelar cuál dato existe en el formulario anónimo permitiría enumerar pacientes; por eso solo el personal autenticado
recibe el detalle.

## Deshabilitar y habilitar

- Ambas acciones exigen `reason` (obligatorio, máx. 500 caracteres) y `patients.disable`.
- `PatientDisabling` abre un lote de auditoría y una transacción, bloquea la fila, vuelve a comprobar el estado y
  guarda. Deshabilitar un paciente ya deshabilitado, o habilitar uno activo, responde **422 para todos**, incluido
  `super-admin`, porque `Gate::before()` omite las políticas.
- Las columnas `disabled_at`, `disabled_by_id` y `disable_reason` guardan solo la deshabilitación vigente; al habilitar
  se limpian. El historial conserva cada ciclo con su motivo.
- Las filas hijas y las consultas no se tocan.
- La interfaz usa diálogos que piden el motivo y muestran el error del servidor.

Detalle del esquema en [modelo de datos de pacientes](patient-data-model.md).

## Historial de cambios

Alcance de la auditoría:

| Se audita | No se audita |
|-----------|--------------|
| `Patient` y sus cinco modelos hijos, con la etiqueta `patient:{id}`. | `uuid`, columnas de deshabilitación, `is_disabled` y marcas de tiempo (no cuentan como cambios). |
| Registro, edición, deshabilitación y habilitación, un lote por operación. | Los pacientes del sembrador base de demostración. |
| El autorregistro, con usuario nulo ("Autorregistro"), IP y agente de usuario. | |

- Los identificadores de catálogos (inscripción, unidad médica familiar, colonia) se muestran con su etiqueta legible,
  tanto el valor anterior como el nuevo, aunque el catálogo cambie después.
- Los valores de enums y booleanos se muestran en español; las fechas, como fecha simple.
- Deshabilitar y habilitar son eventos propios con el motivo, no una copia de atributos.
- Cambiar un teléfono de contacto de emergencia se lee como un contacto eliminado y uno agregado.
- Un registro nuevo guarda sus campos opcionales vacíos como nulos; esos cambios "vacío a vacío" (nulo a nulo) se
  ocultan en el historial de pacientes.
- La bitácora guarda NSS, correos y teléfonos en claro; la protección es de acceso (`patients.audit`).

`GET /patients/{patient}/history` devuelve `data` (grupos del más reciente al más antiguo) y `next_cursor` (`null` al
final). Comparte plantilla (`AuditHistory`), paginación por cursor y diálogo (`ChangeHistoryDialog`) con el historial de
consultas (ver [historial de consultas](medical-consultations.md#historial-de-cambios)). El botón "Historial de
cambios" solo aparece con `can.audit`.

## Permisos

| Permiso | Permite |
|---------|---------|
| `patients.view` | Buscar pacientes, ver su detalle y los datos sensibles del perfil. |
| `patients.update` | Editar pacientes activos. |
| `patients.disable` | Deshabilitar y volver a habilitar pacientes. |
| `patients.audit` | Leer el historial de cambios (junto con `patients.view` para llegar al detalle). |

`patients.create` sigue definido y sin uso: el registro es público. Reglas de `PatientPolicy`:

| Habilidad | Regla |
|-----------|-------|
| `viewAny`, `view` | `patients.view`. |
| `update` | `patients.update` y paciente activo. |
| `disable` | `patients.disable` y paciente activo. |
| `enable` | `patients.disable` y paciente deshabilitado. |
| `audit` | `patients.audit` y `patients.view`. |

`super-admin` omite las políticas con `Gate::before()` (ADR-0005). Por eso los servicios de escritura repiten la
comprobación de estado bajo bloqueo (422) y las páginas de edición y de nueva consulta de un paciente deshabilitado
responden 403 para cualquiera. `PatientAbilities::for` entrega a la interfaz los flags `update`, `disable`, `enable`,
`audit` y `createConsultation`, con el estado ya aplicado; el servidor sigue siendo el límite real.

## Exposición de datos sensibles

El NSS, los correos y todos los teléfonos (incluidos los de los contactos de emergencia) solo se envían a quien tiene
`patients.view`.

| Superficie | Regla |
|------------|-------|
| Listado de pacientes | Nunca incluye datos sensibles. |
| Detalle del paciente | Exige `patients.view`, así que los incluye. |
| Perfil en páginas de consulta y resumen del dashboard | Los campos sensibles llegan como `null` si el usuario no tiene `patients.view`; la interfaz los omite. |
| Historial de cambios | Solo con `patients.audit`; guarda los valores en claro. |

## Consultas de pacientes deshabilitados

No se pueden crear consultas nuevas (403 en la página de creación y 422 al guardar) y las existentes siguen intactas.
Ver [pacientes deshabilitados](medical-consultations.md#pacientes-deshabilitados).

## Datos de demostración

`make seed-demo` (`php artisan demo:seed`) siembra los pacientes demo sin auditoría y después, mediante los servicios
reales y con usuario autor, `DemoPatientHistorySeeder` da historial a los seis primeros pacientes:

1. Cambio de contacto por el médico demo.
2. Contacto de emergencia agregado por el médico demo.
3. Contacto de emergencia quitado por Coordinación médica.
4. Padecimiento agregado por el médico demo.
5. Paciente deshabilitado por Coordinación médica, con motivo.
6. Paciente deshabilitado y luego habilitado por Coordinación médica.

Los demás pacientes demo conservan un historial vacío. Los roles demo `Médico` (ver y editar) y `Coordinación médica`
(ver, editar, deshabilitar y auditar) están descritos en [usuarios, roles y permisos](users-roles-permissions.md). El
índice de búsqueda se actualiza con el `scout:import` que ejecuta `demo:seed`.

## Fuera de alcance

- Eliminar pacientes: no existe ni siquiera para `super-admin`.
- Editar pacientes deshabilitados: hay que habilitarlos antes.
- Fusionar pacientes duplicados y reutilizar NSS, inscripción o correo de un paciente deshabilitado.
- Enmascarar valores sensibles en la bitácora.
- Reportes y exportaciones de pacientes.
