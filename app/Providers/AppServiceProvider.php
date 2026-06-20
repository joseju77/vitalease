<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
