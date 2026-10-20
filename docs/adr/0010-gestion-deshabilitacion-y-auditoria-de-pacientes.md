# ADR-0010: Gestión, deshabilitación y auditoría de pacientes

* Estado: Aceptada

## Contexto

Hasta la Etapa 7 un paciente solo podía registrarse: no existía forma de buscarlo en una lista, ver su expediente, corregir
sus datos ni retirarlo, y ningún cambio a sus datos personales quedaba atribuido a una persona. La evaluación académica
previa observó la falta de control sobre las modificaciones a los datos personales y a la historia clínica, el manejo de
registros duplicados y la falta de controles de auditoría sobre la información clínica. Esta etapa agrega búsqueda,
detalle, edición, deshabilitación reversible e historial de cambios de pacientes, y extiende la bitácora de la Etapa 7
([ADR-0009](0009-bitacora-de-auditoria-de-consultas-medicas.md)) a los pacientes.

Un paciente tampoco es una sola fila: se compone del paciente, su información de contacto, sus contactos de emergencia,
sus padecimientos, otros padecimientos y, en su caso, sus antecedentes gineco-obstétricos. El autorregistro público es
anónimo, de modo que también debe auditarse sin usuario autor.

## Decisión

1. **Deshabilitar en lugar de eliminar, sin `SoftDeletes`.** Ningún paciente ni fila hija se elimina físicamente desde la
   aplicación y no existe la ruta `DELETE /patients/{patient}`. `SoftDeletes` agrega un alcance global que haría que
   `$consulta->paciente` devolviera `null` para los pacientes deshabilitados y rompería las consultas existentes.
   En su lugar, la tabla `patients` tiene las columnas `disabled_at`, `disabled_by_id` (clave foránea a `users` con
   `restrictOnDelete`) y `disable_reason` (máx. 500 caracteres), con la restricción `CHECK`
   `chk_disable_state_all_or_none` que exige que las tres tengan valor o sean nulas a la vez, y un índice sobre
   `disabled_at`. Las columnas guardan solo la deshabilitación vigente: se limpian al habilitar y se sobrescriben al
   volver a deshabilitar; la bitácora conserva cada ciclo. Un paciente deshabilitado no puede recibir consultas nuevas,
   pero sus consultas existentes siguen visibles y sin cambios.
2. **Columna generada `is_disabled`.** `is_disabled` es una columna booleana almacenada (`storedAs('disabled_at IS NOT
   NULL')`). Permite filtrar por estado con la misma columna tanto en el motor `collection` de Scout (pruebas) como en
   Meilisearch (atributo indexado y filtrable), sin depender de consultas adicionales. El documento indexado calcula el
   valor desde `disabled_at`, porque el atributo generado queda obsoleto en memoria justo después de guardar.
3. **Unicidades globales.** `social_security_number`, el par (`enrollment_id`, `enrollment_number`) y
   `institutional_email` siguen siendo únicos para todos los pacientes, deshabilitados incluidos. Volver a registrar a la
   misma persona exige habilitar primero su registro. Se descartaron los índices únicos parciales (solo pacientes
   activos) porque permitirían dos registros vivos de una misma persona y complicarían las reglas de edición.
4. **Edición con `ignore` y mapeo de la violación `23505`.** Al editar, las reglas `unique` ignoran la fila del propio
   paciente (el correo se ignora por `patient_id`). Una carrera entre la validación y la escritura se captura como
   `UniqueConstraintViolationException` y se traduce, por nombre de restricción, al mismo error de campo. No se usa un
   `catch` genérico de `Throwable` en la edición.
5. **Claves naturales para las filas hijas múltiples.** Los padecimientos se identifican por `ailment_type` y los
   contactos de emergencia por `phone_number`, sin exponer identificadores ni cambiar el esquema. Una edición agrega las
   filas nuevas, elimina (por modelo) las ausentes y actualiza en su lugar las coincidentes. Intercambiar los teléfonos
   entre dos contactos se expresa como actualización de nombre y parentesco de las dos filas, de modo que nunca viola la
   unicidad (`patient_id`, `phone_number`); no se necesitan restricciones diferibles. Cambiar un teléfono aparece en el
   historial como un contacto eliminado y uno agregado.
6. **Mensaje público genérico, mensajes detallados para el personal.** El registro público es anónimo, por lo que
   revelar qué NSS, matrícula o correo ya existe permitiría enumerar pacientes. Cualquier duplicado (por regla o por
   carrera) responde con el mismo mensaje genérico. La edición por el personal autenticado devuelve errores detallados
   por campo.
7. **Un paciente deshabilitado no se edita.** Es coherente con las consultas anuladas. Se rechaza en la política y,
   como `super-admin` omite las políticas mediante `Gate::before()` (ADR-0005), también en el servicio de escritura
   bajo bloqueo de fila, con una respuesta 422. La página de edición y la de nueva consulta de un paciente deshabilitado
   responden 403 para cualquier usuario, incluido `super-admin`. Deshabilitar un paciente deshabilitado y habilitar uno
   activo se rechazan con 422 para todos los actores.
8. **Qué se audita y cómo.** `Patient` y sus cinco modelos hijos usan `owen-it/laravel-auditing`, con la etiqueta única
   `patient:{id}` y un lote por operación (registro, edición, deshabilitación, habilitación). Los identificadores de
   catálogos (inscripción, unidad médica familiar, colonia) no se excluyen, para resolver etiquetas legibles del valor
   anterior y del nuevo en el momento de la lectura, y las marcas de tiempo y las columnas de estado no se registran como
   cambios. Deshabilitar y habilitar escriben un solo evento propio (`disabled`, `enabled`) con el motivo, sin copiar los
   atributos. El autorregistro se audita con usuario nulo y se presenta como "Autorregistro", conservando IP y agente de
   usuario como en la Etapa 7.
9. **Valores en claro en la bitácora.** El NSS, los correos y los teléfonos se guardan sin enmascarar, porque la
   trazabilidad es el objetivo de la bitácora. La protección es de acceso: el historial solo lo ve quien tiene
   `patients.audit`, y los datos sensibles del perfil (NSS, correos y teléfonos) solo se envían a quien tiene
   `patients.view`, también en las páginas de consultas y en el resumen del tablero.
10. **Historial generalizado.** El servicio de historial de la Etapa 7 se vuelve una plantilla reutilizable con ganchos
    por sujeto, de modo que consultas y pacientes comparten agrupación, paginación por cursor y el mismo diálogo de la
    interfaz.
11. **Permisos de pacientes (modifica el punto 1 de ADR-0005).** `patients.delete` se renombra a `patients.disable` y
    se agrega `patients.audit` al catálogo cerrado `App\Enums\Permission`; `patients.create` sigue definido y sin uso,
    porque el registro es público. `patients.update` pasa a aplicarse.

    | Permiso | Permite |
    | --- | --- |
    | `patients.view` | Buscar pacientes y ver su detalle. |
    | `patients.update` | Editar pacientes activos. |
    | `patients.disable` | Deshabilitar y volver a habilitar pacientes. |
    | `patients.audit` | Consultar el historial de cambios de un paciente. |

    Como el proyecto sigue en desarrollo y los permisos se siembran desde el enum, el cambio de nombre no requiere una
    migración de datos; se editó directamente la migración de `patients`.
12. **Búsqueda.** Con texto, la búsqueda usa Scout (ADR-0006); sin texto lista desde la base de datos. El filtro de estado
    (`active`, `disabled`, `all`, por defecto `active`) se aplica sobre `is_disabled`. Un paciente deshabilitado
    permanece en el índice y se reindexa tras deshabilitar, habilitar y editar.

## Alternativas consideradas

- `SoftDeletes` sobre `patients`.
- Índices únicos parciales o restricciones diferibles para permitir reutilizar NSS, matrícula o correo.
- Identificadores o uuid expuestos para las filas hijas múltiples.
- Mensajes de duplicado detallados en el formulario público.
- Permitir editar pacientes deshabilitados.
- Enmascarar el NSS, los correos y los teléfonos en la bitácora.

## Consecuencias

### Positivas

- Ningún expediente se pierde por una eliminación, y cada cambio, incluido el autorregistro, queda atribuido, agrupado
  por operación y no modificable.
- Los duplicados producen errores controlados; el formulario público no permite enumerar pacientes.
- El filtro de estado se comporta igual con Meilisearch y con el motor de pruebas.

### Negativas

- La tabla `audits` crece más, porque cada edición escribe filas por cada modelo cambiado.
- Un paciente deshabilitado sigue ocupando su NSS, matrícula y correo; para corregirlo hay que habilitarlo.
- Los valores sensibles quedan en claro en `audits`; una persona con acceso directo a la base de datos podría leerlos.
- La identificación por teléfono hace que corregir un teléfono aparezca como baja y alta en el historial.

### Compromisos aceptados

- No se agregan disparadores ni columnas por estado en las filas hijas; solo se retiran filas hijas mediante la
  eliminación de modelo solicitada en una edición, y queda su valor anterior en la bitácora.
- Las unicidades globales se prefieren sobre la flexibilidad de índices parciales.
