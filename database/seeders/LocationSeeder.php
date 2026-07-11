<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\ZipCode;
use Database\Seeders\Concerns\LoadsCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;

class LocationSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $entries = $this->loadCatalog('location.json');

        DB::transaction(function () use ($entries) {
            foreach ($entries as $municipalityEntry) {
                $municipality = Municipality::query()->updateOrCreate(
                    ['name' => $municipalityEntry['name']],
                );

                foreach ($municipalityEntry['zip_codes'] as $zipCodeEntry) {
                    $zipCode = ZipCode::query()->updateOrCreate(
                        ['code' => $zipCodeEntry['code']],
                        ['municipality_id' => $municipality->id],
                    );

                    foreach ($zipCodeEntry['neighborhoods'] as $neighborhoodName) {
                        Neighborhood::query()->updateOrCreate(
                            ['name' => $neighborhoodName, 'zip_code' => $zipCode->code],
                        );
                    }
                }
            }
        });
    }
}
