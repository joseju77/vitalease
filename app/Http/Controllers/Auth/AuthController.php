<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Throwable;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

class AuthController extends Controller
{
    /**
     * Display the login page.
     */
    public function login(): Response
    {
        return Inertia::render('auth/Login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $request->user()->updateQuietly(['last_login_at' => now()]);

        return redirect()->intended();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Redirect the user to Google's OAuth consent screen.
     */
    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $previous = url()->previous();

        try {
            $previousUri = new Uri($previous);

            $isSameOrigin = $previousUri->getHost() === $request->getHost()
                && $previousUri->getScheme() === $request->getScheme();
            $isLoginPage = $previousUri->getPath() === '/login';
        } catch (InvalidUriException) {
            $isSameOrigin = false;
            $isLoginPage = false;
        }

        if ($isSameOrigin && ! $isLoginPage) {
            session()->put('url.intended', $previous);
        } else {
            session()->forget('url.intended');
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google after authentication.
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('auth.login')
                ->withErrors(['email' => __('modules/auth/login.errors.google_auth_failed')]);
        }

        if (! $googleUser->getEmail()) {
            return redirect()->route('auth.login')
                ->withErrors(['email' => __('modules/auth/login.errors.google_missing_email')]);
        }

        $user = User::query()
            ->where('email', $googleUser->getEmail())
            ->where('has_access', true)
            ->first();

        if (! $user) {
            return redirect()->route('auth.login')
                ->withErrors(['email' => __('modules/auth/login.errors.invalid_credentials')]);
        }

        Auth::login($user);

        $request->session()->regenerate();

        $user->updateQuietly(['last_login_at' => now()]);

        return redirect()->intended();
    }
}
