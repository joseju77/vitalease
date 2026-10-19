<?php

namespace Database\Seeders\Concerns;

use App\Models\User;
use App\Support\Auditing\AuditBatch;
use Closure;
use Illuminate\Support\Facades\Auth;

trait RunsWithAuditing
{
    /**
     * Run one logical demo operation with the consultation audit trail
     * switched on, attributed to `$actor` and grouped in a single audit
     * batch.
     *
     * Seeders run in the console, where auditing is off by default; only
     * the curated demo history opts in, so every other demo consultation
     * keeps an empty history. The previous setting and guard user are always
     * restored.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withAuditing(User $actor, Closure $callback): mixed
    {
        $guard = Auth::guard('web');
        $previousUser = $guard->user();
        $previousSetting = config('audit.console');

        config(['audit.console' => true]);
        $guard->setUser($actor);

        try {
            return app(AuditBatch::class)->within($callback);
        } finally {
            config(['audit.console' => $previousSetting]);

            if ($previousUser === null) {
                $guard->forgetUser();
            } else {
                $guard->setUser($previousUser);
            }
        }
    }

    /**
     * Run the callback with the consultation audit trail explicitly off,
     * whatever the environment configures.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withoutAuditing(Closure $callback): mixed
    {
        $previousSetting = config('audit.console');

        config(['audit.console' => false]);

        try {
            return $callback();
        } finally {
            config(['audit.console' => $previousSetting]);
        }
    }
}
