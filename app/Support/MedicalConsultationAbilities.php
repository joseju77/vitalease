<?php

namespace App\Support;

use App\Models\MedicalConsultation;
use App\Models\User;

/**
 * The per-consultation ability flags the pages need to show or hide their
 * actions.
 *
 * `super-admin` passes every policy check through `Gate::before()`, so the
 * policy alone would offer to edit or void a voided consultation, or to
 * restore an active one. The flags therefore also apply the consultation's
 * voided state, matching what the routes and services enforce.
 */
final class MedicalConsultationAbilities
{
    /**
     * @return array{update: bool, void: bool, restore: bool, audit: bool}
     */
    public static function for(User $user, MedicalConsultation $consultation): array
    {
        $voided = $consultation->trashed();

        return [
            'update' => ! $voided && $user->can('update', $consultation),
            'void' => ! $voided && $user->can('void', $consultation),
            'restore' => $voided && $user->can('restore', $consultation),
            'audit' => $user->can('audit', $consultation),
        ];
    }
}
