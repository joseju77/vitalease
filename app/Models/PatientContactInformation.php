<?php

namespace App\Models;

use Database\Factories\PatientContactInformationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_id',
    'address',
    'phone_number',
    'personal_email',
    'institutional_email',
    'neighborhood_id',
])]
class PatientContactInformation extends Model
{
    /** @use HasFactory<PatientContactInformationFactory> */
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

    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }
}
