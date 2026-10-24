<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Ordinary-least-squares fit `y = a + b*x` over a monthly series with
 * `x = 0..n-1`: `b = Sxy/Sxx`, `a = ȳ - b*x̄`, `R² = 1 - SSres/SStot`.
 *
 * `R² = 1.0` when `SStot = 0` (a constant series is a perfect fit) and
 * `b = 0` when `Sxx = 0` (fewer than two distinct x values). Values are not
 * rounded or clamped here.
 */
final readonly class LinearTrendFit
{
    public function __construct(
        public float $slope,
        public float $intercept,
        public float $rSquared,
    ) {}

    /**
     * @param  list<int|float>  $series  Non-empty monthly series, oldest first.
     */
    public static function fromSeries(array $series): self
    {
        $n = count($series);

        $xMean = ($n - 1) / 2;
        $yMean = array_sum($series) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        foreach ($series as $x => $y) {
            $dx = $x - $xMean;
            $dy = $y - $yMean;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        $slope = $sxx > 0.0 ? $sxy / $sxx : 0.0;
        $intercept = $yMean - $slope * $xMean;

        $ssRes = 0.0;
        foreach ($series as $x => $y) {
            $ssRes += ($y - ($intercept + $slope * $x)) ** 2;
        }

        $rSquared = $syy > 0.0 ? 1.0 - ($ssRes / $syy) : 1.0;

        return new self($slope, $intercept, $rSquared);
    }

    /**
     * Fitted value at month index `$x` (0 is the first month of the series).
     */
    public function valueAt(int $x): float
    {
        return $this->intercept + $this->slope * $x;
    }
}
