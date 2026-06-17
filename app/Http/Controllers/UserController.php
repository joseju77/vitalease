<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserAuthorizationRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a paginated list of users.
     */
    public function index(): Response
    {
        $users = User::query()
            ->select(['uuid', 'name', 'email', 'has_access', 'last_login_at', 'created_at'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('users/Index', [
            'users' => $users,
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
