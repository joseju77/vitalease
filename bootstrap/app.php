<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Outage\DependencyOutage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/auth.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `/health` sits outside the web group on purpose: no session, cookie
        // or Redis dependency, so it can report that Redis is down.
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->redirectTo(fn () => route('auth.login'));

        // The Swarm nginx forwards the real client IP over the overlay
        // network; TRUSTED_PROXIES lists its subnet (comma separated, or `*`).
        $trustedProxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(
            at: in_array($trustedProxies, [null, '', '*'], true)
                ? ($trustedProxies ?: null)
                : array_map('trim', explode(',', $trustedProxies)),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // PostgreSQL and Redis are critical dependencies: when either is
        // unreachable the user gets a plain 503 instead of a stack trace or a
        // hung request. Other database errors (constraints, syntax) stay 500.
        // The Inertia error page is rendered without shared props because the
        // session, the database or Redis may be the very thing that failed.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! DependencyOutage::causedBy($exception)) {
                return null;
            }

            $headers = ['Retry-After' => '30'];

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Servicio temporalmente no disponible.'], 503, $headers);
            }

            Inertia::flushShared();

            return Inertia::render('errors/ServiceUnavailable')
                ->toResponse($request)
                ->setStatusCode(503)
                ->withHeaders($headers);
        });

        // Anonymous patient registration carries demographic and medical
        // data; none of these roots may be flashed back as old input on a
        // validation failure.
        $exceptions->dontFlash([
            'patient',
            'contact_information',
            'emergency_contacts',
            'ailments',
            'other_ailments',
            'gynecological_history',
        ]);
    })->create();
