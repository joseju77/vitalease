<?php

namespace App\Models;

use App\Enums\BloodType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Support\Auditing\ReplacesCatalogIds;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

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
class Patient extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    use ReplacesCatalogIds;
    use Searchable;

    /**
     * Only plain create/update events are audited automatically; further
     * events (disabled, enabled) are recorded explicitly as custom events.
     *
     * @var list<string>
     */
    protected array $auditEvents = ['created', 'updated'];

    /**
     * Attributes that never appear in audit values. The disable state is
     * audited through its own custom events.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id', 'uuid', 'disabled_at', 'disabled_by_id', 'disable_reason', 'is_disabled'];

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
            'disabled_at' => 'immutable_datetime',
            'is_disabled' => 'boolean',
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
     * Tag every audit with its patient so the full history is one query.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return ['patient:'.$this->getKey()];
    }

    /**
     * Replace the enrollment and family medical unit ids by their labels,
     * resolved per audited side.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        return $this->replaceCatalogIds($data, [
            'enrollment_id' => ['enrollment', Enrollment::class],
            'family_medical_unit_id' => ['family_medical_unit', FamilyMedicalUnit::class],
        ]);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by_id');
    }

    /**
     * @return HasOne<PatientContactInformation, $this>
     */
    public function contactInformation(): HasOne
    {
        return $this->hasOne(PatientContactInformation::class);
    }

    /**
     * @return HasMany<PatientEmergencyContact, $this>
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(PatientEmergencyContact::class);
    }

    /**
     * @return HasMany<PatientAilment, $this>
     */
    public function ailments(): HasMany
    {
        return $this->hasMany(PatientAilment::class);
    }

    /**
     * @return HasOne<PatientOtherAilment, $this>
     */
    public function otherAilments(): HasOne
    {
        return $this->hasOne(PatientOtherAilment::class);
    }

    /**
     * @return HasOne<PatientGynecologicalHistory, $this>
     */
    public function gynecologicalHistory(): HasOne
    {
        return $this->hasOne(PatientGynecologicalHistory::class);
    }

    /**
     * @return HasMany<MedicalConsultation, $this>
     */
    public function medicalConsultations(): HasMany
    {
        return $this->hasMany(MedicalConsultation::class);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'second_last_name' => $this->second_last_name,
            'enrollment_number' => $this->enrollment_number,
        ];
    }
}
