<?php

namespace App\Models;

use Database\Factories\MedicalConsultationTreatmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'medical_consultation_id',
    'medication_id',
    'quantity_dispensed',
    'dose',
    'frequency',
    'duration',
])]
class MedicalConsultationTreatment extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<MedicalConsultationTreatmentFactory> */
    use HasFactory;

    /**
     * Attributes that never appear in audit values. The medication is
     * identified by a readable label instead (see `transformAudit()`).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id', 'medical_consultation_id', 'medication_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_dispensed' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Tag every audit with its consultation so the full history is one query.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return ['consultation:'.$this->medical_consultation_id];
    }

    /**
     * Add the medication label (name, presentation and concentration) to the
     * audited values so the history never exposes a bare `medication_id`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $label = $this->medication->auditLabel();

        foreach (['old_values', 'new_values'] as $key) {
            if ($key === 'old_values' && $data['event'] === 'created') {
                continue;
            }

            if ($key === 'new_values' && $data['event'] === 'deleted') {
                continue;
            }

            $data[$key]['medication'] = $label;
        }

        return $data;
    }

    /**
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
