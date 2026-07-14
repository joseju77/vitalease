<?php

namespace App\Models;

use Database\Factories\PhysicalExaminationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medical_consultation_id',
    'neurological',
    'head_neck',
    'thorax_cardiopulmonary',
    'abdomen',
    'extremities',
    'cabinet_laboratory',
])]
class PhysicalExamination extends Model
{
    /** @use HasFactory<PhysicalExaminationFactory> */
    use HasFactory;

    protected $primaryKey = 'medical_consultation_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }
}
