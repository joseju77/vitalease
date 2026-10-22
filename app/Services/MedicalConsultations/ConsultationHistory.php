<?php

namespace App\Services\MedicalConsultations;

use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\MedicalRegulation;
use App\Models\PhysicalExamination;
use App\Models\VitalSigns;
use App\Support\Auditing\AuditHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a consultation's audit trail as a readable change history: audits
 * are grouped by batch (one group per save, void or restore), newest first,
 * with Spanish section, field and enum labels resolved server-side.
 *
 * @phpstan-import-type HistoryGroup from AuditHistory
 */
final readonly class ConsultationHistory extends AuditHistory
{
    /**
     * Fields of the consultation section that are labelled without the
     * `consultation.` prefix in the attribute translations.
     */
    private const array ENUM_FIELDS = [
        'condition' => 'medical_state',
        'prognosis' => 'medical_state',
        'medical_classification' => 'medical_classification',
        'transfer_type' => 'transfer_type',
    ];

    /**
     * One page of history groups, newest first.
     *
     * `$cursor` is the `next_cursor` of the previous page; `next_cursor` is
     * null when no older groups remain.
     *
     * @return array{data: list<HistoryGroup>, next_cursor: int|null}
     */
    public function page(MedicalConsultation $consultation, ?int $cursor = null): array
    {
        return $this->paginate($consultation, $cursor);
    }

    protected function tag(Model $subject): string
    {
        return 'consultation:'.$subject->getKey();
    }

    protected function rootModel(): string
    {
        return MedicalConsultation::class;
    }

    protected function customEvents(): array
    {
        return ['voided', 'restored'];
    }

    protected function sectionKeys(): array
    {
        return [
            MedicalConsultation::class => 'consultation',
            VitalSigns::class => 'vital_signs',
            PhysicalExamination::class => 'physical_examination',
            MedicalRegulation::class => 'regulation',
            MedicalConsultationTreatment::class => 'treatment',
        ];
    }

    protected function langNamespace(): string
    {
        return 'modules/consultations';
    }

    protected function item(string $section, array $old, array $new): ?string
    {
        return $section === 'treatment' ? ($new['medication'] ?? $old['medication'] ?? null) : null;
    }

    protected function hiddenFields(string $section): array
    {
        return $section === 'treatment' ? ['medication'] : [];
    }

    protected function fieldLabel(string $section, string $field): string
    {
        $candidates = match ($section) {
            'consultation' => ['consultation.'.$field, $field],
            'treatment' => ['treatment.*.'.$field],
            default => [$section.'.'.$field],
        };

        $attributes = __('modules/consultations/management.attributes');

        foreach ($candidates as $candidate) {
            if (isset($attributes[$candidate])) {
                return $attributes[$candidate];
            }
        }

        return $field;
    }

    protected function valueLabel(string $section, string $field, mixed $value): mixed
    {
        if ($value === null || ! isset(self::ENUM_FIELDS[$field])) {
            return $value;
        }

        $labels = __('modules/consultations/history.'.self::ENUM_FIELDS[$field]);

        return $labels[(int) $value] ?? $value;
    }
}
