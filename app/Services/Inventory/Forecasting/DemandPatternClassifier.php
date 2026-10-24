<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Syntetos-Boylan demand classification. ADI (average inter-demand interval)
 * is `n / non-zero months`; CV² is the squared coefficient of variation of the
 * non-zero demands (population variance). Cutoffs 1.32 and 0.49 belong to the
 * high side. An all-zero series is `no_demand`; fewer than two non-zero
 * demands yields CV² = 0.
 *
 * Seasonality is separate from the class: with at least 24 months, the lag-12
 * autocorrelation of the linearly detrended series is compared with
 * `2 / sqrt(n)`; shorter series are `not_assessed`.
 */
final class DemandPatternClassifier
{
    public const ADI_CUTOFF = 1.32;

    public const CV2_CUTOFF = 0.49;

    public const SEASONAL_LAG = 12;

    public const MIN_SEASONALITY_LENGTH = 24;

    /**
     * @param  list<int|float>  $series  Monthly demand, oldest first.
     */
    public static function classify(array $series): DemandPattern
    {
        $series = array_values($series);
        $n = count($series);

        $nonZero = array_values(array_filter($series, static fn (int|float $value): bool => $value != 0));

        if ($nonZero === []) {
            return new DemandPattern(DemandPattern::NO_DEMAND, null, null, self::seasonality($series));
        }

        $adi = $n / count($nonZero);
        $cv2 = self::squaredCoefficientOfVariation($nonZero);

        $classification = match (true) {
            $adi < self::ADI_CUTOFF && $cv2 < self::CV2_CUTOFF => DemandPattern::SMOOTH,
            $adi < self::ADI_CUTOFF => DemandPattern::ERRATIC,
            $cv2 < self::CV2_CUTOFF => DemandPattern::INTERMITTENT,
            default => DemandPattern::LUMPY,
        };

        return new DemandPattern($classification, $adi, $cv2, self::seasonality($series));
    }

    /**
     * @param  list<int|float>  $nonZero
     */
    private static function squaredCoefficientOfVariation(array $nonZero): float
    {
        $count = count($nonZero);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($nonZero) / $count;

        $variance = 0.0;
        foreach ($nonZero as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= $count;

        return $variance / ($mean ** 2);
    }

    /**
     * @param  list<int|float>  $series
     * @return DemandPattern::SEASONAL|DemandPattern::NOT_SEASONAL|DemandPattern::NOT_ASSESSED
     */
    private static function seasonality(array $series): string
    {
        $n = count($series);

        if ($n < self::MIN_SEASONALITY_LENGTH) {
            return DemandPattern::NOT_ASSESSED;
        }

        $fit = LinearTrendFit::fromSeries($series);

        $residuals = [];
        foreach ($series as $x => $y) {
            $residuals[] = $y - $fit->valueAt($x);
        }

        $denominator = 0.0;
        foreach ($residuals as $residual) {
            $denominator += $residual ** 2;
        }

        if ($denominator < 1e-12) {
            return DemandPattern::NOT_SEASONAL;
        }

        $numerator = 0.0;
        for ($t = self::SEASONAL_LAG; $t < $n; $t++) {
            $numerator += $residuals[$t] * $residuals[$t - self::SEASONAL_LAG];
        }

        return $numerator / $denominator > 2 / sqrt($n) ? DemandPattern::SEASONAL : DemandPattern::NOT_SEASONAL;
    }
}
