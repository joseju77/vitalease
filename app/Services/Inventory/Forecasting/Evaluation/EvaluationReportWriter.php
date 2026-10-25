<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\LinearTrendForecaster;
use App\Services\Inventory\Forecasting\MovingAverageForecaster;
use App\Services\Inventory\Forecasting\NaiveForecaster;
use App\Services\Inventory\Forecasting\SeasonalNaiveForecaster;
use RuntimeException;

/**
 * Writes the deterministic evaluation outputs of one dataset:
 * `series.csv`, `summary.json`, `summary.md` (Spanish) and `run.json`, the
 * only file holding non-deterministic data (the run date). Rows are already
 * sorted by anonymous index, numbers are rounded to 4 decimals and no
 * practice code or monthly value is ever written.
 */
final class EvaluationReportWriter
{
    public const ATTRIBUTION = 'Contains NHSBSA data, OGL 3.0 (Open Government Licence v3.0)';

    private const MAIN_METHODS = [
        LinearTrendForecaster::NAME,
        HistoricalMeanForecaster::NAME,
        NaiveForecaster::NAME,
        MovingAverageForecaster::NAME,
    ];

    private const SUBSET_METHODS = [
        ...self::MAIN_METHODS,
        SeasonalNaiveForecaster::NAME,
    ];

    private const METHOD_LABELS = [
        LinearTrendForecaster::NAME => 'Regresión lineal',
        HistoricalMeanForecaster::NAME => 'Promedio histórico (referencia)',
        NaiveForecaster::NAME => 'Ingenuo (último valor)',
        MovingAverageForecaster::NAME => 'Media móvil de 3 meses',
        SeasonalNaiveForecaster::NAME => 'Ingenuo estacional (12 meses)',
    ];

    private const GROUP_LABELS = [
        'all' => 'Todas las series',
        'class:smooth' => 'Clase regular (smooth)',
        'class:erratic' => 'Clase errática (erratic)',
        'class:intermittent' => 'Clase intermitente (intermittent)',
        'class:lumpy' => 'Clase irregular (lumpy)',
        'class:no_demand' => 'Sin demanda',
        'seasonality:seasonal' => 'Estacional',
        'seasonality:not_seasonal' => 'No estacional',
        'seasonality:not_assessed' => 'Estacionalidad no evaluada (menos de 24 meses)',
    ];

    /** @var resource|null */
    private $seriesHandle = null;

    private string $directory = '';

    /** @var list<int> */
    private array $horizons = [];

    /**
     * Opens `series.csv` and writes its header; rows are streamed with
     * `appendSeries()` so memory stays flat for thousands of series.
     *
     * @param  list<int>  $horizons
     */
    public function begin(string $directory, array $horizons): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the output directory {$directory}.");
        }

        $handle = fopen($directory.'/series.csv', 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write {$directory}/series.csv.");
        }

        $this->seriesHandle = $handle;
        $this->directory = $directory;
        $this->horizons = $horizons;

        $this->writeLine($this->csvHeader());
    }

    public function appendSeries(SeriesEvaluation $evaluation): void
    {
        $this->writeLine($this->csvRow($evaluation));
    }

    /**
     * Closes `series.csv` and writes the summary and run files.
     *
     * @param  'nhs'|'synthetic'  $dataset
     * @param  array<string, mixed>  $parameters  Evaluation parameters (no run date).
     * @param  array<string, mixed>  $provenance  Source description, counts and checksums.
     * @param  array<string, mixed>  $aggregates  Output of `EvaluationAggregator::summarize()`.
     */
    public function finish(string $dataset, array $parameters, array $provenance, array $aggregates): void
    {
        if ($this->seriesHandle === null) {
            throw new RuntimeException('The report was not started.');
        }

        fclose($this->seriesHandle);
        $this->seriesHandle = null;

        $summary = [
            'dataset' => $dataset,
            'attribution' => $dataset === 'nhs' ? self::ATTRIBUTION : null,
            'parameters' => $parameters,
            'provenance' => $provenance,
            'aggregates' => $aggregates,
        ];

        $this->put($this->directory.'/summary.json', $this->json($summary));
        $this->put($this->directory.'/summary.md', $this->summaryMarkdown($dataset, $parameters, $provenance, $aggregates));
        $this->put($this->directory.'/run.json', $this->json([
            'run_at' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
        ]));
    }

    private function writeLine(string $line): void
    {
        if ($this->seriesHandle === null || fwrite($this->seriesHandle, $line."\n") === false) {
            throw new RuntimeException("Cannot write {$this->directory}/series.csv.");
        }
    }

    /**
     * @return list<string>
     */
    private function onlineMethods(): array
    {
        return [LinearTrendForecaster::NAME, HistoricalMeanForecaster::NAME, ...array_map(EvaluationRunner::marginKey(...), EvaluationRunner::MARGINS)];
    }

    private function csvHeader(): string
    {
        $header = ['index', 'class', 'seasonality', 'n_months', 'nonzero_months', 'adi', 'cv2'];

        foreach ($this->horizons as $horizon) {
            $header[] = "origins_h{$horizon}";
            foreach (['mae', 'rmse', 'mase'] as $metric) {
                foreach (self::MAIN_METHODS as $method) {
                    $header[] = "{$metric}_{$method}_h{$horizon}";
                }
            }
        }

        foreach (EvaluationRunner::MARGINS as $margin) {
            $header[] = 'selects_linear_'.str_replace('selected_', '', EvaluationRunner::marginKey($margin));
        }

        $header[] = 'online_origins';
        foreach ($this->onlineMethods() as $method) {
            $header[] = "online_mae_{$method}";
        }

        foreach ($this->horizons as $horizon) {
            foreach (self::SUBSET_METHODS as $method) {
                $header[] = "subset_mae_{$method}_h{$horizon}";
            }
        }

        return implode(',', $header);
    }

    private function csvRow(SeriesEvaluation $evaluation): string
    {
        $row = [
            $evaluation->label,
            $evaluation->pattern->classification,
            $evaluation->pattern->seasonality,
            $evaluation->months,
            $evaluation->nonZeroMonths,
            $evaluation->pattern->adi,
            $evaluation->pattern->cv2,
        ];

        foreach ($this->horizons as $horizon) {
            $row[] = $evaluation->origins[$horizon] ?? null;
            foreach (['mae', 'rmse', 'mase'] as $metric) {
                foreach (self::MAIN_METHODS as $method) {
                    $row[] = $evaluation->metrics[$horizon][$method][$metric] ?? null;
                }
            }
        }

        foreach (EvaluationRunner::MARGINS as $margin) {
            $row[] = $evaluation->selection === null ? null : ($evaluation->selection['selects_linear'][EvaluationRunner::marginKey($margin)] ? 1 : 0);
        }

        $online = $evaluation->selection['online'] ?? null;
        $row[] = $online['origins'] ?? null;
        foreach ($this->onlineMethods() as $method) {
            $row[] = $online['mae'][$method] ?? null;
        }

        foreach ($this->horizons as $horizon) {
            foreach (self::SUBSET_METHODS as $method) {
                $row[] = $evaluation->subset[$horizon][$method]['mae'] ?? null;
            }
        }

        return implode(',', array_map($this->cell(...), $row));
    }

    private function cell(int|float|string|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) => number_format($value, 4, '.', ''),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $aggregates
     */
    private function summaryMarkdown(string $dataset, array $parameters, array $provenance, array $aggregates): string
    {
        $title = $dataset === 'nhs'
            ? 'Evaluación de la proyección de demanda con recetas del NHS de Inglaterra'
            : 'Evaluación de la proyección de demanda con series sintéticas';

        $lines = ["# {$title}", ''];

        if ($dataset === 'nhs') {
            $lines[] = 'Consumo real público de otro contexto: recetas dispensadas por consultorios de medicina general del NHS de Inglaterra (NHS English Prescribing Dataset). No es consumo de la Unidad.';
            $lines[] = '';
            $lines[] = '> '.self::ATTRIBUTION.'.';
        } else {
            $lines[] = 'Series generadas de forma determinista con una semilla fija (8 patrones x 30 réplicas). No son datos reales.';
        }

        $lines = [...$lines, '', '## Parámetros', '', ...$this->bullets($parameters), '', '## Conjunto de series', '', ...$this->bullets($provenance), ''];

        $lines[] = '## Cómo leer las tablas';
        $lines[] = '';
        $lines[] = 'RelMAE es el MAE del método dividido entre el MAE del promedio histórico en la misma serie y los mismos orígenes; menos de 1 significa que el método supera al promedio. Se resume con media geométrica y mediana; las series donde alguno de los dos MAE es cero se excluyen del cociente y se cuentan aparte. MASE usa como escala el error medio del pronóstico ingenuo a un paso de toda la serie. "Mejor/Empate/Peor" cuenta series frente al promedio histórico (empate si la diferencia es menor que 1e-9). No se usa MAPE porque la demanda mensual contiene ceros.';
        $lines[] = '';

        $lines[] = '## Resultados por grupo (4 métodos, orígenes comunes)';
        $lines[] = '';
        foreach ($aggregates['main'] as $group => $data) {
            array_push($lines, ...$this->groupSection($group, $data, self::MAIN_METHODS));
        }

        $subset = $aggregates['subset'];
        $lines[] = "## Submuestra de series con al menos {$subset['min_months']} meses (5 métodos, mismos orígenes desde el mes 12)";
        $lines[] = '';
        $lines[] = "Series en la submuestra: {$subset['series']}.";
        $lines[] = '';
        foreach ($subset['groups'] as $group => $data) {
            array_push($lines, ...$this->groupSection($group, $data, self::SUBSET_METHODS));
        }

        $online = $aggregates['online_selection'];
        $marginKeys = array_map(EvaluationRunner::marginKey(...), $online['margins']);
        $lines[] = '## Selección en línea (horizonte 1)';
        $lines[] = '';
        $lines[] = 'En cada origen desde el mes 9 se aplica la regla de la aplicación solo con los errores ya observados (regla: lineal si MAE lineal <= (1 - margen) x MAE del promedio, con al menos 3 orígenes). La regla vigente usa margen de 5 %; 0 % y 10 % se muestran como sensibilidad.';
        $lines[] = '';
        foreach ($online['groups'] as $group => $data) {
            array_push($lines, ...$this->groupSection($group, $data, [LinearTrendForecaster::NAME, ...$marginKeys]));
        }

        $lines[] = '## Sensibilidad del margen: series que elegirían regresión lineal';
        $lines[] = '';
        $lines[] = 'Series con al menos 9 meses, regla aplicada al backtest completo (horizonte 1).';
        $lines[] = '';
        $lines[] = '| Grupo | Series elegibles | Lineal con margen 0 % | Lineal con margen 5 % (regla) | Lineal con margen 10 % |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ($aggregates['selection_sensitivity'] as $group => $row) {
            $lines[] = sprintf('| %s | %d | %d | %d | %d |', self::GROUP_LABELS[$group] ?? $group, $row['eligible_series'], $row['linear_margin_0'], $row['linear_margin_5'], $row['linear_margin_10']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{series: int, horizons: array<string, array<string, array<string, mixed>>>}  $data
     * @param  list<string>  $methods
     * @return list<string>
     */
    private function groupSection(string $group, array $data, array $methods): array
    {
        $lines = ['### '.(self::GROUP_LABELS[$group] ?? $group)." ({$data['series']} series)", ''];

        foreach ($data['horizons'] as $horizon => $entries) {
            $lines[] = 'Horizonte '.substr($horizon, 1).' mes(es) adelante.';
            $lines[] = '';
            $lines[] = '| Método | Series | RelMAE media geométrica | RelMAE mediana | MASE mediana | Mejor | Empate | Peor | Proporción mejor | Excluidas (MAE 0) |';
            $lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|';

            foreach (array_unique([HistoricalMeanForecaster::NAME, ...$methods]) as $method) {
                if (! isset($entries[$method])) {
                    continue;
                }

                $entry = $entries[$method];
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                    $this->methodLabel($method),
                    $entry['series'],
                    $this->display($entry['rel_mae_geometric_mean'] ?? null),
                    $this->display($entry['rel_mae_median'] ?? null),
                    $this->display($entry['mase_median']),
                    $this->display($entry['better'] ?? null),
                    $this->display($entry['tie'] ?? null),
                    $this->display($entry['worse'] ?? null),
                    $this->display($entry['win_share'] ?? null),
                    $this->display($entry['rel_mae_excluded_zero_mae'] ?? null),
                );
            }

            $lines[] = '';
        }

        return $lines;
    }

    private function methodLabel(string $method): string
    {
        if (isset(self::METHOD_LABELS[$method])) {
            return self::METHOD_LABELS[$method];
        }

        return 'Selección en línea (margen '.substr($method, strlen('selected_margin_')).' %)';
    }

    private function display(int|float|null $value): string
    {
        return match (true) {
            $value === null => 'n/d',
            is_float($value) => number_format($value, 4, '.', ''),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function bullets(array $values, string $prefix = ''): array
    {
        $lines = [];

        foreach ($values as $key => $value) {
            $name = $prefix.$key;

            if (is_array($value) && array_is_list($value) && array_filter($value, 'is_array') === []) {
                $lines[] = '- `'.$name.'`: '.implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));

                continue;
            }

            if (is_array($value)) {
                array_push($lines, ...$this->bullets($value, $name.'.'));

                continue;
            }

            $lines[] = '- `'.$name.'`: '.match (true) {
                $value === null => 'n/d',
                is_bool($value) => $value ? 'sí' : 'no',
                default => (string) $value,
            };
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }
    }
}
