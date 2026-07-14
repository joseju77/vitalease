<?php

namespace App\Models;

use Database\Factories\VitalSignsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
class VitalSigns extends Model
{
    /** @use HasFactory<VitalSignsFactory> */
    use HasFactory;

    protected $primaryKey = 'medical_consultation_id';

    public $incrementing = false;

    public $timestamps = false;

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
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }
}
