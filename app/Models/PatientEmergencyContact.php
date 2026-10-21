<?php

namespace App\Models;

use App\Enums\KinshipType;
use Database\Factories\PatientEmergencyContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'patient_id',
    'name',
    'phone_number',
    'kinship_type',
])]
class PatientEmergencyContact extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientEmergencyContactFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Attributes that never appear in audit values.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id', 'patient_id'];

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
     * Add the contact name as the row identity, so the history can name the
     * contact even when the audited change does not touch the name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        if ($data['event'] !== 'created') {
            $data['old_values']['contact'] = $this->getOriginal('name');
        }

        if ($data['event'] !== 'deleted') {
            $data['new_values']['contact'] = $this->name;
        }

        return $data;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kinship_type' => KinshipType::class,
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
