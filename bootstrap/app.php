<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogHttpRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(LogHttpRequest::class);

        if (env('APP_ENV') === 'production') {
            $middleware->trustProxies(at: '*');
        }

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
        ]);
        $middleware->redirectGuestsTo('/onboarding');

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(fn (): array => [
            'request_id' => app()->bound('request')
                ? request()->attributes->get('request_id')
                : null,
        ]);
        $exceptions->respond(function (Response $response): Response {
            if (app()->bound('request') && $requestId = request()->attributes->get('request_id')) {
                $response->headers->set('X-Request-ID', $requestId);
            }

            return $response;
        });
    })->create();
