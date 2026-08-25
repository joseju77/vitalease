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

/**
 * The OLS demand projection for one medication, as built by
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
    medication: MedicationSummary;
    is_indicative: boolean;
}
