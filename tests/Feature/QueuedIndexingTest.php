<?php

use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Inventory\TreatmentDispensation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Jobs\MakeSearchable;
use Meilisearch\Exceptions\CommunicationException;

describe('queued search indexing', function () {
    beforeEach(function () {
        config(['scout.queue' => true]);
    });

    it('dispatches the indexing job only after the surrounding transaction commits', function () {
        Queue::fake();

        DB::transaction(function () {
            Patient::factory()->create();

            Queue::assertNotPushed(MakeSearchable::class);
        });

        Queue::assertPushed(MakeSearchable::class, 1);
    });

    it('never dispatches the indexing job when the surrounding transaction rolls back', function () {
        Queue::fake();

        try {
            DB::transaction(function () {
                Patient::factory()->create();

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // The rollback is the behavior under test.
        }

        Queue::assertNothingPushed();
        expect(Patient::query()->count())->toBe(0);
    });

    it('applies the configured tries and backoff to the indexing job', function () {
        config(['scout.jobs.tries' => 7, 'scout.jobs.backoff' => [2, 4, 8]]);

        $job = new MakeSearchable(new Collection);

        expect($job->tries)->toBe(7)
            ->and($job->backoff)->toBe([2, 4, 8]);
    });

    it('ships a retry window of about 49 minutes by default', function () {
        $job = new MakeSearchable(new Collection);

        // 15 tries means 14 waits: 5, 15, 30, 60, 120 and nine times 300 seconds.
        $waits = collect(range(1, $job->tries - 1))
            ->sum(fn (int $attempt): int => $job->backoff[min($attempt, count($job->backoff)) - 1]);

        expect($job->tries)->toBe(15)
            ->and($waits)->toBe(2930);
    });

    it('stores a job that exhausts its tries in failed_jobs', function () {
        config([
            'queue.default' => 'database',
            'scout.jobs.tries' => 1,
            'scout.driver' => 'unreachable',
        ]);
        app(EngineManager::class)->extend('unreachable', fn () => new class extends CollectionEngine
        {
            public function update($models): void
            {
                throw new CommunicationException('engine down');
            }
        });
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Patient::factory()->create();

        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $failed = DB::table('failed_jobs')->first();

        expect($failed)->not->toBeNull()
            ->and($failed->connection)->toBe('database')
            ->and($failed->payload)->toContain('MakeSearchable')
            ->and($failed->exception)->toContain('engine down')
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

it('leaves no consultation, dispensation or movement behind when the dispensation step fails', function () {
    $physician = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 10]);
    InventoryMovement::creating(function (): void {
        throw new RuntimeException('connection lost mid-dispensation');
    });

    $consultationsBefore = MedicalConsultation::query()->count();
    $movementsBefore = InventoryMovement::query()->count();

    try {
        DB::transaction(function () use ($physician, $medication) {
            $consultation = MedicalConsultation::factory()->withoutRegulation()->create([
                'physician_id' => $physician->id,
            ]);

            app(TreatmentDispensation::class)->sync($consultation, [[
                'medication_uuid' => $medication->uuid,
                'quantity_dispensed' => 2,
                'dose' => '500 mg',
                'frequency' => 'Cada 8 horas',
                'duration' => '5 días',
            ]], $physician);
        });
    } catch (RuntimeException) {
        // The rollback is the behavior under test.
    }

    expect(MedicalConsultation::query()->count())->toBe($consultationsBefore)
        ->and(InventoryMovement::query()->count())->toBe($movementsBefore)
        ->and($medication->fresh()->current_stock)->toBe(10);
});
