<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TemporaryFullAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ExpireBrowserGuestSession
{
    public function __construct(private readonly TemporaryFullAccessService $temporary) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->is_browser_guest && ! $this->temporary->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->isMethod('GET')) {
                return redirect()->route('welcome');
            }

            abort(401);
        }

        return $next($request);
    }
}
