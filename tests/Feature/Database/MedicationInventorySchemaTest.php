<?php

use App\Enums\InventoryMovementType;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assert the closure throws a `QueryException` whose underlying database
 * message names the given constraint, so a passing test proves the exact
 * constraint fired rather than any unrelated database error.
 *
 * @param  string|array<int, string>  $constraint  One constraint name, or several
 *                                                 acceptable names when more than one named CHECK genuinely overlaps on the
 *                                                 same row (Postgres reports only the first constraint it evaluates).
 */
function assertViolatesConstraint(Closure $closure, string|array $constraint): void
{
    $constraints = (array) $constraint;

    try {
        $closure();
    } catch (QueryException $e) {
        $matched = array_filter($constraints, fn (string $name) => str_contains($e->getMessage(), $name));
        expect($matched)->not->toBeEmpty('Expected the error to name one of ['.implode(', ', $constraints)."], got: {$e->getMessage()}");

        return;
    }

    throw new RuntimeException('Expected a QueryException naming constraint "'.implode('" or "', $constraints).'", none was thrown.');
}

/** @return array<string, mixed> */
function validMedicationRow(array $overrides = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid7(),
        'name' => 'Medication '.Str::random(10),
        'presentation' => 'Tableta',
        'concentration' => '500 mg',
        'dispensing_unit' => 'unidad',
        'current_stock' => 10,
        'minimum_stock' => 5,
        'is_active' => true,
    ], $overrides);
}

/** @return array<string, mixed> */
function validMovementRow(int $medicationId, int $userId, array $overrides = []): array
{
    return array_merge([
        'medication_id' => $medicationId,
        'type' => InventoryMovementType::Entry->value,
        'quantity' => 5,
        'stock_after' => 5,
        'medical_consultation_id' => null,
        'medical_consultation_code' => null,
        'user_id' => $userId,
        'notes' => null,
        'occurred_at' => now(),
    ], $overrides);
}

/** @return array<string, mixed> */
function validTreatmentRow(int $consultationId, int $medicationId, array $overrides = []): array
{
    return array_merge([
        'medical_consultation_id' => $consultationId,
        'medication_id' => $medicationId,
        'quantity_dispensed' => 1,
        'dose' => '500 mg',
        'frequency' => 'Cada 8 horas',
        'duration' => '5 días',
    ], $overrides);
}

describe('medications table constraints', function () {
    it('rejects a negative current_stock', function () {
        assertViolatesConstraint(
            fn () => DB::table('medications')->insert(validMedicationRow(['current_stock' => -1])),
            'chk_current_stock_nonnegative',
        );
    });

    it('rejects a negative minimum_stock', function () {
        assertViolatesConstraint(
            fn () => DB::table('medications')->insert(validMedicationRow(['minimum_stock' => -1])),
            'chk_minimum_stock_nonnegative',
        );
    });

    it('rejects a duplicate name, presentation, and concentration combination', function () {
        DB::table('medications')->insert(validMedicationRow(['name' => 'Paracetamol']));

        expect(fn () => DB::table('medications')->insert(validMedicationRow(['name' => 'Paracetamol'])))
            ->toThrow(QueryException::class);
    });

    it('allows the same name with a different presentation or concentration', function () {
        DB::table('medications')->insert(validMedicationRow(['name' => 'Paracetamol', 'presentation' => 'Tableta']));
        DB::table('medications')->insert(validMedicationRow(['name' => 'Paracetamol', 'presentation' => 'Jarabe']));

        expect(DB::table('medications')->where('name', 'Paracetamol')->count())->toBe(2);
    });

    it('allows a valid medication row', function () {
        DB::table('medications')->insert(validMedicationRow());

        expect(DB::table('medications')->count())->toBe(1);
    });
});

describe('inventory_movements table constraints', function () {
    it('rejects a type outside the approved domain', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        // An out-of-domain type also fails every disjunct of
        // `chk_quantity_sign_by_type` (none of its `type = 1|2|3|4` branches
        // can match), so Postgres may report either named CHECK first.
        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, ['type' => 5, 'quantity' => 1, 'stock_after' => 1])),
            ['chk_type_domain', 'chk_quantity_sign_by_type'],
        );
    });

    it('rejects a zero quantity', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, ['type' => InventoryMovementType::Adjustment->value, 'quantity' => 0, 'notes' => 'reason'])),
            'chk_quantity_nonzero',
        );
    });

    it('rejects an Entry movement with a non-positive quantity', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, ['type' => InventoryMovementType::Entry->value, 'quantity' => -1, 'stock_after' => 0])),
            'chk_quantity_sign_by_type',
        );
    });

    it('rejects a Dispensation movement with a positive quantity', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Dispensation->value,
                'quantity' => 3,
                'stock_after' => 3,
                'medical_consultation_id' => $consultation->id,
                'medical_consultation_code' => $consultation->code,
            ])),
            'chk_quantity_sign_by_type',
        );
    });

    it('rejects a DispensationReversal movement with a negative quantity', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::DispensationReversal->value,
                'quantity' => -3,
                'stock_after' => 3,
                'medical_consultation_id' => $consultation->id,
                'medical_consultation_code' => $consultation->code,
            ])),
            'chk_quantity_sign_by_type',
        );
    });

    it('allows an Adjustment movement with either sign', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
            'type' => InventoryMovementType::Adjustment->value, 'quantity' => 2, 'stock_after' => 7, 'notes' => 'restock count',
        ]));
        DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
            'type' => InventoryMovementType::Adjustment->value, 'quantity' => -2, 'stock_after' => 5, 'notes' => 'shrinkage',
        ]));

        expect(DB::table('inventory_movements')->count())->toBe(2);
    });

    it('rejects a negative stock_after', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, ['type' => InventoryMovementType::Entry->value, 'quantity' => 1, 'stock_after' => -1])),
            'chk_stock_after_nonnegative',
        );
    });

    it('rejects a Dispensation movement without a consultation code snapshot', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Dispensation->value, 'quantity' => -1, 'stock_after' => 9,
            ])),
            'chk_consultation_code_by_type',
        );
    });

    it('rejects an Entry movement with a consultation code snapshot', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Entry->value, 'quantity' => 1, 'stock_after' => 11, 'medical_consultation_code' => 'MC-260101-0001',
            ])),
            'chk_consultation_code_by_type',
        );
    });

    it('rejects an Entry movement with a consultation id', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Entry->value, 'quantity' => 1, 'stock_after' => 11, 'medical_consultation_id' => $consultation->id,
            ])),
            'chk_consultation_id_by_type',
        );
    });

    it('rejects an Adjustment movement with empty notes', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Adjustment->value, 'quantity' => 2, 'stock_after' => 12, 'notes' => null,
            ])),
            'chk_adjustment_notes',
        );
    });

    it('rejects an Adjustment movement with whitespace-only notes', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
                'type' => InventoryMovementType::Adjustment->value, 'quantity' => 2, 'stock_after' => 12, 'notes' => '   ',
            ])),
            'chk_adjustment_notes',
        );
    });

    it('rejects deleting a medication that has movements', function () {
        $medication = Medication::factory()->create();
        DB::table('inventory_movements')->insert(validMovementRow($medication->id, User::factory()->create()->id));

        expect(fn () => $medication->delete())->toThrow(QueryException::class);
    });

    it('nulls the consultation id and keeps the code snapshot when the consultation is deleted', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id, [
            'type' => InventoryMovementType::Dispensation->value,
            'quantity' => -1,
            'stock_after' => 9,
            'medical_consultation_id' => $consultation->id,
            'medical_consultation_code' => $consultation->code,
        ]));

        $consultation->delete();

        $movement = DB::table('inventory_movements')->where('medication_id', $medication->id)->sole();
        expect($movement->medical_consultation_id)->toBeNull()
            ->and($movement->medical_consultation_code)->toBe($consultation->code);
    });

    it('allows a valid movement row', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();

        DB::table('inventory_movements')->insert(validMovementRow($medication->id, $user->id));

        expect(DB::table('inventory_movements')->count())->toBe(1);
    });
});

describe('medical_consultation_treatments table constraints', function () {
    it('rejects a non-positive quantity_dispensed', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();

        assertViolatesConstraint(
            fn () => DB::table('medical_consultation_treatments')->insert(validTreatmentRow($consultation->id, $medication->id, ['quantity_dispensed' => 0])),
            'chk_quantity_dispensed_positive',
        );
    });

    it('rejects a duplicate medication on the same consultation', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();

        DB::table('medical_consultation_treatments')->insert(validTreatmentRow($consultation->id, $medication->id));

        expect(fn () => DB::table('medical_consultation_treatments')->insert(validTreatmentRow($consultation->id, $medication->id)))
            ->toThrow(QueryException::class);
    });

    it('rejects deleting a medication referenced by a treatment line', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();
        DB::table('medical_consultation_treatments')->insert(validTreatmentRow($consultation->id, $medication->id));

        expect(fn () => $medication->delete())->toThrow(QueryException::class);
    });

    it('allows a valid treatment row', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create();

        DB::table('medical_consultation_treatments')->insert(validTreatmentRow($consultation->id, $medication->id));

        expect(DB::table('medical_consultation_treatments')->count())->toBe(1);
    });
});
