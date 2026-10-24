/**
 * Types for the medication inventory list, detail, and movement history UI,
 * plus the demand projection report. These mirror the JSON shapes returned
 * by `App\Http\Controllers\MedicationController` and
 * `App\Http\Controllers\MedicationDemandProjectionController`. Keep both
 * contracts in sync manually; there is no shared code generation between
 * them.
 */

/** One page of a Laravel paginator response, generic over the item shape. */
export interface Paginator<T> {
    data: T[];
    links: PaginationLink[];
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** A medication catalog row, as mapped by `MedicationController::mapMedication`. */
export interface Medication {
    uuid: string;
    name: string;
    presentation: string;
    concentration: string;
    dispensing_unit: string;
    current_stock: number;
    minimum_stock: number;
    is_active: boolean;
    is_low_stock: boolean;
    /** True only when the medication has zero movements and zero treatment lines. */
    can_be_deleted: boolean;
}

/**
 * One ledger row, as mapped by `MedicationController::mapMovement`. `type`
 * values match `App\Enums\InventoryMovementType` (1 Entry, 2 Dispensation,
 * 3 Adjustment, 4 DispensationReversal).
 */
export interface InventoryMovement {
    id: number;
    type: 1 | 2 | 3 | 4;
    quantity: number;
    stock_after: number;
    medical_consultation_code: string | null;
    user: string | null;
    notes: string | null;
    occurred_at: string;
}

/** The `inventory.index` query filters, echoed back in the `filters` prop. */
export interface InventoryFilters {
    q: string | null;
    low_stock: boolean;
}

/** The one-time medication mutation flash payload, rendered as a toast. */
export interface MedicationFlash {
    uuid: string;
    name: string;
    action: 'created' | 'updated' | 'activated' | 'deactivated' | 'deleted' | 'entry_recorded' | 'adjustment_recorded';
}

/** A minimal medication reference used by the demand projection selector and result. */
export interface MedicationSummary {
    uuid: string;
    name: string;
    presentation: string;
    concentration: string;
}

export interface DemandProjectionHistoryPoint {
    month: string;
    consumed: number;
}

export interface DemandProjectionSeriesPoint {
    month: string;
    value: number;
}

/** Projection method chosen by backtest; values match the PHP forecaster names. */
export type DemandProjectionMethod = 'linear_trend' | 'historical_mean';

/** Why the method was chosen; `short_history` means fewer than 9 months. */
export type DemandProjectionMethodReason = 'short_history' | 'linear_beats_mean' | 'mean_beats_linear';

export interface DemandProjectionMethodError {
    mae: number | null;
    rmse: number | null;
}

/** One-step rolling-origin error of both candidate methods. */
export interface DemandProjectionBacktest {
    horizon: number;
    margin: number;
    evaluated_points: number;
    linear_trend: DemandProjectionMethodError;
    historical_mean: DemandProjectionMethodError;
}

/** Syntetos-Boylan class of the series plus the lag-12 seasonality flag. */
export interface DemandPatternSummary {
    class: 'smooth' | 'erratic' | 'intermittent' | 'lumpy' | 'no_demand';
    adi: number | null;
    cv2: number | null;
    seasonality: 'seasonal' | 'not_seasonal' | 'not_assessed';
}

/**
 * The demand projection for one medication, as built by
 * `App\Services\Inventory\MedicationDemandProjection` and mapped by
 * `MedicationDemandProjectionController`. `status` is `'insufficient_data'`
 * when fewer than two complete months of consumption history exist, in
 * which case `fitted`, `projection`, and the derived numeric fields are
 * empty/null.
 */
export interface DemandProjection {
    status: 'ok' | 'insufficient_data';
    history: DemandProjectionHistoryPoint[];
    fitted: DemandProjectionSeriesPoint[];
    projection: DemandProjectionSeriesPoint[];
    slope: number | null;
    intercept: number | null;
    r_squared: number | null;
    next_month_demand: number | null;
    current_stock: number;
    suggested_reorder_quantity: number | null;
    /** Null only when `status` is `'insufficient_data'` (as are the three fields below). */
    method: DemandProjectionMethod | null;
    method_reason: DemandProjectionMethodReason | null;
    backtest: DemandProjectionBacktest | null;
    demand_pattern: DemandPatternSummary | null;
    medication: MedicationSummary;
    is_indicative: boolean;
}

/**
 * One merged point on the demand projection chart's shared month axis, as
 * built by `lib/demandProjectionChart.ts`'s `buildDemandChartPoints`. `index`
 * is the point's position on the numeric X axis; `history`/`fitted`/
 * `projection` are `undefined` for months that series has no value for, so
 * each line renders a gap instead of interpolating across them.
 */
export interface DemandChartPoint {
    index: number;
    month: string;
    label: string;
    history?: number;
    fitted?: number;
    projection?: number;
}
