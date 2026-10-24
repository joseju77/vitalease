<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic synthetic demand series: 8 patterns x 30 replicates. Each
 * series has its own sub-seed (first 8 hex digits of sha256 over
 * "seed:pattern:index"), so adding a pattern never shifts existing series.
 */
final class SyntheticSeriesGenerator
{
    public const DEFAULT_SEED = 20261024;

    public const REPLICATES = 30;

    public const LENGTH = 36;

    public const SEASONAL_LENGTH = 48;

    public const PATTERNS = [
        'constant',
        'pure_trend',
        'trend_noise',
        'seasonal',
        'erratic',
        'intermittent',
        'lumpy',
        'level_shift',
    ];

    /**
     * @return list<EvaluationSeries>
     */
    public function generate(int $seed = self::DEFAULT_SEED): array
    {
        $series = [];

        foreach (self::PATTERNS as $pattern) {
            for ($index = 1; $index <= self::REPLICATES; $index++) {
                $series[] = $this->generateOne($seed, $pattern, $index);
            }
        }

        return $series;
    }

    public function generateOne(int $seed, string $pattern, int $index): EvaluationSeries
    {
        $randomizer = new Randomizer(new Mt19937(self::subSeed($seed, $pattern, $index)));

        $values = match ($pattern) {
            'constant' => $this->constant($randomizer),
            'pure_trend' => $this->trend($randomizer, noise: 0.0),
            'trend_noise' => $this->trend($randomizer, noise: 4.0),
            'seasonal' => $this->seasonal($randomizer),
            'erratic' => $this->erratic($randomizer),
            'intermittent' => $this->intermittent($randomizer),
            'lumpy' => $this->lumpy($randomizer),
            'level_shift' => $this->levelShift($randomizer),
        };

        return new EvaluationSeries(sprintf('syn-%s-%02d', $pattern, $index), $values, $pattern);
    }

    public static function subSeed(int $seed, string $pattern, int $index): int
    {
        return (int) hexdec(substr(hash('sha256', "{$seed}:{$pattern}:{$index}"), 0, 8));
    }

    /**
     * @return list<int>
     */
    private function constant(Randomizer $random): array
    {
        return array_fill(0, self::LENGTH, $random->getInt(20, 80));
    }

    /**
     * @return list<int>
     */
    private function trend(Randomizer $random, float $noise): array
    {
        $base = $random->getInt(10, 40);
        $slope = 0.5 + $random->nextFloat() * 2.0;

        $values = [];
        for ($t = 0; $t < self::LENGTH; $t++) {
            $values[] = $this->nonNegative($base + $slope * $t + $this->noise($random, $noise));
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    private function seasonal(Randomizer $random): array
    {
        $level = $random->getInt(40, 80);
        $amplitude = $level * (0.3 + $random->nextFloat() * 0.3);
        $phase = $random->nextFloat() * 2 * M_PI;

        $values = [];
        for ($t = 0; $t < self::SEASONAL_LENGTH; $t++) {
            $values[] = $this->nonNegative($level + $amplitude * sin(2 * M_PI * $t / 12 + $phase) + $this->noise($random, 2.0));
        }

        return $values;
    }

    /**
     * Dense demand with a heavy-tailed size distribution (high CV², ADI near 1).
     *
     * @return list<int>
     */
    private function erratic(Randomizer $random): array
    {
        $scale = $random->getInt(20, 40);

        $values = [];
        for ($t = 0; $t < self::LENGTH; $t++) {
            $values[] = 1 + $this->nonNegative($this->exponential($random) * $scale);
        }

        return $values;
    }

    /**
     * Sporadic demand of similar size (high ADI, low CV²).
     *
     * @return list<int>
     */
    private function intermittent(Randomizer $random): array
    {
        $size = $random->getInt(6, 12);

        $values = [];
        for ($t = 0; $t < self::LENGTH; $t++) {
            $values[] = $random->nextFloat() < 0.4 ? $size + $random->getInt(-1, 1) : 0;
        }

        return $values;
    }

    /**
     * Sporadic demand with a heavy-tailed size distribution (high ADI and CV²).
     *
     * @return list<int>
     */
    private function lumpy(Randomizer $random): array
    {
        $scale = $random->getInt(20, 40);

        $values = [];
        for ($t = 0; $t < self::LENGTH; $t++) {
            $values[] = $random->nextFloat() < 0.3 ? 1 + $this->nonNegative($this->exponential($random) * $scale) : 0;
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    private function levelShift(Randomizer $random): array
    {
        $before = $random->getInt(20, 50);
        $after = (int) round($before * (1.6 + $random->nextFloat() * 0.8));
        $breakAt = $random->getInt(12, 24);

        $values = [];
        for ($t = 0; $t < self::LENGTH; $t++) {
            $values[] = $this->nonNegative(($t < $breakAt ? $before : $after) + $this->noise($random, 2.0));
        }

        return $values;
    }

    private function exponential(Randomizer $random): float
    {
        return -log(1 - $random->nextFloat());
    }

    private function noise(Randomizer $random, float $amplitude): float
    {
        return $amplitude === 0.0 ? 0.0 : ($random->nextFloat() * 2 - 1) * $amplitude;
    }

    private function nonNegative(float $value): int
    {
        return max(0, (int) round($value));
    }
}
