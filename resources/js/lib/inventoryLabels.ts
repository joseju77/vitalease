/**
 * Human-readable labels for medication inventory UI. Shared by the
 * consultation treatment picker (`MedicationOption`) today; the inventory
 * list and movement history pages extend this file with their own labels.
 */
import type { MedicationOption } from '@/types/consultations';

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
