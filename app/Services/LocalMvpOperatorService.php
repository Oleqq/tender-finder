<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LocalMvpOperatorService
{
    private const EMAIL = 'local-mvp-operator@tenderfinder.invalid';

    public function isEnabled(): bool
    {
        return $this->isLocalEnabled() || $this->isRemoteEnabled();
    }

    public function isLocalEnabled(): bool
    {
        return app()->environment('local', 'testing')
            && (bool) config('tender.local_mvp_operator.enabled');
    }

    public function isRemoteEnabled(): bool
    {
        return app()->environment('production')
            && (bool) config('tender.remote_mvp_operator.enabled');
    }

    public function provision(): User
    {
        return DB::transaction(function (): User {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => self::EMAIL],
                [
                    'name' => 'MVP test operator',
                    'role' => UserRole::SuperAdmin,
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

    public function isOperator(?User $user): bool
    {
        return $this->isEnabled()
            && $this->isTestOperatorIdentity($user)
            && $user->role === UserRole::SuperAdmin;
    }

    public function isTestOperatorIdentity(?User $user): bool
    {
        return $user !== null && $user->email === self::EMAIL;
    }

    public function activeQueryLimit(): int
    {
        return max(1, (int) config('tender.local_mvp_operator.active_query_limit'));
    }
}
