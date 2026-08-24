<?php

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('tender.telegram.webhook_secret', 'webhook-test-secret');
});

it('requires the telegram webhook secret and deduplicates updates', function () {
    Queue::fake();
    $payload = [
        'update_id' => 100500,
        'message' => ['chat' => ['id' => 445566], 'text' => '/start'],
    ];

    $this->postJson('/telegram/webhook', $payload)->assertForbidden();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-test-secret')
        ->postJson('/telegram/webhook', $payload)
        ->assertOk()
        ->assertJsonPath('duplicate', false);
    Queue::assertPushed(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job) => $job->updateId === 100500);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-test-secret')
        ->postJson('/telegram/webhook', $payload)
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect(TelegramUpdate::query()->count())->toBe(1);
});
