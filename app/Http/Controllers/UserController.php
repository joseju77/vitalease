<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserAuthorizationRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a paginated list of users, alongside the role/permission
     * catalogs and each user's current roles and direct permissions, so
     * the authorization assignment dialog can pre-fill without an extra
     * request per row.
     */
    public function index(): Response
    {
        $users = User::query()
            // `id` must stay selected even though it is never rendered: the
            // roles/permissions eager loads below relate through
            // `model_has_roles`/`model_has_permissions` on `users.id`, not
            // `uuid`, and Eloquent silently returns empty relations without it.
            ->select(['id', 'uuid', 'name', 'email', 'has_access', 'last_login_at', 'created_at'])
            ->with(['roles:name', 'permissions:name'])
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'has_access' => $user->has_access,
                'last_login_at' => $user->last_login_at,
                'roles' => $user->roles->pluck('name')->all(),
                'permissions' => $user->permissions->pluck('name')->all(),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'roleCatalog' => Role::query()->pluck('name'),
            'permissionCatalog' => Permission::values(),
        ]);
    }

    /**
     * Store a newly created user.
     *
     * This is the only account-creation path in the application: the admin
     * sets the new user's password directly, with no invitation email or
     * forced rotation.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::query()->create([
            ...$request->validated(),
            'has_access' => true,
        ]);

        return redirect()->route('users.index');
    }

    /**
     * Update the target user's name, email, and access flag.
     *
     * Toggling `has_access` off only affects the next login attempt; it does
     * not terminate an already-active session.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        return back();
    }

    /**
     * Sync the target user's roles and direct permissions.
     */
    public function updateAuthorization(UpdateUserAuthorizationRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user->syncRoles($request->validated('roles'));
            $user->syncPermissions($request->validated('permissions'));
        });

        return back();
    }
}
