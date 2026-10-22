<?php

namespace App\Models;

use App\Enums\AilmentType;
use Database\Factories\PatientAilmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'patient_id',
    'ailment_type',
    'diagnosed_at',
    'treatment_notes',
])]
class PatientAilment extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientAilmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Attributes that never appear in audit values.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id', 'patient_id'];

    /**
     * Tag every audit with its patient so the full history is one query.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return ['patient:'.$this->patient_id];
    }

    /**
     * Add the ailment type as the row identity (its raw enum value, labelled
     * when the history is read), so the history can name the ailment even
     * when the audited change does not touch the type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        if ($data['event'] !== 'created') {
            $data['old_values']['ailment'] = $this->getOriginal('ailment_type')->value;
        }

        if ($data['event'] !== 'deleted') {
            $data['new_values']['ailment'] = $this->ailment_type->value;
        }

        return $data;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ailment_type' => AilmentType::class,
            'diagnosed_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
