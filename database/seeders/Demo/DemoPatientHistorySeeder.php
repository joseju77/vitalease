<?php

namespace Database\Seeders\Demo;

use App\Enums\AilmentType;
use App\Enums\KinshipType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\PatientDisabling;
use App\Services\Patients\PatientWriter;
use App\Support\Patients\PatientEditPayload;
use Closure;
use Database\Seeders\Concerns\RunsWithAuditing;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * @phpstan-import-type PatientData from PatientWriter
 */
class DemoPatientHistorySeeder extends Seeder
{
    use RunsWithAuditing;

    /**
     * Number of demo patients that receive a curated history (the oldest
     * ones, by id).
     */
    public const int CURATED_COUNT = 6;

    /**
     * Minutes between two consecutive curated operations.
     */
    private const int STEP_MINUTES = 20;

    private ?Carbon $lastSlot = null;

    public function __construct(
        private readonly PatientWriter $writer,
        private readonly PatientDisabling $disabling,
    ) {}

    /**
     * Give the first demo patients a realistic history through the real
     * application services, each operation in its own audit batch with the
     * acting user set:
     *
     * 1. contact information changed by the demo physician;
     * 2. an emergency contact added by the demo physician;
     * 3. an emergency contact removed by medical coordination;
     * 4. an ailment added by the demo physician;
     * 5. disabled by medical coordination with a reason;
     * 6. disabled and then re-enabled by medical coordination.
     *
     * Fewer patients simply skip the later steps. Operations are pinned in
     * time after the newest baseline event (and never in the future), so the
     * audit trail stays chronological. Every other demo patient keeps an
     * empty history. Reindexing is left to the `scout:import` that
     * `demo:seed` runs afterwards.
     *
     * Requires `DemoPatientSeeder`, `DemoConsultationSeeder` and
     * `DemoUserSeeder` to have run.
     */
    public function run(): void
    {
        /** @var Collection<int, Patient> $patients */
        $patients = Patient::query()->orderBy('id')->limit(self::CURATED_COUNT)->get()->values();

        if ($patients->isEmpty()) {
            return;
        }

        $physician = User::query()->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->firstOrFail();
        $coordinator = User::query()->where('email', DemoUserSeeder::DEMO_COORDINATOR_EMAIL)->firstOrFail();

        $this->lastSlot = $this->latestBaselineEvent();

        Patient::withoutSyncingToSearch(function () use ($patients, $physician, $coordinator): void {
            if ($first = $patients->get(0)) {
                $this->updateAs($first, $physician, function (array $data): array {
                    $data['contact_information']['phone_number'] = '+525511223344';
                    $data['contact_information']['address'] = 'Av. Reforma 245, interior 3';

                    return $data;
                });
            }

            if ($second = $patients->get(1)) {
                $this->updateAs($second, $physician, fn (array $data): array => $this->withExtraEmergencyContact($data));
            }

            if ($third = $patients->get(2)) {
                $this->removeEmergencyContact($third, $physician, $coordinator);
            }

            if ($fourth = $patients->get(3)) {
                $this->updateAs($fourth, $physician, fn (array $data): array => $this->withExtraAilment($data));
            }

            if ($fifth = $patients->get(4)) {
                $this->atNextSlot($coordinator, fn () => $this->disabling->disable(
                    $fifth,
                    $coordinator,
                    'Registro duplicado: el paciente ya contaba con un expediente vigente.',
                ));
            }

            if ($sixth = $patients->get(5)) {
                $this->atNextSlot($coordinator, fn () => $this->disabling->disable(
                    $sixth,
                    $coordinator,
                    'Expediente suspendido por inconsistencias en la documentación de afiliación.',
                ));
                $this->atNextSlot($coordinator, fn () => $this->disabling->enable(
                    $sixth,
                    $coordinator,
                    'Se verificó la documentación de afiliación: el expediente es correcto.',
                ));
            }
        });
    }

    /**
     * A removal always has something to remove: a patient with a single
     * emergency contact first gets a second one.
     */
    private function removeEmergencyContact(Patient $patient, User $adder, User $remover): void
    {
        if ($patient->emergencyContacts()->count() < 2) {
            $this->updateAs($patient, $adder, fn (array $data): array => $this->withExtraEmergencyContact($data));
        }

        $this->updateAs($patient, $remover, function (array $data): array {
            array_pop($data['emergency_contacts']);

            return $data;
        });
    }

    /**
     * @param  PatientData  $data
     * @return PatientData
     */
    private function withExtraEmergencyContact(array $data): array
    {
        $data['emergency_contacts'][] = [
            'name' => 'Rosa María Hernández Cruz',
            'phone_number' => '+525544332211',
            'kinship_type' => KinshipType::Friend->value,
        ];

        return $data;
    }

    /**
     * @param  PatientData  $data
     * @return PatientData
     */
    private function withExtraAilment(array $data): array
    {
        $present = collect($data['ailments'] ?? [])->pluck('ailment_type')->map(fn ($type): int => (int) $type);
        $type = collect(AilmentType::cases())->first(fn (AilmentType $case): bool => ! $present->contains($case->value));

        $data['ailments'][] = [
            'ailment_type' => $type->value,
            'diagnosed_at' => now()->subMonths(2)->format('Y-m-d'),
            'treatment_notes' => 'Diagnosticado en una revisión reciente; en seguimiento.',
        ];

        return $data;
    }

    /**
     * @param  Closure(PatientData): PatientData  $change
     */
    private function updateAs(Patient $patient, User $actor, Closure $change): void
    {
        $this->atNextSlot($actor, function () use ($patient, $change): void {
            /** @var PatientData $data */
            $data = PatientEditPayload::for(Patient::query()->findOrFail($patient->id));

            $this->writer->update($patient, $change($data));
        });
    }

    /**
     * Run one operation pinned to the next free time slot, with auditing
     * on and the acting user set.
     *
     * @param  Closure(): mixed  $operation
     */
    private function atNextSlot(User $actor, Closure $operation): void
    {
        $slot = $this->lastSlot->copy()->addMinutes(self::STEP_MINUTES);
        $now = Carbon::now();

        $this->lastSlot = $slot->greaterThan($now) ? $now->copy() : $slot;

        Carbon::withTestNow($this->lastSlot, fn () => $this->withAuditing($actor, $operation));
    }

    /**
     * The newest patient, consultation (including the curated consultation
     * history) or ledger timestamp, so curated operations land after
     * everything the baseline wrote.
     */
    private function latestBaselineEvent(): Carbon
    {
        $candidates = [
            Patient::query()->max('created_at'),
            MedicalConsultation::withTrashed()->max('updated_at'),
            InventoryMovement::query()->max('created_at'),
        ];

        return collect($candidates)
            ->filter()
            ->map(fn (mixed $value): Carbon => Carbon::parse($value))
            ->max() ?? Carbon::now();
    }
}
