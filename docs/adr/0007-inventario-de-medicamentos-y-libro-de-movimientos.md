# ADR-0007: Inventario de medicamentos con libro de movimientos de solo inserción

* Estado: Aceptada

## Contexto

Hasta la Etapa 5, el tratamiento de una consulta médica se guarda como un arreglo `jsonb` de textos libres
(`medication`, `dose`, `frequency`, `duration`) en `medical_consultations.treatment`. Ese formato no permite saber
cuánto de cada medicamento se entrega, ni cuánto queda en existencia, ni cómo ha evolucionado el consumo en el tiempo.
Esta etapa agrega un inventario de medicamentos cuyo stock se descuenta con el tratamiento de cada consulta, de modo
que la plataforma acumule un historial real de consumo que sirva como entrada para proyectar la demanda.

Un inventario así tiene dos riesgos principales: que el stock guardado deje de coincidir con los movimientos que lo
explican, y que dos consultas simultáneas entreguen el mismo medicamento y dejen la existencia en negativo.

## Decisión

1. **Catálogo de medicamentos.** Tabla `medications` con nombre genérico (`citext_255`), presentación,
   concentración, unidad de dispensación, `current_stock`, `minimum_stock` y bandera de activo. La combinación
   nombre + presentación + concentración es única. `current_stock` no es asignable desde formularios: solo el
   servicio del libro de movimientos lo modifica.
2. **Libro de movimientos de solo inserción.** Todo cambio de stock escribe una fila en `inventory_movements`, en la
   misma transacción que el cambio, con la cantidad con signo y el stock resultante (`stock_after`). Los movimientos
   nunca se editan ni se borran: el modelo `InventoryMovement` lanza una excepción ante cualquier intento, y las
   correcciones se registran como movimientos nuevos. `App\Services\Inventory\StockLedger` es el único código
   autorizado para cambiar `current_stock`.
3. **Cuatro tipos de movimiento con signo fijo.** `Entry` (entrada, positiva), `Dispensation` (dispensación en
   consulta, negativa), `Adjustment` (ajuste manual, cualquier signo distinto de cero) y `DispensationReversal`
   (reversión de dispensación, positiva). La reversión se usa cuando una consulta se edita para entregar menos o se
   elimina; así el consumo mensual se calcula como dispensaciones menos reversiones, sin mezclarlo con los ajustes.
   Los signos se garantizan con restricciones `CHECK` en Postgres.
4. **El stock nunca es negativo.** `current_stock >= 0` y `stock_after >= 0` son restricciones de la base de datos, y
   el servicio rechaza cualquier movimiento que dejaría la existencia por debajo de cero. Las filas de medicamentos
   afectadas se bloquean con `SELECT ... FOR UPDATE` en orden ascendente de `id`, para que dos operaciones
   concurrentes sobre los mismos medicamentos no puedan provocar un interbloqueo ni sobregirar el stock.
5. **Copia del código de consulta.** Los movimientos de dispensación y reversión guardan la referencia a la consulta
   (`medical_consultation_id`) y una copia de su código (`medical_consultation_code`). Al eliminar una consulta, la
   referencia pasa a `NULL` (`ON DELETE SET NULL`) pero la copia del código se conserva, de modo que el historial
   sigue siendo trazable. La regla "tiene consulta si y solo si es dispensación o reversión" se valida sobre esa
   copia.
6. **Fecha de consumo.** Las dispensaciones y reversiones usan como `occurred_at` la fecha de creación de la consulta,
   para atribuir el consumo al acto clínico; las entradas y ajustes usan el momento en que se registran.
7. **Permisos nuevos.** Se agregan `inventory.view`, `inventory.create`, `inventory.update` e `inventory.delete` al
   catálogo cerrado de `App\Enums\Permission`, siguiendo la taxonomía `recurso.acción` de ADR-0005. `view` permite
   consultar el inventario; `create`, dar de alta medicamentos y registrar entradas; `update`, editarlos,
   activarlos o desactivarlos y registrar ajustes; y `delete`, eliminar un medicamento solo mientras no tenga
   movimientos ni líneas de tratamiento. Un ajuste siempre exige una nota que explique el motivo. El único rol
   sembrado sigue siendo `super-admin`, con acceso total mediante `Gate::before()`.

## Alternativas consideradas

- Guardar solo `current_stock` y recalcularlo a mano, sin libro de movimientos: más simple, pero sin historial de
  consumo ni forma de auditar diferencias.
- Tres tipos de movimiento, registrando las devoluciones como dispensaciones positivas o como ajustes: rompe el
  signo fijo por tipo, y usar ajustes mezclaría el consumo real con correcciones manuales y exigiría una nota en cada
  edición de consulta.
- Referencia obligatoria a la consulta con borrado en cascada de sus movimientos: contradice el libro de solo
  inserción y haría desaparecer consumo ya ocurrido.
- Proteger la inmutabilidad con un trigger de Postgres: no hay precedente en el proyecto y bloquearía el
  `ON DELETE SET NULL` de la referencia a la consulta.

## Consecuencias

### Positivas

- El stock de cada medicamento siempre se puede reconstruir y auditar a partir de sus movimientos.
- Las reglas críticas (stock no negativo, signo por tipo, nota en ajustes, consulta en dispensaciones) quedan
  garantizadas por la base de datos, no solo por la aplicación.
- El historial de dispensaciones es la fuente directa para la proyección de demanda.

### Negativas

- Cada operación que toca stock debe correr dentro de una transacción y pasar por `StockLedger`; escribir en
  `medications.current_stock` por otro camino rompería la consistencia del libro.
- Los bloqueos por fila serializan las consultas que entregan los mismos medicamentos al mismo tiempo.
- Al eliminar una consulta, sus movimientos quedan sin referencia navegable, solo con la copia del código.

### Compromisos aceptados

- No se modelan lotes, caducidades, proveedores ni órdenes de compra en esta etapa; quedan como extensión futura.
- Se agrega un cuarto tipo de movimiento respecto al plan inicial de la etapa, a cambio de un cálculo de consumo
  limpio y restricciones de signo simples.
