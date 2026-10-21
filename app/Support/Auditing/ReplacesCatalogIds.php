<?php

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;

/**
 * Replaces catalog foreign keys in audited values by readable labels.
 *
 * Each side (`old_values`, `new_values`) is resolved from the id it stored,
 * never from the model's current relation, so a historical label survives
 * a later change of the catalog or of the patient.
 */
trait ReplacesCatalogIds
{
    /**
     * @param  array<string, mixed>  $data  An audit row as received by `transformAudit()`.
     * @param  array<string, array{0: string, 1: class-string<Model>}>  $catalogs  Column => [label key, catalog model with `auditLabel()`].
     * @return array<string, mixed>
     */
    protected function replaceCatalogIds(array $data, array $catalogs): array
    {
        foreach (['old_values', 'new_values'] as $side) {
            foreach ($catalogs as $column => [$labelKey, $catalog]) {
                if (! array_key_exists($column, $data[$side])) {
                    continue;
                }

                $id = $data[$side][$column];
                unset($data[$side][$column]);

                $data[$side][$labelKey] = $id === null ? null : $catalog::query()->find($id)?->auditLabel();
            }
        }

        return $data;
    }
}
