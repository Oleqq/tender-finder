<?php

namespace App\Services;

use App\Enums\ConsentAction;
use App\Enums\ConsentDocument;
use App\Enums\UserRole;
use App\Models\ConsentEvent;
use App\Models\User;

final class TelegramConsentService
{
    public function callbackData(string $telegramId): string
    {
        $version = app(ConsentService::class)->currentVersion(ConsentDocument::Consent);
        $hash = substr(hash('sha256', $version), 0, 20);
        $signature = substr(hash_hmac('sha256', $telegramId.':'.$version, (string) config('app.key')), 0, 16);

        return 'pdn:'.$hash.':'.$signature;
    }

    public function needsPrompt(?string $telegramId, string $chatId): bool
    {
        if (! config('tender.legal.documents_published') || $telegramId === null || $telegramId !== $chatId) {
            return false;
        }
        $user = User::query()->where('telegram_id', $telegramId)->first();
        $event = $user === null ? null : ConsentEvent::query()->where('user_id', $user->id)
            ->latestForDocument(ConsentDocument::Consent)->first();

        return $event?->action !== ConsentAction::Accepted
            || $event->document_version !== app(ConsentService::class)->currentVersion(ConsentDocument::Consent);
    }

    /** @return array<string, mixed> */
    public function keyboard(string $telegramId): array
    {
        return ['inline_keyboard' => [
            [['text' => 'Прочитать согласие на ПДн', 'url' => rtrim((string) config('app.url'), '/').'/consent']],
            [['text' => 'Я согласен на обработку ПДн', 'callback_data' => $this->callbackData($telegramId)]],
        ]];
    }

    /** @param array<string, mixed> $callback */
    public function accept(array $callback, int $updateId): string
    {
        $actor = (string) data_get($callback, 'from.id', '');
        $chat = (string) data_get($callback, 'message.chat.id', '');
        if (preg_match('/\A[1-9]\d*\z/', $actor) !== 1 || $actor !== $chat
            || data_get($callback, 'message.chat.type') !== 'private'
            || data_get($callback, 'from.is_bot', false)) {
            return 'Подтвердите согласие в личном чате с ботом через /start.';
        }
        if (! config('tender.legal.documents_published')) {
            return 'Документ временно недоступен. Попробуйте /start позже.';
        }
        if (! hash_equals($this->callbackData($actor), (string) ($callback['data'] ?? ''))) {
            return 'Эта кнопка устарела или недоступна. Откройте /start и прочитайте актуальное согласие.';
        }

        $user = User::query()->firstOrCreate(['telegram_id' => $actor], [
            'name' => 'Пользователь Telegram', 'role' => UserRole::Subscriber,
        ]);
        app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Consent], null, $updateId);

        return 'Согласие на обработку ПДн сохранено. Для продолжения откройте приложение.';
    }
}
