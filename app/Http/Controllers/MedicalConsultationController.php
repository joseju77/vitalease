<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicalConsultations\StoreMedicalConsultationRequest;
use App\Http\Requests\MedicalConsultations\UpdateMedicalConsultationRequest;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MedicalConsultationController extends Controller
{
    /**
     * Create a medical consultation together with its vital signs, physical
     * examination, and optional regulation, all inside one transaction.
     *
     * The authenticated user becomes the consultation's physician; the code
     * is generated server-side by the model's `creating` hook.
     */
    public function store(StoreMedicalConsultationRequest $request): RedirectResponse
    {
        $consultation = DB::transaction(function () use ($request): MedicalConsultation {
            $patient = Patient::query()->where('uuid', $request->validated('patient_uuid'))->firstOrFail();

            $consultation = MedicalConsultation::query()->create([
                'current_condition' => $request->validated('consultation.current_condition'),
                'diagnosis' => $request->validated('consultation.diagnosis'),
                'condition' => $request->validated('condition'),
                'prognosis' => $request->validated('prognosis'),
                'treatment' => $request->validated('treatment'),
                'medical_classification' => $request->validated('medical_classification'),
                'physician_id' => $request->user()->id,
                'patient_id' => $patient->id,
            ]);

            $consultation->vitalSigns()->create($request->validated('vital_signs'));
            $consultation->physicalExamination()->create($request->validated('physical_examination'));

            $regulation = $request->validated('regulation');
            if ($regulation !== null) {
                $consultation->regulation()->create($regulation);
            }

            return $consultation;
        });

        Inertia::flash('consultation', ['uuid' => $consultation->uuid, 'code' => $consultation->code]);

        return back();
    }

    /**
     * Update the consultation aggregate atomically.
     *
     * The patient, code, and physician never change on update, regardless of
     * the payload: those keys are not even accepted by the request.
     */
    public function update(UpdateMedicalConsultationRequest $request, MedicalConsultation $consultation): RedirectResponse
    {
        DB::transaction(function () use ($request, $consultation): void {
            $consultation->update([
                'current_condition' => $request->validated('consultation.current_condition'),
                'diagnosis' => $request->validated('consultation.diagnosis'),
                'condition' => $request->validated('condition'),
                'prognosis' => $request->validated('prognosis'),
                'treatment' => $request->validated('treatment'),
                'medical_classification' => $request->validated('medical_classification'),
            ]);

            $consultation->vitalSigns->update($request->validated('vital_signs'));
            $consultation->physicalExamination->update($request->validated('physical_examination'));

            $regulation = $request->validated('regulation');
            if ($regulation === null) {
                $consultation->regulation()->delete();
            } else {
                $consultation->regulation()->updateOrCreate([], $regulation);
            }
        });

        return back();
    }

    /**
     * Hard-delete the consultation; child rows cascade at the database
     * level.
     */
    public function destroy(MedicalConsultation $consultation): RedirectResponse
    {
        DB::transaction(function () use ($consultation): void {
            $consultation->delete();
        });

        return back();
    }
}
