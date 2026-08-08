<?php

use App\Enums\Permission;
use App\Models\User;

describe('root route redirect', function () {
    it('redirects a guest to the login page', function () {
        $response = $this->get('/');

        $response->assertRedirect(route('auth.login'));
    });

    it('redirects a user with consultations.view to the dashboard', function () {
        $user = User::factory()->withPermissions(Permission::ConsultationsView)->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('dashboard.index'));
    });

    it('redirects a users.manage-only user to the users index', function () {
        $user = User::factory()->withPermissions(Permission::UsersManage)->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('users.index'));
    });

    it('redirects a roles.manage-only user to the roles index', function () {
        $user = User::factory()->withPermissions(Permission::RolesManage)->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('roles.index'));
    });

    it('aborts with 403 for a user with only patients.view', function () {
        $user = User::factory()->withPermissions(Permission::PatientsView)->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertForbidden();
    });

    it('aborts with 403 for a user with no permissions', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertForbidden();
    });

    it('redirects a super-admin to the dashboard through the Gate::before bypass', function () {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('dashboard.index'));
    });

    it('lands a users.manage-only user on their resolved page immediately after login, never on a 403', function () {
        $user = User::factory()->withPermissions(Permission::UsersManage)->create();

        $loginResponse = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse->assertRedirect('/');
        $this->get('/')->assertRedirect(route('users.index'));
    });
});
