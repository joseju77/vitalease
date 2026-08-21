<?php

namespace Database\Seeders\Demo;

use App\Enums\InventoryMovementType;
use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\TransferType;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\TreatmentDispensation;
use BackedEnum;
use Database\Seeders\Concerns\LoadsCatalog;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class DemoConsultationSeeder extends Seeder
{
    use LoadsCatalog;

    public function __construct(
        private readonly TreatmentDispensation $treatmentDispensation,
        private readonly StockLedger $stockLedger,
    ) {}

    /**
     * Default number of demo consultations to seed.
     */
    private const int CONSULTATION_COUNT = 250;

    /**
     * Approximate share of consultations assigned to the demo physician;
     * the remainder is spread among the other seeded physicians.
     */
    private const int DEMO_PHYSICIAN_SHARE_PERCENT = 40;

    /**
     * Approximate share of consultations dated on a weekday.
     */
    private const int WEEKDAY_SHARE_PERCENT = 90;

    /**
     * How many days back from today consultations may be dated. Public so
     * {@see DemoMedicationSeeder} can anchor its
     * initial stock entries before the earliest possible consultation.
     */
    public const int LOOKBACK_DAYS = 90;

    /**
     * Earliest and latest attention hour, in `America/Mexico_City`.
     */
    private const int OPENING_HOUR = 8;

    private const int CLOSING_HOUR = 19;

    /**
     * Minimum and maximum minutes between a consultation and its
     * regulation (transfer), for the minority of cases that involve one.
     */
    private const int REGULATION_DELAY_MIN_MINUTES = 30;

    private const int REGULATION_DELAY_MAX_MINUTES = 120;

    /**
     * Seed `$consultations` realistic demo consultations from a curated
     * Spanish clinical-case catalog, spread over the last
     * {@see self::LOOKBACK_DAYS} days (mostly weekdays, business hours in
     * `America/Mexico_City`, never after "now"), assigned to seeded
     * patients and physicians. Each consultation is created with its
     * `created_at` (and, when applicable, `regulated_at`) pinned to its
     * assigned datetime via `Carbon::withTestNow()`, so
     * `MedicalConsultationCode::next()` generates a code whose date
     * segment matches the consultation's actual Mexico City calendar day.
     *
     * Requires `DemoUserSeeder`, `DemoPatientSeeder`, and
     * `DemoMedicationSeeder` to have already run: consultations reference
     * the demo physician, the other seeded physicians (role `Médico`), the
     * seeded patients, and treatment lines dispense stock from the seeded
     * medication catalog via {@see TreatmentDispensation}.
     *
     * @throws JsonException
     */
    public function run(int $consultations = self::CONSULTATION_COUNT): void
    {
        $cases = $this->loadCatalog('demo/clinical_cases.json');
        $people = $this->loadCatalog('demo/people.json');
        $faker = fake('es_ES');

        $demoPhysician = User::role('Médico')->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->firstOrFail();
        $otherPhysicians = User::role('Médico')->where('email', '!=', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->get();

        $patientsBySex = Patient::query()->get(['id', 'sex_at_birth'])->groupBy(
            fn (Patient $patient): string => $patient->sex_at_birth->name
        );
        $allPatients = Patient::query()->pluck('id');
        $medicationIndex = $this->buildMedicationIndex();

        $now = Carbon::now('America/Mexico_City');
        $datetimes = $this->generateDatetimes($consultations, $faker, $now);

        foreach ($datetimes as $at) {
            $case = $faker->randomElement($cases);
            $patientId = $this->pickPatientId($case, $patientsBySex, $allPatients);

            if ($patientId === null) {
                continue;
            }

            $physician = $faker->boolean(self::DEMO_PHYSICIAN_SHARE_PERCENT) || $otherPhysicians->isEmpty()
                ? $demoPhysician
                : $otherPhysicians->random();

            Carbon::withTestNow($at, function () use ($case, $patientId, $physician, $faker, $people, $at, $now, $medicationIndex): void {
                DB::transaction(function () use ($case, $patientId, $physician, $faker, $people, $at, $now, $medicationIndex): void {
                    $this->createConsultation($case, $patientId, $physician, $faker, $people, $at, $now, $medicationIndex);
                });
            });
        }
    }

    /**
     * Map each catalog medication key to its database id, uuid, and target
     * stock level, so treatment lines can be resolved and topped up without
     * repeated per-line lookups.
     *
     * @return array<string, array{id: int, uuid: string, target_stock: int}>
     *
     * @throws JsonException
     */
    private function buildMedicationIndex(): array
    {
        $catalog = $this->loadCatalog('demo/medications.json');
        $medications = Medication::query()
            ->whereIn('name', array_column($catalog, 'name'))
            ->get(['id', 'uuid', 'name'])
            ->keyBy('name');

        $index = [];

        foreach ($catalog as $entry) {
            $medication = $medications->get($entry['name']);

            if ($medication === null) {
                continue;
            }

            $index[$entry['key']] = [
                'id' => $medication->id,
                'uuid' => $medication->uuid,
                'target_stock' => $entry['target_stock'],
            ];
        }

        return $index;
    }

    /**
     * Build `$count` datetimes within the lookback window, mostly on
     * weekdays and always within business hours, sorted ascending so that
     * consultations are created in chronological order.
     *
     * @return list<Carbon>
     */
    private function generateDatetimes(int $count, Generator $faker, Carbon $now): array
    {
        $datetimes = [];

        for ($i = 0; $i < $count; $i++) {
            $wantsWeekday = $faker->boolean(self::WEEKDAY_SHARE_PERCENT);

            do {
                $day = $now->copy()->subDays($faker->numberBetween(0, self::LOOKBACK_DAYS))->startOfDay();
            } while ($day->isWeekend() === $wantsWeekday);

            $latestHour = $day->isSameDay($now) ? min(self::CLOSING_HOUR, $now->hour) : self::CLOSING_HOUR;
            $earliestHour = min(self::OPENING_HOUR, $latestHour);

            $datetime = $day->copy()->setTime(
                $faker->numberBetween($earliestHour, $latestHour),
                $faker->numberBetween(0, 59),
                $faker->numberBetween(0, 59),
            );

            $datetimes[] = $datetime->greaterThan($now) ? $now->copy() : $datetime;
        }

        usort($datetimes, fn (Carbon $a, Carbon $b): int => $a->timestamp <=> $b->timestamp);

        return $datetimes;
    }

    /**
     * Pick a random patient id matching the case's `sex` constraint, if
     * any. Returns `null` when no eligible patient exists.
     *
     * @param  array<string, mixed>  $case
     * @param  Collection<string, Collection<int, Patient>>  $patientsBySex
     * @param  Collection<int, int>  $allPatients
     */
    private function pickPatientId(array $case, Collection $patientsBySex, Collection $allPatients): ?int
    {
        if (! isset($case['sex'])) {
            return $allPatients->isNotEmpty() ? $allPatients->random() : null;
        }

        $pool = $patientsBySex->get($case['sex'], collect());

        if ($pool->isEmpty()) {
            return null;
        }

        return $pool->random()->id;
    }

    /**
     * Create the consultation aggregate (vital signs, physical
     * examination, dispensed treatment, and optional regulation) for one
     * catalog case.
     *
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $people
     * @param  array<string, array{id: int, uuid: string, target_stock: int}>  $medicationIndex
     */
    private function createConsultation(array $case, int $patientId, User $physician, Generator $faker, array $people, Carbon $at, Carbon $now, array $medicationIndex): void
    {
        $consultation = MedicalConsultation::query()->create([
            'current_condition' => $case['current_condition'],
            'diagnosis' => $case['diagnosis'],
            'condition' => $this->resolveEnumCase(MedicalState::class, $case['condition']),
            'prognosis' => $this->resolveEnumCase(MedicalState::class, $case['prognosis']),
            'medical_classification' => $this->resolveEnumCase(MedicalClassification::class, $case['medical_classification']),
            'physician_id' => $physician->id,
            'patient_id' => $patientId,
        ]);

        $consultation->vitalSigns()->create($this->sampleVitalSigns($case['vital_signs'], $faker));
        $consultation->physicalExamination()->create($case['physical_examination']);
        $this->dispenseTreatment($case, $consultation, $physician, $medicationIndex);

        if (isset($case['regulation'])) {
            $this->createRegulation($consultation, $case['regulation'], $faker, $people, $at, $now);
        }
    }

    /**
     * Build the consultation's treatment lines from the case's catalog
     * medication keys and dispense them through {@see TreatmentDispensation},
     * topping up any medication that would otherwise run short first.
     *
     * @param  array<string, mixed>  $case
     * @param  array<string, array{id: int, uuid: string, target_stock: int}>  $medicationIndex
     */
    private function dispenseTreatment(array $case, MedicalConsultation $consultation, User $physician, array $medicationIndex): void
    {
        $lines = [];

        foreach ($case['treatment'] as $item) {
            $medication = $medicationIndex[$item['medication_key']] ?? null;

            if ($medication === null) {
                continue;
            }

            $this->ensureSufficientStock($medication, $item['quantity_dispensed'], $physician);

            $lines[] = [
                'medication_uuid' => $medication['uuid'],
                'quantity_dispensed' => $item['quantity_dispensed'],
                'dose' => $item['dose'],
                'frequency' => $item['frequency'],
                'duration' => $item['duration'],
            ];
        }

        if ($lines !== []) {
            $this->treatmentDispensation->sync($consultation, $lines, $physician);
        }
    }

    /**
     * Top up a medication with an emergency `Entry` movement when its
     * current stock would not cover an upcoming dispensation. A simple
     * safety net for the occasional demand spike; the chronological
     * monthly-restock loop keeps stock topped up under normal demand.
     *
     * @param  array{id: int, uuid: string, target_stock: int}  $medication
     */
    private function ensureSufficientStock(array $medication, int $quantityNeeded, User $actor): void
    {
        $current = Medication::query()->findOrFail($medication['id'])->current_stock;

        if ($current >= $quantityNeeded) {
            return;
        }

        $locked = $this->stockLedger->lock([$medication['id']])->get($medication['id']);
        $topUp = max($quantityNeeded, $medication['target_stock']) - $locked->current_stock;

        if ($topUp > 0) {
            $this->stockLedger->record(
                $locked,
                InventoryMovementType::Entry,
                $topUp,
                $actor,
                notes: 'Reabastecimiento de emergencia por demanda inesperada.',
            );
        }
    }

    /**
     * Resolve a catalog enum case name (e.g. `"Serious"`) to its backed
     * enum instance for the given enum class.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function resolveEnumCase(string $enumClass, string $caseName): BackedEnum
    {
        return constant("{$enumClass}::{$caseName}");
    }

    /**
     * Sample plausible vital signs within the case's `[min, max]` ranges,
     * keeping the diastolic reading strictly below the systolic reading.
     *
     * @param  array<string, array{0: int|float, 1: int|float}>  $ranges
     * @return array<string, mixed>
     */
    private function sampleVitalSigns(array $ranges, Generator $faker): array
    {
        [$diastolicMin, $diastolicMax] = $ranges['blood_pressure_diastolic'];
        [$systolicMin, $systolicMax] = $ranges['blood_pressure_systolic'];

        $diastolic = $faker->numberBetween($diastolicMin, min($diastolicMax, $systolicMax - 10));
        $systolic = $faker->numberBetween(max($systolicMin, $diastolic + 10), $systolicMax);

        return [
            'weight' => $faker->randomFloat(2, ...$ranges['weight']),
            'height' => $faker->randomFloat(2, ...$ranges['height']),
            'blood_pressure_systolic' => $systolic,
            'blood_pressure_diastolic' => $diastolic,
            'heart_rate' => $faker->numberBetween(...$ranges['heart_rate']),
            'respiratory_rate' => $faker->numberBetween(...$ranges['respiratory_rate']),
            'temperature' => $faker->randomFloat(1, ...$ranges['temperature']),
            'oxygen_saturation' => $faker->numberBetween(...$ranges['oxygen_saturation']),
            'glasgow' => $faker->numberBetween(...$ranges['glasgow']),
            'glucose' => isset($ranges['glucose']) ? $faker->numberBetween(...$ranges['glucose']) : null,
        ];
    }

    /**
     * Create the regulation (transfer) row for a consultation, delayed
     * {@see self::REGULATION_DELAY_MIN_MINUTES}-{@see self::REGULATION_DELAY_MAX_MINUTES}
     * minutes after the consultation itself, never after the real current
     * time.
     *
     * @param  array<string, string>  $regulation
     * @param  array<string, mixed>  $people
     */
    private function createRegulation(MedicalConsultation $consultation, array $regulation, Generator $faker, array $people, Carbon $at, Carbon $now): void
    {
        $regulatedAt = $at->copy()->addMinutes(
            $faker->numberBetween(self::REGULATION_DELAY_MIN_MINUTES, self::REGULATION_DELAY_MAX_MINUTES)
        );

        if ($regulatedAt->greaterThan($now)) {
            $regulatedAt = $now->copy();
        }

        // Eloquent's date cast serializes a Carbon instance using its own
        // timezone verbatim (unlike `now()`, which is converted implicitly);
        // an uncoverted `America/Mexico_City` instant would be misstored as
        // if it were already UTC in this `timestamptz` column.
        $consultation->regulation()->create([
            'transfer_type' => $this->resolveEnumCase(TransferType::class, $regulation['transfer_type']),
            'ambulance_registration' => 'AMB-'.$faker->numerify('#####'),
            'regulation_number' => 'REG-'.$faker->numerify('#####'),
            'clinic_id' => $regulation['clinic'],
            'regulated_at' => $regulatedAt->setTimezone('UTC'),
            'receiver_physician' => $this->randomReceiverPhysicianName($people, $faker),
        ]);
    }

    /**
     * Build a realistic receiving physician's name from the curated
     * catalog pools, reusing the same convention as `DemoUserSeeder`.
     *
     * @param  array<string, mixed>  $people
     */
    private function randomReceiverPhysicianName(array $people, Generator $faker): string
    {
        $isFemale = $faker->boolean();
        $firstName = $faker->randomElement($isFemale ? $people['first_names_female'] : $people['first_names_male']);
        $lastName = $faker->randomElement($people['surnames']);
        $secondLastName = $faker->randomElement($people['surnames']);

        return ($isFemale ? 'Dra. ' : 'Dr. ')."{$firstName} {$lastName} {$secondLastName}";
    }
}
