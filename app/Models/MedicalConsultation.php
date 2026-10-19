<?php

namespace App\Models;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Support\MedicalConsultationCode;
use Database\Factories\MedicalConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'current_condition',
    'diagnosis',
    'condition',
    'prognosis',
    'medical_classification',
    'physician_id',
    'patient_id',
])]
class MedicalConsultation extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<MedicalConsultationFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * A consultation is never deleted: "voided" means `voided_at` is set. The
     * void columns are written together by `ConsultationVoiding`, never through
     * `delete()`/`restore()`.
     */
    public const string DELETED_AT = 'voided_at';

    /**
     * Only plain create/update events are audited automatically; any further
     * events are recorded explicitly as custom audit events.
     *
     * @var list<string>
     */
    protected array $auditEvents = ['created', 'updated'];

    /**
     * Attributes that never appear in audit values.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id', 'uuid', 'patient_id', 'physician_id', 'voided_at', 'voided_by_id', 'void_reason'];

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
            'voided_at' => 'immutable_datetime',
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
     * Tag every audit with its consultation so the full history is one query.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return ['consultation:'.$this->getKey()];
    }

    /**
     * Include voided consultations, limited to the ones the user may view:
     * active ones, their own, or all of them with `view-voided`/`void-any`.
     *
     * @param  Builder<MedicalConsultation>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->withTrashed();

        if ($user->can(Permission::ConsultationsViewVoided->value) || $user->can(Permission::ConsultationsVoidAny->value)) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('voided_at')->orWhere('physician_id', $user->id);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
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
