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
use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\ZipCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PatientController extends Controller
{
    /**
     * Display the anonymous patient registration metadata: enum options and
     * the location, family-medical-unit, and enrollment catalogs a guest
     * needs to pre-fill the registration form.
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
            'municipalities' => Municipality::query()->orderBy('id')->get(['id', 'name']),
            'postalCodes' => ZipCode::query()->orderBy('code')->get(['code', 'municipality_id']),
            'neighborhoodsByPostalCode' => Neighborhood::query()
                ->orderBy('zip_code')
                ->orderBy('id')
                ->get(['id', 'name', 'zip_code'])
                ->groupBy('zip_code')
                ->map(fn ($neighborhoods) => $neighborhoods
                    ->map(fn (Neighborhood $neighborhood) => ['id' => $neighborhood->id, 'name' => $neighborhood->name])
                    ->values()),
            'familyMedicalUnits' => FamilyMedicalUnit::query()->orderBy('id')->get(['id', 'name', 'address']),
            'enrollments' => Enrollment::query()->orderBy('id')->get(['id', 'name', 'segment']),
        ]);
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
