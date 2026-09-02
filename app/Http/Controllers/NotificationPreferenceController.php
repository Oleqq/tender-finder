<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $attributes = $request->validate([
            'instant_enabled' => ['required', 'boolean'],
            'digest_enabled' => ['required', 'boolean'],
            'digest_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', Rule::in(['Europe/Moscow', 'Asia/Yekaterinburg', 'Asia/Novosibirsk', 'Asia/Vladivostok'])],
        ]);

        $preference = NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            $attributes,
        );

        return response()->json(['preferences' => [
            'instant_enabled' => $preference->instant_enabled,
            'digest_enabled' => $preference->digest_enabled,
            'digest_time' => substr((string) $preference->digest_time, 0, 5),
            'timezone' => $preference->timezone,
        ]]);
    }
}
