<?php

namespace App\Support\Auditing;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads the audit trail of one subject (a consultation, a patient) as a
 * readable change history: audits are grouped by batch (one group per save
 * or custom event), newest first, with Spanish section, field and value
 * labels resolved server-side.
 *
 * Subclasses only describe their subject through the hooks below; grouping,
 * cursor pagination and the response shape are shared.
 *
 * @phpstan-type HistoryChange array{section: string, item: string|null, field: string, old: mixed, new: mixed}
 * @phpstan-type HistoryGroup array{id: string, event: string, event_label: string, occurred_at: string, actor: array{name: string}|null, reason: string|null, changes: list<HistoryChange>}
 */
abstract readonly class AuditHistory
{
    /**
     * Number of groups per page. A group is never split across pages.
     */
    public const int PAGE_SIZE = 20;

    private const string GROUP_KEY = "COALESCE(batch_id::text, 'audit-' || id::text)";

    /**
     * The tag every audit of the subject carries.
     */
    abstract protected function tag(Model $subject): string;

    /**
     * The audited model class whose `created` event makes a batch read as
     * the creation of the subject.
     *
     * @return class-string<Model>
     */
    abstract protected function rootModel(): string;

    /**
     * Custom events recorded explicitly (not a full attribute snapshot),
     * each carrying a reason.
     *
     * @return list<string>
     */
    abstract protected function customEvents(): array;

    /**
     * Section key of every audited model class of the subject.
     *
     * @return array<class-string<Model>, string>
     */
    abstract protected function sectionKeys(): array;

    /**
     * Translation namespace holding the `history` file (sections, events and
     * value labels), for example `modules/consultations`.
     */
    abstract protected function langNamespace(): string;

    /**
     * Name of the row an audited change belongs to, for sections made of
     * several rows (a treatment line, an emergency contact).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    abstract protected function item(string $section, array $old, array $new): ?string;

    /**
     * Audited keys of a section that are not shown as changes (they only
     * identify the row, see `item()`).
     *
     * @return list<string>
     */
    abstract protected function hiddenFields(string $section): array;

    abstract protected function fieldLabel(string $section, string $field): string;

    abstract protected function valueLabel(string $section, string $field, mixed $value): mixed;

    /**
     * Actor name shown when the audit has no user, or null to expose no actor.
     */
    protected function nullActorName(): ?string
    {
        return null;
    }

    /**
     * Whether a field that is null on both sides is left out of the changes.
     * A created row stores its empty optional fields as null, which reads as
     * a meaningless "empty to empty" change; subjects opt in to hide them.
     */
    protected function omitsEmptyToEmptyChanges(): bool
    {
        return false;
    }

    /**
     * One page of history groups, newest first.
     *
     * `$cursor` is the `next_cursor` of the previous page; `next_cursor` is
     * null when no older groups remain.
     *
     * @return array{data: list<HistoryGroup>, next_cursor: int|null}
     */
    protected function paginate(Model $subject, ?int $cursor): array
    {
        $groups = $this->taggedAudits($subject)
            ->selectRaw(self::GROUP_KEY.' as group_key, MAX(id) as last_id')
            ->groupByRaw(self::GROUP_KEY)
            ->when($cursor !== null, fn (Builder $query) => $query->havingRaw('MAX(id) < ?', [$cursor]))
            ->orderByDesc('last_id')
            ->limit(static::PAGE_SIZE + 1)
            ->get();

        $hasMore = $groups->count() > static::PAGE_SIZE;
        $page = $groups->take(static::PAGE_SIZE);

        if ($page->isEmpty()) {
            return ['data' => [], 'next_cursor' => null];
        }

        $audits = $this->taggedAudits($subject)
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
    private function taggedAudits(Model $subject): Builder
    {
        return Audit::query()->where('tags', $this->tag($subject));
    }

    /**
     * @param  Collection<int, Audit>  $audits
     * @return HistoryGroup
     */
    private function formatGroup(string $key, Collection $audits): array
    {
        $first = $audits->first();
        $event = $this->groupEvent($audits);
        $custom = $audits->first(fn (Audit $audit): bool => in_array($audit->event, $this->customEvents(), true));
        $actorName = $first->user?->name ?? $this->nullActorName();

        return [
            'id' => $key,
            'event' => $event,
            'event_label' => __($this->langNamespace().'/history.events.'.$event),
            'occurred_at' => $audits->last()->created_at->toIso8601String(),
            'actor' => $actorName !== null ? ['name' => $actorName] : null,
            'reason' => $custom?->new_values['reason'] ?? null,
            'changes' => $custom ? [] : $audits
                ->flatMap(fn (Audit $audit): array => $this->changes($audit))
                ->values()
                ->all(),
        ];
    }

    /**
     * A batch with a custom event reads as that event; one that created the
     * root model reads as the creation; any other batch of plain audits is a
     * modification.
     *
     * @param  Collection<int, Audit>  $audits
     */
    private function groupEvent(Collection $audits): string
    {
        foreach ($this->customEvents() as $event) {
            if ($audits->contains(fn (Audit $audit): bool => $audit->event === $event)) {
                return $event;
            }
        }

        $createdRoot = $audits->contains(
            fn (Audit $audit): bool => $audit->auditable_type === $this->rootModel() && $audit->event === 'created'
        );

        return $createdRoot ? 'created' : 'updated';
    }

    /**
     * @return list<HistoryChange>
     */
    private function changes(Audit $audit): array
    {
        $section = $this->sectionKeys()[$audit->auditable_type] ?? null;

        if ($section === null) {
            return [];
        }

        $old = $audit->old_values ?? [];
        $new = $audit->new_values ?? [];
        $item = $this->item($section, $old, $new);

        $fields = array_diff(array_unique([...array_keys($old), ...array_keys($new)]), $this->hiddenFields($section));
        $changes = [];

        foreach ($fields as $field) {
            if ($this->omitsEmptyToEmptyChanges() && ($old[$field] ?? null) === null && ($new[$field] ?? null) === null) {
                continue;
            }

            $changes[] = [
                'section' => __($this->langNamespace().'/history.sections.'.$section),
                'item' => $item,
                'field' => $this->fieldLabel($section, $field),
                'old' => $this->valueLabel($section, $field, $old[$field] ?? null),
                'new' => $this->valueLabel($section, $field, $new[$field] ?? null),
            ];
        }

        return $changes;
    }
}
