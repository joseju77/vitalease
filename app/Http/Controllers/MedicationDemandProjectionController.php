<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Services\Inventory\MedicationDemandProjection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MedicationDemandProjectionController extends Controller
{
    public function __construct(private readonly MedicationDemandProjection $projection) {}

    /**
     * Render the medication demand projection report: every active
     * medication for the selector, and the OLS projection for the selected
     * one, if any.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'medication' => ['nullable', 'string', 'uuid', Rule::exists('medications', 'uuid')],
        ]);

        $medications = Medication::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['uuid', 'name', 'presentation', 'concentration']);

        $projectionData = null;

        if (($validated['medication'] ?? null) !== null) {
            $medication = Medication::query()->where('uuid', $validated['medication'])->firstOrFail();

            $projectionData = [
                ...$this->projection->forMedication($medication)->toArray(),
                'medication' => $medication->only(['uuid', 'name', 'presentation', 'concentration']),
                'is_indicative' => true,
            ];
        }

        return Inertia::render('reports/MedicationDemand', [
            'medications' => $medications
                ->map(fn (Medication $medication): array => $medication->only([
                    'uuid', 'name', 'presentation', 'concentration',
                ]))
                ->all(),
            'projection' => $projectionData,
        ]);
    }
}
