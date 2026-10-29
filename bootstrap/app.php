<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SignOutDisabledUsers;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the visitor's address is taken from the proxy (nginx on the same server): a
        // forwarded host or scheme could be set by the visitor and would change our links.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))), headers: Request::HEADER_X_FORWARDED_FOR);
        $middleware->web(append: [AuthenticateSession::class, SignOutDisabledUsers::class, SecurityHeaders::class]);
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One-time tokens typed into forms are never kept in the session on a validation error.
        $exceptions->dontFlash(['api_key', 'apiKey', 'client_secret', 'clientSecret', 'password', 'password_confirmation', 'current_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
