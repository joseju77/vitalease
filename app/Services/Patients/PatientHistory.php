<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientContactInformation;
use App\Models\PatientEmergencyContact;
use App\Models\PatientGynecologicalHistory;
use App\Models\PatientOtherAilment;
use App\Support\Auditing\AuditHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a patient's audit trail (patient and every child row) as a readable
 * change history: audits are grouped by batch (one group per registration,
 * edit, disable or enable), newest first, with Spanish section, field and
 * value labels resolved server-side. An audit without user (anonymous
 * self-registration) reads as "Autorregistro".
 *
 * An emergency contact is identified by its phone number, so a changed
 * number reads as one removed plus one added contact.
 *
 * @phpstan-import-type HistoryGroup from AuditHistory
 */
final readonly class PatientHistory extends AuditHistory
{
    /**
     * Fields holding an enum value, mapped to their label list in the
     * `history` translations.
     */
    private const array ENUM_FIELDS = [
        'sex_at_birth' => 'sex_at_birth',
        'marital_status' => 'marital_status',
        'blood_type' => 'blood_type',
        'kinship_type' => 'kinship_type',
        'ailment_type' => 'ailment_type',
        'ailment' => 'ailment_type',
        'contraceptive_method' => 'contraceptive_method',
    ];

    /**
     * Fields holding a boolean, shown as "Sí" / "No".
     */
    private const array BOOLEAN_FIELDS = ['has_cramps', 'is_cycle_regular', 'last_pap_smear_was_positive'];

    /**
     * Date fields, audited as a datetime and shown as a plain date.
     */
    private const array DATE_FIELDS = ['birth_date', 'diagnosed_at', 'last_cycle_date', 'last_pap_smear_date'];

    /**
     * One page of history groups, newest first.
     *
     * `$cursor` is the `next_cursor` of the previous page; `next_cursor` is
     * null when no older groups remain.
     *
     * @return array{data: list<HistoryGroup>, next_cursor: int|null}
     */
    public function page(Patient $patient, ?int $cursor = null): array
    {
        return $this->paginate($patient, $cursor);
    }

    protected function tag(Model $subject): string
    {
        return 'patient:'.$subject->getKey();
    }

    protected function rootModel(): string
    {
        return Patient::class;
    }

    protected function customEvents(): array
    {
        return ['disabled', 'enabled'];
    }

    protected function sectionKeys(): array
    {
        return [
            Patient::class => 'patient',
            PatientContactInformation::class => 'contact_information',
            PatientEmergencyContact::class => 'emergency_contacts',
            PatientAilment::class => 'ailments',
            PatientOtherAilment::class => 'other_ailments',
            PatientGynecologicalHistory::class => 'gynecological_history',
        ];
    }

    protected function langNamespace(): string
    {
        return 'modules/patients';
    }

    protected function omitsEmptyToEmptyChanges(): bool
    {
        return true;
    }

    protected function nullActorName(): ?string
    {
        return __('modules/patients/history.self_registration');
    }

    protected function item(string $section, array $old, array $new): ?string
    {
        return match ($section) {
            'emergency_contacts' => $new['contact'] ?? $old['contact'] ?? null,
            'ailments' => $this->valueLabel($section, 'ailment', $new['ailment'] ?? $old['ailment'] ?? null),
            default => null,
        };
    }

    protected function hiddenFields(string $section): array
    {
        return match ($section) {
            'emergency_contacts' => ['contact'],
            'ailments' => ['ailment'],
            default => [],
        };
    }

    protected function fieldLabel(string $section, string $field): string
    {
        $key = in_array($section, ['emergency_contacts', 'ailments'], true)
            ? $section.'.*.'.$field
            : $section.'.'.$field;

        $attributes = __('modules/patients/management.attributes');

        return $attributes[$key] ?? $field;
    }

    protected function valueLabel(string $section, string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return __('modules/patients/history.'.(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no'));
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return substr((string) $value, 0, 10);
        }

        if (isset(self::ENUM_FIELDS[$field])) {
            $labels = __('modules/patients/history.'.self::ENUM_FIELDS[$field]);

            return $labels[(int) $value] ?? $value;
        }

        return $value;
    }
}
