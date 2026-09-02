<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EisCatalogController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\LocalMvpEisRssPreviewController;
use App\Http\Controllers\LocalMvpOperatorSessionController;
use App\Http\Controllers\LocalMvpSubscriberSessionController;
use App\Http\Controllers\LocalMvpTenderAnnotationController;
use App\Http\Controllers\LocalMvpTenderDetailController;
use App\Http\Controllers\LocalMvpTenderEnrichmentController;
use App\Http\Controllers\LocalMvpTenderGuruPreviewController;
use App\Http\Controllers\LocalMvpTenderStateController;
use App\Http\Controllers\MvpWorkspaceController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\RemoteMvpOperatorSessionController;
use App\Http\Controllers\SavedSearchRunController;
use App\Http\Controllers\SavedSearchRunHistoryController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\TelegramSessionController;
use App\Http\Controllers\TenderExportController;
use App\Http\Controllers\TenderFeedController;
use App\Http\Controllers\TrialController;
use App\Services\LocalMvpSubscriberService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('welcome');

Route::get('/local/mvp-operator', [LocalMvpOperatorSessionController::class, 'store'])
    ->name('local.mvp-operator.session');
Route::get('/mvp/operator/access', [RemoteMvpOperatorSessionController::class, 'store'])
    ->middleware(['signed', 'throttle:remote-mvp-operator'])
    ->name('mvp.remote-operator.session');
Route::get('/local/mvp-subscriber', [LocalMvpSubscriberSessionController::class, 'store'])
    ->name('local.mvp-subscriber.session');

Route::get('/onboarding', fn (LocalMvpSubscriberService $subscriber) => Inertia::render('Onboarding', [
    'localSubscriberEntryEnabled' => $subscriber->isEnabled(),
]))->name('onboarding');
Route::get('/offer', [LegalDocumentController::class, 'offer'])->name('legal.offer');
Route::get('/privacy', [LegalDocumentController::class, 'privacy'])->name('legal.privacy');

Route::post('/telegram/session', [TelegramSessionController::class, 'store'])
    ->middleware('throttle:telegram-session')
    ->name('telegram.session.store');

Route::middleware('auth')->group(function () {
    Route::get('/consents', fn () => Inertia::render('Consents'))->name('consents');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/tenders', [TenderFeedController::class, 'index'])->name('tenders');
    Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile');
    Route::get('/plans', fn () => Inertia::render('Plans'))->name('plans');
    Route::get('/mvp/workspace', [MvpWorkspaceController::class, 'show'])
        ->middleware('super_admin')
        ->name('mvp.workspace');
    Route::get('/operations', [OperationsDashboardController::class, 'show'])
        ->middleware('super_admin')
        ->name('operations.dashboard');
    Route::redirect('/operations-demo', '/operations')
        ->middleware('super_admin')
        ->name('operations.demo');
    Route::get('/queries', [SearchQueryController::class, 'index'])->name('queries.index');
    Route::post('/queries', [SearchQueryController::class, 'store'])->name('queries.store');
    Route::patch('/queries/{query}', [SearchQueryController::class, 'update'])->name('queries.update');
    Route::post('/queries/{query}/run', SavedSearchRunController::class)
        ->middleware(['super_admin', 'throttle:local-mvp-rss-preview'])
        ->name('queries.run');
    Route::get('/queries/{query}/runs', [SavedSearchRunHistoryController::class, 'index'])
        ->middleware('super_admin')
        ->name('queries.runs.index');
    Route::get('/queries/{query}/runs/{run}', [SavedSearchRunHistoryController::class, 'show'])
        ->middleware('super_admin')
        ->name('queries.runs.show');
    Route::post('/queries/{query}/pause', [SearchQueryController::class, 'pause'])->name('queries.pause');
    Route::post('/queries/{query}/resume', [SearchQueryController::class, 'resume'])->name('queries.resume');
    Route::post('/queries/{query}/freeze', [SearchQueryController::class, 'freeze'])->name('queries.freeze');
    Route::delete('/queries/{query}', [SearchQueryController::class, 'destroy'])->name('queries.destroy');
    Route::post('/consents', [ConsentController::class, 'store'])->name('consents.store');
    Route::post('/consents/revoke', [ConsentController::class, 'revoke'])->name('consents.revoke');
    Route::post('/trial/start', [TrialController::class, 'store'])->name('trial.start');
    Route::post('/local/mvp/tenderguru-preview', [LocalMvpTenderGuruPreviewController::class, 'store'])
        ->middleware('throttle:local-mvp-preview')
        ->name('local.mvp.tenderguru-preview');
    Route::post('/local/mvp/eis-rss-preview', [LocalMvpEisRssPreviewController::class, 'store'])
        ->middleware('throttle:local-mvp-rss-preview')
        ->name('local.mvp.eis-rss-preview');
    Route::get('/local/mvp/eis/okpd2-options', [EisCatalogController::class, 'okpd2'])
        ->middleware('throttle:local-mvp-rss-preview')
        ->name('local.mvp.eis.okpd2-options');
    Route::post('/local/mvp/tenders/export', TenderExportController::class)
        ->middleware('throttle:local-mvp-preview')
        ->name('local.mvp.tenders.export');
    Route::patch('/local/mvp/tenders/{tender}/annotation', LocalMvpTenderAnnotationController::class)
        ->name('local.mvp.tenders.annotation');
    Route::post('/local/mvp/tenders/{tender}/enrich', LocalMvpTenderEnrichmentController::class)
        ->middleware('throttle:local-mvp-rss-preview')
        ->name('local.mvp.tenders.enrich');
    Route::post('/local/mvp/tenders/{tender}/status', [LocalMvpTenderStateController::class, 'update'])
        ->name('local.mvp.tenders.status');
    Route::post('/local/mvp/tenders/status', [LocalMvpTenderStateController::class, 'bulkUpdate'])
        ->name('local.mvp.tenders.bulk-status');
    Route::get('/local/mvp/tenders/{tender}', [LocalMvpTenderDetailController::class, 'show'])
        ->name('local.mvp.tenders.show');
});
