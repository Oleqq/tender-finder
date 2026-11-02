<?php

namespace App\Services;

final class TelegramCommands
{
    /** @return list<array{command: string, description: string}> */
    public static function menu(): array
    {
        return array_map(fn (string $command, string $description): array => compact('command', 'description'),
            ['start', 'terms', 'privacy', 'consent', 'offer', 'info', 'paysupport', 'support', 'help'],
            ['Открыть TenderFinder', 'Пользовательское соглашение', 'Политика обработки персональных данных', 'Согласие на обработку персональных данных', 'Публичная оферта', 'О сервисе, тарифы и реквизиты', 'Поддержка по оплате и возвратам', 'Связаться с поддержкой', 'Список команд']);
    }

    public static function accepts(string $command): bool
    {
        return $command === '/subscribe' || in_array(ltrim($command, '/'), array_column(self::menu(), 'command'), true);
    }

    /** @return list<string> */
    public function messages(string $command): array
    {
        $type = ltrim($command, '/');
        $base = rtrim((string) config('app.url'), '/');
        $email = (string) config('company.support_email');
        if (in_array($type, LegalDocumentRegistry::TYPES, true)) {
            if (! config('tender.legal.documents_published', false)) {
                return ['Документы временно недоступны. Поддержка: '.$email];
            }
            $documents = app(LegalDocumentRegistry::class);
            $text = $documents->text($type)."\n\nНа сайте: {$base}/{$type}\nОригинал Word: {$base}/legal/{$type}/download";

            // Stay below Telegram's 4096-character limit even for non-BMP text.
            return mb_str_split($text, 1800);
        }

        return [match ($command) {
            '/start' => "Добро пожаловать в TenderFinder! Откройте Mini App кнопкой меню.\n\nДокументы: /terms /offer /privacy /consent\nО сервисе: /info\nПоддержка: /support /paysupport",
            '/support' => "Поддержка TenderFinder: {$email}\nВ приложении: Профиль → Мои обращения.\nОпишите проблему и время её возникновения. Не отправляйте пароли и данные карты.",
            '/paysupport' => "Вопросы оплаты и возвратов: {$email}\nВ приложении: Профиль → Мои обращения. Укажите тариф, дату и номер заказа, если он есть. Не отправляйте данные карты.\nУсловия: {$base}/offer",
            '/info' => "TenderFinder — мониторинг закупок и подготовка заявок.\nBasic: 3 мониторинга, Pro: 10, Ultra: 30.\nУслуга предоставляется в электронной форме через Telegram-аккаунт.\n".config('company.short_name')."\nИНН: ".config('company.inn')."\nОГРНИП: ".config('company.ogrnip')."\nКонтакты: {$email}\nО сервисе: {$base}/info\nТарифы: {$base}/pricing\nРеквизиты: {$base}/requisites\nДокументы: /terms /offer /privacy /consent",
            '/subscribe' => 'Оплата подписки в Telegram отключена. По вопросам доступа используйте /support.',
            default => implode("\n", array_map(fn (array $item): string => '/'.$item['command'].' — '.$item['description'], self::menu())),
        }];
    }
}
