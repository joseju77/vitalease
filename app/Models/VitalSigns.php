<?php

namespace App\Models;

use Database\Factories\VitalSignsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'medical_consultation_id',
    'weight',
    'height',
    'blood_pressure_systolic',
    'blood_pressure_diastolic',
    'heart_rate',
    'respiratory_rate',
    'temperature',
    'oxygen_saturation',
    'glasgow',
    'glucose',
])]
class VitalSigns extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<VitalSignsFactory> */
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'height' => 'decimal:2',
            'temperature' => 'decimal:1',
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
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }
}
