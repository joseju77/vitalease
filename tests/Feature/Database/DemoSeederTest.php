<?php

use App\Enums\SexAtBirth;
use App\Models\MedicalConsultation;
use App\Models\MedicalRegulation;
use App\Models\Patient;
use App\Models\PatientGynecologicalHistory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\FamilyMedicalUnitSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

function seedDemoCatalogs(): void
{
    (new RolePermissionSeeder)->run();
    (new LocationSeeder)->run();
    (new FamilyMedicalUnitSeeder)->run();
    (new EnrollmentSeeder)->run();
}

function seedSmallDemoData(int $users = 4, int $patients = 6, int $consultations = 15): void
{
    seedDemoCatalogs();

    app(DemoSeeder::class)->setContainer(app())->__invoke([
        'users' => $users,
        'patients' => $patients,
        'consultations' => $consultations,
    ]);
}

describe('DemoSeeder', function () {
    it('creates the requested counts of users, patients, and consultations with no lorem markers and date-consistent codes', function () {
        seedSmallDemoData(users: 5, patients: 10, consultations: 30);

        expect(User::query()->count())->toBe(5)
            ->and(Patient::query()->count())->toBe(10)
            ->and(MedicalConsultation::query()->count())->toBe(30);

        $loremPattern = '/lorem|ipsum/i';

        foreach (MedicalConsultation::query()->get(['current_condition', 'diagnosis']) as $consultation) {
            expect($consultation->current_condition)->not->toMatch($loremPattern)
                ->and($consultation->diagnosis)->not->toMatch($loremPattern);
        }

        foreach (Patient::query()->get(['first_name', 'last_name']) as $patient) {
            expect($patient->first_name)->not->toMatch($loremPattern)
                ->and($patient->last_name)->not->toMatch($loremPattern);
        }

        foreach (User::query()->pluck('name') as $name) {
            expect($name)->not->toMatch($loremPattern);
        }

        $consultations = MedicalConsultation::query()->orderBy('created_at')->get(['code', 'created_at']);

        expect($consultations->pluck('code')->unique())->toHaveCount(30);

        $byDay = $consultations->groupBy(
            fn (MedicalConsultation $consultation): string => $consultation->created_at->setTimezone('America/Mexico_City')->format('ymd')
        );

        foreach ($byDay as $day => $dayConsultations) {
            foreach ($dayConsultations->values() as $index => $consultation) {
                expect($consultation->code)->toMatch('/^MC-\d{6}-\d{4}$/')
                    ->and($consultation->code)->toBe(sprintf('MC-%s-%04d', $day, $index + 1));
            }
        }
    });

    it('creates gynecological history only for female patients, keeps regulated_at after created_at, and restores the frozen test time', function () {
        seedSmallDemoData(users: 4, patients: 20, consultations: 80);

        $malePatientIds = Patient::query()->where('sex_at_birth', SexAtBirth::Male)->pluck('id');
        $femalePatientIds = Patient::query()->where('sex_at_birth', SexAtBirth::Female)->pluck('id');

        expect(PatientGynecologicalHistory::query()->whereIn('patient_id', $malePatientIds)->exists())->toBeFalse();

        if ($femalePatientIds->isNotEmpty()) {
            expect(PatientGynecologicalHistory::query()->whereIn('patient_id', $femalePatientIds)->count())
                ->toBe($femalePatientIds->count());
        }

        $regulations = MedicalRegulation::query()->with('medicalConsultation')->get();

        expect($regulations)->not->toBeEmpty();

        foreach ($regulations as $regulation) {
            expect($regulation->regulated_at->greaterThan($regulation->medicalConsultation->created_at))->toBeTrue()
                ->and($regulation->regulated_at->lessThanOrEqualTo(Carbon::now()))->toBeTrue();
        }

        expect(Carbon::getTestNow())->toBeNull();
    });

    it('refuses to run in production before writing any data', function () {
        app()['env'] = 'production';

        expect(fn () => app(DemoSeeder::class)->setContainer(app())->__invoke(['users' => 4, 'patients' => 6, 'consultations' => 15]))
            ->toThrow(RuntimeException::class);

        expect(User::query()->count())->toBe(0)
            ->and(Patient::query()->count())->toBe(0)
            ->and(MedicalConsultation::query()->count())->toBe(0);
    });

    it('refuses a second run once the demo physician account already exists', function () {
        seedSmallDemoData();

        $usersAfterFirstRun = User::query()->count();
        $patientsAfterFirstRun = Patient::query()->count();

        expect(fn () => app(DemoSeeder::class)->setContainer(app())->__invoke(['users' => 4, 'patients' => 6, 'consultations' => 15]))
            ->toThrow(RuntimeException::class);

        expect(User::query()->count())->toBe($usersAfterFirstRun)
            ->and(Patient::query()->count())->toBe($patientsAfterFirstRun);
    });
});

describe('demo:seed command', function () {
    it('seeds users, patients, and consultations end-to-end and reports the demo physician login', function () {
        $this->artisan('demo:seed', ['--users' => 3, '--patients' => 1, '--consultations' => 0])
            ->assertSuccessful();

        expect(User::query()->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->exists())->toBeTrue()
            ->and(Patient::query()->count())->toBe(1)
            ->and(MedicalConsultation::query()->count())->toBe(0);
    });

    it('refuses to run in production without writing any data', function () {
        app()['env'] = 'production';

        $this->artisan('demo:seed', ['--users' => 4, '--patients' => 6, '--consultations' => 15])
            ->assertFailed();

        expect(User::query()->count())->toBe(0);
    });

    it('rejects a --users option below the 3 fixed accounts', function () {
        $this->artisan('demo:seed', ['--users' => 2])->assertFailed();

        expect(User::query()->count())->toBe(0);
    });

    it('rejects a non-positive --patients option', function () {
        $this->artisan('demo:seed', ['--patients' => 0])->assertFailed();

        expect(User::query()->count())->toBe(0);
    });

    it('rejects a negative --consultations option', function () {
        $this->artisan('demo:seed', ['--consultations' => -1])->assertFailed();

        expect(User::query()->count())->toBe(0);
    });
});

describe('default DatabaseSeeder', function () {
    it('creates no demo users, patients, or consultations', function () {
        (new DatabaseSeeder)->run();

        expect(User::query()->count())->toBe(0)
            ->and(Patient::query()->count())->toBe(0)
            ->and(MedicalConsultation::query()->count())->toBe(0);
    });
});
