<?php

namespace App\Models;

use Database\Factories\PhysicalExaminationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'medical_consultation_id',
    'neurological',
    'head_neck',
    'thorax_cardiopulmonary',
    'abdomen',
    'extremities',
    'cabinet_laboratory',
])]
class PhysicalExamination extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PhysicalExaminationFactory> */
    use HasFactory;

    protected $primaryKey = 'medical_consultation_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Attributes that never appear in audit values.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['medical_consultation_id'];

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
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }
}
