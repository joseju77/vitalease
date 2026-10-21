<?php

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Records a custom audit event (not a full attribute snapshot), such as a
 * void or a disable carrying its reason. The actor, tags and batch are
 * resolved by the audit resolvers like any other audit.
 */
final class CustomAudit
{
    /**
     * Dispatch one custom audit event for the model. Does nothing when
     * auditing is disabled for the model class.
     *
     * @param  Model&Auditable  $model
     * @param  array<string, mixed>  $new
     */
    public static function record(Model $model, string $event, array $new): void
    {
        if (! $model::isAuditingEnabled()) {
            return;
        }

        $model->auditEvent = $event;
        $model->isCustomEvent = true;
        $model->auditCustomOld = [];
        $model->auditCustomNew = $new;

        try {
            Event::dispatch(new AuditCustom($model));
        } finally {
            $model->isCustomEvent = false;
            $model->auditCustomOld = null;
            $model->auditCustomNew = null;
        }
    }
}
