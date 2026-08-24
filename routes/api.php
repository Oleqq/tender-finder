<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'liveness'])->name('health');
Route::get('/ops/readiness', ReadinessController::class)->name('operations.readiness');
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:telegram-webhook')
    ->name('telegram.webhook');
