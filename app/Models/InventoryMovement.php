<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'medication_id',
    'type',
    'quantity',
    'stock_after',
    'medical_consultation_id',
    'medical_consultation_code',
    'user_id',
    'notes',
    'occurred_at',
])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    /**
     * The ledger is append-only: rows are never updated after creation.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'integer',
            'stock_after' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     *
     * Enforces the append-only ledger invariant: no code path may update or
     * delete a movement row once it has been written.
     */
    protected static function booted(): void
    {
        static::updating(function (InventoryMovement $movement) {
            throw new LogicException('InventoryMovement rows are append-only and cannot be updated.');
        });

        static::deleting(function (InventoryMovement $movement) {
            throw new LogicException('InventoryMovement rows are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function medicalConsultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
