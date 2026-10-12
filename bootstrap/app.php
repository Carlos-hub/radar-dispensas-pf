<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Só o host de APP_URL (e o localhost do healthcheck) é aceito; o closure roda com a config já carregada.
        // Fora de local/testing o TrustHosts recusa outros hosts; em qualquer ambiente o AppServiceProvider fixa a raiz das URLs.
        $middleware->trustHosts(at: fn () => array_filter([
            ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? '^'.preg_quote($host, '/').'$' : null,
            '^localhost$',
        ]), subdomains: false);

        // Sessões abertas antes de uma troca de senha deixam de valer na requisição seguinte.
        $middleware->authenticateSessions();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
