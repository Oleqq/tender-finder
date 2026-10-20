<?php

use App\Jobs\ProcessTelegramPaymentUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tenders:dispatch-rostender')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-sber-ast')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-workspace-ru')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-etp-gpb')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-bidzaar')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-etp-region')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-b2b-center')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:dispatch-platform-catalog')->everyMinute()->withoutOverlapping();
Schedule::command('trials:process-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('billing:reconcile-stars --max=1000')
    ->dailyAt('03:30')
    ->timezone('Europe/Moscow')
    ->when(fn (): bool => filled(config('tender.telegram.bot_token')))
    ->withoutOverlapping();
Schedule::command('billing:check-stars-incidents')
    ->everyFiveMinutes()
    ->when(fn (): bool => filled(config('tender.telegram.bot_token')))
    ->withoutOverlapping();
Schedule::call(function (): void {
    TelegramUpdate::query()
        ->whereIn('type', ['successful_payment', 'refunded_payment', 'subscription'])
        ->whereIn('status', ['received', 'failed'])
        ->where('payment_attempts', '<', 10)
        ->where('updated_at', '<', now()->subMinute())
        ->orderBy('id')
        ->limit(50)
        ->pluck('telegram_update_id')
        ->each(fn (int $id) => ProcessTelegramPaymentUpdate::dispatch($id));
})->name('retry-telegram-payment-updates')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:send-due-digests')->everyMinute()->withoutOverlapping();

Schedule::command('notifications:send-tender-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('tenders:refresh-watched')->hourly()->withoutOverlapping();

Schedule::command('notifications:send-task-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('teams:process-tender-reviews')
    ->everyMinute()
    ->withoutOverlapping();
