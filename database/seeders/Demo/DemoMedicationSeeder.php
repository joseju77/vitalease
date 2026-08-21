<?php

namespace Database\Seeders\Demo;

use App\Enums\InventoryMovementType;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\Concerns\LoadsCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class DemoMedicationSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * Seed the demo medication catalog and record every medication's
     * starting stock as an `Entry` movement through {@see StockLedger},
     * dated one day before the earliest possible demo consultation so the
     * ledger always has stock to draw from once dispensations begin.
     *
     * Requires `DemoUserSeeder` to have already run: the initial entries are
     * attributed to the first seeded `Administrador`, falling back to the
     * demo physician when no administrator exists yet.
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function run(StockLedger $stockLedger): void
    {
        if (Medication::query()->exists()) {
            throw new RuntimeException('Demo data already appears to be seeded: medications already exist.');
        }

        $catalog = $this->loadCatalog('demo/medications.json');
        $actor = $this->resolveActor();
        $windowStart = Carbon::now('America/Mexico_City')
            ->subDays(DemoConsultationSeeder::LOOKBACK_DAYS)
            ->subDay()
            ->startOfDay();

        Carbon::withTestNow($windowStart, function () use ($catalog, $actor, $stockLedger): void {
            DB::transaction(function () use ($catalog, $actor, $stockLedger): void {
                foreach ($catalog as $entry) {
                    $medication = Medication::query()->create([
                        'name' => $entry['name'],
                        'presentation' => $entry['presentation'],
                        'concentration' => $entry['concentration'],
                        'dispensing_unit' => $entry['dispensing_unit'],
                        'minimum_stock' => $entry['minimum_stock'],
                    ]);

                    $locked = $stockLedger->lock([$medication->id])->get($medication->id);

                    $stockLedger->record($locked, InventoryMovementType::Entry, $entry['target_stock'], $actor);
                }
            });
        });
    }

    /**
     * Resolve the actor attributed to the initial stock entries: the first
     * seeded `Administrador`, or the demo physician when no administrator
     * exists yet.
     */
    private function resolveActor(): User
    {
        return User::role('Administrador')->first()
            ?? User::query()->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->firstOrFail();
    }
}
