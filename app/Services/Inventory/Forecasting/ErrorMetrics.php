<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Point-forecast error metrics. MAPE is deliberately absent: monthly demand
 * contains zeros, which make percentage errors undefined.
 */
final class ErrorMetrics
{
    /**
     * Absolute difference below which two errors are considered tied.
     */
    public const TIE_TOLERANCE = 1e-9;

    /**
     * @param  list<int|float>  $actual
     * @param  list<int|float>  $forecast
     */
    public static function mae(array $actual, array $forecast): ?float
    {
        self::assertSameLength($actual, $forecast);

        if ($actual === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($actual as $i => $value) {
            $sum += abs($value - $forecast[$i]);
        }

        return $sum / count($actual);
    }

    /**
     * @param  list<int|float>  $actual
     * @param  list<int|float>  $forecast
     */
    public static function rmse(array $actual, array $forecast): ?float
    {
        self::assertSameLength($actual, $forecast);

        if ($actual === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($actual as $i => $value) {
            $sum += ($value - $forecast[$i]) ** 2;
        }

        return sqrt($sum / count($actual));
    }

    /**
     * Mean absolute scaled error: `$mae` divided by the mean absolute
     * one-step naive difference of the whole series. Null when the scale is
     * zero (constant series) or the series has fewer than two points.
     *
     * @param  list<int|float>  $series
     */
    public static function mase(float $mae, array $series): ?float
    {
        $n = count($series);

        if ($n < 2) {
            return null;
        }

        $sum = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $sum += abs($series[$i] - $series[$i - 1]);
        }

        $scale = $sum / ($n - 1);

        return $scale > 0.0 ? $mae / $scale : null;
    }

    /**
     * MAE of a method relative to the historical-mean MAE. Null (undefined)
     * when the historical-mean MAE is zero.
     */
    public static function relativeMae(float $mae, float $meanMae): ?float
    {
        return $meanMae > 0.0 ? $mae / $meanMae : null;
    }

    public static function isTie(float $a, float $b): bool
    {
        return abs($a - $b) < self::TIE_TOLERANCE;
    }

    /**
     * @param  list<int|float>  $actual
     * @param  list<int|float>  $forecast
     */
    private static function assertSameLength(array $actual, array $forecast): void
    {
        if (count($actual) !== count($forecast)) {
            throw new \InvalidArgumentException('Actual and forecast series must have the same length.');
        }
    }
}
