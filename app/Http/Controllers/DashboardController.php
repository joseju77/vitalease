<?php

namespace App\Http\Controllers;

use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\ConsultationListItem;
use App\Support\PatientAbilities;
use App\Support\Search\ResilientSearch;
use App\Support\Search\SearchDegradation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Render the staff dashboard: the authenticated physician's latest
     * consultations plus the ability flags the page needs to gate the
     * patient search bar and the "new consultation" entry points.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $includeVoided = $request->boolean('include_voided');

        return Inertia::render('dashboard/Index', [
            'latestConsultations' => $this->latestConsultationItems($user, $includeVoided),
            'filters' => ['include_voided' => $includeVoided],
            'can' => [
                'searchPatients' => $user->can('viewAny', Patient::class),
                'createConsultation' => $user->can('create', MedicalConsultation::class),
            ],
        ]);
    }

    /**
     * Look up patients by name or enrollment number for the staff search
     * bar, restricted to the Scout-indexed fields. Disabled patients are
     * included and flagged `is_disabled` so the bar can show them as not
     * selectable. When the engine is unavailable the same fields are matched
     * in Postgres and the response carries an `X-Search-Degraded` header; the
     * body shape never changes.
     */
    public function searchPatients(Request $request, ResilientSearch $search, SearchDegradation $degradation): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:100'],
        ]);

        $text = $validated['query'];

        $patients = $search->run(
            'patients',
            fn (): Collection => Patient::search($text)->take(10)->get(),
            fn (): Collection => Patient::query()
                ->matchingText($text)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('id')
                ->limit(10)
                ->get(),
        );

        return response()->json(
            $patients->map(fn (Patient $patient): array => $this->mapPatient($patient))->all(),
            headers: $degradation->isDegraded() ? ['X-Search-Degraded' => '1'] : [],
        );
    }

    /**
     * Return a patient's basic data plus their 5 most recent consultations.
     */
    public function patientSummary(Request $request, Patient $patient): JsonResponse
    {
        $consultations = $patient->medicalConsultations()
            ->visibleTo($request->user())
            ->with(['patient', 'physician'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'patient' => [
                'uuid' => $patient->uuid,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'second_last_name' => $patient->second_last_name,
                'birth_date' => $patient->birth_date->format('Y-m-d'),
                'sex_at_birth' => $patient->sex_at_birth->value,
                'blood_type' => $patient->blood_type->value,
                'enrollment_number' => $patient->enrollment_number,
                'is_disabled' => $patient->disabled_at !== null,
            ],
            'can' => [
                'createConsultation' => PatientAbilities::for($request->user(), $patient)['createConsultation'],
            ],
            'latest_consultations' => $consultations
                ->map(fn (MedicalConsultation $consultation): array => ConsultationListItem::for($consultation, $request->user()))
                ->all(),
        ]);
    }

    /**
     * Return the authenticated physician's own most recent consultations.
     */
    public function latestConsultations(Request $request): JsonResponse
    {
        return response()->json($this->latestConsultationItems($request->user()));
    }

    /**
     * Shared query behind the dashboard page's `latestConsultations` prop and
     * the JSON endpoint of the same name: the authenticated user's own
     * consultations, newest first, capped at 10. Voided ones are left out
     * unless requested.
     *
     * @return list<array<string, mixed>>
     */
    private function latestConsultationItems(User $user, bool $includeVoided = false): array
    {
        return MedicalConsultation::query()
            ->when($includeVoided, fn (Builder $query) => $query->withTrashed())
            ->where('physician_id', $user->id)
            ->with(['patient', 'physician'])
            ->latest()
            ->take(10)
            ->get()
            ->map(fn (MedicalConsultation $consultation): array => ConsultationListItem::for($consultation, $user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPatient(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'second_last_name' => $patient->second_last_name,
            'enrollment_number' => $patient->enrollment_number,
            'is_disabled' => $patient->disabled_at !== null,
        ];
    }
}
