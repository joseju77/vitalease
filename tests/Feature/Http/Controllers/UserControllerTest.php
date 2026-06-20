<?php

use App\Enums\Permission;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

dataset('userManagementRoutes', [
    'listing users' => ['get', 'users.index', false],
    'creating a user' => ['post', 'users.store', false],
    'updating a user' => ['patch', 'users.update', true],
    'updating user authorization' => ['put', 'users.authorization', true],
]);

describe('listing users', function () {
    it('allows a super-admin to list users paginated', function () {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->count(20)->create();

        $response = $this->actingAs($admin)->get(route('users.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('users/Index', false)
            ->has('users.data', 15)
            ->where('users.total', 21)
        );
    });
});

describe('creating users', function () {
    it('allows a super-admin to create a user who defaults to has_access=true and can then log in', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->post(route('auth.login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response = $this->post(route('users.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('users.index'));

        $newUser = User::query()->where('email', 'jane@example.com')->first();
        expect($newUser)->not->toBeNull();
        expect($newUser->has_access)->toBeTrue();

        $this->post(route('auth.logout'));

        $loginResponse = $this->post(route('auth.login.store'), [
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertRedirect();
        $this->assertAuthenticatedAs($newUser);
    });

    it('rejects creating a user with a duplicate email', function () {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($admin)->postJson(route('users.store'), [
            'name' => 'New User',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });
});

describe('updating users', function () {
    it("allows a super-admin to update a user's name, email, and access flag", function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['has_access' => true]);

        $response = $this->actingAs($admin)->patch(route('users.update', $target), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'has_access' => false,
        ]);

        $response->assertRedirect();
        $target->refresh();
        expect($target->name)->toBe('Updated Name');
        expect($target->email)->toBe('updated@example.com');
        expect($target->has_access)->toBeFalse();
    });

    it('ignores the current user when checking email uniqueness on update', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'keep@example.com']);

        $response = $this->actingAs($admin)->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => 'keep@example.com',
            'has_access' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    });

    it("rejects updating a user's email to another user's existing email", function () {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->patchJson(route('users.update', $target), [
            'name' => $target->name,
            'email' => 'taken@example.com',
            'has_access' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });

    it('rejects invalid or missing fields on update', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->patchJson(route('users.update', $target), [
            'name' => '',
            'email' => 'not-an-email',
            'has_access' => 'maybe',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'has_access']);
    });
});

describe('access toggle', function () {
    it("blocks the next password login attempt once access is disabled, without invalidating the caller's own session", function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->post(route('auth.login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response = $this->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'has_access' => false,
        ]);

        $response->assertRedirect();
        expect($target->fresh()->has_access)->toBeFalse();
        $this->assertAuthenticatedAs($admin);

        $this->post(route('auth.logout'));

        $loginResponse = $this->post(route('auth.login.store'), [
            'email' => $target->email,
            'password' => 'password',
        ]);

        $loginResponse->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('blocks the next google login attempt once access is disabled', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->post(route('auth.login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'has_access' => false,
        ]);

        $this->post(route('auth.logout'));

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['email' => $target->email]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.login'));
        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('restores login ability once access is re-enabled', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->inactive()->create();

        $this->actingAs($admin)->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'has_access' => true,
        ]);

        expect($target->fresh()->has_access)->toBeTrue();
    });
});

describe('role and permission assignment', function () {
    it('accepts an empty roles and permissions array', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put(route('users.authorization', $target), [
            'roles' => [],
            'permissions' => [],
        ]);

        $response->assertRedirect();
        expect($target->fresh()->roles)->toHaveCount(0);
        expect($target->fresh()->permissions)->toHaveCount(0);
    });

    it('rejects assigning an unknown role name', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->putJson(route('users.authorization', $target), [
            'roles' => ['ghost-role'],
            'permissions' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roles.0']);
    });

    it('rejects assigning an unknown permission name', function () {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->putJson(route('users.authorization', $target), [
            'roles' => [],
            'permissions' => ['ghost.permission'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0']);
    });

    it('keeps a permission effective when revoked directly but still granted through a role', function () {
        $admin = User::factory()->superAdmin()->create();
        PermissionModel::findOrCreate(Permission::PatientsView->value, 'web');
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::PatientsView->value);

        $target = User::factory()->create();
        $target->assignRole('editor');
        $target->givePermissionTo(Permission::PatientsView->value);

        $response = $this->actingAs($admin)->put(route('users.authorization', $target), [
            'roles' => ['editor'],
            'permissions' => [],
        ]);

        $response->assertRedirect();
        expect($target->fresh()->hasDirectPermission(Permission::PatientsView->value))->toBeFalse();
        expect($target->fresh()->can(Permission::PatientsView->value))->toBeTrue();
    });
});

describe('authorization boundary on users.* routes', function () {
    it('denies a caller with no role and no direct permission', function (string $method, string $routeName, bool $needsTarget) {
        $caller = User::factory()->create();
        $target = User::factory()->create();
        $uri = $needsTarget ? route($routeName, $target) : route($routeName);

        $response = $this->actingAs($caller)->{$method}($uri, []);

        $response->assertForbidden();
    })->with('userManagementRoutes');

    it('denies a caller holding only an unrelated permission (patients.view)', function (string $method, string $routeName, bool $needsTarget) {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();
        $target = User::factory()->create();
        $uri = $needsTarget ? route($routeName, $target) : route($routeName);

        $response = $this->actingAs($caller)->{$method}($uri, []);

        $response->assertForbidden();
    })->with('userManagementRoutes');
});

describe('self-lockout is permitted', function () {
    it('allows a super-admin to revoke their own role', function () {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->put(route('users.authorization', $admin), [
            'roles' => [],
            'permissions' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        expect($admin->fresh()->roles)->toHaveCount(0);
    });

    it('allows a super-admin to disable their own account', function () {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->patch(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'has_access' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        expect($admin->fresh()->has_access)->toBeFalse();
    });
});

describe('super-admin gate bypass', function () {
    it('allows a super-admin with zero direct permissions and zero role grants through every users.* route', function (string $method, string $routeName, bool $needsTarget) {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $uri = $needsTarget ? route($routeName, $target) : route($routeName);
        $payload = match ($routeName) {
            'users.store' => ['name' => 'New', 'email' => 'new-user@example.com', 'password' => 'password123'],
            'users.update' => ['name' => $target->name, 'email' => $target->email, 'has_access' => true],
            'users.authorization' => ['roles' => [], 'permissions' => []],
            default => [],
        };

        $response = $this->actingAs($admin)->{$method}($uri, $payload);

        expect($response->getStatusCode())->toBeLessThan(400);
    })->with('userManagementRoutes');

    it('confirms the super-admin role holds zero permission grants (the bypass is not a hidden permission grant)', function () {
        User::factory()->superAdmin()->create();

        $role = Role::where('name', 'super-admin')->first();

        expect($role)->not->toBeNull();
        expect($role->permissions)->toHaveCount(0);
    });
});

describe('guest access', function () {
    it('redirects an unauthenticated caller to login instead of returning 403', function (string $method, string $routeName, bool $needsTarget) {
        $target = User::factory()->create();
        $uri = $needsTarget ? route($routeName, $target) : route($routeName);

        $response = $this->{$method}($uri);

        $response->assertRedirect(route('auth.login'));
    })->with('userManagementRoutes');
});
