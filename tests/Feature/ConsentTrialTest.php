<?php

use App\Models\ConsentEvent;
use App\Models\Entitlement;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.legal.offer_url', 'https://example.test/offer');
    config()->set('tender.legal.offer_version', '2026-08-25');
    config()->set('tender.legal.privacy_url', 'https://example.test/privacy');
    config()->set('tender.legal.privacy_version', '2026-08-25');
    config()->set('tender.access.trial_hours', 72);
    config()->set('tender.access.basic_active_query_limit', 3);
});

it('records current consents and starts a single 72 hour trial in one action', function () {
    $user = User::factory()->create(['telegram_id' => '7001']);

    $this->actingAs($user)
        ->postJson('/consents', ['documents' => ['offer', 'privacy']])
        ->assertCreated()
        ->assertJsonPath('status', 'trial_started')
        ->assertJsonPath('access.state', 'trialing')
        ->assertJsonPath('access.active_query_limit', 3);
    $this->actingAs($user)
        ->postJson('/consents', ['documents' => ['privacy', 'offer']])
        ->assertOk();

    expect(ConsentEvent::query()->where('user_id', $user->id)->count())->toBe(2);

    $entitlement = Entitlement::query()->where('user_id', $user->id)->firstOrFail();
    expect($entitlement->starts_at?->diffInHours($entitlement->ends_at))->toBe(72.0);

    $this->actingAs($user)->postJson('/trial/start')->assertUnprocessable();
    expect(Entitlement::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not start a trial before both current consents exist', function () {
    $user = User::factory()->create(['telegram_id' => '7002']);

    $this->actingAs($user)->postJson('/trial/start')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Сначала примите актуальные юридические документы.');
});

it('does not record consents while legal documents are not explicitly published', function () {
    config()->set('tender.legal.documents_published', false);
    $user = User::factory()->create(['telegram_id' => '7003']);

    $this->actingAs($user)
        ->postJson('/consents', ['documents' => ['offer', 'privacy']])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Юридические документы пока не опубликованы.');

    expect(ConsentEvent::query()->where('user_id', $user->id)->count())->toBe(0);
});
