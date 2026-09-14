<?php

use App\Http\Controllers\ChecklistTemplateController;
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
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\ParticipationAnalyticsController;
use App\Http\Controllers\ParticipationApprovalController;
use App\Http\Controllers\ParticipationCommentController;
use App\Http\Controllers\ParticipationEconomicsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RemoteMvpOperatorSessionController;
use App\Http\Controllers\SavedSearchRunController;
use App\Http\Controllers\SavedSearchRunHistoryController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamTenderFeedController;
use App\Http\Controllers\TeamWorkflowController;
use App\Http\Controllers\TelegramSessionController;
use App\Http\Controllers\TenderCalendarController;
use App\Http\Controllers\TenderExportController;
use App\Http\Controllers\TenderFeedbackController;
use App\Http\Controllers\TenderFeedController;
use App\Http\Controllers\TenderFeedViewController;
use App\Http\Controllers\TenderPersonalStateController;
use App\Http\Controllers\TenderWorkController;
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

Route::get('/team-invitations/{token}', [TeamController::class, 'invitation']);

Route::middleware('auth')->group(function () {
    Route::get('/teams', [TeamController::class, 'index'])->name('teams');
    Route::post('/teams', [TeamController::class, 'store']);
    Route::patch('/teams/{team}/archive', [TeamController::class, 'archive']);
    Route::post('/teams/{team}/transfer-ownership', [TeamController::class, 'transferOwnership']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
    Route::post('/teams/{team}/monitorings', [TeamTenderFeedController::class, 'shareMonitoring']);
    Route::delete('/teams/{team}/monitorings/{query}', [TeamTenderFeedController::class, 'unshareMonitoring']);
    Route::patch('/teams/{team}/tenders/{tender}/review', [TeamTenderFeedController::class, 'update']);
    Route::patch('/teams/{team}/tenders/reviews', [TeamTenderFeedController::class, 'bulkUpdate']);
    Route::post('/teams/{team}/tenders/{tender}/comments', [TeamTenderFeedController::class, 'comment']);
    Route::post('/teams/{team}/tenders/{tender}/promote', [TeamTenderFeedController::class, 'promote']);
    Route::patch('/teams/{team}/workflow-settings', [TeamWorkflowController::class, 'update']);
    Route::post('/teams/{team}/routing-rules', [TeamWorkflowController::class, 'storeRule']);
    Route::delete('/teams/{team}/routing-rules/{rule}', [TeamWorkflowController::class, 'destroyRule']);
    Route::post('/teams/{team}/invitations', [TeamController::class, 'invite'])->middleware('throttle:30,1');
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamController::class, 'revoke']);
    Route::patch('/teams/{team}/members/{member}', [TeamController::class, 'member']);
    Route::delete('/teams/{team}/members/{member}', [TeamController::class, 'member']);
    Route::post('/team-invitations/{token}', [TeamController::class, 'accept'])->middleware('throttle:30,1');
    Route::post('/checklist-templates', [ChecklistTemplateController::class, 'store']);
    Route::patch('/checklist-templates/{template}', [ChecklistTemplateController::class, 'update']);
    Route::delete('/checklist-templates/{template}', [ChecklistTemplateController::class, 'destroy']);
    Route::post('/tenders/{tender}/templates/{template}', [ChecklistTemplateController::class, 'apply']);
    Route::get('/consents', fn () => Inertia::render('Consents'))->name('consents');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/tenders', [TenderFeedController::class, 'index'])->name('tenders');
    Route::get('/participation', [TenderWorkController::class, 'index'])->name('participation');
    Route::get('/participation/analytics', [ParticipationAnalyticsController::class, 'index'])->name('participation.analytics');
    Route::get('/participation/analytics/export', [ParticipationAnalyticsController::class, 'export'])->name('participation.analytics.export');
    Route::get('/calendar', [TenderCalendarController::class, 'index'])->name('calendar');
    Route::get('/calendar/export', [TenderCalendarController::class, 'index'])->name('calendar.export');
    Route::get('/tenders/{tender}/work', [TenderWorkController::class, 'show'])->name('tenders.work');
    Route::put('/tenders/{tender}/participation', [TenderWorkController::class, 'update'])->name('tenders.participation');
    Route::patch('/tenders/{tender}/economics', [ParticipationEconomicsController::class, 'update'])->name('tenders.economics');
    Route::post('/tenders/{tender}/approval-requests', [ParticipationApprovalController::class, 'store'])->name('tenders.approvals.store');
    Route::patch('/tenders/{tender}/approval-requests/{approval}/vote', [ParticipationApprovalController::class, 'vote'])->name('tenders.approvals.vote');
    Route::post('/tenders/{tender}/comments', [ParticipationCommentController::class, 'store'])->name('tenders.comments.store');
    Route::patch('/tenders/{tender}/comments/{comment}', [ParticipationCommentController::class, 'update'])->name('tenders.comments.update');
    Route::delete('/tenders/{tender}/comments/{comment}', [ParticipationCommentController::class, 'destroy'])->name('tenders.comments.destroy');
    Route::post('/tenders/{tender}/checklist', [TenderWorkController::class, 'storeItem'])->name('tenders.checklist.store');
    Route::patch('/tenders/{tender}/checklist/{item}', [TenderWorkController::class, 'updateItem'])->name('tenders.checklist.update');
    Route::delete('/tenders/{tender}/checklist/{item}', [TenderWorkController::class, 'destroyItem'])->name('tenders.checklist.destroy');
    Route::post('/tender-feed-views', [TenderFeedViewController::class, 'store'])
        ->name('tender-feed-views.store');
    Route::delete('/tender-feed-views/{view}', [TenderFeedViewController::class, 'destroy'])
        ->name('tender-feed-views.destroy');
    Route::patch('/tenders/{tender}/state', TenderPersonalStateController::class)
        ->name('tenders.state');
    Route::get('/profile', ProfileController::class)->name('profile');
    Route::put('/profile/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('profile.notification-preferences.update');
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
    Route::post('/queries/preview', [SearchQueryController::class, 'preview'])->middleware('throttle:local-mvp-rss-preview')->name('queries.preview');
    Route::post('/tenders/{tender}/feedback', [TenderFeedbackController::class, 'store'])->middleware('throttle:local-mvp-preview')->name('tenders.feedback');
    Route::get('/tenders/{tender}/changes', [TenderFeedbackController::class, 'changes'])->name('tenders.changes');
    Route::post('/queries', [SearchQueryController::class, 'store'])->name('queries.store');
    Route::patch('/queries/{query}', [SearchQueryController::class, 'update'])->name('queries.update');
    Route::post('/queries/{query}/run', SavedSearchRunController::class)
        ->middleware('throttle:local-mvp-rss-preview')
        ->name('queries.run');
    Route::get('/queries/{query}/runs', [SavedSearchRunHistoryController::class, 'index'])
        ->name('queries.runs.index');
    Route::get('/queries/{query}/runs/{run}', [SavedSearchRunHistoryController::class, 'show'])
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
