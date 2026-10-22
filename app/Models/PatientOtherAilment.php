<?php

namespace App\Models;

use Database\Factories\PatientOtherAilmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'patient_id',
    'surgeries',
    'allergies',
    'others',
])]
class PatientOtherAilment extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientOtherAilmentFactory> */
    use HasFactory;

    protected $primaryKey = 'patient_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Attributes that never appear in audit values.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['patient_id'];

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
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
