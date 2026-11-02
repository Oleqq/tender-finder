<?php

namespace App\Services;

use App\Models\SubscriptionRenewal;
use App\Models\User;

final class TelegramCommands
{
    /** @return list<array{command: string, description: string}> */
    public static function menu(): array
    {
        return array_map(fn (string $command, string $description): array => compact('command', 'description'),
            ['start', 'terms', 'privacy', 'consent', 'offer', 'info', 'paysupport', 'support', 'cancel', 'help'],
            ['Открыть TenderFinder', 'Пользовательское соглашение', 'Политика обработки персональных данных', 'Согласие на обработку персональных данных', 'Публичная оферта', 'О сервисе, тарифы и реквизиты', 'Поддержка по оплате и возвратам', 'Связаться с поддержкой', 'Отменить автопродление', 'Список команд']);
    }

    public static function accepts(string $command): bool
    {
        return $command === '/subscribe' || in_array(ltrim($command, '/'), array_column(self::menu(), 'command'), true);
    }

    /** @return list<string> */
    public function messages(string $command, ?string $telegramId = null): array
    {
        $type = ltrim($command, '/');
        $base = rtrim((string) config('app.url'), '/');
        $email = (string) config('company.support_email');
        if (in_array($type, LegalDocumentRegistry::TYPES, true)) {
            if (! config('tender.legal.documents_published', false)) {
                return app(TelegramDocumentFormatter::class)->plain('Документы временно недоступны. Поддержка: '.$email, 'Документы');
            }
            $documents = app(LegalDocumentRegistry::class);

            return app(TelegramDocumentFormatter::class)->document($documents->document($type));
        }

        $text = match ($command) {
            '/cancel' => $this->cancellationText($telegramId),
            '/start' => "Добро пожаловать в TenderFinder! Откройте Mini App кнопкой меню.\n\nДокументы: /terms /offer /privacy /consent\nО сервисе: /info\nПоддержка: /support /paysupport",
            '/support' => "Поддержка TenderFinder: {$email}\nВ приложении: Профиль → Мои обращения.\nОпишите проблему и время её возникновения. Не отправляйте пароли и данные карты.",
            '/paysupport' => "Вопросы оплаты и возвратов: {$email}\nВ приложении: Профиль → Мои обращения. Укажите тариф, дату и номер заказа, если он есть. Не отправляйте данные карты.\nУсловия: {$base}/offer",
            '/info' => "TenderFinder — мониторинг закупок и подготовка заявок.\nBasic: 3 мониторинга, Pro: 10, Ultra: 30.\nУслуга предоставляется в электронной форме через Telegram-аккаунт.\n".config('company.short_name')."\nИНН: ".config('company.inn')."\nОГРНИП: ".config('company.ogrnip')."\nКонтакты: {$email}\nО сервисе: {$base}/info\nТарифы: {$base}/pricing\nРеквизиты: {$base}/requisites\nДокументы: /terms /offer /privacy /consent",
            '/subscribe' => 'Оплата подписки в Telegram отключена. По вопросам доступа используйте /support.',
            default => implode("\n", array_map(fn (array $item): string => '/'.$item['command'].' — '.$item['description'], self::menu())),
        };
        $title = match ($command) {
            '/start' => 'Добро пожаловать в TenderFinder',
            '/info' => 'О сервисе TenderFinder',
            '/support' => 'Поддержка',
            '/paysupport' => 'Оплата и возвраты',
            '/cancel' => 'Отмена автопродления',
            '/subscribe' => 'Доступ к сервису',
            default => 'Команды TenderFinder',
        };

        return app(TelegramDocumentFormatter::class)->plain($text, $title);
    }

    private function cancellationText(?string $telegramId): string
    {
        if ($telegramId === null) {
            return 'Для отмены откройте личный чат с ботом и отправьте /cancel.';
        }
        $user = User::query()->where('telegram_id', $telegramId)->first();
        $items = $user === null ? collect() : SubscriptionRenewal::query()
            ->where('user_id', $user->id)->where('state', 'active')->limit(10)->get();
        if ($items->isEmpty()) {
            return 'Автопродление не включено. Будущие автоматические списания не запланированы. Оплаченный доступ сохраняется до конца срока. По вопросам платежей: /paysupport.';
        }
        $text = 'Откройте ссылку нужного тарифа и подтвердите отмену. Оплаченный доступ сохранится до конца срока.';
        foreach ($items as $item) {
            $text .= "\n\n".strtoupper($item->plan_code).': '.app(SubscriptionRenewalService::class)->cancelUrl($item);
        }

        return $text;
    }
}
