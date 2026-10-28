<?php

namespace App\Http\Middleware;

use App\Services\AccessFreezeService;
use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceWorkspaceAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_demo_guest && $request->isMethod('POST') && $request->is('team-invitations/*', 'teams/*/invitations', 'teams/*/transfer-ownership')) {
            abort(403, 'Демонстрационные аккаунты работают изолированно.');
        }
        if ($user === null || app(AccessService::class)->hasActiveAccess($user)) {
            return $next($request);
        }
        app(AccessFreezeService::class)->freezeIfInactive($user, now());
        $read = in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->routeIs('*.export');
        $account = $request->is('consents*', 'trial/start', 'support*', 'profile*', 'demo*')
            || $request->routeIs('queries.pause', 'queries.freeze', 'queries.destroy', 'calendar.subscription.destroy');
        if (! $read && ! $account) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Срок доступа завершён. Данные сохранены; для продолжения нужен действующий доступ.', 'code' => 'access_required'], 403);
            }

            return redirect()->route('plans');
        }

        return $next($request);
    }
}
