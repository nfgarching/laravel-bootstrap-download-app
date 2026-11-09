<?php

use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Middleware\SecurityHeaders;
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
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Die Anwendung läuft im Container hinter einem Reverse Proxy
        // (Traefik bzw. Caddy) und ist nie direkt erreichbar. Ohne diese
        // Einstellung wären Client-IP und HTTPS-Erkennung falsch.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'portal.api' => AuthenticateApiClient::class,
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
