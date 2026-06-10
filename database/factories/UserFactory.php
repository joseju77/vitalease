<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'has_access' => true,
        ];
    }

    /**
     * Indicate that the user's access is disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_access' => false,
        ]);
    }

    /**
     * Assign the `super-admin` role, which grants access through the
     * `Gate::before()` bypass rather than through direct permission grants.
     */
    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            Role::findOrCreate('super-admin', 'web');

            $user->assignRole('super-admin');

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Grant the given direct permissions to the user, independent of any role.
     */
    public function withPermissions(Permission ...$permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions) {
            foreach ($permissions as $permission) {
                PermissionModel::findOrCreate($permission->value, 'web');
            }

            $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
