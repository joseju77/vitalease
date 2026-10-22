<?php

namespace App\Models;

use App\Enums\ContraceptiveMethod;
use Database\Factories\PatientGynecologicalHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'patient_id',
    'menarche',
    'has_cramps',
    'is_cycle_regular',
    'cycle_intensity',
    'cycle_duration',
    'cycle_flow_level',
    'last_cycle_date',
    'sexual_activity_start_age',
    'contraceptive_method',
    'last_pap_smear_date',
    'last_pap_smear_was_positive',
    'pregnancies',
    'vaginal_deliveries',
    'cesareans',
    'abortions',
])]
class PatientGynecologicalHistory extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientGynecologicalHistoryFactory> */
    use HasFactory;

    protected $table = 'patient_gynecological_history';

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_cramps' => 'boolean',
            'is_cycle_regular' => 'boolean',
            'last_cycle_date' => 'date',
            'contraceptive_method' => ContraceptiveMethod::class,
            'last_pap_smear_date' => 'date',
            'last_pap_smear_was_positive' => 'boolean',
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
