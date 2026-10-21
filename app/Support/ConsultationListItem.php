<?php

namespace App\Support;

use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;

/**
 * The compact consultation shape shared by the dashboard lists and the
 * patient detail page.
 */
final class ConsultationListItem
{
    /**
     * Map a consultation, with its eager-loaded `patient` and `physician`
     * relations, into the shared list item shape.
     *
     * @return array<string, mixed>
     */
    public static function for(MedicalConsultation $consultation, User $viewer): array
    {
        return [
            'uuid' => $consultation->uuid,
            'code' => $consultation->code,
            'created_at' => $consultation->created_at->toISOString(),
            'voided_at' => $consultation->voided_at?->toISOString(),
            'diagnosis' => $consultation->diagnosis,
            'medical_classification' => $consultation->medical_classification->value,
            'patient' => [
                'uuid' => $consultation->patient->uuid,
                'full_name' => self::fullName($consultation->patient),
            ],
            'physician' => [
                'name' => $consultation->physician->name,
            ],
            'can' => MedicalConsultationAbilities::for($viewer, $consultation),
        ];
    }

    private static function fullName(Patient $patient): string
    {
        return trim(implode(' ', array_filter([
            $patient->first_name,
            $patient->last_name,
            $patient->second_last_name,
        ])));
    }
}
