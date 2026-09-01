/**
 * Human-readable labels for medication inventory UI: the consultation
 * treatment picker (`MedicationOption`), the movement history table
 * (`InventoryMovement.type`), and the toast messages for medication mutation
 * flashes (`MedicationFlash.action`).
 */
import type { MedicationOption } from '@/types/consultations';
import type { InventoryMovement, MedicationFlash } from '@/types/inventory';

/**
 * "Paracetamol · Tableta 500 mg · 120 disponibles" for an active medication,
 * or "Paracetamol · Tableta 500 mg · inactivo" for a medication that is no
 * longer active but stays selectable because it is already linked to the
 * consultation being edited. Stock is informational text only.
 */
export function medicationOptionLabel(option: MedicationOption): string {
    const presentation = `${option.presentation} ${option.concentration}`.trim();
    const availability = option.is_active ? `${option.current_stock ?? 0} disponibles` : 'inactivo';

    return [option.name, presentation, availability].join(' · ');
}

/** Spanish label per `App\Enums\InventoryMovementType` backing value. */
export const MOVEMENT_TYPE_LABELS: Record<InventoryMovement['type'], string> = {
    1: 'Entrada',
    2: 'Dispensación',
    3: 'Ajuste',
    4: 'Reversión de dispensación',
};

export function movementTypeLabel(type: InventoryMovement['type']): string {
    return MOVEMENT_TYPE_LABELS[type];
}

/** Spanish toast message per `MedicationFlash.action`, keyed on the medication name. */
export const MEDICATION_ACTION_MESSAGES: Record<MedicationFlash['action'], (name: string) => string> = {
    created: (name) => `Medicamento ${name} creado`,
    updated: (name) => `Medicamento ${name} actualizado`,
    activated: (name) => `Medicamento ${name} activado`,
    deactivated: (name) => `Medicamento ${name} desactivado`,
    deleted: (name) => `Medicamento ${name} eliminado`,
    entry_recorded: (name) => `Entrada registrada para ${name}`,
    adjustment_recorded: (name) => `Ajuste registrado para ${name}`,
};

export function medicationActionMessage(flash: MedicationFlash): string {
    return MEDICATION_ACTION_MESSAGES[flash.action](flash.name);
}
