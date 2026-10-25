# Evaluación de la proyección de demanda con series sintéticas

Series generadas de forma determinista con una semilla fija (8 patrones x 30 réplicas). No son datos reales.

## Parámetros

- `min_train`: 6
- `horizons`: 1, 3
- `selection_margins`: 0, 0.05, 0.1
- `in_app_margin`: 0.05
- `min_history_for_selection`: 9
- `min_evaluated_origins`: 3
- `seed`: 20261024

## Conjunto de series

- `patterns`: constant, pure_trend, trend_noise, seasonal, erratic, intermittent, lumpy, level_shift
- `replicates`: 30
- `length_months`: 36
- `seasonal_length_months`: 48

## Cómo leer las tablas

RelMAE es el MAE del método dividido entre el MAE del promedio histórico en la misma serie y los mismos orígenes; menos de 1 significa que el método supera al promedio. Se resume con media geométrica y mediana; las series donde alguno de los dos MAE es cero se excluyen del cociente y se cuentan aparte. MASE usa como escala el error medio del pronóstico ingenuo a un paso de toda la serie. "Mejor/Empate/Peor" cuenta series frente al promedio histórico (empate si la diferencia es menor que 1e-9). No se usa MAPE porque la demanda mensual contiene ceros.

## Resultados por grupo (4 métodos, orígenes comunes)

### Todas las series (240 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 240 | n/d | n/d | 2.0077 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 240 | 0.4081 | 0.9616 | 0.9313 | 114 | 30 | 96 | 0.4750 | 32 |
| Ingenuo (último valor) | 240 | 0.4242 | 0.4954 | 1.0061 | 143 | 30 | 67 | 0.5958 | 30 |
| Media móvil de 3 meses | 240 | 0.5263 | 0.8867 | 1.2052 | 137 | 30 | 73 | 0.5708 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 240 | n/d | n/d | 2.1643 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 240 | 0.4270 | 0.9384 | 0.9473 | 113 | 30 | 97 | 0.4708 | 32 |
| Ingenuo (último valor) | 240 | 0.6348 | 0.9436 | 1.6054 | 114 | 30 | 96 | 0.4750 | 30 |
| Media móvil de 3 meses | 240 | 0.6852 | 0.9357 | 1.8516 | 123 | 30 | 87 | 0.5125 | 30 |

### Clase regular (smooth) (150 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 150 | n/d | n/d | 5.5894 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 150 | 0.2001 | 0.4114 | 1.4326 | 90 | 30 | 30 | 0.6000 | 32 |
| Ingenuo (último valor) | 150 | 0.2027 | 0.1824 | 1.0057 | 120 | 30 | 0 | 0.8000 | 30 |
| Media móvil de 3 meses | 150 | 0.3124 | 0.2252 | 1.7645 | 120 | 30 | 0 | 0.8000 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 150 | n/d | n/d | 6.3513 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 150 | 0.2115 | 0.4736 | 1.7934 | 90 | 30 | 30 | 0.6000 | 32 |
| Ingenuo (último valor) | 150 | 0.4067 | 0.2776 | 2.6489 | 90 | 30 | 30 | 0.6000 | 30 |
| Media móvil de 3 meses | 150 | 0.4936 | 0.3340 | 3.0594 | 90 | 30 | 30 | 0.6000 | 30 |

### Clase errática (erratic) (30 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 30 | n/d | n/d | 0.7723 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 30 | 1.0848 | 1.0726 | 0.8274 | 2 | 0 | 28 | 0.0667 | 0 |
| Ingenuo (último valor) | 30 | 1.3041 | 1.3550 | 1.0127 | 2 | 0 | 28 | 0.0667 | 0 |
| Media móvil de 3 meses | 30 | 1.1325 | 1.1292 | 0.8558 | 1 | 0 | 29 | 0.0333 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 30 | n/d | n/d | 0.7492 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 30 | 1.1378 | 1.1431 | 0.8449 | 2 | 0 | 28 | 0.0667 | 0 |
| Ingenuo (último valor) | 30 | 1.3503 | 1.3954 | 1.0378 | 1 | 0 | 29 | 0.0333 | 0 |
| Media móvil de 3 meses | 30 | 1.1526 | 1.1495 | 0.8813 | 3 | 0 | 27 | 0.1000 | 0 |

### Clase intermitente (intermittent) (35 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 35 | n/d | n/d | 0.9693 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 35 | 1.0051 | 1.0176 | 0.9735 | 13 | 0 | 22 | 0.3714 | 0 |
| Ingenuo (último valor) | 35 | 1.0066 | 1.0198 | 0.9909 | 15 | 0 | 20 | 0.4286 | 0 |
| Media móvil de 3 meses | 35 | 0.9984 | 1.0221 | 0.9647 | 10 | 0 | 25 | 0.2857 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 35 | n/d | n/d | 0.9541 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 35 | 1.0319 | 1.0399 | 0.9824 | 12 | 0 | 23 | 0.3429 | 0 |
| Ingenuo (último valor) | 35 | 1.0262 | 1.0374 | 0.9994 | 16 | 0 | 19 | 0.4571 | 0 |
| Media móvil de 3 meses | 35 | 1.0096 | 0.9964 | 0.9506 | 19 | 0 | 16 | 0.5429 | 0 |

### Clase irregular (lumpy) (25 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 25 | n/d | n/d | 0.8656 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 25 | 1.0335 | 1.1434 | 0.9870 | 9 | 0 | 16 | 0.3600 | 0 |
| Ingenuo (último valor) | 25 | 1.1387 | 1.2278 | 1.0930 | 6 | 0 | 19 | 0.2400 | 0 |
| Media móvil de 3 meses | 25 | 1.0464 | 1.1414 | 0.9620 | 6 | 0 | 19 | 0.2400 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 25 | n/d | n/d | 0.8953 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 25 | 1.0561 | 1.2231 | 1.0020 | 9 | 0 | 16 | 0.3600 | 0 |
| Ingenuo (último valor) | 25 | 1.1104 | 1.2056 | 1.0884 | 7 | 0 | 18 | 0.2800 | 0 |
| Media móvil de 3 meses | 25 | 1.0300 | 1.1433 | 0.9581 | 11 | 0 | 14 | 0.4400 | 0 |

### Estacional (34 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 34 | n/d | n/d | 2.0184 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 34 | 0.7654 | 1.0993 | 2.2047 | 3 | 0 | 31 | 0.0882 | 0 |
| Ingenuo (último valor) | 34 | 0.4420 | 0.4954 | 0.9995 | 33 | 0 | 1 | 0.9706 | 0 |
| Media móvil de 3 meses | 34 | 0.7977 | 0.9068 | 1.8499 | 33 | 0 | 1 | 0.9706 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 34 | n/d | n/d | 2.1691 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 34 | 0.9334 | 1.3757 | 2.9782 | 3 | 0 | 31 | 0.0882 | 0 |
| Ingenuo (último valor) | 34 | 1.1033 | 1.2596 | 2.7234 | 3 | 0 | 31 | 0.0882 | 0 |
| Media móvil de 3 meses | 34 | 1.2847 | 1.4855 | 3.1868 | 4 | 0 | 30 | 0.1176 | 0 |

### No estacional (206 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 206 | n/d | n/d | 1.4998 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 206 | 0.3609 | 0.5700 | 0.8740 | 111 | 30 | 65 | 0.5388 | 32 |
| Ingenuo (último valor) | 206 | 0.4209 | 0.5491 | 1.0115 | 110 | 30 | 66 | 0.5340 | 30 |
| Media móvil de 3 meses | 206 | 0.4856 | 0.5944 | 1.1138 | 104 | 30 | 72 | 0.5049 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 206 | n/d | n/d | 1.5581 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 206 | 0.3665 | 0.6744 | 0.8990 | 110 | 30 | 66 | 0.5340 | 32 |
| Ingenuo (último valor) | 206 | 0.5705 | 0.5973 | 1.3579 | 111 | 30 | 65 | 0.5388 | 30 |
| Media móvil de 3 meses | 206 | 0.6068 | 0.5685 | 1.3021 | 119 | 30 | 57 | 0.5777 | 30 |

## Submuestra de series con al menos 24 meses (5 métodos, mismos orígenes desde el mes 12)

Series en la submuestra: 240.

### Todas las series (240 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 240 | n/d | n/d | 1.9598 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 240 | 0.3865 | 0.9340 | 0.9075 | 125 | 30 | 85 | 0.5208 | 32 |
| Ingenuo (último valor) | 240 | 0.4006 | 0.5122 | 1.0049 | 145 | 30 | 65 | 0.6042 | 30 |
| Media móvil de 3 meses | 240 | 0.5044 | 0.9287 | 1.2550 | 143 | 30 | 67 | 0.5958 | 30 |
| Ingenuo estacional (12 meses) | 240 | 0.7241 | 0.9807 | 1.2406 | 130 | 30 | 80 | 0.5417 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 240 | n/d | n/d | 2.0745 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 240 | 0.3954 | 0.9355 | 0.9324 | 127 | 30 | 83 | 0.5292 | 32 |
| Ingenuo (último valor) | 240 | 0.6060 | 0.9095 | 1.6653 | 117 | 30 | 93 | 0.4875 | 30 |
| Media móvil de 3 meses | 240 | 0.6600 | 0.9090 | 1.9646 | 120 | 30 | 90 | 0.5000 | 30 |
| Ingenuo estacional (12 meses) | 240 | 0.6850 | 0.8804 | 1.2437 | 135 | 30 | 75 | 0.5625 | 30 |

### Clase regular (smooth) (150 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 150 | n/d | n/d | 6.5840 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 150 | 0.1853 | 0.3841 | 1.4652 | 90 | 30 | 30 | 0.6000 | 32 |
| Ingenuo (último valor) | 150 | 0.1865 | 0.1679 | 1.0061 | 120 | 30 | 0 | 0.8000 | 30 |
| Media móvil de 3 meses | 150 | 0.2908 | 0.2066 | 1.8126 | 120 | 30 | 0 | 0.8000 | 30 |
| Ingenuo estacional (12 meses) | 150 | 0.5212 | 0.9776 | 6.6371 | 110 | 30 | 10 | 0.7333 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 150 | n/d | n/d | 7.5264 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 150 | 0.1917 | 0.4616 | 1.6909 | 90 | 30 | 30 | 0.6000 | 32 |
| Ingenuo (último valor) | 150 | 0.3787 | 0.2595 | 2.6801 | 90 | 30 | 30 | 0.6000 | 30 |
| Media móvil de 3 meses | 150 | 0.4634 | 0.3146 | 3.1353 | 90 | 30 | 30 | 0.6000 | 30 |
| Ingenuo estacional (12 meses) | 150 | 0.4743 | 0.8724 | 6.8959 | 114 | 30 | 6 | 0.7600 | 30 |

### Clase errática (erratic) (30 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 30 | n/d | n/d | 0.7519 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 30 | 1.0590 | 1.0505 | 0.8382 | 7 | 0 | 23 | 0.2333 | 0 |
| Ingenuo (último valor) | 30 | 1.2940 | 1.3079 | 0.9846 | 1 | 0 | 29 | 0.0333 | 0 |
| Media móvil de 3 meses | 30 | 1.1377 | 1.1434 | 0.8830 | 2 | 0 | 28 | 0.0667 | 0 |
| Ingenuo estacional (12 meses) | 30 | 1.1969 | 1.2454 | 0.9252 | 6 | 0 | 24 | 0.2000 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 30 | n/d | n/d | 0.7540 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 30 | 1.0777 | 1.0432 | 0.8353 | 8 | 0 | 22 | 0.2667 | 0 |
| Ingenuo (último valor) | 30 | 1.3469 | 1.3812 | 1.1080 | 1 | 0 | 29 | 0.0333 | 0 |
| Media móvil de 3 meses | 30 | 1.1589 | 1.1435 | 0.8860 | 2 | 0 | 28 | 0.0667 | 0 |
| Ingenuo estacional (12 meses) | 30 | 1.1807 | 1.2242 | 0.9899 | 5 | 0 | 25 | 0.1667 | 0 |

### Clase intermitente (intermittent) (35 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 35 | n/d | n/d | 0.9425 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 35 | 0.9792 | 0.9981 | 0.9315 | 18 | 0 | 17 | 0.5143 | 0 |
| Ingenuo (último valor) | 35 | 0.9891 | 1.0339 | 0.9844 | 15 | 0 | 20 | 0.4286 | 0 |
| Media móvil de 3 meses | 35 | 0.9909 | 1.0241 | 0.9448 | 13 | 0 | 22 | 0.3714 | 0 |
| Ingenuo estacional (12 meses) | 35 | 1.0394 | 1.0437 | 0.9901 | 12 | 0 | 23 | 0.3429 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 35 | n/d | n/d | 0.9593 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 35 | 0.9774 | 0.9978 | 0.9332 | 18 | 0 | 17 | 0.5143 | 0 |
| Ingenuo (último valor) | 35 | 1.0163 | 1.0142 | 0.9868 | 17 | 0 | 18 | 0.4857 | 0 |
| Media móvil de 3 meses | 35 | 0.9979 | 1.0133 | 0.9448 | 17 | 0 | 18 | 0.4857 | 0 |
| Ingenuo estacional (12 meses) | 35 | 1.0468 | 1.0453 | 1.0415 | 12 | 0 | 23 | 0.3429 | 0 |

### Clase irregular (lumpy) (25 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 25 | n/d | n/d | 0.8787 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 25 | 1.0071 | 1.0236 | 1.0173 | 10 | 0 | 15 | 0.4000 | 0 |
| Ingenuo (último valor) | 25 | 1.0850 | 1.2114 | 1.0067 | 9 | 0 | 16 | 0.3600 | 0 |
| Media móvil de 3 meses | 25 | 1.0389 | 1.1127 | 0.9613 | 8 | 0 | 17 | 0.3200 | 0 |
| Ingenuo estacional (12 meses) | 25 | 1.1570 | 1.1716 | 1.0179 | 2 | 0 | 23 | 0.0800 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 25 | n/d | n/d | 0.8408 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 25 | 1.0201 | 1.0580 | 1.0541 | 11 | 0 | 14 | 0.4400 | 0 |
| Ingenuo (último valor) | 25 | 1.0763 | 1.2034 | 0.9679 | 9 | 0 | 16 | 0.3600 | 0 |
| Media móvil de 3 meses | 25 | 1.0280 | 1.0578 | 0.9795 | 11 | 0 | 14 | 0.4400 | 0 |
| Ingenuo estacional (12 meses) | 25 | 1.1486 | 1.1850 | 0.9926 | 4 | 0 | 21 | 0.1600 | 0 |

### Estacional (34 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 34 | n/d | n/d | 1.9618 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 34 | 0.7496 | 1.0842 | 2.1263 | 3 | 0 | 31 | 0.0882 | 0 |
| Ingenuo (último valor) | 34 | 0.4491 | 0.5122 | 1.0000 | 33 | 0 | 1 | 0.9706 | 0 |
| Media móvil de 3 meses | 34 | 0.8122 | 0.9427 | 1.8530 | 33 | 0 | 1 | 0.9706 | 0 |
| Ingenuo estacional (12 meses) | 34 | 0.1031 | 0.0782 | 0.1502 | 34 | 0 | 0 | 1.0000 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 34 | n/d | n/d | 2.0847 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 34 | 0.8412 | 1.2685 | 2.6057 | 4 | 0 | 30 | 0.1176 | 0 |
| Ingenuo (último valor) | 34 | 1.1239 | 1.3263 | 2.7321 | 3 | 0 | 31 | 0.0882 | 0 |
| Media móvil de 3 meses | 34 | 1.3099 | 1.5334 | 3.1921 | 4 | 0 | 30 | 0.1176 | 0 |
| Ingenuo estacional (12 meses) | 34 | 0.0961 | 0.0722 | 0.1460 | 34 | 0 | 0 | 1.0000 | 0 |

### No estacional (206 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 206 | n/d | n/d | 1.5341 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 206 | 0.3395 | 0.5601 | 0.8671 | 122 | 30 | 54 | 0.5922 | 32 |
| Ingenuo (último valor) | 206 | 0.3918 | 0.4928 | 1.0068 | 112 | 30 | 64 | 0.5437 | 30 |
| Media móvil de 3 meses | 206 | 0.4601 | 0.5801 | 1.1370 | 110 | 30 | 66 | 0.5340 | 30 |
| Ingenuo estacional (12 meses) | 206 | 1.0551 | 0.9882 | 1.6203 | 96 | 30 | 80 | 0.4660 | 30 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 206 | n/d | n/d | 1.5813 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 206 | 0.3412 | 0.6519 | 0.8593 | 123 | 30 | 53 | 0.5971 | 32 |
| Ingenuo (último valor) | 206 | 0.5378 | 0.4950 | 1.3728 | 114 | 30 | 62 | 0.5534 | 30 |
| Media móvil de 3 meses | 206 | 0.5782 | 0.5263 | 1.4035 | 116 | 30 | 60 | 0.5631 | 30 |
| Ingenuo estacional (12 meses) | 206 | 1.0010 | 0.9154 | 1.6964 | 101 | 30 | 75 | 0.4903 | 30 |

## Selección en línea (horizonte 1)

En cada origen desde el mes 9 se aplica la regla de la aplicación solo con los errores ya observados (regla: lineal si MAE lineal <= (1 - margen) x MAE del promedio, con al menos 3 orígenes). La regla vigente usa margen de 5 %; 0 % y 10 % se muestran como sensibilidad.

### Todas las series (240 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 240 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 240 | 0.3977 | 0.9524 | n/d | 119 | 30 | 91 | 0.4958 | 32 |
| Selección en línea (margen 0 %) | 240 | 0.3873 | 0.9973 | n/d | 106 | 76 | 58 | 0.4417 | 32 |
| Selección en línea (margen 5 %) | 240 | 0.3875 | 0.9944 | n/d | 107 | 89 | 44 | 0.4458 | 32 |
| Selección en línea (margen 10 %) | 240 | 0.3897 | 1.0000 | n/d | 103 | 97 | 40 | 0.4292 | 32 |

### Clase regular (smooth) (150 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 150 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 150 | 0.1925 | 0.4067 | n/d | 90 | 30 | 30 | 0.6000 | 32 |
| Selección en línea (margen 0 %) | 150 | 0.1913 | 0.4183 | n/d | 90 | 42 | 18 | 0.6000 | 32 |
| Selección en línea (margen 5 %) | 150 | 0.1922 | 0.4203 | n/d | 90 | 42 | 18 | 0.6000 | 32 |
| Selección en línea (margen 10 %) | 150 | 0.1939 | 0.4308 | n/d | 90 | 42 | 18 | 0.6000 | 32 |

### Clase errática (erratic) (30 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 30 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 30 | 1.0719 | 1.0688 | n/d | 3 | 0 | 27 | 0.1000 | 0 |
| Selección en línea (margen 0 %) | 30 | 1.0148 | 1.0000 | n/d | 2 | 17 | 11 | 0.0667 | 0 |
| Selección en línea (margen 5 %) | 30 | 1.0103 | 1.0000 | n/d | 3 | 19 | 8 | 0.1000 | 0 |
| Selección en línea (margen 10 %) | 30 | 1.0122 | 1.0000 | n/d | 1 | 20 | 9 | 0.0333 | 0 |

### Clase intermitente (intermittent) (35 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 35 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 35 | 0.9968 | 1.0198 | n/d | 16 | 0 | 19 | 0.4571 | 0 |
| Selección en línea (margen 0 %) | 35 | 0.9943 | 1.0020 | n/d | 5 | 12 | 18 | 0.1429 | 0 |
| Selección en línea (margen 5 %) | 35 | 0.9889 | 1.0000 | n/d | 5 | 17 | 13 | 0.1429 | 0 |
| Selección en línea (margen 10 %) | 35 | 0.9901 | 1.0000 | n/d | 4 | 21 | 10 | 0.1143 | 0 |

### Clase irregular (lumpy) (25 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 25 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 25 | 1.0262 | 1.1354 | n/d | 10 | 0 | 15 | 0.4000 | 0 |
| Selección en línea (margen 0 %) | 25 | 0.9091 | 1.0000 | n/d | 9 | 5 | 11 | 0.3600 | 0 |
| Selección en línea (margen 5 %) | 25 | 0.9053 | 1.0000 | n/d | 9 | 11 | 5 | 0.3600 | 0 |
| Selección en línea (margen 10 %) | 25 | 0.9057 | 1.0000 | n/d | 8 | 14 | 3 | 0.3200 | 0 |

### Estacional (34 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 34 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 34 | 0.7667 | 1.0992 | n/d | 3 | 0 | 31 | 0.0882 | 0 |
| Selección en línea (margen 0 %) | 34 | 0.7282 | 1.0294 | n/d | 3 | 13 | 18 | 0.0882 | 0 |
| Selección en línea (margen 5 %) | 34 | 0.7222 | 1.0151 | n/d | 3 | 13 | 18 | 0.0882 | 0 |
| Selección en línea (margen 10 %) | 34 | 0.7195 | 1.0274 | n/d | 3 | 13 | 18 | 0.0882 | 0 |

### No estacional (206 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 206 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 206 | 0.3498 | 0.5904 | n/d | 116 | 30 | 60 | 0.5631 | 32 |
| Selección en línea (margen 0 %) | 206 | 0.3423 | 0.5912 | n/d | 103 | 63 | 40 | 0.5000 | 32 |
| Selección en línea (margen 5 %) | 206 | 0.3431 | 0.6021 | n/d | 104 | 76 | 26 | 0.5049 | 32 |
| Selección en línea (margen 10 %) | 206 | 0.3457 | 0.6080 | n/d | 100 | 84 | 22 | 0.4854 | 32 |

## Sensibilidad del margen: series que elegirían regresión lineal

Series con al menos 9 meses, regla aplicada al backtest completo (horizonte 1).

| Grupo | Series elegibles | Lineal con margen 0 % | Lineal con margen 5 % (regla) | Lineal con margen 10 % |
|---|---:|---:|---:|---:|
| Todas las series | 240 | 114 | 106 | 98 |
| Clase regular (smooth) | 150 | 90 | 90 | 90 |
| Clase errática (erratic) | 30 | 2 | 2 | 0 |
| Clase intermitente (intermittent) | 35 | 13 | 6 | 2 |
| Clase irregular (lumpy) | 25 | 9 | 8 | 6 |
