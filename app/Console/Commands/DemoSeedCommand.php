<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('demo:seed {--users=8 : Total demo users, including the 3 fixed accounts} {--patients=80 : Number of demo patients} {--consultations=250 : Number of demo consultations}')]
#[Description('Seed realistic demo users, patients and consultations for local/staging demonstrations (fresh database only)')]
class DemoSeedCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('Demo data seeding is not allowed in production.');

            return self::FAILURE;
        }

        $users = filter_var($this->option('users'), FILTER_VALIDATE_INT);
        $patients = filter_var($this->option('patients'), FILTER_VALIDATE_INT);
        $consultations = filter_var($this->option('consultations'), FILTER_VALIDATE_INT);

        if ($users === false || $users < 3) {
            $this->components->error('The --users option must be an integer of at least 3 (super-admin, demo physician, and administrador).');

            return self::FAILURE;
        }

        if ($patients === false || $patients < 1) {
            $this->components->error('The --patients option must be a positive integer.');

            return self::FAILURE;
        }

        if ($consultations === false || $consultations < 0) {
            $this->components->error('The --consultations option must be a non-negative integer.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--no-interaction' => true]);

        try {
            app(DemoSeeder::class)
                ->setContainer($this->laravel)
                ->setCommand($this)
                ->__invoke(['users' => $users, 'patients' => $patients, 'consultations' => $consultations]);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->call('scout:sync-index-settings');
        $this->call('scout:import', ['model' => 'App\Models\Patient']);
        $this->call('scout:import', ['model' => 'App\Models\Medication']);

        $this->components->info("Seeded {$users} demo users, {$patients} demo patients and {$consultations} demo consultations.");
        $this->components->twoColumnDetail('Demo physician login', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL);

        return self::SUCCESS;
    }
}
