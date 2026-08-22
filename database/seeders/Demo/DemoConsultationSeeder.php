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
    private const int CONSULTATION_COUNT = 600;

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
     * How many days back from today consultations may be dated (roughly
     * twelve months). Public so {@see DemoMedicationSeeder} can anchor its
     * initial stock entries before the earliest possible consultation.
     */
    public const int LOOKBACK_DAYS = 365;

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
     * The lookback window is treated as {@see self::MONTHS_IN_WINDOW} equal
     * months (for monthly restocks and demand-growth weighting) and
     * {@see self::QUARTERS_IN_WINDOW} equal quarters (for inventory
     * adjustments).
     */
    private const int MONTHS_IN_WINDOW = 12;

    private const int QUARTERS_IN_WINDOW = 4;

    /**
     * Hour of day, in `America/Mexico_City`, at which monthly restocks and
     * quarterly adjustments are recorded.
     */
    private const int RESTOCK_HOUR = 7;

    private const int ADJUSTMENT_HOUR = 20;

    /**
     * How many medications are sampled for a quarterly adjustment, and the
     * inclusive range (in absolute units) each sampled adjustment removes.
     */
    private const int QUARTERLY_ADJUSTMENT_SAMPLE_SIZE = 3;

    private const int QUARTERLY_ADJUSTMENT_MIN = 1;

    private const int QUARTERLY_ADJUSTMENT_MAX = 3;

    /**
     * Spanish reasons cited for a quarterly stock adjustment.
     *
     * @var list<string>
     */
    private const array ADJUSTMENT_NOTES = [
        'Merma por caducidad detectada en revisión trimestral.',
        'Ajuste por unidades dañadas durante el almacenamiento.',
        'Diferencia detectada en el conteo físico de inventario.',
    ];

    /**
     * Seed `$consultations` realistic demo consultations from a curated
     * Spanish clinical-case catalog, interleaved chronologically with
     * monthly restocks and quarterly inventory adjustments, all spread over
     * the last {@see self::LOOKBACK_DAYS} days (consultations mostly on
     * weekdays, business hours in `America/Mexico_City`, never after
     * "now"). Cases with a `demand_growth` factor are weighted more heavily
     * as the window progresses, producing a visible upward consumption
     * trend for their medications. Every event is created with its
     * timestamp pinned via `Carbon::withTestNow()`, so
     * `MedicalConsultationCode::next()` generates consultation codes whose
     * date segment matches the assigned Mexico City calendar day.
     *
     * Requires `DemoUserSeeder`, `DemoPatientSeeder`, and
     * `DemoMedicationSeeder` to have already run: consultations reference
     * the demo physician, the other seeded physicians (role `Médico`), the
     * seeded patients, and every stock movement goes through
     * {@see TreatmentDispensation} and {@see StockLedger}.
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
        $inventoryActor = User::role('Administrador')->first() ?? $demoPhysician;

        $patientsBySex = Patient::query()->get(['id', 'sex_at_birth'])->groupBy(
            fn (Patient $patient): string => $patient->sex_at_birth->name
        );
        $allPatients = Patient::query()->pluck('id');
        $medicationIndex = $this->buildMedicationIndex();

        $now = Carbon::now('America/Mexico_City');
        $windowStart = $now->copy()->subDays(self::LOOKBACK_DAYS)->startOfDay();

        $events = [
            ...$this->buildConsultationEvents($consultations, $faker, $now),
            ...$this->buildRestockEvents($windowStart, $now),
            ...$this->buildAdjustmentEvents($windowStart, $now),
        ];

        usort($events, fn (array $a, array $b): int => $a['at']->timestamp <=> $b['at']->timestamp);

        $context = [
            'cases' => $cases,
            'people' => $people,
            'faker' => $faker,
            'patientsBySex' => $patientsBySex,
            'allPatients' => $allPatients,
            'medicationIndex' => $medicationIndex,
            'demoPhysician' => $demoPhysician,
            'otherPhysicians' => $otherPhysicians,
            'inventoryActor' => $inventoryActor,
            'now' => $now,
            'windowStart' => $windowStart,
        ];

        foreach ($events as $event) {
            Carbon::withTestNow($event['at'], function () use ($event, $context): void {
                DB::transaction(function () use ($event, $context): void {
                    $this->processEvent($event, $context);
                });
            });
        }
    }

    /**
     * Dispatch one chronological-timeline event to its handler.
     *
     * @param  array{type: string, at: Carbon}  $event
     * @param  array<string, mixed>  $context
     */
    private function processEvent(array $event, array $context): void
    {
        match ($event['type']) {
            'consultation' => $this->processConsultationEvent($event, $context),
            'restock' => $this->processMonthlyRestock($context['medicationIndex'], $context['inventoryActor']),
            'adjustment' => $this->processQuarterlyAdjustment($context['medicationIndex'], $context['faker'], $context['inventoryActor']),
        };
    }

    /**
     * Pick a demand-weighted case and eligible patient/physician for a
     * `consultation` event, then create the full consultation aggregate.
     *
     * @param  array{type: string, at: Carbon}  $event
     * @param  array<string, mixed>  $context
     */
    private function processConsultationEvent(array $event, array $context): void
    {
        $monthIndex = $this->monthIndexFor($event['at'], $context['windowStart']);
        $case = $this->pickWeightedCase($context['cases'], $monthIndex, $context['faker']);
        $patientId = $this->pickPatientId($case, $context['patientsBySex'], $context['allPatients']);

        if ($patientId === null) {
            return;
        }

        $physician = $context['faker']->boolean(self::DEMO_PHYSICIAN_SHARE_PERCENT) || $context['otherPhysicians']->isEmpty()
            ? $context['demoPhysician']
            : $context['otherPhysicians']->random();

        $this->createConsultation(
            $case,
            $patientId,
            $physician,
            $context['faker'],
            $context['people'],
            $event['at'],
            $context['now'],
            $context['medicationIndex'],
        );
    }

    /**
     * Build one `consultation` event per generated datetime.
     *
     * @return list<array{type: string, at: Carbon}>
     */
    private function buildConsultationEvents(int $count, Generator $faker, Carbon $now): array
    {
        return array_map(
            fn (Carbon $at): array => ['type' => 'consultation', 'at' => $at],
            $this->generateDatetimes($count, $faker, $now)
        );
    }

    /**
     * Build one monthly `restock` event per {@see self::MONTHS_IN_WINDOW}
     * equal slice of the lookback window, never after "now".
     *
     * @return list<array{type: string, at: Carbon}>
     */
    private function buildRestockEvents(Carbon $windowStart, Carbon $now): array
    {
        $events = [];
        $monthLength = max(1, intdiv(self::LOOKBACK_DAYS, self::MONTHS_IN_WINDOW));

        for ($month = 0; $month < self::MONTHS_IN_WINDOW; $month++) {
            $at = $windowStart->copy()->addDays($month * $monthLength)->setTime(self::RESTOCK_HOUR, 0, 0);

            if ($at->greaterThan($now)) {
                break;
            }

            $events[] = ['type' => 'restock', 'at' => $at];
        }

        return $events;
    }

    /**
     * Build one quarterly `adjustment` event per {@see self::QUARTERS_IN_WINDOW}
     * equal slice of the lookback window, never after "now".
     *
     * @return list<array{type: string, at: Carbon}>
     */
    private function buildAdjustmentEvents(Carbon $windowStart, Carbon $now): array
    {
        $events = [];
        $quarterLength = max(1, intdiv(self::LOOKBACK_DAYS, self::QUARTERS_IN_WINDOW));

        for ($quarter = 1; $quarter <= self::QUARTERS_IN_WINDOW; $quarter++) {
            $at = $windowStart->copy()->addDays($quarter * $quarterLength)->setTime(self::ADJUSTMENT_HOUR, 0, 0);

            if ($at->greaterThan($now)) {
                break;
            }

            $events[] = ['type' => 'adjustment', 'at' => $at];
        }

        return $events;
    }

    /**
     * The zero-based index (0..{@see self::MONTHS_IN_WINDOW}-1) of the
     * equal-length month slice `$at` falls into, relative to the window
     * start. Used to weight demand-growth cases more heavily as the window
     * progresses.
     */
    private function monthIndexFor(Carbon $at, Carbon $windowStart): int
    {
        $monthLength = max(1, intdiv(self::LOOKBACK_DAYS, self::MONTHS_IN_WINDOW));
        $daysSinceStart = max(0, intdiv($at->timestamp - $windowStart->timestamp, 86400));

        return min(self::MONTHS_IN_WINDOW - 1, intdiv($daysSinceStart, $monthLength));
    }

    /**
     * Pick a random case weighted by its optional `demand_growth` factor:
     * weight is `1 + demand_growth * monthIndex / (MONTHS_IN_WINDOW - 1)`,
     * so a case without `demand_growth` always has weight 1 while a growth
     * case's weight increases across the window, biasing its medications
     * towards a visible upward consumption trend.
     *
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function pickWeightedCase(array $cases, int $monthIndex, Generator $faker): array
    {
        $weights = array_map(
            fn (array $case): float => 1 + ($case['demand_growth'] ?? 0) * $monthIndex / (self::MONTHS_IN_WINDOW - 1),
            $cases
        );

        $target = $faker->randomFloat(6, 0, array_sum($weights));
        $cumulative = 0.0;

        foreach ($cases as $index => $case) {
            $cumulative += $weights[$index];

            if ($target <= $cumulative) {
                return $case;
            }
        }

        return end($cases);
    }

    /**
     * Top up every medication to its catalog target stock. Recorded once a
     * month so the ledger keeps drawing from a realistic, replenished
     * supply rather than depleting to zero over the year.
     *
     * @param  array<string, array{id: int, uuid: string, target_stock: int}>  $medicationIndex
     */
    private function processMonthlyRestock(array $medicationIndex, User $actor): void
    {
        foreach ($medicationIndex as $medication) {
            $locked = $this->stockLedger->lock([$medication['id']])->get($medication['id']);
            $topUp = $medication['target_stock'] - $locked->current_stock;

            if ($topUp > 0) {
                $this->stockLedger->record($locked, InventoryMovementType::Entry, $topUp, $actor);
            }
        }
    }

    /**
     * Record a small negative `Adjustment` (shrinkage, damage, or a count
     * discrepancy) on a handful of randomly sampled medications, skipping
     * any medication the adjustment would drive negative.
     *
     * @param  array<string, array{id: int, uuid: string, target_stock: int}>  $medicationIndex
     */
    private function processQuarterlyAdjustment(array $medicationIndex, Generator $faker, User $actor): void
    {
        $sample = $faker->randomElements(
            array_values($medicationIndex),
            min(self::QUARTERLY_ADJUSTMENT_SAMPLE_SIZE, count($medicationIndex)),
        );

        foreach ($sample as $medication) {
            $locked = $this->stockLedger->lock([$medication['id']])->get($medication['id']);
            $quantity = -$faker->numberBetween(self::QUARTERLY_ADJUSTMENT_MIN, self::QUARTERLY_ADJUSTMENT_MAX);

            if ($locked->current_stock + $quantity < 0) {
                continue;
            }

            $this->stockLedger->record(
                $locked,
                InventoryMovementType::Adjustment,
                $quantity,
                $actor,
                notes: $faker->randomElement(self::ADJUSTMENT_NOTES),
            );
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
