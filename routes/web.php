<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\TelegramSessionController;
use App\Http\Controllers\TenderFeedController;
use App\Http\Controllers\TrialController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('welcome');

Route::get('/onboarding', fn () => Inertia::render('Onboarding'))->name('onboarding');
Route::get('/consents', fn () => Inertia::render('Consents'))->name('consents');
Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
Route::get('/tenders', [TenderFeedController::class, 'index'])->name('tenders');
Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile');
Route::get('/plans', fn () => Inertia::render('Plans'))->name('plans');
Route::get('/operations-demo', fn () => Inertia::render('OperationsDemo'))->name('operations.demo');
Route::get('/offer', [LegalDocumentController::class, 'offer'])->name('legal.offer');
Route::get('/privacy', [LegalDocumentController::class, 'privacy'])->name('legal.privacy');

Route::post('/telegram/session', [TelegramSessionController::class, 'store'])
    ->middleware('throttle:telegram-session')
    ->name('telegram.session.store');

Route::middleware('auth')->group(function () {
    Route::get('/queries', [SearchQueryController::class, 'index'])->name('queries.index');
    Route::post('/queries', [SearchQueryController::class, 'store'])->name('queries.store');
    Route::patch('/queries/{query}', [SearchQueryController::class, 'update'])->name('queries.update');
    Route::post('/queries/{query}/pause', [SearchQueryController::class, 'pause'])->name('queries.pause');
    Route::post('/queries/{query}/resume', [SearchQueryController::class, 'resume'])->name('queries.resume');
    Route::post('/queries/{query}/freeze', [SearchQueryController::class, 'freeze'])->name('queries.freeze');
    Route::delete('/queries/{query}', [SearchQueryController::class, 'destroy'])->name('queries.destroy');
    Route::post('/consents', [ConsentController::class, 'store'])->name('consents.store');
    Route::post('/consents/revoke', [ConsentController::class, 'revoke'])->name('consents.revoke');
    Route::post('/trial/start', [TrialController::class, 'store'])->name('trial.start');
});
