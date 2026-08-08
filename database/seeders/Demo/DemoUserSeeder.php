<?php

namespace Database\Seeders\Demo;

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\Concerns\LoadsCatalog;
use Faker\Generator;
use Illuminate\Database\Seeder;
use JsonException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoUserSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * Deterministic email for the demo physician account. `DemoSeeder`'s
     * guard checks this exact address to detect a previous run.
     */
    public const string DEMO_PHYSICIAN_EMAIL = 'medico.demo@vitalease.test';

    /**
     * Number of fixed demo accounts (super-admin, demo physician,
     * administrador) counted towards the `$users` total.
     */
    private const int FIXED_ACCOUNT_COUNT = 3;

    /**
     * Seed realistic demo users covering every role the application
     * distinguishes: a super-admin, a demo physician with full clinical
     * access, `$users - 3` other physicians, and an administrator. Names
     * come from a curated Mexican pool (fakerphp ships no `es_MX` locale)
     * and a dedicated `es_ES`-scoped Faker instance, so the application's
     * default Faker locale is never touched.
     *
     * @throws JsonException
     */
    public function run(int $users = 8): void
    {
        $people = $this->loadCatalog('demo/people.json');
        $faker = fake('es_ES');
        $physicianCount = max(0, $users - self::FIXED_ACCOUNT_COUNT);

        $medico = Role::findOrCreate('Médico', 'web');
        $medico->syncPermissions([
            Permission::ConsultationsView->value,
            Permission::ConsultationsCreate->value,
            Permission::ConsultationsUpdate->value,
            Permission::ConsultationsDelete->value,
        ]);

        $administrador = Role::findOrCreate('Administrador', 'web');
        $administrador->syncPermissions([
            Permission::UsersManage->value,
            Permission::RolesManage->value,
        ]);

        User::factory()->superAdmin()->create([
            'name' => $this->randomFullName($people, $faker, female: true),
            'email' => 'superadmin@vitalease.test',
        ]);

        $demoPhysician = User::factory()->create([
            'name' => 'Dra. '.$this->randomFullName($people, $faker, female: true),
            'email' => self::DEMO_PHYSICIAN_EMAIL,
        ]);
        $demoPhysician->assignRole($medico);
        $demoPhysician->givePermissionTo(Permission::PatientsView->value);

        for ($index = 1; $index <= $physicianCount; $index++) {
            $isFemale = $faker->boolean();

            User::factory()
                ->create([
                    'name' => ($isFemale ? 'Dra. ' : 'Dr. ').$this->randomFullName($people, $faker, $isFemale),
                    'email' => "medico{$index}@vitalease.test",
                ])
                ->assignRole($medico);
        }

        User::factory()
            ->create([
                'name' => $this->randomFullName($people, $faker, female: true),
                'email' => 'administrador@vitalease.test',
            ])
            ->assignRole($administrador);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Build a realistic two-surname Mexican full name from the curated
     * catalog pools.
     *
     * @param  array<string, mixed>  $people
     */
    private function randomFullName(array $people, Generator $faker, bool $female): string
    {
        $firstName = $faker->randomElement($female ? $people['first_names_female'] : $people['first_names_male']);
        $lastName = $faker->randomElement($people['surnames']);
        $secondLastName = $faker->randomElement($people['surnames']);

        return "{$firstName} {$lastName} {$secondLastName}";
    }
}
