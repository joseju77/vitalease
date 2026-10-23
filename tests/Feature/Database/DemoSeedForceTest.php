<?php

use App\Models\Enrollment;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;

describe('demo:seed --force', function () {
    it('refuses in production without --force and seeds nothing, not even the catalogs', function () {
        app()['env'] = 'production';

        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 3, '--consultations' => 5])
            ->expectsOutputToContain('not allowed in production')
            ->assertFailed();

        expect(User::query()->count())->toBe(0)
            ->and(Patient::query()->count())->toBe(0)
            ->and(MedicalConsultation::withTrashed()->count())->toBe(0);
    });

    it('seeds in production when --force is given, including the catalogs seeded by db:seed', function () {
        app()['env'] = 'production';

        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 3, '--consultations' => 0, '--force' => true])
            ->assertSuccessful();

        expect(User::query()->count())->toBe(4)
            ->and(Patient::query()->count())->toBe(3)
            ->and(Medication::query()->exists())->toBeTrue()
            ->and(Enrollment::query()->exists())->toBeTrue();
    });

    it('keeps the other guards active when forced', function () {
        app()['env'] = 'production';

        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 2, '--consultations' => 0, '--force' => true])
            ->assertSuccessful();

        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 2, '--consultations' => 0, '--force' => true])
            ->assertFailed();

        expect(Patient::query()->count())->toBe(2);
    });
});

describe('demo:seed --memory-limit', function () {
    it('rejects a malformed value before seeding anything', function (string $value) {
        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 1, '--consultations' => 0, '--memory-limit' => $value])
            ->assertFailed();

        expect(User::query()->count())->toBe(0);
    })->with(['1 gigabyte', '12.5M', 'G', '--1']);

    it('applies a valid value for the run', function () {
        $previous = ini_get('memory_limit');

        try {
            $this->artisan('demo:seed', ['--users' => 4, '--patients' => 1, '--consultations' => 0, '--memory-limit' => '768M'])
                ->assertSuccessful();

            expect(ini_get('memory_limit'))->toBe('768M');
        } finally {
            ini_set('memory_limit', $previous);
        }
    });
});
