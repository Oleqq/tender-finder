<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LogHttpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::error('http.request.failed', $this->context($request, $requestId, $started) + [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $response->headers->set('X-Request-ID', $requestId);

        // The internal Docker health probe runs every 30 seconds.
        if ($request->is('health') && $request->ip() === '127.0.0.1' && $response->isSuccessful()) {
            return $response;
        }

        $status = $response->getStatusCode();
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

        Log::log($level, 'http.request.completed', $this->context($request, $requestId, $started) + [
            'status' => $status,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'client_platform' => $this->clientPlatform($request->userAgent()),
        ]);

        return $response;
    }

    /** @return array<string, int|float|string|null> */
    private function context(Request $request, string $requestId, int $started): array
    {
        return [
            'request_id' => $requestId,
            'method' => $request->method(),
            // Route templates do not contain signed URL, invitation or calendar tokens.
            'route' => $request->route()?->uri() ?? '[unmatched]',
            'route_name' => $request->route()?->getName(),
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
        ];
    }

    private function clientPlatform(?string $userAgent): string
    {
        if (preg_match('/iPhone|iPad|iPod/i', $userAgent ?? '')) {
            return 'ios';
        }

        if (preg_match('/Android/i', $userAgent ?? '')) {
            return 'android';
        }

        return 'other';
    }
}
