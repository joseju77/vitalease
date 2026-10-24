# Evaluación de la proyección de demanda con recetas del NHS de Inglaterra

Consumo real público de otro contexto: recetas dispensadas por consultorios de medicina general del NHS de Inglaterra (NHS English Prescribing Dataset). No es consumo de la Unidad.

> Contains NHSBSA data, OGL 3.0 (Open Government Licence v3.0).

## Parámetros

- `min_train`: 6
- `horizons`: 1, 3
- `selection_margins`: 0, 0.05, 0.1
- `in_app_margin`: 0.05
- `min_history_for_selection`: 9
- `min_evaluated_origins`: 3

## Conjunto de series

- `endpoint`: https://opendata.nhsbsa.net/api/3/action/datastore_search_sql
- `unit`: ITEMS
- `sampling.from`: 2020-11
- `sampling.to`: 2026-08
- `sampling.practices`: 20
- `sampling.seed`: 20261024
- `sampling.min_practice_items`: 1000
- `sampling.unit`: ITEMS
- `first_resource_id`: EPD_SNOMED_202011
- `last_resource_id`: EPD_SNOMED_202608
- `months`: 70
- `practices`: 20
- `candidates`: 18002
- `kept`: 16699
- `dropped.too_short`: 0
- `dropped.all_zero`: 0
- `dropped.single_non_zero`: 1303
- `counts_by_min_length.12`: 16699
- `counts_by_min_length.18`: 16699
- `counts_by_min_length.24`: 16699
- `extract_sha256`: fb4c3163e3c34a46c270a4e32eb1b33da4258e36f127eda63295e4d3b88f120d

## Cómo leer las tablas

RelMAE es el MAE del método dividido entre el MAE del promedio histórico en la misma serie y los mismos orígenes; menos de 1 significa que el método supera al promedio. Se resume con media geométrica y mediana; las series donde alguno de los dos MAE es cero se excluyen del cociente y se cuentan aparte. MASE usa como escala el error medio del pronóstico ingenuo a un paso de toda la serie. "Mejor/Empate/Peor" cuenta series frente al promedio histórico (empate si la diferencia es menor que 1e-9). No se usa MAPE porque la demanda mensual contiene ceros.

## Resultados por grupo (4 métodos, orígenes comunes)

### Todas las series (16699 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16699 | n/d | n/d | 1.0227 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16699 | 0.8768 | 0.9756 | 0.9239 | 9281 | 0 | 7418 | 0.5558 | 44 |
| Ingenuo (último valor) | 16699 | 0.8848 | 0.9950 | 1.0220 | 8470 | 0 | 8229 | 0.5072 | 68 |
| Media móvil de 3 meses | 16699 | 0.7806 | 0.8743 | 0.8615 | 11630 | 0 | 5069 | 0.6964 | 34 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16699 | n/d | n/d | 1.0516 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16699 | 0.9112 | 1.0090 | 0.9933 | 8070 | 30 | 8599 | 0.4833 | 51 |
| Ingenuo (último valor) | 16699 | 0.8849 | 0.9829 | 1.0182 | 8703 | 30 | 7966 | 0.5212 | 68 |
| Media móvil de 3 meses | 16699 | 0.8148 | 0.9000 | 0.9101 | 11051 | 30 | 5618 | 0.6618 | 34 |

### Clase regular (smooth) (7680 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 7680 | n/d | n/d | 0.9305 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 7680 | 0.8740 | 0.9680 | 0.8509 | 4529 | 0 | 3151 | 0.5897 | 0 |
| Ingenuo (último valor) | 7680 | 0.9920 | 1.0787 | 1.0093 | 3115 | 0 | 4565 | 0.4056 | 0 |
| Media móvil de 3 meses | 7680 | 0.8054 | 0.8838 | 0.8081 | 5523 | 0 | 2157 | 0.7191 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 7680 | n/d | n/d | 0.9569 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 7680 | 0.9132 | 1.0033 | 0.9117 | 3789 | 0 | 3891 | 0.4934 | 0 |
| Ingenuo (último valor) | 7680 | 0.9517 | 1.0410 | 0.9875 | 3446 | 0 | 4234 | 0.4487 | 0 |
| Media móvil de 3 meses | 7680 | 0.8254 | 0.8977 | 0.8456 | 5279 | 0 | 2401 | 0.6874 | 0 |

### Clase errática (erratic) (302 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 302 | n/d | n/d | 1.3547 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 302 | 0.7844 | 0.8851 | 1.0982 | 205 | 0 | 97 | 0.6788 | 0 |
| Ingenuo (último valor) | 302 | 0.6907 | 0.7433 | 1.0336 | 218 | 0 | 84 | 0.7219 | 0 |
| Media móvil de 3 meses | 302 | 0.6197 | 0.6477 | 0.9057 | 245 | 0 | 57 | 0.8113 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 302 | n/d | n/d | 1.4295 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 302 | 0.8331 | 0.9321 | 1.2235 | 187 | 0 | 115 | 0.6192 | 0 |
| Ingenuo (último valor) | 302 | 0.7354 | 0.7735 | 1.1233 | 208 | 0 | 94 | 0.6887 | 0 |
| Media móvil de 3 meses | 302 | 0.6878 | 0.7285 | 1.0313 | 229 | 0 | 73 | 0.7583 | 0 |

### Clase intermitente (intermittent) (8210 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 8210 | n/d | n/d | 1.1185 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 8210 | 0.8868 | 0.9914 | 1.0454 | 4260 | 0 | 3950 | 0.5189 | 43 |
| Ingenuo (último valor) | 8210 | 0.8093 | 0.9148 | 1.0781 | 4824 | 0 | 3386 | 0.5876 | 67 |
| Media móvil de 3 meses | 8210 | 0.7686 | 0.8714 | 0.9410 | 5507 | 0 | 2703 | 0.6708 | 33 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 8210 | n/d | n/d | 1.1416 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 8210 | 0.9157 | 1.0181 | 1.1081 | 3840 | 30 | 4340 | 0.4677 | 48 |
| Ingenuo (último valor) | 8210 | 0.8367 | 0.9311 | 1.0717 | 4777 | 30 | 3403 | 0.5819 | 67 |
| Media móvil de 3 meses | 8210 | 0.8121 | 0.9070 | 0.9958 | 5230 | 30 | 2950 | 0.6370 | 33 |

### Clase irregular (lumpy) (507 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 507 | n/d | n/d | 1.1539 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 507 | 0.8199 | 0.9674 | 1.0521 | 287 | 0 | 220 | 0.5661 | 1 |
| Ingenuo (último valor) | 507 | 0.7606 | 0.8893 | 1.0501 | 313 | 0 | 194 | 0.6174 | 1 |
| Media móvil de 3 meses | 507 | 0.7166 | 0.8229 | 0.9464 | 355 | 0 | 152 | 0.7002 | 1 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 507 | n/d | n/d | 1.2030 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 507 | 0.8579 | 1.0016 | 1.1514 | 254 | 0 | 253 | 0.5010 | 3 |
| Ingenuo (último valor) | 507 | 0.8073 | 0.9518 | 1.1129 | 272 | 0 | 235 | 0.5365 | 1 |
| Media móvil de 3 meses | 507 | 0.7805 | 0.8979 | 1.0525 | 313 | 0 | 194 | 0.6174 | 1 |

### Estacional (431 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 431 | n/d | n/d | 1.0398 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 431 | 0.9164 | 1.0327 | 0.9715 | 180 | 0 | 251 | 0.4176 | 0 |
| Ingenuo (último valor) | 431 | 0.9420 | 0.9808 | 1.0228 | 227 | 0 | 204 | 0.5267 | 0 |
| Media móvil de 3 meses | 431 | 0.8624 | 0.9400 | 0.9344 | 273 | 0 | 158 | 0.6334 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 431 | n/d | n/d | 1.0627 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 431 | 0.9571 | 1.0790 | 1.0412 | 160 | 0 | 271 | 0.3712 | 0 |
| Ingenuo (último valor) | 431 | 0.9984 | 1.1269 | 1.1129 | 151 | 0 | 280 | 0.3503 | 0 |
| Media móvil de 3 meses | 431 | 0.9492 | 1.0634 | 1.0455 | 169 | 0 | 262 | 0.3921 | 0 |

### No estacional (16268 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16268 | n/d | n/d | 1.0222 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16268 | 0.8758 | 0.9737 | 0.9228 | 9101 | 0 | 7167 | 0.5594 | 44 |
| Ingenuo (último valor) | 16268 | 0.8834 | 0.9955 | 1.0219 | 8243 | 0 | 8025 | 0.5067 | 68 |
| Media móvil de 3 meses | 16268 | 0.7785 | 0.8725 | 0.8604 | 11357 | 0 | 4911 | 0.6981 | 34 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16268 | n/d | n/d | 1.0512 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16268 | 0.9100 | 1.0071 | 0.9920 | 7910 | 30 | 8328 | 0.4862 | 51 |
| Ingenuo (último valor) | 16268 | 0.8821 | 0.9788 | 1.0164 | 8552 | 30 | 7686 | 0.5257 | 68 |
| Media móvil de 3 meses | 16268 | 0.8115 | 0.8962 | 0.9082 | 10882 | 30 | 5356 | 0.6689 | 34 |

## Submuestra de series con al menos 24 meses (5 métodos, mismos orígenes desde el mes 12)

Series en la submuestra: 16699.

### Todas las series (16699 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16699 | n/d | n/d | 1.0473 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16699 | 0.8564 | 0.9661 | 0.9264 | 9635 | 0 | 7064 | 0.5770 | 124 |
| Ingenuo (último valor) | 16699 | 0.8730 | 0.9880 | 1.0319 | 8662 | 0 | 8037 | 0.5187 | 211 |
| Media móvil de 3 meses | 16699 | 0.7677 | 0.8684 | 0.8640 | 11654 | 0 | 5045 | 0.6979 | 163 |
| Ingenuo estacional (12 meses) | 16699 | 1.0661 | 1.1159 | 1.1744 | 5614 | 0 | 11085 | 0.3362 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16699 | n/d | n/d | 1.0696 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16699 | 0.8865 | 0.9926 | 0.9830 | 8656 | 30 | 8013 | 0.5184 | 146 |
| Ingenuo (último valor) | 16699 | 0.8739 | 0.9796 | 1.0209 | 8840 | 30 | 7829 | 0.5294 | 211 |
| Media móvil de 3 meses | 16699 | 0.8015 | 0.8956 | 0.9118 | 11082 | 30 | 5587 | 0.6636 | 163 |
| Ingenuo estacional (12 meses) | 16699 | 1.0350 | 1.0947 | 1.1639 | 6017 | 30 | 10652 | 0.3603 | 13 |

### Clase regular (smooth) (7680 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 7680 | n/d | n/d | 0.9419 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 7680 | 0.8611 | 0.9565 | 0.8511 | 4744 | 0 | 2936 | 0.6177 | 0 |
| Ingenuo (último valor) | 7680 | 0.9799 | 1.0706 | 1.0157 | 3234 | 0 | 4446 | 0.4211 | 0 |
| Media móvil de 3 meses | 7680 | 0.7952 | 0.8766 | 0.8130 | 5522 | 0 | 2158 | 0.7190 | 0 |
| Ingenuo estacional (12 meses) | 7680 | 1.1383 | 1.2003 | 1.1315 | 1784 | 0 | 5896 | 0.2323 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 7680 | n/d | n/d | 0.9681 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 7680 | 0.8922 | 0.9840 | 0.9028 | 4110 | 0 | 3570 | 0.5352 | 0 |
| Ingenuo (último valor) | 7680 | 0.9430 | 1.0355 | 0.9933 | 3519 | 0 | 4161 | 0.4582 | 0 |
| Media móvil de 3 meses | 7680 | 0.8158 | 0.8921 | 0.8484 | 5273 | 0 | 2407 | 0.6866 | 0 |
| Ingenuo estacional (12 meses) | 7680 | 1.1063 | 1.1724 | 1.1303 | 2041 | 0 | 5639 | 0.2658 | 0 |

### Clase errática (erratic) (302 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 302 | n/d | n/d | 1.4174 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 302 | 0.7712 | 0.8724 | 1.1328 | 212 | 0 | 90 | 0.7020 | 0 |
| Ingenuo (último valor) | 302 | 0.6722 | 0.7228 | 1.0669 | 221 | 0 | 81 | 0.7318 | 0 |
| Media móvil de 3 meses | 302 | 0.6035 | 0.6368 | 0.9345 | 247 | 0 | 55 | 0.8179 | 0 |
| Ingenuo estacional (12 meses) | 302 | 1.0487 | 1.1114 | 1.5316 | 122 | 0 | 180 | 0.4040 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 302 | n/d | n/d | 1.4822 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 302 | 0.8156 | 0.9134 | 1.2467 | 193 | 0 | 109 | 0.6391 | 0 |
| Ingenuo (último valor) | 302 | 0.7177 | 0.7665 | 1.1567 | 208 | 0 | 94 | 0.6887 | 0 |
| Media móvil de 3 meses | 302 | 0.6719 | 0.7269 | 1.0691 | 228 | 0 | 74 | 0.7550 | 0 |
| Ingenuo estacional (12 meses) | 302 | 1.0102 | 1.0720 | 1.5390 | 125 | 0 | 177 | 0.4139 | 0 |

### Clase intermitente (intermittent) (8210 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 8210 | n/d | n/d | 1.1413 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 8210 | 0.8584 | 0.9845 | 1.0575 | 4395 | 0 | 3815 | 0.5353 | 115 |
| Ingenuo (último valor) | 8210 | 0.7964 | 0.9124 | 1.0905 | 4897 | 0 | 3313 | 0.5965 | 200 |
| Media móvil de 3 meses | 8210 | 0.7543 | 0.8671 | 0.9591 | 5534 | 0 | 2676 | 0.6741 | 156 |
| Ingenuo estacional (12 meses) | 8210 | 1.0048 | 1.0433 | 1.1897 | 3500 | 0 | 4710 | 0.4263 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 8210 | n/d | n/d | 1.1584 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 8210 | 0.8866 | 1.0039 | 1.1172 | 4089 | 30 | 4091 | 0.4981 | 134 |
| Ingenuo (último valor) | 8210 | 0.8230 | 0.9284 | 1.0679 | 4839 | 30 | 3341 | 0.5894 | 200 |
| Media móvil de 3 meses | 8210 | 0.7969 | 0.9045 | 1.0114 | 5267 | 30 | 2913 | 0.6415 | 156 |
| Ingenuo estacional (12 meses) | 8210 | 0.9766 | 1.0289 | 1.2321 | 3636 | 30 | 4544 | 0.4429 | 12 |

### Clase irregular (lumpy) (507 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 507 | n/d | n/d | 1.2060 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 507 | 0.8067 | 0.9657 | 1.0829 | 284 | 0 | 223 | 0.5602 | 9 |
| Ingenuo (último valor) | 507 | 0.7534 | 0.8920 | 1.0997 | 310 | 0 | 197 | 0.6114 | 11 |
| Media móvil de 3 meses | 507 | 0.6867 | 0.8216 | 0.9809 | 351 | 0 | 156 | 0.6923 | 7 |
| Ingenuo estacional (12 meses) | 507 | 1.0403 | 1.0769 | 1.3127 | 208 | 0 | 299 | 0.4103 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 507 | n/d | n/d | 1.2449 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 507 | 0.8418 | 0.9939 | 1.1558 | 264 | 0 | 243 | 0.5207 | 12 |
| Ingenuo (último valor) | 507 | 0.7984 | 0.9618 | 1.1518 | 274 | 0 | 233 | 0.5404 | 11 |
| Media móvil de 3 meses | 507 | 0.7455 | 0.8976 | 1.0509 | 314 | 0 | 193 | 0.6193 | 7 |
| Ingenuo estacional (12 meses) | 507 | 0.9801 | 1.0437 | 1.2938 | 215 | 0 | 292 | 0.4241 | 1 |

### Estacional (431 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 431 | n/d | n/d | 1.0463 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 431 | 0.8951 | 1.0200 | 0.9511 | 191 | 0 | 240 | 0.4432 | 0 |
| Ingenuo (último valor) | 431 | 0.9205 | 0.9740 | 1.0289 | 227 | 0 | 204 | 0.5267 | 0 |
| Media móvil de 3 meses | 431 | 0.8468 | 0.9392 | 0.9218 | 271 | 0 | 160 | 0.6288 | 0 |
| Ingenuo estacional (12 meses) | 431 | 0.7975 | 0.8422 | 0.8922 | 326 | 0 | 105 | 0.7564 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 431 | n/d | n/d | 1.0630 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 431 | 0.9123 | 1.0427 | 1.0171 | 181 | 0 | 250 | 0.4200 | 0 |
| Ingenuo (último valor) | 431 | 0.9757 | 1.1265 | 1.1328 | 162 | 0 | 269 | 0.3759 | 0 |
| Media móvil de 3 meses | 431 | 0.9288 | 1.0658 | 1.0633 | 178 | 0 | 253 | 0.4130 | 0 |
| Ingenuo estacional (12 meses) | 431 | 0.7817 | 0.8285 | 0.8977 | 332 | 0 | 99 | 0.7703 | 0 |

### No estacional (16268 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16268 | n/d | n/d | 1.0473 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16268 | 0.8554 | 0.9647 | 0.9254 | 9444 | 0 | 6824 | 0.5805 | 124 |
| Ingenuo (último valor) | 16268 | 0.8717 | 0.9886 | 1.0320 | 8435 | 0 | 7833 | 0.5185 | 211 |
| Media móvil de 3 meses | 16268 | 0.7657 | 0.8664 | 0.8631 | 11383 | 0 | 4885 | 0.6997 | 163 |
| Ingenuo estacional (12 meses) | 16268 | 1.0743 | 1.1233 | 1.1853 | 5288 | 0 | 10980 | 0.3251 | 0 |

Horizonte 3 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16268 | n/d | n/d | 1.0697 | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16268 | 0.8858 | 0.9916 | 0.9822 | 8475 | 30 | 7763 | 0.5210 | 146 |
| Ingenuo (último valor) | 16268 | 0.8713 | 0.9763 | 1.0197 | 8678 | 30 | 7560 | 0.5334 | 211 |
| Media móvil de 3 meses | 16268 | 0.7983 | 0.8921 | 0.9094 | 10904 | 30 | 5334 | 0.6703 | 163 |
| Ingenuo estacional (12 meses) | 16268 | 1.0428 | 1.1025 | 1.1720 | 5685 | 30 | 10553 | 0.3495 | 13 |

## Selección en línea (horizonte 1)

En cada origen desde el mes 9 se aplica la regla de la aplicación solo con los errores ya observados (regla: lineal si MAE lineal <= (1 - margen) x MAE del promedio, con al menos 3 orígenes). La regla vigente usa margen de 5 %; 0 % y 10 % se muestran como sensibilidad.

### Todas las series (16699 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16699 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16699 | 0.8663 | 0.9708 | n/d | 9492 | 0 | 7207 | 0.5684 | 79 |
| Selección en línea (margen 0 %) | 16699 | 0.8463 | 1.0000 | n/d | 8379 | 1388 | 6932 | 0.5018 | 79 |
| Selección en línea (margen 5 %) | 16699 | 0.8522 | 1.0000 | n/d | 8139 | 4150 | 4410 | 0.4874 | 79 |
| Selección en línea (margen 10 %) | 16699 | 0.8617 | 1.0000 | n/d | 7589 | 5524 | 3586 | 0.4545 | 77 |

### Clase regular (smooth) (7680 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 7680 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 7680 | 0.8675 | 0.9607 | n/d | 4669 | 0 | 3011 | 0.6079 | 0 |
| Selección en línea (margen 0 %) | 7680 | 0.8695 | 0.9914 | n/d | 3968 | 790 | 2922 | 0.5167 | 0 |
| Selección en línea (margen 5 %) | 7680 | 0.8768 | 1.0000 | n/d | 3739 | 1387 | 2554 | 0.4868 | 0 |
| Selección en línea (margen 10 %) | 7680 | 0.8860 | 1.0000 | n/d | 3473 | 2006 | 2201 | 0.4522 | 0 |

### Clase errática (erratic) (302 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 302 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 302 | 0.7772 | 0.8769 | n/d | 211 | 0 | 91 | 0.6987 | 0 |
| Selección en línea (margen 0 %) | 302 | 0.7712 | 0.8947 | n/d | 197 | 17 | 88 | 0.6523 | 0 |
| Selección en línea (margen 5 %) | 302 | 0.7799 | 0.9161 | n/d | 192 | 28 | 82 | 0.6358 | 0 |
| Selección en línea (margen 10 %) | 302 | 0.7905 | 0.9477 | n/d | 188 | 45 | 69 | 0.6225 | 0 |

### Clase intermitente (intermittent) (8210 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 8210 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 8210 | 0.8719 | 0.9874 | n/d | 4324 | 0 | 3886 | 0.5267 | 74 |
| Selección en línea (margen 0 %) | 8210 | 0.8312 | 1.0000 | n/d | 3949 | 560 | 3701 | 0.4810 | 74 |
| Selección en línea (margen 5 %) | 8210 | 0.8360 | 1.0000 | n/d | 3928 | 2650 | 1632 | 0.4784 | 74 |
| Selección en línea (margen 10 %) | 8210 | 0.8457 | 1.0000 | n/d | 3651 | 3347 | 1212 | 0.4447 | 72 |

### Clase irregular (lumpy) (507 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 507 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 507 | 0.8155 | 0.9677 | n/d | 288 | 0 | 219 | 0.5680 | 5 |
| Selección en línea (margen 0 %) | 507 | 0.7899 | 0.9878 | n/d | 265 | 21 | 221 | 0.5227 | 5 |
| Selección en línea (margen 5 %) | 507 | 0.7952 | 0.9881 | n/d | 280 | 85 | 142 | 0.5523 | 5 |
| Selección en línea (margen 10 %) | 507 | 0.8033 | 0.9940 | n/d | 277 | 126 | 104 | 0.5464 | 5 |

### Estacional (431 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 431 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 431 | 0.9091 | 1.0282 | n/d | 181 | 0 | 250 | 0.4200 | 0 |
| Selección en línea (margen 0 %) | 431 | 0.8699 | 1.0000 | n/d | 165 | 58 | 208 | 0.3828 | 0 |
| Selección en línea (margen 5 %) | 431 | 0.8731 | 1.0000 | n/d | 153 | 135 | 143 | 0.3550 | 0 |
| Selección en línea (margen 10 %) | 431 | 0.8799 | 1.0000 | n/d | 149 | 166 | 116 | 0.3457 | 0 |

### No estacional (16268 series)

Horizonte 1 mes(es) adelante.

| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Promedio histórico (referencia) | 16268 | n/d | n/d | n/d | n/d | n/d | n/d | n/d | n/d |
| Regresión lineal | 16268 | 0.8652 | 0.9690 | n/d | 9311 | 0 | 6957 | 0.5724 | 79 |
| Selección en línea (margen 0 %) | 16268 | 0.8456 | 0.9983 | n/d | 8214 | 1330 | 6724 | 0.5049 | 79 |
| Selección en línea (margen 5 %) | 16268 | 0.8517 | 1.0000 | n/d | 7986 | 4015 | 4267 | 0.4909 | 79 |
| Selección en línea (margen 10 %) | 16268 | 0.8612 | 1.0000 | n/d | 7440 | 5358 | 3470 | 0.4573 | 77 |

## Sensibilidad del margen: series que elegirían regresión lineal

Series con al menos 9 meses, regla aplicada al backtest completo (horizonte 1).

| Grupo | Series elegibles | Lineal con margen 0 % | Lineal con margen 5 % (regla) | Lineal con margen 10 % |
|---|---:|---:|---:|---:|
| Todas las series | 16699 | 9281 | 7573 | 6289 |
| Clase regular (smooth) | 7680 | 4529 | 3560 | 2869 |
| Clase errática (erratic) | 302 | 205 | 183 | 162 |
| Clase intermitente (intermittent) | 8210 | 4260 | 3588 | 3051 |
| Clase irregular (lumpy) | 507 | 287 | 242 | 207 |
