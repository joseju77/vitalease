<?php

namespace Database\Seeders;

use App\Models\Enrollment;
use Database\Seeders\Concerns\LoadsCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;

class EnrollmentSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $entries = $this->loadCatalog('enrollments.json');

        DB::transaction(function () use ($entries) {
            $this->upsertRows(Enrollment::class, $entries, ['name', 'segment'], ['name', 'segment']);
        });
    }
}
