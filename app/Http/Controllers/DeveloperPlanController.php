<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DeveloperPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

final class DeveloperPlanController extends Controller
{
    public function update(Request $request, DeveloperPlanService $developer): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($developer->allowed($actor), 403);
        $data = $request->validate(['plan_code' => ['present', 'nullable', Rule::in(DeveloperPlanService::CODES)]]);
        $change = DB::transaction(function () use ($actor, $developer, $data): ?array {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($developer->allowed($user), 403);
            $before = $user->developer_plan_code;
            $after = $data['plan_code'];
            if ($before === $after) {
                return null;
            }
            $user->forceFill(['developer_plan_code' => $after])->save();

            return ['user_id' => $user->id, 'from_plan_code' => $before, 'to_plan_code' => $after];
        });
        if ($change !== null) {
            Log::info('developer.plan_changed', $change);
        }

        return redirect()->route('profile', status: 303);
    }
}
