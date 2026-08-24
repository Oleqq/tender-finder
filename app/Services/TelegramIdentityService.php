<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Telegram\VerifiedTelegramUser;
use Illuminate\Support\Facades\DB;

class TelegramIdentityService
{
    public function findOrCreate(VerifiedTelegramUser $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $ownerId = config('tender.telegram.owner_id');
            $role = is_string($ownerId) && $ownerId !== '' && hash_equals($ownerId, $identity->id)
                ? UserRole::SuperAdmin
                : UserRole::Subscriber;

            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['telegram_id' => $identity->id],
                [
                    'name' => $identity->displayName(),
                    'telegram_username' => $identity->username,
                    'telegram_first_name' => $identity->firstName,
                    'telegram_last_name' => $identity->lastName,
                    'telegram_language_code' => $identity->languageCode,
                    'role' => $role,
                    'last_seen_at' => now(),
                ],
            );

            return $user;
        });
    }
}
