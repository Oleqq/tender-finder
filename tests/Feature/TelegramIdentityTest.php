<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;

function signedTelegramInitData(array $user, ?int $authDate = null): string
{
    $data = [
        'auth_date' => (string) ($authDate ?? now()->timestamp),
        'query_id' => 'AAEAAAE',
        'user' => json_encode($user, JSON_THROW_ON_ERROR),
    ];
    ksort($data, SORT_STRING);
    $checkString = collect($data)->map(fn (string $value, string $key) => $key.'='.$value)->implode("\n");
    $secret = hash_hmac('sha256', 'WebAppData', (string) config('tender.telegram.bot_token'), true);
    $data['hash'] = hash_hmac('sha256', $checkString, $secret);

    return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
}

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.telegram.init_data_max_age_seconds', 300);
});

it('creates a subscriber only after verified init data and regenerates a session', function () {
    $initData = signedTelegramInitData(['id' => 123456, 'first_name' => 'Ирина', 'username' => 'irina']);

    $this->postJson('/telegram/session', ['init_data' => $initData])
        ->assertOk()
        ->assertJsonPath('session_refreshed', true)
        ->assertJsonPath('user.role', 'subscriber')
        ->assertJsonPath('access.state', 'preview');

    $user = User::query()->where('telegram_id', '123456')->firstOrFail();
    expect($user->role)->toBe(UserRole::Subscriber)
        ->and($user->telegram_first_name)->toBe('Ирина')
        ->and($user->last_seen_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);

    $this->postJson('/telegram/session', ['init_data' => $initData])
        ->assertOk()
        ->assertJsonPath('session_refreshed', false);
});

it('assigns super admin only to a configured verified owner id', function () {
    config()->set('tender.telegram.owner_id', '991122');

    $this->postJson('/telegram/session', [
        'init_data' => signedTelegramInitData(['id' => 991122, 'first_name' => 'Owner']),
    ])->assertOk()->assertJsonPath('user.role', 'super_admin');

    expect(User::query()->where('telegram_id', '991122')->firstOrFail()->role)->toBe(UserRole::SuperAdmin);
});

it('assigns and revokes super admin based on the configured verified Telegram ID list', function () {
    config()->set('tender.telegram.superadmin_ids', '112233, 445566;778899');

    $this->postJson('/telegram/session', [
        'init_data' => signedTelegramInitData(['id' => 445566, 'first_name' => 'Admin']),
    ])->assertOk()->assertJsonPath('user.role', 'super_admin');

    config()->set('tender.telegram.superadmin_ids', '112233,778899');

    $this->postJson('/telegram/session', [
        'init_data' => signedTelegramInitData(['id' => 445566, 'first_name' => 'Admin']),
    ])->assertOk()->assertJsonPath('user.role', 'subscriber');

    expect(User::query()->where('telegram_id', '445566')->firstOrFail()->role)->toBe(UserRole::Subscriber);
});

it('rejects forged and expired telegram init data', function () {
    $forged = signedTelegramInitData(['id' => 1, 'first_name' => 'Test']);
    $forged = str_replace('query_id=AAEAAAE', 'query_id=tampered', $forged);

    $this->postJson('/telegram/session', ['init_data' => $forged])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('init_data');

    $expired = signedTelegramInitData(['id' => 2, 'first_name' => 'Old'], Carbon::now()->subSeconds(301)->timestamp);

    $this->postJson('/telegram/session', ['init_data' => $expired])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('init_data');
});
