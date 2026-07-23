<?php

namespace App\Http\Controllers;

use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
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

        return Inertia::render('dashboard/Index', [
            'latestConsultations' => $this->latestConsultationItems($user),
            'can' => [
                'searchPatients' => $user->can('viewAny', Patient::class),
                'createConsultation' => $user->can('create', MedicalConsultation::class),
            ],
        ]);
    }

    /**
     * Look up patients by name or enrollment number for the staff search
     * bar, restricted to the Scout-indexed fields.
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $patients = Patient::search($validated['query'])->take(10)->get();

        return response()->json(
            $patients->map(fn (Patient $patient): array => $this->mapPatient($patient))->all()
        );
    }

    /**
     * Return a patient's basic data plus their 5 most recent consultations.
     */
    public function patientSummary(Request $request, Patient $patient): JsonResponse
    {
        $consultations = $patient->medicalConsultations()
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
            ],
            'latest_consultations' => $consultations
                ->map(fn (MedicalConsultation $consultation): array => $this->mapConsultationItem($consultation, $request->user()))
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
     * consultations, newest first, capped at 10.
     *
     * @return list<array<string, mixed>>
     */
    private function latestConsultationItems(User $user): array
    {
        return MedicalConsultation::query()
            ->where('physician_id', $user->id)
            ->with(['patient', 'physician'])
            ->latest()
            ->take(10)
            ->get()
            ->map(fn (MedicalConsultation $consultation): array => $this->mapConsultationItem($consultation, $user))
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
        ];
    }

    /**
     * Map a consultation, its eager-loaded `patient` and `physician`
     * relations, into the shared dashboard item shape.
     *
     * @return array<string, mixed>
     */
    private function mapConsultationItem(MedicalConsultation $consultation, User $currentUser): array
    {
        return [
            'uuid' => $consultation->uuid,
            'code' => $consultation->code,
            'created_at' => $consultation->created_at->toISOString(),
            'diagnosis' => $consultation->diagnosis,
            'medical_classification' => $consultation->medical_classification->value,
            'patient' => [
                'uuid' => $consultation->patient->uuid,
                'full_name' => $this->fullName($consultation->patient),
            ],
            'physician' => [
                'name' => $consultation->physician->name,
            ],
            'can' => [
                'update' => $currentUser->can('update', $consultation),
                'delete' => $currentUser->can('delete', $consultation),
            ],
        ];
    }

    private function fullName(Patient $patient): string
    {
        return trim(implode(' ', array_filter([
            $patient->first_name,
            $patient->last_name,
            $patient->second_last_name,
        ])));
    }
}
