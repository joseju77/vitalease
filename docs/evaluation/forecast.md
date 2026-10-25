# Evaluación de la proyección de demanda

Este documento resume cómo se evaluó la proyección de demanda de medicamentos (regresión lineal frente a métodos de
referencia) y qué resultados se obtuvieron. Las decisiones que se derivan están en
[ADR-0013](../adr/0013-evaluacion-y-seleccion-del-metodo-de-proyeccion.md) y la descripción funcional, en
[medication-inventory.md](../features/medication-inventory.md#proyección-de-demanda). Todas las cifras salen de los
archivos de [`results/05-forecast-nhs`](results/05-forecast-nhs) y [`results/06-forecast-synthetic`](results/06-forecast-synthetic)
(`summary.md` y `summary.json`); cada tabla indica el archivo del que se copió. Ningún número se recalculó ni se
redondeó de otra forma.

Alcance y límites, dichos desde el principio:

- Los datos reales son **consumo real público de otro contexto**: recetas de atención primaria del NHS de Inglaterra.
  **No son consumo de la Unidad**, y la proyección **no está validada con el consumo de la Unidad**.
- La unidad de demanda es **ITEMS** (renglones de receta), no unidades dispensadas.
- Las series sintéticas no son datos reales; su resultado depende de cómo se diseñaron.
- La proyección sigue siendo **indicativa** y de **apoyo a la decisión**.

> Contains NHSBSA data, OGL 3.0 (Open Government Licence v3.0). Fuente: NHS Business Services Authority, NHS English
> Prescribing Dataset (EPD) con código SNOMED.

## Procedimiento

Origen móvil con ventana expansiva. Para cada serie y cada origen se ajusta el método con los meses anteriores y se
predice el mes siguiente (horizonte 1) y el tercero (horizonte 3). Parámetros (de `summary.json`):

| Parámetro | Valor |
| --- | --- |
| Entrenamiento mínimo (`min_train`) | 6 meses |
| Horizontes | 1 y 3 meses |
| Recorte | Pronósticos negativos se recortan en 0 |
| Margen de selección de la aplicación (`in_app_margin`) | 0.05 |
| Márgenes de sensibilidad (`selection_margins`) | 0, 0.05 y 0.1 |
| Historial mínimo para elegir (`min_history_for_selection`) | 9 meses |
| Orígenes mínimos evaluados (`min_evaluated_origins`) | 3 |
| Semilla | 20261024 |

- **Métodos.** Regresión lineal y promedio histórico (los dos que la aplicación puede elegir); ingenuo (último valor)
  y media móvil de 3 meses (MA3), solo para evaluación; ingenuo estacional de 12 meses, solo en la submuestra de series
  con al menos 24 meses (orígenes desde el mes 12).
- **Sin fuga.** Cada pronóstico usa únicamente meses anteriores a su origen y todos los métodos se comparan en los
  mismos orígenes.
- **Métricas.** MAE y RMSE por serie (en `series.csv`), MASE y RelMAE para agregar entre series. RelMAE es el MAE del
  método dividido entre el MAE del promedio histórico en la misma serie y los mismos orígenes: menos de 1 significa
  mejor que el promedio. Se resume con media geométrica y mediana; las series en las que alguno de los dos MAE es 0
  se excluyen del cociente y se cuentan en "Excluidas". "Mejor/Peor" cuenta series frente al promedio (empate si la
  diferencia es menor que 1e-9). No se usa MAPE porque la demanda mensual contiene ceros.
- **Clasificación de patrón** (Syntetos-Boylan, ADI 1.32 y CV² 0.49): regular (`smooth`), errática (`erratic`),
  intermitente (`intermittent`), irregular (`lumpy`); y bandera de estacionalidad (autocorrelación de rezago 12, solo con
  al menos 24 meses).
- **Selección en línea.** En cada origen desde el mes 9 se aplica la regla de la aplicación con los errores ya
  observados. La regla vigente usa margen de 5 %; 0 % y 10 % son sensibilidad.

## Datos

### NHS English Prescribing Dataset

Fuente: `05-forecast-nhs/summary.md`.

| Dato | Valor |
| --- | --- |
| Consulta | API de datastore del portal NHSBSA (`datastore_search_sql`), agregada por consultorio y sustancia química |
| Unidad de demanda | ITEMS |
| Ventana | 2020-11 a 2026-08 (70 meses) |
| Consultorios muestreados | 20, semilla 20261024 |
| Elegibilidad | Al menos 1000 ítems (`min_practice_items`) en el primer y en el último mes de la ventana |
| Series candidatas | 18002 |
| Series conservadas | 16699 |
| Descartadas por demasiado cortas | 0 |
| Descartadas por no tener demanda | 0 |
| Descartadas por un solo mes con demanda | 1303 |
| Series con al menos 12, 18 y 24 meses | 16699, 16699 y 16699 |
| Suma de verificación de la extracción (`extract_sha256`) | `fb4c3163e3c34a46c270a4e32eb1b33da4258e36f127eda63295e4d3b88f120d` |

Limitaciones de estos datos:

- El muestreo favorece a **consultorios grandes y estables** (regla de al menos 1000 ítems en dos meses extremos de la
  ventana), por lo que no representa a todos los consultorios ni a la demanda de un servicio de salud universitario.
- La ventana incluye el periodo de la pandemia.
- Las series de un mismo consultorio no son independientes entre sí.
- Un mes ausente de un consultorio que sí reportó otras recetas cuenta como cero; si no reportó nada, el mes es
  faltante y no se rellena con cero. Se usa el tramo contiguo más largo y solo si dura al menos 18 meses.
- **Cambio de esquema del NHS.** Desde el archivo de 2025-03 el código de la sustancia pasó a la columna
  `BNF_CHEMICAL_SUBSTANCE_CODE` y `BNF_CHEMICAL_SUBSTANCE` pasó a contener el nombre. La primera extracción lo ignoró,
  partió las series en esa fecha y produjo resultados inválidos (casi todo intermitente) que no se publicaron. Se
  detectó con un recuento independiente y se corrigió en el commit `14908db`: la extracción resuelve la columna por
  mes, valida el formato de cada código (falla si no coincide) y registra la columna usada en `manifest.json`. Los
  resultados de este documento corresponden a la extracción corregida.

### Series sintéticas

Fuente: `06-forecast-synthetic/summary.md`. Generadas con semilla fija 20261024: 8 patrones (constante, tendencia pura,
tendencia con ruido, estacional, errática, intermitente, irregular, cambio de nivel) por 30 réplicas, con longitud de 36
meses (48 meses en el patrón estacional) y una sub-semilla por serie, de modo que agregar un patrón no cambia las demás.
En total son 240 series. El resumen agrupa por clase de demanda detectada, no por patrón de generación, así que las
tablas siguientes están por clase.

## Cómo reproducir

```bash
make forecast-fetch-nhs   # red; descarga la muestra del NHS (reanudable)
make forecast-evaluate    # sin red; escribe results/05-* y results/06-*
```

- `forecast-fetch-nhs` consulta la API del NHS mes por mes y deja la extracción en
  `storage/app/private/datasets/nhs-epd/` (`sample.json`, `months/AAAAMM.csv`, `manifest.json`). **La extracción está
  ignorada por Git** y no se publica; el `manifest.json` conserva las consultas y las sumas de verificación, y la
  suma de verificación de la extracción queda registrada en los resultados (`extract_sha256`).
- `forecast-evaluate` no usa red: lee la extracción local y genera las series sintéticas. Sin extracción, la parte del
  NHS falla indicando `make forecast-fetch-nhs`.
- El orden de las filas está fijado y los decimales se redondean a 4, de modo que dos corridas producen los mismos
  archivos salvo `run.json`, que registra la fecha real de la corrida y la versión de PHP.
- `05-forecast-nhs/series.csv.gz` es el CSV por serie comprimido con `gzip -n` (sin marca de tiempo): se lee con
  `zcat docs/evaluation/results/05-forecast-nhs/series.csv.gz`. Contiene índices anónimos (`nhs-00001`), clase,
  estacionalidad, MAE/RMSE/MASE por método y horizonte; no contiene códigos de consultorio ni valores mensuales.

## Resultados con datos del NHS

Fuente de las tablas: `05-forecast-nhs/summary.md`. Todas tienen como referencia el promedio histórico (RelMAE = 1 por
definición, por eso no aparece). "Proporción mejor" es la fracción de series en las que el método tuvo menos error que
el promedio.

Contains NHSBSA data, OGL 3.0.

### Comparación agregada y por clase, horizonte de 1 mes

Sección "Resultados por grupo (4 métodos, orígenes comunes)", horizonte 1.

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Todas las series (16699 series) | Regresión lineal | 0.8768 | 0.9756 | 9281 | 7418 | 0.5558 | 44 |
| Todas las series (16699 series) | Ingenuo (último valor) | 0.8848 | 0.9950 | 8470 | 8229 | 0.5072 | 68 |
| Todas las series (16699 series) | Media móvil de 3 meses | 0.7806 | 0.8743 | 11630 | 5069 | 0.6964 | 34 |
| Clase regular (smooth) (7680 series) | Regresión lineal | 0.8740 | 0.9680 | 4529 | 3151 | 0.5897 | 0 |
| Clase regular (smooth) (7680 series) | Ingenuo (último valor) | 0.9920 | 1.0787 | 3115 | 4565 | 0.4056 | 0 |
| Clase regular (smooth) (7680 series) | Media móvil de 3 meses | 0.8054 | 0.8838 | 5523 | 2157 | 0.7191 | 0 |
| Clase errática (erratic) (302 series) | Regresión lineal | 0.7844 | 0.8851 | 205 | 97 | 0.6788 | 0 |
| Clase errática (erratic) (302 series) | Ingenuo (último valor) | 0.6907 | 0.7433 | 218 | 84 | 0.7219 | 0 |
| Clase errática (erratic) (302 series) | Media móvil de 3 meses | 0.6197 | 0.6477 | 245 | 57 | 0.8113 | 0 |
| Clase intermitente (intermittent) (8210 series) | Regresión lineal | 0.8868 | 0.9914 | 4260 | 3950 | 0.5189 | 43 |
| Clase intermitente (intermittent) (8210 series) | Ingenuo (último valor) | 0.8093 | 0.9148 | 4824 | 3386 | 0.5876 | 67 |
| Clase intermitente (intermittent) (8210 series) | Media móvil de 3 meses | 0.7686 | 0.8714 | 5507 | 2703 | 0.6708 | 33 |
| Clase irregular (lumpy) (507 series) | Regresión lineal | 0.8199 | 0.9674 | 287 | 220 | 0.5661 | 1 |
| Clase irregular (lumpy) (507 series) | Ingenuo (último valor) | 0.7606 | 0.8893 | 313 | 194 | 0.6174 | 1 |
| Clase irregular (lumpy) (507 series) | Media móvil de 3 meses | 0.7166 | 0.8229 | 355 | 152 | 0.7002 | 1 |
| Estacional (431 series) | Regresión lineal | 0.9164 | 1.0327 | 180 | 251 | 0.4176 | 0 |
| Estacional (431 series) | Ingenuo (último valor) | 0.9420 | 0.9808 | 227 | 204 | 0.5267 | 0 |
| Estacional (431 series) | Media móvil de 3 meses | 0.8624 | 0.9400 | 273 | 158 | 0.6334 | 0 |
| No estacional (16268 series) | Regresión lineal | 0.8758 | 0.9737 | 9101 | 7167 | 0.5594 | 44 |
| No estacional (16268 series) | Ingenuo (último valor) | 0.8834 | 0.9955 | 8243 | 8025 | 0.5067 | 68 |
| No estacional (16268 series) | Media móvil de 3 meses | 0.7785 | 0.8725 | 11357 | 4911 | 0.6981 | 34 |

### Comparación agregada y por clase, horizonte de 3 meses

Misma sección, horizonte 3.

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Todas las series (16699 series) | Regresión lineal | 0.9112 | 1.0090 | 8070 | 8599 | 0.4833 | 51 |
| Todas las series (16699 series) | Ingenuo (último valor) | 0.8849 | 0.9829 | 8703 | 7966 | 0.5212 | 68 |
| Todas las series (16699 series) | Media móvil de 3 meses | 0.8148 | 0.9000 | 11051 | 5618 | 0.6618 | 34 |
| Clase regular (smooth) (7680 series) | Regresión lineal | 0.9132 | 1.0033 | 3789 | 3891 | 0.4934 | 0 |
| Clase regular (smooth) (7680 series) | Ingenuo (último valor) | 0.9517 | 1.0410 | 3446 | 4234 | 0.4487 | 0 |
| Clase regular (smooth) (7680 series) | Media móvil de 3 meses | 0.8254 | 0.8977 | 5279 | 2401 | 0.6874 | 0 |
| Clase errática (erratic) (302 series) | Regresión lineal | 0.8331 | 0.9321 | 187 | 115 | 0.6192 | 0 |
| Clase errática (erratic) (302 series) | Ingenuo (último valor) | 0.7354 | 0.7735 | 208 | 94 | 0.6887 | 0 |
| Clase errática (erratic) (302 series) | Media móvil de 3 meses | 0.6878 | 0.7285 | 229 | 73 | 0.7583 | 0 |
| Clase intermitente (intermittent) (8210 series) | Regresión lineal | 0.9157 | 1.0181 | 3840 | 4340 | 0.4677 | 48 |
| Clase intermitente (intermittent) (8210 series) | Ingenuo (último valor) | 0.8367 | 0.9311 | 4777 | 3403 | 0.5819 | 67 |
| Clase intermitente (intermittent) (8210 series) | Media móvil de 3 meses | 0.8121 | 0.9070 | 5230 | 2950 | 0.6370 | 33 |
| Clase irregular (lumpy) (507 series) | Regresión lineal | 0.8579 | 1.0016 | 254 | 253 | 0.5010 | 3 |
| Clase irregular (lumpy) (507 series) | Ingenuo (último valor) | 0.8073 | 0.9518 | 272 | 235 | 0.5365 | 1 |
| Clase irregular (lumpy) (507 series) | Media móvil de 3 meses | 0.7805 | 0.8979 | 313 | 194 | 0.6174 | 1 |
| Estacional (431 series) | Regresión lineal | 0.9571 | 1.0790 | 160 | 271 | 0.3712 | 0 |
| Estacional (431 series) | Ingenuo (último valor) | 0.9984 | 1.1269 | 151 | 280 | 0.3503 | 0 |
| Estacional (431 series) | Media móvil de 3 meses | 0.9492 | 1.0634 | 169 | 262 | 0.3921 | 0 |
| No estacional (16268 series) | Regresión lineal | 0.9100 | 1.0071 | 7910 | 8328 | 0.4862 | 51 |
| No estacional (16268 series) | Ingenuo (último valor) | 0.8821 | 0.9788 | 8552 | 7686 | 0.5257 | 68 |
| No estacional (16268 series) | Media móvil de 3 meses | 0.8115 | 0.8962 | 10882 | 5356 | 0.6689 | 34 |

### Submuestra de series con al menos 24 meses (5 métodos)

Sección "Submuestra de series con al menos 24 meses". Incluye el ingenuo estacional; los orígenes comienzan en el mes 12.
Horizonte 1:

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Todas las series (16699 series) | Regresión lineal | 0.8564 | 0.9661 | 9635 | 7064 | 0.5770 |
| Todas las series (16699 series) | Ingenuo (último valor) | 0.8730 | 0.9880 | 8662 | 8037 | 0.5187 |
| Todas las series (16699 series) | Media móvil de 3 meses | 0.7677 | 0.8684 | 11654 | 5045 | 0.6979 |
| Todas las series (16699 series) | Ingenuo estacional (12 meses) | 1.0661 | 1.1159 | 5614 | 11085 | 0.3362 |
| Estacional (431 series) | Regresión lineal | 0.8951 | 1.0200 | 191 | 240 | 0.4432 |
| Estacional (431 series) | Ingenuo (último valor) | 0.9205 | 0.9740 | 227 | 204 | 0.5267 |
| Estacional (431 series) | Media móvil de 3 meses | 0.8468 | 0.9392 | 271 | 160 | 0.6288 |
| Estacional (431 series) | Ingenuo estacional (12 meses) | 0.7975 | 0.8422 | 326 | 105 | 0.7564 |
| No estacional (16268 series) | Regresión lineal | 0.8554 | 0.9647 | 9444 | 6824 | 0.5805 |
| No estacional (16268 series) | Ingenuo (último valor) | 0.8717 | 0.9886 | 8435 | 7833 | 0.5185 |
| No estacional (16268 series) | Media móvil de 3 meses | 0.7657 | 0.8664 | 11383 | 4885 | 0.6997 |
| No estacional (16268 series) | Ingenuo estacional (12 meses) | 1.0743 | 1.1233 | 5288 | 10980 | 0.3251 |

Horizonte 3:

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Todas las series (16699 series) | Regresión lineal | 0.8865 | 0.9926 | 8656 | 8013 | 0.5184 |
| Todas las series (16699 series) | Ingenuo (último valor) | 0.8739 | 0.9796 | 8840 | 7829 | 0.5294 |
| Todas las series (16699 series) | Media móvil de 3 meses | 0.8015 | 0.8956 | 11082 | 5587 | 0.6636 |
| Todas las series (16699 series) | Ingenuo estacional (12 meses) | 1.0350 | 1.0947 | 6017 | 10652 | 0.3603 |
| Estacional (431 series) | Regresión lineal | 0.9123 | 1.0427 | 181 | 250 | 0.4200 |
| Estacional (431 series) | Ingenuo (último valor) | 0.9757 | 1.1265 | 162 | 269 | 0.3759 |
| Estacional (431 series) | Media móvil de 3 meses | 0.9288 | 1.0658 | 178 | 253 | 0.4130 |
| Estacional (431 series) | Ingenuo estacional (12 meses) | 0.7817 | 0.8285 | 332 | 99 | 0.7703 |
| No estacional (16268 series) | Regresión lineal | 0.8858 | 0.9916 | 8475 | 7763 | 0.5210 |
| No estacional (16268 series) | Ingenuo (último valor) | 0.8713 | 0.9763 | 8678 | 7560 | 0.5334 |
| No estacional (16268 series) | Media móvil de 3 meses | 0.7983 | 0.8921 | 10904 | 5334 | 0.6703 |
| No estacional (16268 series) | Ingenuo estacional (12 meses) | 1.0428 | 1.1025 | 5685 | 10553 | 0.3495 |

### Selección en línea (horizonte 1)

Sección "Selección en línea (horizonte 1)". "Regresión lineal" es la regresión lineal usada siempre, en los mismos
orígenes (desde el mes 9); "Selección en línea" aplica la regla de la aplicación con el margen indicado. Todas las series:

| Método | RelMAE media geométrica | RelMAE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Regresión lineal | 0.8663 | 0.9708 | 9492 | 0 | 7207 | 0.5684 | 79 |
| Selección en línea (margen 0 %) | 0.8463 | 1.0000 | 8379 | 1388 | 6932 | 0.5018 | 79 |
| Selección en línea (margen 5 %) | 0.8522 | 1.0000 | 8139 | 4150 | 4410 | 0.4874 | 79 |
| Selección en línea (margen 10 %) | 0.8617 | 1.0000 | 7589 | 5524 | 3586 | 0.4545 | 77 |

Regresión lineal siempre frente a la regla vigente (margen 5 %), por clase:

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Empate | Peor | Proporción mejor |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Clase regular (smooth) (7680 series) | Regresión lineal | 0.8675 | 0.9607 | 4669 | 0 | 3011 | 0.6079 |
| Clase regular (smooth) (7680 series) | Selección en línea (margen 5 %) | 0.8768 | 1.0000 | 3739 | 1387 | 2554 | 0.4868 |
| Clase errática (erratic) (302 series) | Regresión lineal | 0.7772 | 0.8769 | 211 | 0 | 91 | 0.6987 |
| Clase errática (erratic) (302 series) | Selección en línea (margen 5 %) | 0.7799 | 0.9161 | 192 | 28 | 82 | 0.6358 |
| Clase intermitente (intermittent) (8210 series) | Regresión lineal | 0.8719 | 0.9874 | 4324 | 0 | 3886 | 0.5267 |
| Clase intermitente (intermittent) (8210 series) | Selección en línea (margen 5 %) | 0.8360 | 1.0000 | 3928 | 2650 | 1632 | 0.4784 |
| Clase irregular (lumpy) (507 series) | Regresión lineal | 0.8155 | 0.9677 | 288 | 0 | 219 | 0.5680 |
| Clase irregular (lumpy) (507 series) | Selección en línea (margen 5 %) | 0.7952 | 0.9881 | 280 | 85 | 142 | 0.5523 |
| Estacional (431 series) | Regresión lineal | 0.9091 | 1.0282 | 181 | 0 | 250 | 0.4200 |
| Estacional (431 series) | Selección en línea (margen 5 %) | 0.8731 | 1.0000 | 153 | 135 | 143 | 0.3550 |
| No estacional (16268 series) | Regresión lineal | 0.8652 | 0.9690 | 9311 | 0 | 6957 | 0.5724 |
| No estacional (16268 series) | Selección en línea (margen 5 %) | 0.8517 | 1.0000 | 7986 | 4015 | 4267 | 0.4909 |

### Sensibilidad del margen

Sección "Sensibilidad del margen". Series con al menos 9 meses, regla aplicada al backtest completo (horizonte 1):

| Grupo | Series elegibles | Lineal con margen 0 % | Lineal con margen 5 % (regla) | Lineal con margen 10 % |
| --- | ---: | ---: | ---: | ---: |
| Todas las series | 16699 | 9281 | 7573 | 6289 |
| Clase regular (smooth) | 7680 | 4529 | 3560 | 2869 |
| Clase errática (erratic) | 302 | 205 | 183 | 162 |
| Clase intermitente (intermittent) | 8210 | 4260 | 3588 | 3051 |
| Clase irregular (lumpy) | 507 | 287 | 242 | 207 |

## Resultados con series sintéticas

Fuente de las tablas: `06-forecast-synthetic/summary.md`. Series generadas, no datos reales.

### Horizonte de 1 mes

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Todas las series (240 series) | Regresión lineal | 0.4081 | 0.9616 | 114 | 96 | 0.4750 | 32 |
| Todas las series (240 series) | Ingenuo (último valor) | 0.4242 | 0.4954 | 143 | 67 | 0.5958 | 30 |
| Todas las series (240 series) | Media móvil de 3 meses | 0.5263 | 0.8867 | 137 | 73 | 0.5708 | 30 |
| Clase regular (smooth) (150 series) | Regresión lineal | 0.2001 | 0.4114 | 90 | 30 | 0.6000 | 32 |
| Clase regular (smooth) (150 series) | Ingenuo (último valor) | 0.2027 | 0.1824 | 120 | 0 | 0.8000 | 30 |
| Clase regular (smooth) (150 series) | Media móvil de 3 meses | 0.3124 | 0.2252 | 120 | 0 | 0.8000 | 30 |
| Clase errática (erratic) (30 series) | Regresión lineal | 1.0848 | 1.0726 | 2 | 28 | 0.0667 | 0 |
| Clase errática (erratic) (30 series) | Ingenuo (último valor) | 1.3041 | 1.3550 | 2 | 28 | 0.0667 | 0 |
| Clase errática (erratic) (30 series) | Media móvil de 3 meses | 1.1325 | 1.1292 | 1 | 29 | 0.0333 | 0 |
| Clase intermitente (intermittent) (35 series) | Regresión lineal | 1.0051 | 1.0176 | 13 | 22 | 0.3714 | 0 |
| Clase intermitente (intermittent) (35 series) | Ingenuo (último valor) | 1.0066 | 1.0198 | 15 | 20 | 0.4286 | 0 |
| Clase intermitente (intermittent) (35 series) | Media móvil de 3 meses | 0.9984 | 1.0221 | 10 | 25 | 0.2857 | 0 |
| Clase irregular (lumpy) (25 series) | Regresión lineal | 1.0335 | 1.1434 | 9 | 16 | 0.3600 | 0 |
| Clase irregular (lumpy) (25 series) | Ingenuo (último valor) | 1.1387 | 1.2278 | 6 | 19 | 0.2400 | 0 |
| Clase irregular (lumpy) (25 series) | Media móvil de 3 meses | 1.0464 | 1.1414 | 6 | 19 | 0.2400 | 0 |
| Estacional (34 series) | Regresión lineal | 0.7654 | 1.0993 | 3 | 31 | 0.0882 | 0 |
| Estacional (34 series) | Ingenuo (último valor) | 0.4420 | 0.4954 | 33 | 1 | 0.9706 | 0 |
| Estacional (34 series) | Media móvil de 3 meses | 0.7977 | 0.9068 | 33 | 1 | 0.9706 | 0 |
| No estacional (206 series) | Regresión lineal | 0.3609 | 0.5700 | 111 | 65 | 0.5388 | 32 |
| No estacional (206 series) | Ingenuo (último valor) | 0.4209 | 0.5491 | 110 | 66 | 0.5340 | 30 |
| No estacional (206 series) | Media móvil de 3 meses | 0.4856 | 0.5944 | 104 | 72 | 0.5049 | 30 |

### Horizonte de 3 meses

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Todas las series (240 series) | Regresión lineal | 0.4270 | 0.9384 | 113 | 97 | 0.4708 | 32 |
| Todas las series (240 series) | Ingenuo (último valor) | 0.6348 | 0.9436 | 114 | 96 | 0.4750 | 30 |
| Todas las series (240 series) | Media móvil de 3 meses | 0.6852 | 0.9357 | 123 | 87 | 0.5125 | 30 |
| Clase regular (smooth) (150 series) | Regresión lineal | 0.2115 | 0.4736 | 90 | 30 | 0.6000 | 32 |
| Clase regular (smooth) (150 series) | Ingenuo (último valor) | 0.4067 | 0.2776 | 90 | 30 | 0.6000 | 30 |
| Clase regular (smooth) (150 series) | Media móvil de 3 meses | 0.4936 | 0.3340 | 90 | 30 | 0.6000 | 30 |
| Clase errática (erratic) (30 series) | Regresión lineal | 1.1378 | 1.1431 | 2 | 28 | 0.0667 | 0 |
| Clase errática (erratic) (30 series) | Ingenuo (último valor) | 1.3503 | 1.3954 | 1 | 29 | 0.0333 | 0 |
| Clase errática (erratic) (30 series) | Media móvil de 3 meses | 1.1526 | 1.1495 | 3 | 27 | 0.1000 | 0 |
| Clase intermitente (intermittent) (35 series) | Regresión lineal | 1.0319 | 1.0399 | 12 | 23 | 0.3429 | 0 |
| Clase intermitente (intermittent) (35 series) | Ingenuo (último valor) | 1.0262 | 1.0374 | 16 | 19 | 0.4571 | 0 |
| Clase intermitente (intermittent) (35 series) | Media móvil de 3 meses | 1.0096 | 0.9964 | 19 | 16 | 0.5429 | 0 |
| Clase irregular (lumpy) (25 series) | Regresión lineal | 1.0561 | 1.2231 | 9 | 16 | 0.3600 | 0 |
| Clase irregular (lumpy) (25 series) | Ingenuo (último valor) | 1.1104 | 1.2056 | 7 | 18 | 0.2800 | 0 |
| Clase irregular (lumpy) (25 series) | Media móvil de 3 meses | 1.0300 | 1.1433 | 11 | 14 | 0.4400 | 0 |
| Estacional (34 series) | Regresión lineal | 0.9334 | 1.3757 | 3 | 31 | 0.0882 | 0 |
| Estacional (34 series) | Ingenuo (último valor) | 1.1033 | 1.2596 | 3 | 31 | 0.0882 | 0 |
| Estacional (34 series) | Media móvil de 3 meses | 1.2847 | 1.4855 | 4 | 30 | 0.1176 | 0 |
| No estacional (206 series) | Regresión lineal | 0.3665 | 0.6744 | 110 | 66 | 0.5340 | 32 |
| No estacional (206 series) | Ingenuo (último valor) | 0.5705 | 0.5973 | 111 | 65 | 0.5388 | 30 |
| No estacional (206 series) | Media móvil de 3 meses | 0.6068 | 0.5685 | 119 | 57 | 0.5777 | 30 |

### Submuestra de series con al menos 24 meses, horizonte 1 (5 métodos)

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Peor | Proporción mejor |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Todas las series (240 series) | Regresión lineal | 0.3865 | 0.9340 | 125 | 85 | 0.5208 |
| Todas las series (240 series) | Ingenuo (último valor) | 0.4006 | 0.5122 | 145 | 65 | 0.6042 |
| Todas las series (240 series) | Media móvil de 3 meses | 0.5044 | 0.9287 | 143 | 67 | 0.5958 |
| Todas las series (240 series) | Ingenuo estacional (12 meses) | 0.7241 | 0.9807 | 130 | 80 | 0.5417 |
| Estacional (34 series) | Regresión lineal | 0.7496 | 1.0842 | 3 | 31 | 0.0882 |
| Estacional (34 series) | Ingenuo (último valor) | 0.4491 | 0.5122 | 33 | 1 | 0.9706 |
| Estacional (34 series) | Media móvil de 3 meses | 0.8122 | 0.9427 | 33 | 1 | 0.9706 |
| Estacional (34 series) | Ingenuo estacional (12 meses) | 0.1031 | 0.0782 | 34 | 0 | 1.0000 |
| No estacional (206 series) | Regresión lineal | 0.3395 | 0.5601 | 122 | 54 | 0.5922 |
| No estacional (206 series) | Ingenuo (último valor) | 0.3918 | 0.4928 | 112 | 64 | 0.5437 |
| No estacional (206 series) | Media móvil de 3 meses | 0.4601 | 0.5801 | 110 | 66 | 0.5340 |
| No estacional (206 series) | Ingenuo estacional (12 meses) | 1.0551 | 0.9882 | 96 | 80 | 0.4660 |

### Selección en línea (horizonte 1)

Todas las series:

| Método | RelMAE media geométrica | RelMAE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Regresión lineal | 0.3977 | 0.9524 | 119 | 30 | 91 | 0.4958 | 32 |
| Selección en línea (margen 0 %) | 0.3873 | 0.9973 | 106 | 76 | 58 | 0.4417 | 32 |
| Selección en línea (margen 5 %) | 0.3875 | 0.9944 | 107 | 89 | 44 | 0.4458 | 32 |
| Selección en línea (margen 10 %) | 0.3897 | 1.0000 | 103 | 97 | 40 | 0.4292 | 32 |

Regresión lineal siempre frente a la regla vigente (margen 5 %), por clase:

| Grupo | Método | RelMAE media geométrica | RelMAE mediana | Mejor | Empate | Peor | Proporción mejor |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Clase regular (smooth) (150 series) | Regresión lineal | 0.1925 | 0.4067 | 90 | 30 | 30 | 0.6000 |
| Clase regular (smooth) (150 series) | Selección en línea (margen 5 %) | 0.1922 | 0.4203 | 90 | 42 | 18 | 0.6000 |
| Clase errática (erratic) (30 series) | Regresión lineal | 1.0719 | 1.0688 | 3 | 0 | 27 | 0.1000 |
| Clase errática (erratic) (30 series) | Selección en línea (margen 5 %) | 1.0103 | 1.0000 | 3 | 19 | 8 | 0.1000 |
| Clase intermitente (intermittent) (35 series) | Regresión lineal | 0.9968 | 1.0198 | 16 | 0 | 19 | 0.4571 |
| Clase intermitente (intermittent) (35 series) | Selección en línea (margen 5 %) | 0.9889 | 1.0000 | 5 | 17 | 13 | 0.1429 |
| Clase irregular (lumpy) (25 series) | Regresión lineal | 1.0262 | 1.1354 | 10 | 0 | 15 | 0.4000 |
| Clase irregular (lumpy) (25 series) | Selección en línea (margen 5 %) | 0.9053 | 1.0000 | 9 | 11 | 5 | 0.3600 |
| Estacional (34 series) | Regresión lineal | 0.7667 | 1.0992 | 3 | 0 | 31 | 0.0882 |
| Estacional (34 series) | Selección en línea (margen 5 %) | 0.7222 | 1.0151 | 3 | 13 | 18 | 0.0882 |
| No estacional (206 series) | Regresión lineal | 0.3498 | 0.5904 | 116 | 30 | 60 | 0.5631 |
| No estacional (206 series) | Selección en línea (margen 5 %) | 0.3431 | 0.6021 | 104 | 76 | 26 | 0.5049 |

### Sensibilidad del margen

| Grupo | Series elegibles | Lineal con margen 0 % | Lineal con margen 5 % (regla) | Lineal con margen 10 % |
| --- | ---: | ---: | ---: | ---: |
| Todas las series | 240 | 114 | 106 | 98 |
| Clase regular (smooth) | 150 | 90 | 90 | 90 |
| Clase errática (erratic) | 30 | 2 | 2 | 0 |
| Clase intermitente (intermittent) | 35 | 13 | 6 | 2 |
| Clase irregular (lumpy) | 25 | 9 | 8 | 6 |

## Conclusiones

Las lecturas siguientes se limitan a lo que muestran las tablas anteriores.

1. **Con datos del NHS la regresión lineal mejora al promedio histórico en conjunto a un mes, pero no a tres.** A un
   mes tuvo RelMAE media geométrica de 0.8768 y mediana de 0.9756, y mejoró al promedio en 9281 de 16699 series
   (proporción 0.5558). A tres meses la media geométrica fue 0.9112, la mediana 1.0090 y mejoró en 8070 series contra
   8599 en las que empeoró (proporción 0.4833).
2. **La regresión lineal no fue la mejor opción.** La media móvil de 3 meses tuvo menos error que la regresión lineal y
   que el promedio: RelMAE media geométrica a un mes de 0.7806 contra 0.8768, y a tres meses de 0.8148 contra 0.9112.
   También el ingenuo (último valor) superó a la regresión lineal a tres meses (0.8849 contra 0.9112). La
   aplicación mantiene en esta etapa la elección entre regresión lineal y promedio por ser la comparación solicitada
   por la revisión y porque cambiar la oferta de métodos con estos resultados implicaría elegir y medir con los mismos
   datos; queda como trabajo futuro (ADR-0013).
3. **Estacionalidad: la regresión lineal pierde.** En las 431 series estacionales del NHS tuvo RelMAE mediana de 1.0327
   a un mes y de 1.0790 a tres, con proporción de series mejores de 0.4176 y 0.3712. En la submuestra de 24 meses, el
   ingenuo estacional fue el mejor de la clase (RelMAE media geométrica 0.7975 a un mes). En las series sintéticas
   estacionales, la regresión lineal empeoró frente al promedio en 31 de 34 series a un mes.
4. **Demanda errática, intermitente e irregular.** En series sintéticas la regresión lineal no superó al promedio
   (RelMAE media geométrica a un mes de 1.0848, 1.0051 y 1.0335). En el NHS sí lo superó en media geométrica (0.7844,
   0.8868 y 0.8199 a un mes), pero en intermitente e irregular su mediana queda cerca de 1 (0.9914 y 0.9674 a un mes;
   1.0181 y 1.0016 a tres meses), es decir, con poca ganancia típica. La diferencia entre ambas fuentes se debe
   explicar con cautela: no se investigó su causa.
5. **Efecto de la regla de selección con margen de 5 %.** En el NHS, frente a usar siempre la regresión lineal, la regla
   bajó la RelMAE media geométrica global (0.8522 contra 0.8663) con mediana de 1.0000, a costa de más empates (4150)
   y de menos series con mejora clara (8139 contra 9492). En la clase regular quedó peor que la regresión lineal
   siempre (0.8768 contra 0.8675) y en la errática prácticamente igual (0.7799 contra 0.7772); en la intermitente, la
   irregular y la estacional quedó mejor (0.8360 contra 0.8719, 0.7952 contra 0.8155 y 0.8731 contra 0.9091). En las
   sintéticas redujo la pérdida de la regresión lineal en las clases errática (1.0103 contra 1.0719) e irregular
   (0.9053 contra 1.0262).
6. **Sensibilidad del margen.** Con 0 %, 5 % y 10 %, elegirían regresión lineal 9281, 7573 y 6289 de las 16699 series
   del NHS; en las sintéticas, 114, 106 y 98 de 240. La regla de la aplicación no se modificó con estos resultados.
7. **Límites.** Estos resultados describen una muestra de 20 consultorios de Inglaterra (con las limitaciones de la
   sección Datos) y series sintéticas; **no validan la proyección para el consumo de la Unidad**. En la aplicación la
   proyección sigue siendo indicativa y de apoyo a la decisión.
