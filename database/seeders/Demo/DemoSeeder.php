<?php

namespace Database\Seeders\Demo;

use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Medication;
use App\Models\Neighborhood;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DemoSeeder extends Seeder
{
    /**
     * Orchestrate realistic demo data for local/staging demonstrations:
     * `$users` users covering every role (including the 3 fixed accounts),
     * `$patients` realistic patients, a demo medication catalog with its
     * starting stock, and `$consultations` realistic medical consultations
     * (with treatment lines dispensing that stock) spread over the last
     * {@see DemoConsultationSeeder::LOOKBACK_DAYS}
     * days.
     *
     * Not idempotent by design: run once against a fresh database, after
     * the catalog seeders (RolePermissionSeeder, LocationSeeder,
     * FamilyMedicalUnitSeeder, EnrollmentSeeder). See `php artisan demo:seed`.
     *
     * @throws RuntimeException
     */
    public function run(int $users = 8, int $patients = 80, int $consultations = 600): void
    {
        $this->guardAgainstUnsafeRun();

        $this->call(DemoUserSeeder::class, parameters: ['users' => $users]);
        $this->call(DemoPatientSeeder::class, parameters: ['count' => $patients]);

        // Medications are created and repeatedly restocked/dispensed while
        // seeding; deferring their search index sync to a single bulk
        // `scout:import` (run by `demo:seed` once seeding finishes) avoids
        // one sync job per stock movement.
        Medication::withoutSyncingToSearch(function () use ($consultations): void {
            $this->call(DemoMedicationSeeder::class);
            $this->call(DemoConsultationSeeder::class, parameters: ['consultations' => $consultations]);
        });
    }

    /**
     * Refuse to seed demo data in production, before the required catalogs
     * exist, or when the demo data already appears to be seeded.
     *
     * @throws RuntimeException
     */
    private function guardAgainstUnsafeRun(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data seeding is not allowed in production.');
        }

        if (Role::where('name', 'super-admin')->where('guard_name', 'web')->doesntExist()) {
            throw new RuntimeException('Required catalogs are missing: run RolePermissionSeeder before seeding demo data.');
        }

        if (Enrollment::query()->doesntExist() || FamilyMedicalUnit::query()->doesntExist() || Neighborhood::query()->doesntExist()) {
            throw new RuntimeException('Required catalogs are missing: run LocationSeeder, FamilyMedicalUnitSeeder, and EnrollmentSeeder before seeding demo data.');
        }

        if (User::query()->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->exists()) {
            throw new RuntimeException('Demo data already appears to be seeded: the demo physician account already exists.');
        }

        if (Medication::query()->exists()) {
            throw new RuntimeException('Demo data already appears to be seeded: medications already exist.');
        }
    }
}
