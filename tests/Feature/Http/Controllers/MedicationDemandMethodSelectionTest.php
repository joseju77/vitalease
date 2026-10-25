<?php

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Today" is 2026-08-15, so the last complete month is July 2026. A series of
 * N values covers the N months ending in July 2026.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15, 12, 0, 0, 'America/Mexico_City'));
});

/**
 * Create a medication whose monthly dispensed units (oldest month first) end
 * in the last complete month. Movements land mid-month, far from any
 * America/Mexico_City month boundary.
 *
 * @param  list<int>  $monthlyConsumption
 */
function medicationConsumedMonthly(array $monthlyConsumption): Medication
{
    $medication = Medication::factory()->create(['current_stock' => 4]);
    $user = User::factory()->create();
    $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
    $firstMonth = CarbonImmutable::create(2026, 7, 15, 18, 0, 0, 'UTC')->subMonthsNoOverflow(count($monthlyConsumption) - 1);

    foreach ($monthlyConsumption as $offset => $consumed) {
        if ($consumed === 0) {
            continue;
        }

        InventoryMovement::factory()->create([
            'medication_id' => $medication->id,
            'type' => InventoryMovementType::Dispensation,
            'quantity' => -$consumed,
            'stock_after' => 0,
            'medical_consultation_id' => $consultation->id,
            'medical_consultation_code' => $consultation->code,
            'user_id' => $user->id,
            'occurred_at' => $firstMonth->addMonthsNoOverflow($offset),
        ]);
    }

    return $medication;
}

function reportViewer(): User
{
    return User::factory()->withPermissions(Permission::ReportsGenerate)->create();
}

describe('method selection reaches the Inertia props', function () {
    it('selects the linear trend for a steadily growing medication', function () {
        $medication = medicationConsumedMonthly(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 11)));

        $this->actingAs(reportViewer())->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/MedicationDemand', false)
                ->where('projection.status', 'ok')
                ->where('projection.method', 'linear_trend')
                ->where('projection.method_reason', 'linear_beats_mean')
                ->where('projection.slope', 4)
                ->where('projection.next_month_demand', 58)
                ->where('projection.suggested_reorder_quantity', 54)
                ->where('projection.backtest.evaluated_points', 6)
                ->where('projection.backtest.horizon', 1)
                ->where('projection.backtest.margin', 0.05)
                ->where('projection.backtest.linear_trend.mae', 0)
                ->where('projection.backtest.historical_mean.mae', 19)
                ->where('projection.demand_pattern.class', 'smooth')
                ->where('projection.demand_pattern.seasonality', 'not_assessed')
                ->where('projection.is_indicative', true));
    });

    it('selects the historical mean for an intermittent medication', function () {
        $medication = medicationConsumedMonthly(array_merge(...array_fill(0, 6, [10, 0])));

        $this->actingAs(reportViewer())->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('projection.method', 'historical_mean')
                ->where('projection.method_reason', 'mean_beats_linear')
                ->where('projection.slope', null)
                ->where('projection.intercept', null)
                ->where('projection.r_squared', null)
                ->where('projection.next_month_demand', 5)
                ->where('projection.suggested_reorder_quantity', 1)
                ->where('projection.demand_pattern.class', 'intermittent')
                ->has('projection.fitted', 12)
                ->has('projection.projection', 3));
    });

    it('reports short_history for fewer than nine months', function () {
        $medication = medicationConsumedMonthly([3, 4, 5, 6, 7]);

        $this->actingAs(reportViewer())->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('projection.status', 'ok')
                ->where('projection.method', 'historical_mean')
                ->where('projection.method_reason', 'short_history')
                ->where('projection.backtest.evaluated_points', 0)
                ->where('projection.next_month_demand', 5));
    });

    it('keeps the insufficient-data shape, with no method, for a single month', function () {
        $medication = medicationConsumedMonthly([7]);

        $this->actingAs(reportViewer())->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('projection.status', 'insufficient_data')
                ->where('projection.method', null)
                ->where('projection.method_reason', null)
                ->where('projection.backtest', null)
                ->where('projection.demand_pattern', null)
                ->where('projection.is_indicative', true));
    });
});

describe('gating is unchanged', function () {
    it('still forbids a user without reports.generate', function () {
        $medication = medicationConsumedMonthly([3, 4, 5, 6, 7]);

        $this->actingAs(User::factory()->create())
            ->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertForbidden();
    });

    it('still redirects a guest to login', function () {
        $medication = medicationConsumedMonthly([3, 4, 5, 6, 7]);

        $this->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertRedirect(route('auth.login'));
    });
});
