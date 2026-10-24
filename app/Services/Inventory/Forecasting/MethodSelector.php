<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Chooses between the linear trend and the historical mean from their
 * one-step backtest errors. The margin is fixed a priori, never tuned.
 *
 * - fewer than 2 months: `insufficient_data` (no method);
 * - fewer than 9 months: historical mean, `short_history`;
 * - otherwise linear only when at least 3 origins were evaluated, the
 *   historical-mean MAE is positive and `MAE_linear <= (1 - margin) *
 *   MAE_mean`; ties, the zero-error case and anything inside the margin choose
 *   the historical mean.
 */
final class MethodSelector
{
    public const DEFAULT_MARGIN = 0.05;

    public const MIN_SERIES_LENGTH = 2;

    public const MIN_HISTORY_FOR_SELECTION = 9;

    public const MIN_EVALUATED_ORIGINS = 3;

    private const EPSILON = 1e-9;

    /**
     * @param  int  $seriesLength  Months of history available when choosing.
     * @param  list<float>  $linearAbsoluteErrors  One-step absolute errors, one per origin.
     * @param  list<float>  $meanAbsoluteErrors  Same origins as `$linearAbsoluteErrors`.
     */
    public static function choose(int $seriesLength, array $linearAbsoluteErrors, array $meanAbsoluteErrors, float $margin = self::DEFAULT_MARGIN): MethodSelection
    {
        if (count($linearAbsoluteErrors) !== count($meanAbsoluteErrors)) {
            throw new \InvalidArgumentException('Both methods must be evaluated on identical origins.');
        }

        $evaluated = count($linearAbsoluteErrors);
        $linearMae = $evaluated === 0 ? null : array_sum($linearAbsoluteErrors) / $evaluated;
        $meanMae = $evaluated === 0 ? null : array_sum($meanAbsoluteErrors) / $evaluated;

        if ($seriesLength < self::MIN_SERIES_LENGTH) {
            return new MethodSelection(null, MethodSelection::REASON_INSUFFICIENT_DATA, $evaluated, $linearMae, $meanMae);
        }

        if ($seriesLength < self::MIN_HISTORY_FOR_SELECTION) {
            return new MethodSelection(HistoricalMeanForecaster::NAME, MethodSelection::REASON_SHORT_HISTORY, $evaluated, $linearMae, $meanMae);
        }

        $linearWins = $evaluated >= self::MIN_EVALUATED_ORIGINS
            && $meanMae > 0.0
            && $linearMae <= (1 - $margin) * $meanMae + self::EPSILON;

        return $linearWins
            ? new MethodSelection(LinearTrendForecaster::NAME, MethodSelection::REASON_LINEAR_BEATS_MEAN, $evaluated, $linearMae, $meanMae)
            : new MethodSelection(HistoricalMeanForecaster::NAME, MethodSelection::REASON_MEAN_BEATS_LINEAR, $evaluated, $linearMae, $meanMae);
    }
}
