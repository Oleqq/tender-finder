<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\LocalMvpOperatorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, LocalMvpOperatorService $mvpOperator): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->role !== UserRole::SuperAdmin
            || ($mvpOperator->isTestOperatorIdentity($user) && ! $mvpOperator->isOperator($user))) {
            abort(403);
        }

        return $next($request);
    }
}
