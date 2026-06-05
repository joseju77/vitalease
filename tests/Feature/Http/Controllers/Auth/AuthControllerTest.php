<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

describe('password login', function () {
    it('authenticates the user, regenerates the session, and records the last login timestamp', function () {
        $this->freezeTime();
        $user = User::factory()->create();

        $this->get(route('auth.login'));
        $previousSessionId = session()->getId();

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
        expect(session()->getId())->not->toBe($previousSessionId)
            ->and($user->fresh()->last_login_at->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    it('rejects an incorrect password with the generic invalid-credentials error', function () {
        $user = User::factory()->create();

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('rejects an inactive user with the same generic invalid-credentials error as a wrong password', function () {
        $user = User::factory()->inactive()->create();

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('rejects an unknown email with the generic invalid-credentials error', function () {
        $response = $this->post(route('auth.login.store'), [
            'email' => 'unknown@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('rate limits repeated failed attempts and rejects the sixth attempt even with the correct password', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('auth.login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.rate_limited'),
        ]);
        $this->assertGuest();
    });

    it('requires both email and password', function () {
        $response = $this->post(route('auth.login.store'), []);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.validation.email.required'),
            'password' => __('modules/auth/login.validation.password.required'),
        ]);
        $this->assertGuest();
    });

    it('rejects an invalid email format', function () {
        $response = $this->post(route('auth.login.store'), [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.validation.email.email'),
        ]);
        $this->assertGuest();
    });
});

describe('google login', function () {
    it('redirects to the login page with a generic error when the google exchange fails', function () {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('oauth exchange failed'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.login'));
        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.google_auth_failed'),
        ]);
        $this->assertGuest();
    });

    it('redirects to the login page when google does not return an email', function () {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['email' => null]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.login'));
        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.google_missing_email'),
        ]);
        $this->assertGuest();
    });

    it('logs in an existing active user matched by google email and records the last login timestamp', function () {
        $this->freezeTime();
        $user = User::factory()->create();

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['email' => $user->email]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
        expect($user->fresh()->last_login_at->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    it('rejects a google email with no matching user using the generic invalid-credentials error', function () {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['email' => 'unknown@example.com']));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.login'));
        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('rejects a google email matching an inactive user using the same generic invalid-credentials error', function () {
        $user = User::factory()->inactive()->create();

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['email' => $user->email]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.login'));
        $response->assertSessionHasErrors([
            'email' => __('modules/auth/login.errors.invalid_credentials'),
        ]);
        $this->assertGuest();
    });

    it('remembers a same-origin, non-login referrer as the intended url before redirecting to google', function () {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $referer = url('/dashboard');

        $this->withHeader('referer', $referer)->get(route('auth.google.redirect'));

        expect(session('url.intended'))->toBe($referer);
    });

    it('does not remember the intended url when the referrer is the login page or a different origin', function (string $case) {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $referer = $case === 'login-page' ? url('/login') : 'https://evil.example.com/dashboard';

        $this->withHeader('referer', $referer)->get(route('auth.google.redirect'));

        expect(session()->has('url.intended'))->toBeFalse();
    })->with([
        'the login page itself' => 'login-page',
        'a different origin' => 'different-origin',
    ]);
});
