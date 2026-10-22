<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;

/**
 * The per-patient ability flags the detail page needs to show or hide its
 * actions.
 *
 * `super-admin` passes every policy check through `Gate::before()`, so the
 * policy alone would offer to edit or disable a disabled patient, or to enable
 * an active one. The flags therefore also apply the patient's state, matching
 * what the routes and services enforce. A consultation can only be created for
 * an active patient.
 */
final class PatientAbilities
{
    /**
     * @return array{update: bool, disable: bool, enable: bool, audit: bool, createConsultation: bool}
     */
    public static function for(User $user, Patient $patient): array
    {
        $disabled = $patient->disabled_at !== null;

        return [
            'update' => ! $disabled && $user->can('update', $patient),
            'disable' => ! $disabled && $user->can('disable', $patient),
            'enable' => $disabled && $user->can('enable', $patient),
            'audit' => $user->can('audit', $patient),
            'createConsultation' => ! $disabled && $user->can(Permission::ConsultationsCreate->value),
        ];
    }
}
