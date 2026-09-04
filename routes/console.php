<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tenders:dispatch-rss')->everySecond()->withoutOverlapping();
Schedule::command('trials:process-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:send-due-digests')->everyMinute()->withoutOverlapping();
