<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LocalMvpSubscriberService
{
    private const EMAIL = 'local-mvp-subscriber@tenderfinder.invalid';

    public function isEnabled(): bool
    {
        return app()->environment('local', 'testing')
            && (bool) config('tender.local_mvp_subscriber.enabled');
    }

    public function provision(): User
    {
        return DB::transaction(function (): User {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => self::EMAIL],
                [
                    'name' => 'Local MVP subscriber',
                    'role' => UserRole::Subscriber,
                    'telegram_id' => null,
                    'telegram_username' => null,
                    'telegram_first_name' => null,
                    'telegram_last_name' => null,
                    'telegram_language_code' => null,
                    'last_seen_at' => now(),
                ],
            );

            return $user;
        });
    }
}
