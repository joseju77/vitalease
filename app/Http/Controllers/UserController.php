<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserAccessRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
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
     * Display the form for creating a new user.
     */
    public function create(): Response
    {
        return Inertia::render('users/Create');
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
     * Toggle the target user's access flag.
     *
     * This only affects the next login attempt; it does not terminate an
     * already-active session.
     */
    public function updateAccess(UpdateUserAccessRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        return back();
    }
}
