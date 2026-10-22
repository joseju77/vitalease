# ADR-0009: Bitácora de auditoría inmutable y anulación de las consultas médicas

* Estado: Aceptada; el punto 3 fue ampliado por [ADR-0010](0010-gestion-deshabilitacion-y-auditoria-de-pacientes.md)

## Contexto

Hasta la Etapa 6, una consulta médica podía editarse y eliminarse sin dejar rastro de quién cambió qué y cuándo. Para un
expediente clínico esto es un problema de trazabilidad: no se puede atribuir un cambio a una persona, ni reconstruir el
estado anterior de una consulta, ni demostrar que un registro no fue alterado. La evaluación académica previa observó
justamente la falta de trazabilidad de los cambios al expediente clínico, la ausencia de permisos específicos para
rectificar registros y la posibilidad de eliminar información médica sin autorización.

Una consulta no es una sola fila: se compone de la consulta, sus signos vitales, su exploración física, una regulación
opcional y varias líneas de tratamiento. Un solo guardado del formulario modifica varias tablas, y el historial debe
mostrarse como un único cambio, no como una fila por tabla.

## Decisión

1. **Paquete `owen-it/laravel-auditing` (`^14`).** Es la dependencia nueva autorizada de esta etapa. Se eligió sobre una
   implementación propia porque ya resuelve la captura de valores anteriores y nuevos, el usuario autor, la URL, la IP y
   las etiquetas, y es el paquete de auditoría más usado del ecosistema Laravel. Es compatible con PHP 8.2+ y Laravel
   11 a 13.
2. **Qué se audita.** `MedicalConsultation`, `VitalSigns`, `PhysicalExamination`, `MedicalRegulation` y
   `MedicalConsultationTreatment`. Cada fila de auditoría lleva la etiqueta única `consultation:{id}` (los hijos usan su
   `medical_consultation_id`), de modo que una sola consulta filtrada por etiqueta devuelve todo el historial, incluso
   para las líneas de tratamiento, que tienen su propio identificador.
3. **Qué no se audita.** El inventario ya tiene su propio libro de movimientos de solo inserción (ADR-0007), y los
   pacientes se auditarán en una etapa posterior. Las marcas de tiempo no se registran como valores cambiados.

   > **Ampliado por ADR-0010.** La etapa posterior llegó: los pacientes y sus filas hijas se auditan con la etiqueta
   > `patient:{id}` y la misma infraestructura (ver
   > [ADR-0010](0010-gestion-deshabilitacion-y-auditoria-de-pacientes.md)).
4. **Agrupación por lote.** Se agrega la columna `batch_id` (uuid, indexada) a `audits`. Un servicio singleton
   `App\Support\Auditing\AuditBatch` abre un lote por operación lógica (`within()`), y el resolvedor
   `BatchIdResolver`, registrado en `config/audit.php`, lo escribe en cada fila. Todas las auditorías de un guardado
   comparten el mismo `batch_id`; fuera de un lote la columna queda en `null`.
5. **Inmutabilidad.** `App\Models\Audit` extiende el modelo del paquete y lanza `LogicException` ante cualquier
   actualización o eliminación, igual que `InventoryMovement`. El umbral de retención es `0`: nunca se purga. No se
   agrega un disparador en la base de datos; la garantía es de la aplicación.
6. **Cero escrituras por constructor de consultas.** Los eventos de modelo son los que generan auditorías, así que
   ninguna ruta de código puede borrar ni actualizar modelos auditados con el constructor de consultas. La baja de una
   regulación y de las líneas de tratamiento se hace modelo por modelo. `ConsultationWriter` concentra la creación y
   edición de la consulta (lote, transacción y bloqueo de fila) y lo usan tanto el controlador como los sembradores.
7. **Usuario autor y contexto de consola.** El autor se resuelve con la guardia `web`. Fuera de una petición web la
   auditoría solo está activa si se habilita de forma explícita (`AUDIT_CONSOLE`, activo en las pruebas) y el sembrador
   debe fijar al usuario autor.
8. **Etiqueta de medicamento.** Las auditorías de las líneas de tratamiento incluyen el nombre, la presentación y la
   concentración del medicamento, de modo que el historial nunca dependa de un `medication_id` sin contexto.
9. **Anulación en lugar de eliminación.** Ninguna consulta ni sus registros hijos se eliminan físicamente desde la
   aplicación: se retira la ruta `DELETE /consultations/{consultation}`. Una consulta se anula con `SoftDeletes` sobre
   la columna `voided_at`, junto con `voided_by_id` y `void_reason` (máx. 500 caracteres); una restricción `CHECK` exige
   que las tres columnas tengan valor o sean nulas a la vez. Las columnas guardan solo la anulación vigente; el historial
   de todas las anulaciones y restauraciones vive en la bitácora.
   - `App\Services\MedicalConsultations\ConsultationVoiding` anula y restaura dentro de una transacción y un lote de
     auditoría. Bloquea la fila (`lockForUpdate`), vuelve a comprobar el estado y escribe las tres columnas en un solo
     guardado, de modo que de dos solicitudes simultáneas solo una surte efecto.
   - Al anular, el medicamento dispensado regresa al inventario con movimientos `DispensationReversal`, sin borrar las
     líneas de tratamiento. Al restaurar, esas líneas se dispensan de nuevo mediante `StockLedger`; si alguna no tiene
     existencia suficiente, la restauración se rechaza completa con un error por línea y no cambia nada.
   - Anular y restaurar exigen un motivo y quedan registrados como eventos de auditoría propios (`voided`, `restored`)
     que guardan el motivo, en vez de copiar todos los atributos de la consulta.
   - Las consultas anuladas quedan ocultas por defecto. Editar o anular una consulta ya anulada responde 404, y un estado
     inválido (anular dos veces, restaurar una consulta vigente) responde 422 para cualquier usuario, incluido
     `super-admin`, que omite las políticas mediante `Gate::before()`.
   - El código `MC-…` de una consulta anulada nunca se reutiliza.
10. **Permisos de consultas (modifica el punto 1 de ADR-0005).** El permiso `consultations.delete` se renombra a
    `consultations.void` y se agregan tres permisos al catálogo cerrado `App\Enums\Permission`:

    | Permiso | Permite |
    | --- | --- |
    | `consultations.void` | Anular y restaurar las consultas propias. |
    | `consultations.void-any` | Anular y restaurar las consultas de cualquier médico; incluye ver las anuladas. |
    | `consultations.view-voided` | Ver las consultas anuladas de otros médicos. |
    | `consultations.audit` | Consultar el historial de cambios de una consulta. |

    Ver una consulta anulada requiere `consultations.view` y, además, ser su autor o tener `view-voided` o `void-any`.
    Editar sigue exigiendo `consultations.update` y ser el autor, y ahora también que la consulta no esté anulada. Las
    rutas aplican las mismas reglas con el middleware `can:`. Como el proyecto sigue en desarrollo y los permisos se
    siembran desde el enum, el cambio de nombre no requiere una migración de datos.

## Consecuencias

### Positivas

- Cada cambio queda atribuido a un usuario, con valores anteriores y nuevos, agrupado por operación y no modificable.
- Una consulta filtrada por etiqueta ofrece su historial completo, que la página de detalle muestra como historial de
  cambios (ver [consultas médicas](../features/medical-consultations.md#historial-de-cambios)).
- Las auditorías escritas dentro de una transacción fallida se revierten con ella.
- Ninguna información clínica se pierde por una eliminación: una consulta anulada conserva sus datos, su historial y
  sus líneas de tratamiento, y puede restaurarse.
- Rectificar, anular y consultar el historial son permisos separados, que pueden asignarse a roles distintos (por
  ejemplo, un rol de coordinación médica que anula y audita sin registrar consultas).

### Negativas

- Cada escritura de un modelo auditado produce filas adicionales, y la tabla `audits` crece sin límite porque no se
  purga.
- Escribir modelos auditados por el constructor de consultas dejaría cambios sin rastro; es una regla de disciplina del
  código, vigilada por pruebas.
- La inmutabilidad depende de la aplicación: una persona con acceso directo a la base de datos podría modificar filas.
- Restaurar puede fallar si el medicamento devuelto al anular ya se dispensó en otra consulta.
- Si un medicamento se entregó físicamente y la consulta se anula sin volver a registrarse, la existencia del sistema
  queda por encima de la física y debe corregirse con un ajuste de inventario.

### Compromisos aceptados

- No se agregan disparadores ni firmas criptográficas en esta etapa.
- Las consultas ya existentes no tienen historial previo; solo los cambios posteriores quedan auditados.
