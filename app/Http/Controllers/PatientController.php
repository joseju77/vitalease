<?php

namespace App\Http\Controllers;

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\ContraceptiveMethod;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Http\Requests\Patients\DisablePatientRequest;
use App\Http\Requests\Patients\EnablePatientRequest;
use App\Http\Requests\Patients\StorePatientRegistrationRequest;
use App\Http\Requests\Patients\UpdatePatientRequest;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\MedicalConsultation;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Services\Patients\PatientDisabling;
use App\Services\Patients\PatientHistory;
use App\Services\Patients\PatientWriter;
use App\Support\ConsultationListItem;
use App\Support\PatientAbilities;
use App\Support\Patients\PatientEditPayload;
use App\Support\Patients\PatientProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Scout\Builder as ScoutBuilder;
use Throwable;

class PatientController extends Controller
{
    private const array STATUS_FILTERS = ['active', 'disabled', 'all'];

    /**
     * Display a paginated, searchable patient list.
     *
     * Free-text search runs through Scout (`Patient::search()`); without a
     * query the list is read directly from Postgres. The status filter
     * (`active` by default, `disabled`, `all`) applies on both paths on the
     * stored `is_disabled` column, so the Meilisearch engine and the
     * `collection` driver used in tests behave the same. Rows carry only
     * identifying fields: never the social security number, emails or phones.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(self::STATUS_FILTERS)],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $status = $validated['status'] ?? 'active';
        $disabled = match ($status) {
            'disabled' => true,
            'active' => false,
            default => null,
        };

        if ($query !== '') {
            $patients = Patient::search($query)
                ->when($disabled !== null, fn (ScoutBuilder $builder) => $builder->where('is_disabled', $disabled))
                ->orderBy('last_name')
                ->paginate(15)
                ->withQueryString();
        } else {
            $patients = Patient::query()
                ->when($disabled !== null, fn (Builder $builder) => $builder->where('is_disabled', $disabled))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('id')
                ->paginate(15)
                ->withQueryString();
        }

        $patients->getCollection()->load('enrollment');

        return Inertia::render('patients/Index', [
            'patients' => $patients->through(fn (Patient $patient): array => [
                'uuid' => $patient->uuid,
                'full_name' => $this->fullName($patient),
                'enrollment' => $patient->enrollment?->name,
                'enrollment_number' => $patient->enrollment_number,
                'is_disabled' => $patient->disabled_at !== null,
            ]),
            'filters' => [
                'q' => $query !== '' ? $query : null,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Display one patient with every section, the disable data when
     * disabled, and the consultations the viewer may see (voided ones follow
     * the consultation visibility rules). Disabled patients stay viewable.
     */
    public function show(Request $request, Patient $patient): Response
    {
        $user = $request->user();
        $patient->load('disabledBy');

        $consultations = $patient->medicalConsultations()
            ->visibleTo($user)
            ->with(['patient', 'physician'])
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('patients/Show', [
            'patient' => [
                'uuid' => $patient->uuid,
                'full_name' => $this->fullName($patient),
                'is_disabled' => $patient->disabled_at !== null,
                'disabled' => $patient->disabled_at === null ? null : [
                    'at' => $patient->disabled_at->toIso8601String(),
                    'by' => $patient->disabledBy?->name,
                    'reason' => $patient->disable_reason,
                ],
                'profile' => PatientProfile::for($patient, $user),
            ],
            'consultations' => $consultations
                ->map(fn (MedicalConsultation $consultation): array => ConsultationListItem::for($consultation, $user))
                ->all(),
            'can' => PatientAbilities::for($user, $patient),
        ]);
    }

    /**
     * Return one page of the patient's change history, grouped by operation.
     */
    public function history(Request $request, Patient $patient, PatientHistory $history): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($history->page($patient, isset($validated['cursor']) ? (int) $validated['cursor'] : null));
    }

    /**
     * Display the anonymous patient registration metadata: enum options and
     * the family-medical-unit and enrollment catalogs a guest needs to
     * pre-fill the registration form.
     */
    public function create(): Response
    {
        return Inertia::render('patients/Register', $this->registrationMetadata());
    }

    /**
     * Display the edit form prefilled with the stored aggregate, plus the
     * same catalogs the registration form uses and the neighborhoods of the
     * patient's current postal code. A disabled patient has no edit page,
     * not even for `super-admin` (whose policy checks always pass).
     */
    public function edit(Patient $patient): Response
    {
        abort_if($patient->disabled_at !== null, 403);

        $form = PatientEditPayload::for($patient);
        $zipCode = $form['contact_information']['zip_code'];

        return Inertia::render('patients/Edit', [
            ...$this->registrationMetadata(),
            'patient' => [
                'uuid' => $patient->uuid,
                'full_name' => $this->fullName($patient),
            ],
            'form' => $form,
            'neighborhoods' => $zipCode === null
                ? []
                : Neighborhood::query()->where('zip_code', $zipCode)->orderBy('id')->get(['id', 'name']),
        ]);
    }

    /**
     * Replace the patient aggregate with the submitted one (see
     * `PatientWriter::update`). Duplicate values and a patient disabled in
     * the meantime come back as validation errors.
     */
    public function update(UpdatePatientRequest $request, Patient $patient, PatientWriter $writer): RedirectResponse
    {
        $writer->update($patient, $request->validated());

        Inertia::flash('patientUpdated', true);

        return redirect()->route('patients.show', $patient);
    }

    /**
     * Disable the patient. Nothing is deleted: consultations and every other
     * record stay intact. A reason is required.
     */
    public function disable(DisablePatientRequest $request, Patient $patient, PatientDisabling $disabling): RedirectResponse
    {
        $disabled = $disabling->disable($patient, $request->user(), $request->validated('reason'));

        Inertia::flash('patientDisabled', true);

        return redirect()->route('patients.show', $disabled);
    }

    /**
     * Enable a disabled patient again. A reason is required.
     */
    public function enable(EnablePatientRequest $request, Patient $patient, PatientDisabling $disabling): RedirectResponse
    {
        $enabled = $disabling->enable($patient, $request->user(), $request->validated('reason'));

        Inertia::flash('patientEnabled', true);

        return redirect()->route('patients.show', $enabled);
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
     * transaction and one audit batch (see `PatientWriter`). Any failure
     * after validation rolls back every row and reports the exception; the
     * client only ever sees a generic error, never a patient id, aggregate
     * field, or medical value.
     */
    public function store(StorePatientRegistrationRequest $request, PatientWriter $writer): RedirectResponse
    {
        try {
            $writer->register($request->validated());
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'registration' => __('modules/patients/registration.duplicate_registration'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'registration' => 'Ocurrió un error al procesar el registro. Inténtalo de nuevo más tarde.',
            ]);
        }

        Inertia::flash('registrationSuccess', true);

        return redirect()->route('patients.register');
    }

    private function fullName(Patient $patient): string
    {
        return trim(implode(' ', array_filter([
            $patient->first_name,
            $patient->last_name,
            $patient->second_last_name,
        ])));
    }

    /**
     * The enum options and catalogs the registration and edit forms share.
     *
     * @return array<string, mixed>
     */
    private function registrationMetadata(): array
    {
        return [
            'sexAtBirthOptions' => array_column(SexAtBirth::cases(), 'value'),
            'maritalStatusOptions' => array_column(MaritalStatus::cases(), 'value'),
            'bloodTypeOptions' => array_column(BloodType::cases(), 'value'),
            'kinshipTypeOptions' => array_column(KinshipType::cases(), 'value'),
            'ailmentTypeOptions' => array_column(AilmentType::cases(), 'value'),
            'contraceptiveMethodOptions' => array_column(ContraceptiveMethod::cases(), 'value'),
            'familyMedicalUnits' => FamilyMedicalUnit::query()->orderBy('id')->get(['id', 'name', 'address']),
            'enrollments' => Enrollment::query()->orderBy('id')->get(['id', 'name', 'segment']),
        ];
    }
}
