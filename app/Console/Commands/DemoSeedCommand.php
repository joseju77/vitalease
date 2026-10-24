<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('demo:seed {--users=8 : Total demo users, including the 4 fixed accounts} {--patients=80 : Number of demo patients} {--consultations=600 : Number of demo consultations} {--force : Seed even in production (required for the Swarm stack)} {--memory-limit= : PHP memory limit for this run, e.g. 1G (large datasets)}')]
#[Description('Seed realistic demo users, patients and consultations for local/staging demonstrations (fresh database only)')]
class DemoSeedCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (app()->isProduction() && ! $force) {
            $this->components->error('Demo data seeding is not allowed in production without --force.');

            return self::FAILURE;
        }

        $users = filter_var($this->option('users'), FILTER_VALIDATE_INT);
        $patients = filter_var($this->option('patients'), FILTER_VALIDATE_INT);
        $consultations = filter_var($this->option('consultations'), FILTER_VALIDATE_INT);

        if ($users === false || $users < 4) {
            $this->components->error('The --users option must be an integer of at least 4 (super-admin, demo physician, administrador, and medical coordination).');

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

        $memoryLimit = $this->option('memory-limit');

        if ($memoryLimit !== null) {
            if (! is_string($memoryLimit) || preg_match('/^(-1|\d+[KMG]?)$/i', $memoryLimit) !== 1) {
                $this->components->error('The --memory-limit option must be -1 or an integer with an optional K, M or G suffix (e.g. 1G).');

                return self::FAILURE;
            }

            ini_set('memory_limit', $memoryLimit);
        }

        // db:seed has its own production confirmation, so --force is propagated.
        $this->call('db:seed', ['--no-interaction' => true] + ($force ? ['--force' => true] : []));

        try {
            app(DemoSeeder::class)
                ->setContainer($this->laravel)
                ->setCommand($this)
                ->__invoke(['users' => $users, 'patients' => $patients, 'consultations' => $consultations, 'force' => $force]);
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
