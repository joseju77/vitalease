<?php

namespace App\Http\Controllers;

use App\Models\MedicalConsultation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class HomeController extends Controller
{
    /**
     * Redirect the authenticated user to the first page they are
     * permitted to access: the consultations dashboard, the users list,
     * or the roles list, in that order. Aborts with 403 when the user
     * holds none of those permissions.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->can('viewAny', MedicalConsultation::class)) {
            return redirect()->route('dashboard.index');
        }

        if ($user->can('viewAny', User::class)) {
            return redirect()->route('users.index');
        }

        if ($user->can('viewAny', Role::class)) {
            return redirect()->route('roles.index');
        }

        abort(403);
    }
}
