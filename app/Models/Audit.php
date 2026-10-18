<?php

namespace App\Models;

use LogicException;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * An immutable audit row: once written it can never be updated or deleted
 * through the application, mirroring the append-only inventory ledger.
 */
class Audit extends BaseAudit
{
    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::updating(function (Audit $audit) {
            throw new LogicException('Audit rows are immutable and cannot be updated.');
        });

        static::deleting(function (Audit $audit) {
            throw new LogicException('Audit rows are immutable and cannot be deleted.');
        });
    }
}
