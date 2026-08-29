# ADR-0008: Gráfica de proyección con componentes shadcn-vue chart y Unovis

* Estado: Aceptada

## Contexto

La etapa 06b agrega un reporte de proyección de demanda (`reports/MedicationDemand.vue`) que debe mostrar, para un
medicamento, tres series mensuales en una sola gráfica: el consumo histórico, la línea ajustada por regresión
lineal simple (mínimos cuadrados ordinarios, OLS) y la proyección a futuro. La proyección debe distinguirse
visualmente de las otras dos series, y la proyección en sí es indicativa/demostrativa, no una garantía de compra
(ver [ADR-0007](0007-inventario-de-medicamentos-y-libro-de-movimientos.md)). El proyecto ya usa componentes
shadcn-vue (Reka UI + Tailwind) en el resto de la interfaz, pero hasta esta etapa ninguno de ellos era una gráfica.

## Decisión

1. **Componentes de gráfica vendorizados con `shadcn-vue add chart`.** Se instalan en
   `resources/js/components/ui/chart/` mediante el CLI `npx shadcn-vue add chart`, siguiendo el mismo patrón que el
   resto de los componentes `ui/*` del proyecto: código fuente propio, no una dependencia opaca. La única
   dependencia nueva que este comando autoriza es `@unovis/vue` y `@unovis/ts` en `^1.7`, el motor de gráficas que
   esos componentes envuelven.
2. **Tres series en una sola gráfica**, cada una un `VisLine` independiente sobre el mismo eje X numérico (índice de
   mes) y el mismo eje Y: histórico y ajuste con trazo sólido, proyección con trazo discontinuo
   (`line-dash-array="[6, 4]"`), la única diferencia visual entre ellas.
3. **Proyección indicativa, no una librería estadística.** El ajuste OLS se calcula en PHP puro
   (`App\Services\Inventory\MedicationDemandProjection`), sin ninguna librería de estadística; el frontend solo
   grafica los puntos que el backend ya calculó. El horizonte de proyección es fijo en 3 meses; el reporte no ofrece
   selector de horizonte.
4. **Reorganización de datos aislada de Unovis.** `lib/demandProjectionChart.ts` (`buildDemandChartPoints`,
   `formatMonthLabel`) fusiona las tres series del backend en una sola lista de puntos por mes, sin importar nada de
   `@unovis/vue` ni de los componentes `ui/chart`. Esto permite probar la lógica de fusión con Vitest normal, sin
   necesitar que Unovis renderice nada.

## Alternativas consideradas

- **Chart.js / vue-chartjs**: biblioteca de gráficas madura y ampliamente usada, pero habría introducido un segundo
  patrón de gráficas distinto al de los componentes shadcn-vue ya presentes en el proyecto (Reka UI + Tailwind).
- **ECharts**: muy completo, pero es una dependencia considerablemente más pesada de lo que este único reporte
  necesita.
- **SVG a mano**: sin dependencias nuevas, pero reimplementaría ejes, tooltips y grosor de línea que Unovis ya
  resuelve, por un ahorro de bundle marginal frente a un único componente vendorizado.
- **Librería estadística en PHP o JavaScript** para el ajuste OLS: innecesaria porque la fórmula (`b = Sxy/Sxx`,
  `a = ȳ - b·x̄`, `R² = 1 - SSres/SStot`) es simple de implementar y probar directamente, sin agregar una
  dependencia solo para una regresión lineal simple.

## Consecuencias

### Positivas

- La gráfica sigue el mismo patrón de componentes vendorizados (`components/ui/*`) que el resto de la interfaz, en
  vez de introducir una convención de gráficas distinta.
- `lib/demandProjectionChart.ts` se prueba con Vitest normal, sin necesitar que Unovis renderice nada en `jsdom`.
- El ajuste OLS no depende de ninguna librería estadística externa: es una función pura, corta y fácil de auditar.

### Negativas

- `@unovis/vue`/`@unovis/ts` agregan tamaño al bundle de la página del reporte
  (`MedicationDemand-*.js`, ~210 kB / ~68 kB gzip, el primer chunk que realmente incluye Unovis).
- Unovis no renderiza en `jsdom`: `DemandProjectionChart.test.ts` no puede verificar la salida SVG real y en su
  lugar sustituye (`stub`) los componentes de `@unovis/vue`, verificando únicamente las props que el componente les
  pasa (tres líneas, `line-dash-array` solo en la de proyección).
- El CLI `shadcn-vue add chart` no instaló `@unovis/vue`/`@unovis/ts` automáticamente en la primera corrida e
  intentó además actualizar `reka-ui` a una versión distinta a la ya fijada en el proyecto; ese cambio de versión
  se revirtió y las dependencias de Unovis se agregaron de forma explícita.

### Compromisos aceptados

- La proyección de demanda es una demostración indicativa, con un horizonte fijo de 3 meses y sin selector de
  horizonte, estacionalidad ni validación de datos atípicos; extenderla queda fuera del alcance de esta etapa (ver
  [medication-inventory.md](../features/medication-inventory.md)).
