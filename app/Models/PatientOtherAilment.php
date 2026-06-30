<?php

namespace App\Models;

use Database\Factories\PatientOtherAilmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_id',
    'surgeries',
    'allergies',
    'others',
])]
class PatientOtherAilment extends Model
{
    /** @use HasFactory<PatientOtherAilmentFactory> */
    use HasFactory;

    protected $primaryKey = 'patient_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
