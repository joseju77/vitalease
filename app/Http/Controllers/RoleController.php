<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a list of every role alongside its permission names.
     */
    public function index(): Response
    {
        return Inertia::render('roles/Index', [
            'roles' => Role::query()
                ->with('permissions:name')
                ->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->all(),
                ]),
            'permissionCatalog' => Permission::values(),
        ]);
    }

    /**
     * Store a newly created role and sync its permissions.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::query()->create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($request->validated('permissions'));

        return redirect()->route('roles.index');
    }

    /**
     * Rename the target role and re-sync its permissions.
     *
     * The `super-admin` role is rejected here as defense in depth: the
     * `can:update,role` middleware already blocks it via `RolePolicy`, but a
     * direct-request path must never rely on the policy alone.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin') {
            abort(403);
        }

        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($request->validated('permissions'));

        return redirect()->route('roles.index');
    }

    /**
     * Delete the target role.
     *
     * See {@see self::update()} for why `super-admin` is rejected here too.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin') {
            abort(403);
        }

        $role->delete();

        return redirect()->route('roles.index');
    }
}
