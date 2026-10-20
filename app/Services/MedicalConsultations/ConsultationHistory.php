<?php

namespace App\Services\MedicalConsultations;

use App\Models\Audit;
use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\MedicalRegulation;
use App\Models\PhysicalExamination;
use App\Models\VitalSigns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads a consultation's audit trail as a readable change history: audits
 * are grouped by batch (one group per save, void or restore), newest first,
 * with Spanish section, field and enum labels resolved server-side.
 *
 * @phpstan-type HistoryChange array{section: string, item: string|null, field: string, old: mixed, new: mixed}
 * @phpstan-type HistoryGroup array{id: string, event: string, event_label: string, occurred_at: string, actor: array{name: string}|null, reason: string|null, changes: list<HistoryChange>}
 */
final readonly class ConsultationHistory
{
    /**
     * Number of groups per page. A group is never split across pages.
     */
    public const int PAGE_SIZE = 20;

    private const string GROUP_KEY = "COALESCE(batch_id::text, 'audit-' || id::text)";

    private const array SECTION_KEYS = [
        MedicalConsultation::class => 'consultation',
        VitalSigns::class => 'vital_signs',
        PhysicalExamination::class => 'physical_examination',
        MedicalRegulation::class => 'regulation',
        MedicalConsultationTreatment::class => 'treatment',
    ];

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
        $groups = $this->taggedAudits($consultation)
            ->selectRaw(self::GROUP_KEY.' as group_key, MAX(id) as last_id')
            ->groupByRaw(self::GROUP_KEY)
            ->when($cursor !== null, fn (Builder $query) => $query->havingRaw('MAX(id) < ?', [$cursor]))
            ->orderByDesc('last_id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $groups->count() > self::PAGE_SIZE;
        $page = $groups->take(self::PAGE_SIZE);

        if ($page->isEmpty()) {
            return ['data' => [], 'next_cursor' => null];
        }

        $audits = $this->taggedAudits($consultation)
            ->whereRaw(self::GROUP_KEY.' in ('.$page->map(fn () => '?')->implode(',').')', $page->pluck('group_key')->all())
            ->with('user')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Audit $audit): string => $audit->batch_id ?? 'audit-'.$audit->id);

        return [
            'data' => $page
                ->map(fn ($group): array => $this->formatGroup($group->group_key, $audits->get($group->group_key)))
                ->values()
                ->all(),
            'next_cursor' => $hasMore ? (int) $page->last()->last_id : null,
        ];
    }

    /**
     * @return Builder<Audit>
     */
    private function taggedAudits(MedicalConsultation $consultation): Builder
    {
        return Audit::query()->where('tags', 'consultation:'.$consultation->getKey());
    }

    /**
     * @param  Collection<int, Audit>  $audits
     * @return HistoryGroup
     */
    private function formatGroup(string $key, Collection $audits): array
    {
        $first = $audits->first();
        $event = $this->groupEvent($audits);
        $custom = $audits->first(fn (Audit $audit): bool => in_array($audit->event, ['voided', 'restored'], true));

        return [
            'id' => $key,
            'event' => $event,
            'event_label' => __('modules/consultations/history.events.'.$event),
            'occurred_at' => $audits->last()->created_at->toIso8601String(),
            'actor' => $first->user ? ['name' => $first->user->name] : null,
            'reason' => $custom?->new_values['reason'] ?? null,
            'changes' => $custom ? [] : $audits
                ->flatMap(fn (Audit $audit): array => $this->changes($audit))
                ->values()
                ->all(),
        ];
    }

    /**
     * A batch reads as the creation only when it created the consultation
     * itself; any other batch of plain audits is a modification.
     *
     * @param  Collection<int, Audit>  $audits
     */
    private function groupEvent(Collection $audits): string
    {
        foreach (['voided', 'restored'] as $event) {
            if ($audits->contains(fn (Audit $audit): bool => $audit->event === $event)) {
                return $event;
            }
        }

        $createdConsultation = $audits->contains(
            fn (Audit $audit): bool => $audit->auditable_type === MedicalConsultation::class && $audit->event === 'created'
        );

        return $createdConsultation ? 'created' : 'updated';
    }

    /**
     * @return list<HistoryChange>
     */
    private function changes(Audit $audit): array
    {
        $section = self::SECTION_KEYS[$audit->auditable_type] ?? null;

        if ($section === null) {
            return [];
        }

        $old = $audit->old_values ?? [];
        $new = $audit->new_values ?? [];
        $item = $section === 'treatment' ? ($new['medication'] ?? $old['medication'] ?? null) : null;

        $fields = array_diff(array_unique([...array_keys($old), ...array_keys($new)]), ['medication']);
        $changes = [];

        foreach ($fields as $field) {
            $changes[] = [
                'section' => __('modules/consultations/history.sections.'.$section),
                'item' => $item,
                'field' => $this->fieldLabel($section, $field),
                'old' => $this->valueLabel($field, $old[$field] ?? null),
                'new' => $this->valueLabel($field, $new[$field] ?? null),
            ];
        }

        return $changes;
    }

    private function fieldLabel(string $section, string $field): string
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

    private function valueLabel(string $field, mixed $value): mixed
    {
        if ($value === null || ! isset(self::ENUM_FIELDS[$field])) {
            return $value;
        }

        $labels = __('modules/consultations/history.'.self::ENUM_FIELDS[$field]);

        return $labels[(int) $value] ?? $value;
    }
}
