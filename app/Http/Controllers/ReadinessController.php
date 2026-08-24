<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = config('operations.readiness_token');
        $providedToken = $request->bearerToken();

        if (! is_string($configuredToken) || $configuredToken === '' || ! is_string($providedToken) || ! hash_equals($configuredToken, $providedToken)) {
            abort(404);
        }

        try {
            DB::select('select 1');
            Redis::connection()->ping();
        } catch (Throwable) {
            return response()->json(['status' => 'not_ready'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
