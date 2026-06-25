<?php

namespace App\Models;

use App\Enums\BloodType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'first_name',
    'last_name',
    'second_last_name',
    'birth_date',
    'sex_at_birth',
    'marital_status',
    'blood_type',
    'enrollment_id',
    'enrollment_number',
    'external_enrollment',
    'family_medical_unit_id',
    'other_family_medical_unit',
    'social_security_number',
])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex_at_birth' => SexAtBirth::class,
            'marital_status' => MaritalStatus::class,
            'blood_type' => BloodType::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            $patient->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<FamilyMedicalUnit, $this>
     */
    public function familyMedicalUnit(): BelongsTo
    {
        return $this->belongsTo(FamilyMedicalUnit::class);
    }
}
