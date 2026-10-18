<?php

namespace App\Models;

use App\Enums\TransferType;
use Database\Factories\MedicalRegulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'medical_consultation_id',
    'transfer_type',
    'ambulance_registration',
    'regulation_number',
    'clinic_id',
    'regulated_at',
    'receiver_physician',
])]
class MedicalRegulation extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<MedicalRegulationFactory> */
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
            'transfer_type' => TransferType::class,
            'regulated_at' => 'immutable_datetime',
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
