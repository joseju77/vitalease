<?php

namespace App\Models;

use App\Enums\TransferType;
use Database\Factories\MedicalRegulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medical_consultation_id',
    'transfer_type',
    'ambulance_registration',
    'regulation_number',
    'clinic_id',
    'regulated_at',
    'receiver_physician',
])]
class MedicalRegulation extends Model
{
    /** @use HasFactory<MedicalRegulationFactory> */
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
            'transfer_type' => TransferType::class,
            'regulated_at' => 'immutable_datetime',
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
