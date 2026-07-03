<?php

namespace App\Http\Controllers;

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\ContraceptiveMethod;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Http\Requests\Patients\StorePatientRegistrationRequest;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Neighborhood;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PatientController extends Controller
{
    /**
     * Display the anonymous patient registration metadata: enum options and
     * the family-medical-unit and enrollment catalogs a guest needs to
     * pre-fill the registration form.
     */
    public function create(): Response
    {
        return Inertia::render('patients/Register', [
            'sexAtBirthOptions' => array_column(SexAtBirth::cases(), 'value'),
            'maritalStatusOptions' => array_column(MaritalStatus::cases(), 'value'),
            'bloodTypeOptions' => array_column(BloodType::cases(), 'value'),
            'kinshipTypeOptions' => array_column(KinshipType::cases(), 'value'),
            'ailmentTypeOptions' => array_column(AilmentType::cases(), 'value'),
            'contraceptiveMethodOptions' => array_column(ContraceptiveMethod::cases(), 'value'),
            'familyMedicalUnits' => FamilyMedicalUnit::query()->orderBy('id')->get(['id', 'name', 'address']),
            'enrollments' => Enrollment::query()->orderBy('id')->get(['id', 'name', 'segment']),
        ]);
    }

    /**
     * Anonymous, rate-limited postal-code neighborhood lookup for the
     * registration form: returns only the neighborhoods belonging to one
     * submitted 5-digit postal code, scoped narrowly instead of shipping the
     * full neighborhood catalog on every page load.
     */
    public function neighborhoods(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zip_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
        ]);

        $neighborhoods = Neighborhood::query()
            ->where('zip_code', $validated['zip_code'])
            ->orderBy('id')
            ->get(['id', 'name']);

        return response()->json($neighborhoods);
    }

    /**
     * Persist a complete anonymous patient registration aggregate.
     *
     * The patient and every applicable extension are created inside one
     * transaction. Any failure after validation rolls back every row and
     * reports the exception; the client only ever sees a generic error,
     * never a patient id, aggregate field, or medical value.
     */
    public function store(StorePatientRegistrationRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $patient = Patient::query()->create($request->safe()->input('patient'));

                $contactInformation = $request->safe()->input('contact_information');
                unset($contactInformation['zip_code']);
                $patient->contactInformation()->create($contactInformation);

                foreach ($request->safe()->input('emergency_contacts', []) as $emergencyContact) {
                    $patient->emergencyContacts()->create($emergencyContact);
                }

                foreach ($request->safe()->input('ailments', []) as $ailment) {
                    $patient->ailments()->create($ailment);
                }

                $otherAilments = $request->safe()->input('other_ailments');
                if ($otherAilments !== null) {
                    $patient->otherAilments()->create($otherAilments);
                }

                $gynecologicalHistory = $request->safe()->input('gynecological_history');
                if ($gynecologicalHistory !== null) {
                    $patient->gynecologicalHistory()->create($gynecologicalHistory);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'registration' => 'Ocurrió un error al procesar el registro. Inténtalo de nuevo más tarde.',
            ]);
        }

        Inertia::flash('registrationSuccess', true);

        return redirect()->route('patients.register');
    }
}
