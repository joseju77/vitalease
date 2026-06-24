<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(FamilyMedicalUnitSeeder::class);
        $this->call(EnrollmentSeeder::class);
    }
}
