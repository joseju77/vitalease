<?php

namespace App\Models;

use App\Support\Auditing\ReplacesCatalogIds;
use Database\Factories\PatientContactInformationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'patient_id',
    'address',
    'phone_number',
    'personal_email',
    'institutional_email',
    'neighborhood_id',
])]
class PatientContactInformation extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PatientContactInformationFactory> */
    use HasFactory;

    use ReplacesCatalogIds;

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
     * Replace the neighborhood id by its label, resolved per audited side.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        return $this->replaceCatalogIds($data, ['neighborhood_id' => ['neighborhood', Neighborhood::class]]);
    }

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
