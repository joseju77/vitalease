<?php

namespace App\Models;

use App\Support\Search\TextTokens;
use Database\Factories\MedicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

#[Fillable([
    'name',
    'presentation',
    'concentration',
    'dispensing_unit',
    'minimum_stock',
    'is_active',
])]
class Medication extends Model
{
    /** @use HasFactory<MedicationFactory> */
    use HasFactory;

    use Searchable;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'minimum_stock' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (Medication $medication) {
            $medication->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * Human-readable identifier combining name, presentation and concentration.
     */
    public function auditLabel(): string
    {
        return trim(implode(' ', array_filter([$this->name, $this->presentation, $this->concentration])));
    }

    /**
     * Database fallback for the Scout search: every token (at most five) must
     * match, case-insensitively, at least one of the indexed fields. No typo
     * tolerance and no accent folding, unlike the search engine.
     *
     * @param  Builder<Medication>  $query
     */
    #[Scope]
    protected function matchingText(Builder $query, string $text): void
    {
        foreach (TextTokens::from($text) as $token) {
            $pattern = TextTokens::containsPattern($token);

            $query->where(function (Builder $query) use ($pattern): void {
                foreach (['name', 'presentation', 'concentration'] as $column) {
                    $query->orWhere($column, 'ilike', $pattern);
                }
            });
        }
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * @return HasMany<MedicalConsultationTreatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(MedicalConsultationTreatment::class);
    }

    /**
     * Whether current stock is at or below the configured minimum. Not
     * persisted as a column: Meilisearch cannot compare two attributes of
     * the same document to each other, so this is computed here and indexed
     * as a plain filterable value in {@see self::toSearchableArray()}.
     */
    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->minimum_stock;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'presentation' => $this->presentation,
            'concentration' => $this->concentration,
            'is_active' => $this->is_active,
            'is_low_stock' => $this->isLowStock(),
        ];
    }
}
