/**
 * Shared shapes of the change-history endpoints (consultations and patients),
 * both produced by `App\Support\Auditing\AuditHistory`. Labels and enum values
 * are already Spanish.
 */

/** One field change inside a history group. */
export interface HistoryChange {
    section: string;
    item: string | null;
    field: string;
    old: string | number | boolean | null;
    new: string | number | boolean | null;
}

/** Every event a history group can carry, across both subjects. */
export type HistoryEvent = 'created' | 'updated' | 'voided' | 'restored' | 'disabled' | 'enabled';

/** One operation (save, void, restore, disable, enable) of the audited subject. */
export interface HistoryGroup<E extends HistoryEvent = HistoryEvent> {
    id: string;
    event: E;
    event_label: string;
    occurred_at: string;
    actor: { name: string } | null;
    reason: string | null;
    changes: HistoryChange[];
}

/** A page of history groups, newest first; `next_cursor` is null on the last page. */
export interface HistoryPage<E extends HistoryEvent = HistoryEvent> {
    data: HistoryGroup<E>[];
    next_cursor: number | null;
}

export type PatientHistoryEvent = 'created' | 'updated' | 'disabled' | 'enabled';
export type PatientHistoryGroup = HistoryGroup<PatientHistoryEvent>;
export type PatientHistoryPage = HistoryPage<PatientHistoryEvent>;
