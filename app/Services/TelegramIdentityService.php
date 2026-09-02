<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Telegram\VerifiedTelegramUser;
use Illuminate\Support\Facades\DB;

class TelegramIdentityService
{
    public function __construct(private readonly LocalMvpFullAccessService $fullAccess) {}

    public function findOrCreate(VerifiedTelegramUser $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $role = $this->fullAccess->isEnabled() || $this->isSuperAdmin($identity->id)
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

    private function isSuperAdmin(string $telegramId): bool
    {
        foreach ($this->superAdminIds() as $configuredId) {
            if (hash_equals($configuredId, $telegramId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function superAdminIds(): array
    {
        $configured = [config('tender.telegram.owner_id')];
        $additionalIds = config('tender.telegram.superadmin_ids');

        if (is_string($additionalIds)) {
            $configured = [...$configured, ...preg_split('/[\s,;]+/', $additionalIds)];
        }

        return array_values(array_unique(array_filter(
            $configured,
            static fn (mixed $id): bool => is_string($id) && preg_match('/^\d+$/', $id) === 1,
        )));
    }
}
