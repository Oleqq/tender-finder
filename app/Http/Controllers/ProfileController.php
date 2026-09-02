<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $preference = NotificationPreference::query()->firstOrNew(
            ['user_id' => $user->id],
            [
                'instant_enabled' => true,
                'digest_enabled' => true,
                'digest_time' => '09:00',
                'timezone' => 'Europe/Moscow',
            ],
        );

        return Inertia::render('Profile', [
            'notificationPreferences' => [
                'instant_enabled' => $preference->instant_enabled,
                'digest_enabled' => $preference->digest_enabled,
                'digest_time' => substr((string) $preference->digest_time, 0, 5),
                'timezone' => $preference->timezone,
            ],
        ]);
    }
}
