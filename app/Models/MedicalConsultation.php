<?php

namespace App\Models;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Support\MedicalConsultationCode;
use Database\Factories\MedicalConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'current_condition',
    'diagnosis',
    'condition',
    'prognosis',
    'medical_classification',
    'physician_id',
    'patient_id',
])]
class MedicalConsultation extends Model
{
    /** @use HasFactory<MedicalConsultationFactory> */
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
            'condition' => MedicalState::class,
            'prognosis' => MedicalState::class,
            'medical_classification' => MedicalClassification::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (MedicalConsultation $consultation) {
            $consultation->uuid ??= (string) Str::uuid7();
            $consultation->code ??= MedicalConsultationCode::next();
        });
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<VitalSigns, $this>
     */
    public function vitalSigns(): HasOne
    {
        return $this->hasOne(VitalSigns::class);
    }

    /**
     * @return HasOne<PhysicalExamination, $this>
     */
    public function physicalExamination(): HasOne
    {
        return $this->hasOne(PhysicalExamination::class);
    }

    /**
     * @return HasOne<MedicalRegulation, $this>
     */
    public function regulation(): HasOne
    {
        return $this->hasOne(MedicalRegulation::class);
    }

    /**
     * @return HasMany<MedicalConsultationTreatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(MedicalConsultationTreatment::class);
    }
}
