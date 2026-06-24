<?php

namespace Database\Seeders;

use App\Models\FamilyMedicalUnit;
use Database\Seeders\Concerns\LoadsCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;

class FamilyMedicalUnitSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $entries = $this->loadCatalog('family_medical_units.json');

        DB::transaction(function () use ($entries) {
            $this->upsertRows(FamilyMedicalUnit::class, $entries, ['name'], ['address']);
        });
    }
}
