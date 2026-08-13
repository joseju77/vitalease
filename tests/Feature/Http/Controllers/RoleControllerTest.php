<?php

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

dataset('roleManagementRoutes', [
    'listing roles' => ['get', 'roles.index', false],
    'creating a role' => ['post', 'roles.store', false],
    'updating a role' => ['patch', 'roles.update', true],
    'deleting a role' => ['delete', 'roles.destroy', true],
]);

describe('listing roles', function () {
    it('allows a super-admin to list roles with their permission names', function () {
        $admin = User::factory()->superAdmin()->create();
        PermissionModel::findOrCreate(Permission::PatientsView->value, 'web');
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::PatientsView->value);

        $response = $this->actingAs($admin)->get(route('roles.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('roles/Index', false)
            ->has('roles', 2)
        );
    });
});

describe('creating roles', function () {
    it('allows a super-admin to create a role with a subset of the permission catalog', function () {
        $admin = User::factory()->superAdmin()->create();
        PermissionModel::findOrCreate(Permission::PatientsView->value, 'web');
        PermissionModel::findOrCreate(Permission::PatientsCreate->value, 'web');

        $response = $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'editor',
            'permissions' => [Permission::PatientsView->value, Permission::PatientsCreate->value],
        ]);

        $response->assertRedirect(route('roles.index'));

        $role = Role::where('name', 'editor')->first();
        expect($role)->not->toBeNull();
        expect($role->permissions->pluck('name')->all())->toEqualCanonicalizing([
            Permission::PatientsView->value,
            Permission::PatientsCreate->value,
        ]);
    });

    it('rejects creating a role with a duplicate name', function () {
        $admin = User::factory()->superAdmin()->create();
        Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)->postJson(route('roles.store'), [
            'name' => 'editor',
            'permissions' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    it('rejects creating a role with an unknown permission name', function () {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson(route('roles.store'), [
            'name' => 'editor',
            'permissions' => ['ghost.permission'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0']);
    });
});

describe('updating roles', function () {
    it("allows a super-admin to update a role's name and permissions", function () {
        $admin = User::factory()->superAdmin()->create();
        PermissionModel::findOrCreate(Permission::PatientsView->value, 'web');
        PermissionModel::findOrCreate(Permission::PatientsUpdate->value, 'web');
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::PatientsView->value);

        $response = $this->actingAs($admin)->patch(route('roles.update', $role), [
            'name' => 'senior-editor',
            'permissions' => [Permission::PatientsUpdate->value],
        ]);

        $response->assertRedirect(route('roles.index'));

        $role->refresh();
        expect($role->name)->toBe('senior-editor');
        expect($role->permissions->pluck('name')->all())->toBe([Permission::PatientsUpdate->value]);
    });

    it('allows a role to keep its own current name on update', function () {
        $admin = User::factory()->superAdmin()->create();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)->patch(route('roles.update', $role), [
            'name' => 'editor',
            'permissions' => [],
        ]);

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHasNoErrors();
    });

    it("rejects renaming a role to another existing role's name", function () {
        $admin = User::factory()->superAdmin()->create();
        Role::create(['name' => 'viewer', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)->patchJson(route('roles.update', $role), [
            'name' => 'viewer',
            'permissions' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    it('rejects updating a role with an unknown permission name', function () {
        $admin = User::factory()->superAdmin()->create();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)->patchJson(route('roles.update', $role), [
            'name' => 'editor',
            'permissions' => ['ghost.permission'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0']);
    });
});

describe('deleting roles', function () {
    it('allows a super-admin to delete a role', function () {
        $admin = User::factory()->superAdmin()->create();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)->delete(route('roles.destroy', $role));

        $response->assertRedirect(route('roles.index'));
        expect(Role::find($role->id))->toBeNull();
    });
});

describe('super-admin role protection', function () {
    it('rejects updating the super-admin role even for a roles.manage holder', function () {
        $caller = User::factory()->withPermissions(Permission::RolesManage)->create();
        $superAdminRole = Role::findOrCreate('super-admin', 'web');

        $response = $this->actingAs($caller)->patchJson(route('roles.update', $superAdminRole), [
            'name' => 'renamed',
            'permissions' => [],
        ]);

        $response->assertForbidden();
        expect($superAdminRole->fresh()->name)->toBe('super-admin');
    });

    it('rejects deleting the super-admin role even for a roles.manage holder', function () {
        $caller = User::factory()->withPermissions(Permission::RolesManage)->create();
        $superAdminRole = Role::findOrCreate('super-admin', 'web');

        $response = $this->actingAs($caller)->delete(route('roles.destroy', $superAdminRole));

        $response->assertForbidden();
        expect(Role::find($superAdminRole->id))->not->toBeNull();
    });
});

describe('authorization boundary on roles.* routes', function () {
    it('denies a plain user with no roles.manage', function (string $method, string $routeName, bool $needsTarget) {
        $caller = User::factory()->create();
        $target = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $uri = $needsTarget ? route($routeName, $target) : route($routeName);

        $response = $this->actingAs($caller)->{$method}($uri, []);

        $response->assertForbidden();
    })->with('roleManagementRoutes');

    it('denies a user holding only users.manage, proving it does not imply roles.manage', function (string $method, string $routeName, bool $needsTarget) {
        $caller = User::factory()->withPermissions(Permission::UsersManage)->create();
        $target = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $uri = $needsTarget ? route($routeName, $target) : route($routeName);

        $response = $this->actingAs($caller)->{$method}($uri, []);

        $response->assertForbidden();
    })->with('roleManagementRoutes');
});

describe('seeding', function () {
    it('seeds exactly the full permission catalog and exactly one permissionless super-admin role', function () {
        $this->seed(RolePermissionSeeder::class);

        expect(PermissionModel::query()->pluck('name')->all())
            ->toEqualCanonicalizing(Permission::values());

        $roles = Role::all();
        expect($roles)->toHaveCount(1);
        expect($roles->first()->name)->toBe('super-admin');
        expect($roles->first()->permissions)->toHaveCount(0);
    });
});
