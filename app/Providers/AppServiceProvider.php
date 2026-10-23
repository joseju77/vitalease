<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Auditing\AuditBatch;
use App\Support\Health\DatabaseCheck;
use App\Support\Health\MeilisearchCheck;
use App\Support\Health\QueueCheck;
use App\Support\Health\RedisCheck;
use App\Support\Health\SchedulerCheck;
use App\Support\Search\SearchDegradation;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Scout;
use Meilisearch\Client as MeilisearchClient;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditBatch::class);

        $this->app->scoped(SearchDegradation::class);

        // Scout binds this client without timeouts, so a hung Meilisearch
        // would stall requests until PHP's execution limit. Rebinding it here
        // (this provider registers after Scout's) bounds every call; the
        // fallback in `ResilientSearch` then keeps search working.
        $this->app->singleton(MeilisearchClient::class, function (Application $app): MeilisearchClient {
            $config = $app['config']->get('scout.meilisearch');

            return new MeilisearchClient(
                $config['host'],
                $config['key'],
                new HttpClient([
                    'connect_timeout' => $config['connect_timeout'],
                    'timeout' => $config['timeout'],
                ]),
                clientAgents: [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
            );
        });

        $this->app->tag([
            DatabaseCheck::class,
            RedisCheck::class,
            MeilisearchCheck::class,
            QueueCheck::class,
            SchedulerCheck::class,
        ], 'health.checks');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('super-admin') ? true : null;
        });

        // Explicit registration: Laravel's policy auto-discovery only maps
        // models under `App\Models` to `App\Policies`, and spatie's `Role`
        // model lives outside that namespace.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
