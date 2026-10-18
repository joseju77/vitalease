<?php

namespace App\Support\Auditing;

use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

/**
 * Resolves the `batch_id` column of an audit row from the operation in
 * progress. Outside an audit batch the column stays null.
 */
class BatchIdResolver implements Resolver
{
    public static function resolve(Auditable $auditable): ?string
    {
        return app(AuditBatch::class)->current();
    }
}
