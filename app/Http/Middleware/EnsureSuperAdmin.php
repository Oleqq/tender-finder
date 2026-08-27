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
    public function __construct(private readonly LocalMvpOperatorService $mvpOperator) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->role !== UserRole::SuperAdmin
            || ($this->mvpOperator->isTestOperatorIdentity($user) && ! $this->mvpOperator->isOperator($user))) {
            abort(403);
        }

        return $next($request);
    }
}
