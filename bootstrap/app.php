<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trusted = env('TRUSTED_PROXIES', '');
        if (!empty($trusted)) {
            $middleware->trustProxies(at: array_map('trim', explode(',', $trusted)));
        }

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/admin/login');
        $middleware->prepend(\App\Http\Middleware\DynamicCorsOriginMiddleware::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'super_admin' => EnsureSuperAdmin::class,
            'headless.api' => \App\Http\Middleware\EnsureHeadlessApiKey::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'sliding-throttle' => \App\Http\Middleware\SlidingWindowThrottle::class,
            'throttle' => \App\Http\Middleware\SlidingWindowThrottle::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
