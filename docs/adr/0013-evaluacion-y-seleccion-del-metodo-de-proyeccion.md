# ADR-0013: Evaluación y selección del método de proyección de demanda

* Estado: Aceptada; amplía la proyección descrita en [ADR-0008](0008-grafica-de-proyeccion-con-shadcn-vue-chart-y-unovis.md) (sin cambiar su contrato público)

## Contexto

Hasta la Etapa 9 la proyección de demanda de un medicamento era siempre una regresión lineal simple (OLS) sobre su
consumo mensual histórico. La aplicación no comparaba esa técnica con ninguna alternativa ni medía su error, así que
no había evidencia de que la tendencia lineal fuera mejor que una referencia trivial como el promedio histórico.

La revisión académica pidió justificar la técnica elegida y compararla al menos con el promedio histórico. Esta
decisión responde a esa petición con tres piezas: una justificación formal de la regresión lineal, una evaluación
reproducible contra métodos de referencia (baselines) y una regla fija con la que la propia aplicación decide, por
medicamento, si usa la tendencia lineal o el promedio histórico.

Restricciones que condicionan la decisión:

- **No existe un histórico real y público del consumo de la Unidad** con el que validar la proyección. La evaluación
  usa dos fuentes que no son el consumo de la Unidad: recetas del NHS de Inglaterra ("consumo real público de otro
  contexto") y series sintéticas.
- La etapa no agrega dependencias PHP ni JS, y el contrato público de `MedicationDemandProjection` (`forMedication()`
  y `fromSeries()`) no cambia: solo crece el resultado con campos nuevos.
- La proyección sigue siendo **indicativa**: apoyo a la decisión del encargado del inventario, no una orden de compra.

## Decisión

### 1. La técnica principal sigue siendo la regresión lineal

Se conserva la regresión lineal simple como técnica principal por estas razones:

- **Interpretable.** Dos parámetros (pendiente e intercepto) y un R² que cualquier persona puede leer: "el consumo
  sube o baja tantas unidades por mes".
- **Barata y determinista.** Cálculo cerrado sobre decenas de puntos, sin entrenamiento, sin estado y sin dependencias.
- **Explicable al encargado del inventario.** Se puede mostrar la recta sobre la gráfica y decir por qué la
  proyección crece o decrece.
- **Suficiente para capturar tendencia**, que es lo que el módulo necesita. No pretende modelar estacionalidad,
  campañas ni proveedores.

La evaluación (ver [forecast.md](../evaluation/forecast.md)) no mostró que la regresión lineal sea la mejor opción
posible; mostró **dónde sí ayuda y dónde no**. Por eso la aplicación ya no la aplica a ciegas: la elige solo cuando
una prueba con los propios meses del medicamento la respalda.

### 2. Métodos de referencia

| Método | Papel | Dónde se usa |
| --- | --- | --- |
| Regresión lineal (OLS) | Técnica principal | Aplicación y evaluación |
| Promedio histórico | Referencia exigida por la revisión; la comparación mínima razonable | Aplicación y evaluación |
| Ingenuo (último valor) | Referencia estándar de pronóstico | Solo evaluación |
| Media móvil de 3 meses (MA3) | Referencia que reacciona a cambios de nivel | Solo evaluación |
| Ingenuo estacional (rezago 12) | Referencia para series con patrón anual; requiere al menos 24 meses | Solo evaluación |

La aplicación solo puede elegir entre **regresión lineal y promedio histórico**. MA3 y el ingenuo estacional se
miden en la evaluación para dar contexto, pero no son seleccionables en la aplicación.

### 3. Métricas

- **MAE** y **RMSE** por serie, método y horizonte, en las unidades de la serie.
- **MASE** (escala: error medio del pronóstico ingenuo a un paso sobre toda la serie) y **RelMAE** (MAE del método
  dividido entre el MAE del promedio histórico, en los mismos orígenes) para comparar entre series de escalas muy
  distintas. Se resumen con media geométrica y mediana; las series con MAE igual a cero se excluyen del cociente y se
  cuentan aparte.
- **No se usa MAPE.** La demanda mensual contiene ceros (meses sin consumo) y el error porcentual queda indefinido o
  explota en esos meses.

### 4. Procedimiento de evaluación (origen móvil)

- **Origen móvil con ventana expansiva.** En cada origen se ajusta el método con todos los meses anteriores y se
  predice un mes futuro.
- **Entrenamiento mínimo de 6 meses**, horizontes de **1 y 3 meses** (predicción directa desde el mismo origen).
- **Valores proyectados recortados en 0**, igual que la aplicación.
- **Sin fuga de información.** El pronóstico de un mes usa solo observaciones anteriores a su origen; todos los
  métodos se comparan en los mismos orígenes. Las pruebas automatizadas verifican que cambiar el último valor de una
  serie no altera los pronósticos de meses anteriores.
- La clasificación de patrón (Syntetos-Boylan: regular, errática, intermitente, irregular, con cortes ADI 1.32 y CV²
  0.49) y una bandera de estacionalidad (autocorrelación de rezago 12, solo con al menos 24 meses) permiten desglosar
  los resultados por tipo de demanda.

### 5. Regla de selección en la aplicación

Para cada medicamento, `forMedication()` ejecuta el mismo backtest sobre la serie que ya consultó (sin consultas
nuevas) y aplica esta regla, con el mismo código que usa la evaluación:

| Condición | Método | Motivo |
| --- | --- | --- |
| Menos de 2 meses | Sin proyección (`insufficient_data`) | No hay serie que ajustar |
| De 2 a 8 meses | Promedio histórico | `short_history` |
| 9 meses o más, al menos 3 orígenes evaluados y MAE lineal ≤ 0.95 × MAE del promedio (horizonte 1) | Regresión lineal | `linear_beats_mean` |
| Cualquier otro caso, incluidos empates y MAE del promedio igual a 0 | Promedio histórico | `mean_beats_linear` |

El **margen de 5 %** se fijó **a priori**, para no cambiar a la tendencia lineal por ruido cuando su ventaja es
mínima. **No se calibró con los datos de la evaluación**: ajustarlo con ellos sería ajustar y medir sobre los mismos
datos. La evaluación solo reporta la sensibilidad con márgenes de 0 % y 10 %, sin modificar la regla.

### 6. Dónde la regresión lineal no es adecuada (evidencia medida)

Las cifras salen de `docs/evaluation/results/05-forecast-nhs/summary.md` y
`docs/evaluation/results/06-forecast-synthetic/summary.md`. "RelMAE" menor que 1 significa mejor que el promedio
histórico; "Peor" cuenta series en las que el método tuvo más error que el promedio.

- **Estacionalidad.** En las 431 series estacionales del NHS la regresión lineal quedó peor que el promedio en más
  series de las que mejoró: a un mes, RelMAE mediana 1.0327 y proporción de series mejores 0.4176; a tres meses,
  1.0790 y 0.3712. En la submuestra de al menos 24 meses, el ingenuo estacional fue el mejor en esa clase (RelMAE
  media geométrica 0.7975 a un mes, contra 0.8951 de la regresión lineal). En las series sintéticas estacionales la
  regresión lineal también perdió contra el promedio en 31 de 34 series a un mes (mediana 1.0993).
- **Demanda errática, intermitente e irregular en series sintéticas.** La regresión lineal no superó al promedio
  histórico: RelMAE media geométrica a un mes de 1.0848 (errática, 28 de 30 series peores), 1.0051 (intermitente) y
  1.0335 (irregular).
- **Horizonte de 3 meses en datos reales.** Con las 16699 series del NHS, la regresión lineal mejoró al promedio en
  8070 series y empeoró en 8599; su RelMAE mediana fue 1.0090 y la proporción de series mejores, 0.4833. Su ventaja
  sobre el promedio a un mes (RelMAE media geométrica 0.8768) se reduce a tres meses (0.9112).
- **Clases intermitente e irregular en el NHS.** La ventaja de la regresión lineal es poca en términos de mediana: a
  un mes 0.9914 (intermitente) y 0.9674 (irregular); a tres meses 1.0181 y 1.0016.

Esta evidencia es la que sustenta que la aplicación advierta (de forma informativa) cuando el patrón es errático,
intermitente o irregular, o cuando hay menos de 9 meses de historial.

### 7. Hallazgo medido: la media móvil de 3 meses superó a ambos

En la evaluación con datos del NHS, **MA3 tuvo menos error que la regresión lineal y que el promedio histórico**:
con las 16699 series y orígenes comunes, RelMAE media geométrica a un mes de 0.7806 (MA3) contra 0.8768 (regresión
lineal), y a tres meses de 0.8148 contra 0.9112. También superó al ingenuo (0.8848 y 0.8849).

En esta etapa **la aplicación mantiene la elección entre regresión lineal y promedio histórico**, por dos razones:
es la comparación que pidió la revisión, y adoptar MA3 después de ver los resultados sería elegir el método con los
mismos datos con los que se midió. Cambiar la oferta de métodos exigiría fijar de antemano un nuevo protocolo y
evaluarlo sobre datos nuevos. Queda como **trabajo futuro**. El resultado no se presenta como una conclusión sobre el
consumo de la Unidad.

### 8. Datos de la evaluación y sus límites

- **NHS English Prescribing Dataset (EPD) con código SNOMED** (NHS Business Services Authority, Open Government Licence
  v3.0; "Contains NHSBSA data, OGL 3.0"). Son recetas de atención primaria de Inglaterra: **consumo real público de
  otro contexto**, no consumo de la Unidad.
- **Unidad de demanda: ITEMS** (renglones de receta), **no unidades dispensadas**. Agregar por sustancia química mezcla
  formas farmacéuticas (tabletas, mililitros, gramos), de modo que `TOTAL_QUANTITY` no es aditivo; ITEMS sí lo es y
  se parece más a un evento de dispensación.
- **Muestra de 20 consultorios** elegidos con semilla 20261024 entre los que tuvieron al menos 1000 ítems tanto en el
  primer como en el último mes de la ventana. Esa regla **sesga la muestra hacia consultorios grandes y estables**; no
  representa a todos los consultorios ni a un servicio de salud universitario.
- **Ventana de noviembre de 2020 a agosto de 2026** (70 meses), que incluye el periodo de la pandemia.
- **Las series de un mismo consultorio no son independientes** entre sí; los conteos de series (16699) no equivalen a
  16699 observaciones independientes. Los resultados describen esta muestra.
- **Reglas de datos.** Un mes ausente de un consultorio que sí reportó otras recetas ese mes cuenta como cero; si el
  consultorio no reportó nada, el mes es faltante y no se rellena con cero. Se conserva el tramo contiguo más largo y
  solo si dura al menos 18 meses. Se excluyen las series sin demanda o con un solo mes con demanda (1303 series con un
  solo mes con demanda en esta corrida).
- **Cambio de esquema del NHS detectado y corregido.** A partir del archivo de marzo de 2025 el NHS renombró columnas:
  el código de la sustancia pasó a `BNF_CHEMICAL_SUBSTANCE_CODE` y `BNF_CHEMICAL_SUBSTANCE` pasó a contener el nombre.
  La primera extracción agrupaba por esa columna y partía cada serie en marzo de 2025, lo que clasificaba casi todas
  las series como intermitentes; esos primeros resultados eran inválidos y no se publicaron. El problema se detectó
  con un recuento independiente, se corrigió en el commit `14908db` (la columna se resuelve por mes consultando los
  campos del recurso) y los resultados se regeneraron. Ahora la extracción valida que cada código tenga el formato
  esperado (nueve caracteres o los cuatro de los códigos de dispositivos) y falla con un error claro si no, y el
  manifiesto registra la columna usada en cada mes.
- **Series sintéticas.** 8 patrones (constante, tendencia pura, tendencia con ruido, estacional, errática,
  intermitente, irregular, cambio de nivel) por 30 réplicas, con semilla fija y sub-semilla por serie. Sirven para
  comprobar el comportamiento en situaciones conocidas; **no son datos reales** y el resultado depende de cómo se
  diseñaron.

### 9. Contribución al módulo

El módulo pasa de una tendencia lineal aplicada siempre a una **elección automática y explicada**: la pantalla muestra
el método elegido, el motivo, el error de prueba contra el promedio y el patrón de demanda. La proyección y la
cantidad de reorden se calculan con el método elegido. Sigue siendo una **proyección indicativa de apoyo a la
decisión**: **no está validada con el consumo de la Unidad**, y la advertencia de patrón es informativa (no oculta ni
cambia la sugerencia de reorden).

## Alternativas consideradas

- **Dejar solo la regresión lineal.** Rechazada: la evaluación la muestra peor que el promedio en estacionalidad,
  horizonte de 3 meses y demanda no regular en series sintéticas.
- **Promedio histórico como único método.** Rechazada: pierde la tendencia, que a un mes mejoró al promedio en 9281 de
  las 16699 series del NHS (media geométrica de RelMAE 0.8768).
- **Elegir MA3 u otro método más complejo (Holt-Winters, ARIMA, modelos de aprendizaje automático).** Fuera del
  alcance de esta etapa: MA3 queda como trabajo futuro y los demás exigen más datos, más dependencias y menos
  interpretabilidad.
- **Calibrar el margen con los resultados.** Rechazada: se ajustaría y mediría con los mismos datos. Se fijó a priori y
  se publica la sensibilidad.
- **Descargar el NHS completo.** Inviable: cada mes pesa del orden de gigabytes; se agrega por consulta SQL de la API
  del portal y solo se guarda la muestra.
- **Usar MAPE.** Rechazada por los ceros.

## Consecuencias

### Positivas

- La elección entre regresión lineal y promedio queda respaldada por una prueba con los meses del propio medicamento y
  por una evaluación reproducible (`make forecast-fetch-nhs`, `make forecast-evaluate`).
- La evaluación y la aplicación usan el mismo backtest y la misma regla, así que lo medido es lo que se ejecuta.
- La interfaz informa con transparencia el método, su error de prueba y las limitaciones.
- Los resultados publicados no incluyen valores mensuales ni códigos de consultorio.

### Negativas

- Cada consulta del reporte ejecuta un backtest sobre la serie del medicamento (cálculo adicional pequeño sobre decenas
  de puntos).
- Las series de consumo de la Unidad suelen ser más cortas y de menor volumen que los consultorios de la muestra; con
  menos de 9 meses se usa el promedio histórico por falta de evidencia.
- El margen de 5 % es una decisión a priori y puede no ser el mejor para estos datos.

### Compromisos aceptados

- **La proyección no está validada con el consumo de la Unidad.** Se validó con datos públicos de otro contexto y con
  series sintéticas; se comunica así en la documentación y en el reporte de evaluación. La pantalla solo indica que
  la proyección es una referencia para planear compras.
- La media móvil de 3 meses quedó fuera de las opciones de la aplicación pese a haber tenido menos error en la
  evaluación (trabajo futuro).
- La extracción del NHS depende de la disponibilidad y del esquema de una API externa que ya cambió una vez; las
  validaciones detectan el cambio, pero no lo evitan.
- La extracción del NHS no se versiona (`storage/app/private/datasets/nhs-epd/`, ignorada por Git); solo se publican
  los resultados y la suma de verificación de la extracción.
