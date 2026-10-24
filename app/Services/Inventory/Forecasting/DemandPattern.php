<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Demand pattern of a monthly series: Syntetos-Boylan class, its two
 * statistics, and a separate lag-12 seasonality flag.
 */
final readonly class DemandPattern
{
    public const SMOOTH = 'smooth';

    public const ERRATIC = 'erratic';

    public const INTERMITTENT = 'intermittent';

    public const LUMPY = 'lumpy';

    public const NO_DEMAND = 'no_demand';

    public const SEASONAL = 'seasonal';

    public const NOT_SEASONAL = 'not_seasonal';

    public const NOT_ASSESSED = 'not_assessed';

    /**
     * @param  self::SMOOTH|self::ERRATIC|self::INTERMITTENT|self::LUMPY|self::NO_DEMAND  $classification
     * @param  self::SEASONAL|self::NOT_SEASONAL|self::NOT_ASSESSED  $seasonality
     */
    public function __construct(
        public string $classification,
        public ?float $adi,
        public ?float $cv2,
        public string $seasonality,
    ) {}

    /**
     * @return array{class: string, adi: ?float, cv2: ?float, seasonality: string}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->classification,
            'adi' => $this->adi === null ? null : round($this->adi, 4),
            'cv2' => $this->cv2 === null ? null : round($this->cv2, 4),
            'seasonality' => $this->seasonality,
        ];
    }
}
