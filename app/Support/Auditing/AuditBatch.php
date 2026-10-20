<?php

namespace App\Support\Auditing;

use Closure;
use Illuminate\Support\Str;

/**
 * Tracks the batch identifier shared by every audit row produced by one
 * logical operation (a single request or a single seed operation), so a
 * multimodel save can later be shown as one change.
 */
final class AuditBatch
{
    private ?string $current = null;

    /**
     * The batch identifier of the operation in progress, if any.
     */
    public function current(): ?string
    {
        return $this->current;
    }

    /**
     * Run the callback inside one audit batch.
     *
     * Nested calls reuse the enclosing batch, so an operation composed of
     * several services still produces a single batch.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function within(Closure $callback): mixed
    {
        if ($this->current !== null) {
            return $callback();
        }

        $this->current = (string) Str::uuid7();

        try {
            return $callback();
        } finally {
            $this->current = null;
        }
    }
}
