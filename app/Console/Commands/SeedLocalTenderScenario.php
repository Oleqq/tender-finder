<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedLocalTenderScenario extends Command
{
    protected $signature = 'tenders:seed-local-scenario';

    protected $description = 'Create an idempotent synthetic tender-monitoring scenario in a local or test database only.';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('The synthetic scenario is restricted to local and test environments.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'local-rss-demo@example.invalid'],
            [
                'name' => 'Локальный пример RSS',
                'password' => Hash::make(Str::random(48)),
                'role' => UserRole::Subscriber,
            ],
        );

        Entitlement::query()->updateOrCreate(
            ['user_id' => $user->id, 'code' => 'active_queries'],
            [
                'status' => SubscriptionStatus::Active,
                'value' => 3,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDays(30),
                'metadata' => ['local_fixture' => true],
            ],
        );

        SearchQuery::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Локальная проверка: поддержка сайтов'],
            [
                'keywords' => ['поддержка', 'сайт'],
                'minus_keywords' => ['строительство'],
                'region' => null,
                'status' => 'active',
                'monitoring_started_at' => now()->subMinute(),
            ],
        );

        $this->components->info('Synthetic local user and one active monitoring query are ready.');
        $this->line('This record has no Telegram ID, no consent, and no real access; it cannot receive Telegram messages.');

        return self::SUCCESS;
    }
}
