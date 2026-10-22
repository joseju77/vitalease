<?php

namespace App\Http\Requests\Patients;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

/**
 * Anonymous self-registration. Uniqueness is deliberately not validated here:
 * a rule would tell an anonymous visitor which NSS, enrollment or email is
 * already registered. The database constraints decide, and the controller
 * answers any duplicate with one generic message.
 */
#[FailOnUnknownFields]
class StorePatientRegistrationRequest extends PatientAggregateRequest {}
