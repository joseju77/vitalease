# ADR-0009: Bitácora de auditoría inmutable para las consultas médicas

* Estado: Aceptada

## Contexto

Hasta la Etapa 6, una consulta médica podía editarse y eliminarse sin dejar rastro de quién cambió qué y cuándo. Para un
expediente clínico esto es un problema de trazabilidad: no se puede atribuir un cambio a una persona, ni reconstruir el
estado anterior de una consulta, ni demostrar que un registro no fue alterado. La evaluación académica previa observó
justamente la falta de trazabilidad de los cambios al expediente clínico.

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

## Consecuencias

### Positivas

- Cada cambio queda atribuido a un usuario, con valores anteriores y nuevos, agrupado por operación y no modificable.
- Una consulta filtrada por etiqueta ofrece su historial completo para una futura vista de cambios.
- Las auditorías escritas dentro de una transacción fallida se revierten con ella.

### Negativas

- Cada escritura de un modelo auditado produce filas adicionales, y la tabla `audits` crece sin límite porque no se
  purga.
- Escribir modelos auditados por el constructor de consultas dejaría cambios sin rastro; es una regla de disciplina del
  código, vigilada por pruebas.
- La inmutabilidad depende de la aplicación: una persona con acceso directo a la base de datos podría modificar filas.

### Compromisos aceptados

- No se agregan disparadores ni firmas criptográficas en esta etapa.
- Las consultas ya existentes no tienen historial previo; solo los cambios posteriores quedan auditados.
