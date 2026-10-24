<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use App\Services\Inventory\Forecasting\DemandPattern;
use App\Services\Inventory\Forecasting\ErrorMetrics;
use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\MethodSelector;

/**
 * Aggregates per-series results by Syntetos-Boylan class and seasonality.
 *
 * Every method is compared with the historical mean, series by series:
 * RelMAE = MAE_method / MAE_mean, summarised by geometric mean and median
 * (series where either MAE is zero are excluded from the ratio and counted),
 * median MASE, and the share of series where the method is better, tied
 * (|difference| < 1e-9) or worse than the mean.
 */
final class EvaluationAggregator
{
    private const CLASS_ORDER = [
        DemandPattern::SMOOTH,
        DemandPattern::ERRATIC,
        DemandPattern::INTERMITTENT,
        DemandPattern::LUMPY,
        DemandPattern::NO_DEMAND,
    ];

    private const SEASONALITY_ORDER = [
        DemandPattern::SEASONAL,
        DemandPattern::NOT_SEASONAL,
        DemandPattern::NOT_ASSESSED,
    ];

    /**
     * Consumes the evaluations once, keeping only running accumulators.
     *
     * @param  iterable<SeriesEvaluation>  $evaluations
     * @return array{
     *     series: int,
     *     main: array<string, mixed>,
     *     subset: array{series: int, min_months: int, groups: array<string, mixed>},
     *     online_selection: array{margins: list<float>, groups: array<string, mixed>},
     *     selection_sensitivity: array<string, mixed>
     * }
     */
    public function summarize(iterable $evaluations): array
    {
        $main = ['counts' => [], 'accumulators' => []];
        $subset = ['counts' => [], 'accumulators' => []];
        $online = ['counts' => [], 'accumulators' => []];
        $sensitivity = [];
        $total = 0;

        foreach ($evaluations as $evaluation) {
            $total++;

            $this->accumulate($main, $evaluation, $evaluation->metrics);

            if ($evaluation->subset !== null) {
                $this->accumulate($subset, $evaluation, $evaluation->subset);
            }

            $onlineMetrics = $this->onlineMetrics($evaluation);
            if ($onlineMetrics !== null) {
                $this->accumulate($online, $evaluation, $onlineMetrics);
            }

            $this->accumulateSensitivity($sensitivity, $evaluation);
        }

        return [
            'series' => $total,
            'main' => $this->groups($main),
            'subset' => [
                'series' => $subset['counts']['all'] ?? 0,
                'min_months' => EvaluationRunner::SUBSET_MIN_MONTHS,
                'groups' => $this->groups($subset),
            ],
            'online_selection' => [
                'margins' => EvaluationRunner::MARGINS,
                'groups' => $this->groups($online),
            ],
            'selection_sensitivity' => $this->finalizeSensitivity($sensitivity),
        ];
    }

    /**
     * @return array<int, array<string, array{mae: ?float, mase: ?float}>>|null
     */
    private function onlineMetrics(SeriesEvaluation $evaluation): ?array
    {
        $online = $evaluation->selection['online'] ?? null;

        if ($online === null) {
            return null;
        }

        $methods = [];
        foreach ($online['mae'] as $method => $mae) {
            $methods[$method] = ['mae' => $mae, 'mase' => null];
        }

        return [1 => $methods];
    }

    /**
     * @param  array{counts: array<string, int>, accumulators: array<string, array<int, array<string, array<string, mixed>>>>}  $state
     * @param  array<int, array<string, array{mae: ?float, mase: ?float}>>  $metrics
     */
    private function accumulate(array &$state, SeriesEvaluation $evaluation, array $metrics): void
    {
        $groups = [
            'all',
            'class:'.$evaluation->pattern->classification,
            'seasonality:'.$evaluation->pattern->seasonality,
        ];

        foreach ($groups as $group) {
            $state['counts'][$group] = ($state['counts'][$group] ?? 0) + 1;

            foreach ($metrics as $horizon => $methods) {
                $baseline = $methods[HistoricalMeanForecaster::NAME]['mae'] ?? null;

                foreach ($methods as $method => $values) {
                    $accumulator = &$state['accumulators'][$group][$horizon][$method];
                    $accumulator ??= ['series' => 0, 'rel' => [], 'excluded' => 0, 'mase' => [], 'better' => 0, 'tie' => 0, 'worse' => 0];

                    if ($values['mae'] !== null) {
                        $accumulator['series']++;
                    }

                    if ($values['mase'] !== null) {
                        $accumulator['mase'][] = $values['mase'];
                    }

                    if ($method !== HistoricalMeanForecaster::NAME && $values['mae'] !== null && $baseline !== null) {
                        if (ErrorMetrics::isTie($values['mae'], $baseline)) {
                            $accumulator['tie']++;
                        } elseif ($values['mae'] < $baseline) {
                            $accumulator['better']++;
                        } else {
                            $accumulator['worse']++;
                        }

                        if ($values['mae'] > 0.0 && $baseline > 0.0) {
                            $accumulator['rel'][] = $values['mae'] / $baseline;
                        } else {
                            $accumulator['excluded']++;
                        }
                    }

                    unset($accumulator);
                }
            }
        }
    }

    /**
     * @param  array{counts: array<string, int>, accumulators: array<string, array<int, array<string, array<string, mixed>>>>}  $state
     * @return array<string, mixed>
     */
    private function groups(array $state): array
    {
        $groups = [];

        foreach ($this->orderedGroups(array_keys($state['counts'])) as $group) {
            $horizons = [];
            $byHorizon = $state['accumulators'][$group];
            ksort($byHorizon);

            foreach ($byHorizon as $horizon => $methods) {
                $entries = [];
                foreach ($methods as $method => $accumulator) {
                    $entries[$method] = $this->finalize($method, $accumulator);
                }
                $horizons['h'.$horizon] = $entries;
            }

            $groups[$group] = ['series' => $state['counts'][$group], 'horizons' => $horizons];
        }

        return $groups;
    }

    /**
     * @param  array{series: int, rel: list<float>, excluded: int, mase: list<float>, better: int, tie: int, worse: int}  $accumulator
     * @return array<string, int|float|null>
     */
    private function finalize(string $method, array $accumulator): array
    {
        $entry = ['series' => $accumulator['series'], 'mase_median' => $this->round(self::median($accumulator['mase']))];

        if ($method === HistoricalMeanForecaster::NAME) {
            return $entry;
        }

        $compared = $accumulator['better'] + $accumulator['tie'] + $accumulator['worse'];
        $rel = $accumulator['rel'];

        return $entry + [
            'rel_mae_geometric_mean' => $rel === [] ? null : $this->round(exp(array_sum(array_map('log', $rel)) / count($rel))),
            'rel_mae_median' => $this->round(self::median($rel)),
            'rel_mae_series' => count($rel),
            'rel_mae_excluded_zero_mae' => $accumulator['excluded'],
            'better' => $accumulator['better'],
            'tie' => $accumulator['tie'],
            'worse' => $accumulator['worse'],
            'win_share' => $compared === 0 ? null : $this->round($accumulator['better'] / $compared),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $groups
     */
    private function accumulateSensitivity(array &$groups, SeriesEvaluation $evaluation): void
    {
        if ($evaluation->selection === null || $evaluation->months < MethodSelector::MIN_HISTORY_FOR_SELECTION) {
            return;
        }

        foreach (['all', 'class:'.$evaluation->pattern->classification] as $group) {
            $groups[$group]['eligible_series'] = ($groups[$group]['eligible_series'] ?? 0) + 1;

            foreach ($evaluation->selection['selects_linear'] as $margin => $selectsLinear) {
                $groups[$group][$margin] = ($groups[$group][$margin] ?? 0) + ($selectsLinear ? 1 : 0);
            }
        }
    }

    /**
     * How many series would choose the linear trend at each margin, using the
     * in-app rule on the full backtest. Only series with enough history to be
     * eligible (at least 9 months) are counted.
     *
     * @param  array<string, array<string, int>>  $groups
     * @return array<string, array<string, int>>
     */
    private function finalizeSensitivity(array $groups): array
    {
        $ordered = [];

        foreach ($this->orderedGroups(array_keys($groups)) as $group) {
            $ordered[$group] = ['eligible_series' => $groups[$group]['eligible_series']];

            foreach (EvaluationRunner::MARGINS as $margin) {
                $key = EvaluationRunner::marginKey($margin);
                $ordered[$group]['linear_'.str_replace('selected_', '', $key)] = $groups[$group][$key] ?? 0;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    private function orderedGroups(array $groups): array
    {
        $order = ['all'];
        foreach (self::CLASS_ORDER as $class) {
            $order[] = 'class:'.$class;
        }
        foreach (self::SEASONALITY_ORDER as $seasonality) {
            $order[] = 'seasonality:'.$seasonality;
        }

        return array_values(array_filter($order, static fn (string $group): bool => in_array($group, $groups, true)));
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }
}
