<?php

use App\Enums\AccessState;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrowserGuestSessionController;
use App\Http\Controllers\CalendarSubscriptionController;
use App\Http\Controllers\ChecklistTemplateController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\CostGapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoCheckoutController;
use App\Http\Controllers\DeveloperPlanController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\LocalMvpOperatorSessionController;
use App\Http\Controllers\LocalMvpSubscriberSessionController;
use App\Http\Controllers\LocalMvpTenderAnnotationController;
use App\Http\Controllers\LocalMvpTenderDetailController;
use App\Http\Controllers\LocalMvpTenderGuruPreviewController;
use App\Http\Controllers\LocalMvpTenderStateController;
use App\Http\Controllers\MiniAppOpenController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\ParticipationAnalyticsController;
use App\Http\Controllers\ParticipationApprovalController;
use App\Http\Controllers\ParticipationCommentController;
use App\Http\Controllers\ParticipationDocumentController;
use App\Http\Controllers\ParticipationEconomicsController;
use App\Http\Controllers\ParticipationExecutionController;
use App\Http\Controllers\ParticipationLifecycleController;
use App\Http\Controllers\PriceCatalogController;
use App\Http\Controllers\PricingPageController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\RemoteMvpOperatorSessionController;
use App\Http\Controllers\RepeatTenderController;
use App\Http\Controllers\SavedSearchRunController;
use App\Http\Controllers\SavedSearchRunHistoryController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamTenderFeedController;
use App\Http\Controllers\TeamWorkflowController;
use App\Http\Controllers\TelegramSessionController;
use App\Http\Controllers\TenderCalendarController;
use App\Http\Controllers\TenderDetailController;
use App\Http\Controllers\TenderExportController;
use App\Http\Controllers\TenderFeedbackController;
use App\Http\Controllers\TenderFeedController;
use App\Http\Controllers\TenderFeedViewController;
use App\Http\Controllers\TenderPersonalStateController;
use App\Http\Controllers\TenderQuestionController;
use App\Http\Controllers\TenderWorkController;
use App\Http\Controllers\TrialController;
use App\Http\Controllers\WinnerOpportunityController;
use App\Http\Middleware\EnforceWorkspaceAccess;
use App\Services\AccessService;
use App\Services\LocalMvpSubscriberService;
use App\Services\TemporaryFullAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, AccessService $access, TemporaryFullAccessService $temporary) {
    $user = $request->user();

    if ($user === null) {
        return Inertia::render('Welcome', ['guestEntryEnabled' => $temporary->isActive()]);
    }

    return match ($access->snapshotFor($user)->state) {
        AccessState::Preview => redirect()->route('onboarding'),
        AccessState::Active, AccessState::Trialing => redirect()->route('tenders'),
        default => redirect()->route('dashboard'),
    };
})->name('welcome');

Route::get('/open', MiniAppOpenController::class)->name('mini-app.open');

Route::get('/pricing/{theme?}', PricingPageController::class)
    ->where('theme', 'light|dark')->name('pricing');

Route::get('/demo/reviewer', [DemoCheckoutController::class, 'reviewer'])
    ->middleware(['signed', 'throttle:10,1'])->name('demo.reviewer');
Route::get('/demo', [DemoCheckoutController::class, 'index'])->name('demo.index');
Route::post('/demo/session', [DemoCheckoutController::class, 'session'])->middleware('throttle:10,1');
Route::middleware(['auth', 'throttle:30,1'])->prefix('demo')->group(function () {
    Route::post('/orders', [DemoCheckoutController::class, 'create']);
    Route::get('/orders/{order}', [DemoCheckoutController::class, 'show'])->whereUuid('order');
    Route::post('/orders/{order}/confirm', [DemoCheckoutController::class, 'confirm'])->whereUuid('order');
    Route::post('/expire', [DemoCheckoutController::class, 'expire']);
});

Route::post('/guest/session', [BrowserGuestSessionController::class, 'store'])
    ->middleware('throttle:10,1')->name('guest.session.store');

Route::get('/local/mvp-operator', [LocalMvpOperatorSessionController::class, 'store'])
    ->name('local.mvp-operator.session');
Route::get('/mvp/operator/access', [RemoteMvpOperatorSessionController::class, 'store'])
    ->middleware(['signed', 'throttle:remote-mvp-operator'])
    ->name('mvp.remote-operator.session');
Route::get('/local/mvp-subscriber', [LocalMvpSubscriberSessionController::class, 'store'])
    ->name('local.mvp-subscriber.session');

Route::get('/onboarding', fn (LocalMvpSubscriberService $subscriber, TemporaryFullAccessService $temporary) => Inertia::render('Onboarding', [
    'localSubscriberEntryEnabled' => $subscriber->isEnabled(),
    'temporaryFullAccessEnabled' => ! request()->user()?->is_demo_guest && $temporary->isActive(),
]))->name('onboarding');
Route::get('/offer', [LegalDocumentController::class, 'offer'])->name('legal.offer');
Route::get('/privacy', [LegalDocumentController::class, 'privacy'])->name('legal.privacy');
Route::get('/requisites', fn () => Inertia::render('Requisites', [
    'company' => config('company'),
]))->name('legal.requisites');

Route::post('/telegram/session', [TelegramSessionController::class, 'store'])
    ->middleware('throttle:telegram-session')
    ->name('telegram.session.store');

Route::get('/team-invitations/{token}', [TeamController::class, 'invitation']);
Route::get('/calendar/subscriptions/{token}.ics', [CalendarSubscriptionController::class, 'feed'])
    ->name('calendar.subscription.feed');

Route::middleware(['auth', EnforceWorkspaceAccess::class])->group(function () {
    Route::get('/repeat-tenders', [RepeatTenderController::class, 'index'])->name('repeat-tenders');
    Route::get('/repeat-tenders/lookup', [RepeatTenderController::class, 'lookup'])->middleware('throttle:30,1,repeat-lookup');
    Route::post('/repeat-tenders', [RepeatTenderController::class, 'create'])->middleware('throttle:20,1,repeat-create');
    Route::get('/repeat-tenders/{watch}', [RepeatTenderController::class, 'show'])->whereNumber('watch');
    Route::patch('/repeat-tenders/{watch}', [RepeatTenderController::class, 'update'])->whereNumber('watch')->middleware('throttle:30,1,repeat-update');
    Route::patch('/repeat-tenders/{watch}/archive', [RepeatTenderController::class, 'archive'])->whereNumber('watch');
    Route::post('/repeat-tenders/{watch}/scan', [RepeatTenderController::class, 'scan'])->whereNumber('watch')->middleware('throttle:5,1,repeat-scan');
    Route::post('/repeat-tenders/{watch}/candidates', [RepeatTenderController::class, 'addCandidate'])->whereNumber('watch')->middleware('throttle:20,1,repeat-add');
    Route::post('/repeat-tenders/{watch}/candidates/{candidate}/{decision}', [RepeatTenderController::class, 'decision'])->whereNumber('watch')->whereNumber('candidate')->whereIn('decision', ['confirm', 'dismiss', 'read'])->middleware('throttle:30,1,repeat-decision');
    Route::get('/winner-opportunities', [WinnerOpportunityController::class, 'index'])->name('winner-opportunities');
    Route::post('/winner-opportunities', [WinnerOpportunityController::class, 'saveEvidence'])->middleware('throttle:20,1,winner-create');
    Route::get('/winner-opportunities/{opportunity}', [WinnerOpportunityController::class, 'show'])->whereNumber('opportunity');
    Route::patch('/winner-opportunities/{opportunity}', [WinnerOpportunityController::class, 'saveEvidence'])->whereNumber('opportunity')->middleware('throttle:30,1,winner-evidence');
    Route::post('/winner-opportunities/{opportunity}/compose', [WinnerOpportunityController::class, 'compose'])->whereNumber('opportunity')->middleware('throttle:20,1,winner-compose');
    Route::patch('/winner-opportunities/{opportunity}/draft', [WinnerOpportunityController::class, 'saveDraft'])->whereNumber('opportunity')->middleware('throttle:30,1,winner-draft');
    Route::post('/winner-opportunities/{opportunity}/outcome', [WinnerOpportunityController::class, 'outcome'])->whereNumber('opportunity')->middleware('throttle:20,1,winner-outcome');
    Route::patch('/winner-opportunities/{opportunity}/archive', [WinnerOpportunityController::class, 'archive'])->whereNumber('opportunity');
    Route::get('/winner-opportunities/{opportunity}/export', [WinnerOpportunityController::class, 'export'])->whereNumber('opportunity')->name('winner-opportunities.export');
    Route::get('/procurement', [ProcurementController::class, 'index'])->name('procurement');
    Route::post('/procurement/needs', [ProcurementController::class, 'saveNeed'])->middleware('throttle:30,1,procurement');
    Route::patch('/procurement/needs/{need}', [ProcurementController::class, 'saveNeed'])->whereNumber('need')->middleware('throttle:30,1,procurement');
    Route::delete('/procurement/needs/{need}', [ProcurementController::class, 'deleteNeed'])->whereNumber('need');
    Route::post('/procurement/comparisons', [ProcurementController::class, 'createComparison'])->middleware('throttle:20,1,procurement-compare');
    Route::get('/procurement/comparisons/{comparison}', [ProcurementController::class, 'show'])->whereNumber('comparison');
    Route::patch('/procurement/comparisons/{comparison}', [ProcurementController::class, 'saveComparison'])->whereNumber('comparison')->middleware('throttle:30,1,procurement-quote');
    Route::post('/procurement/comparisons/{comparison}/{decision}', [ProcurementController::class, 'decision'])->whereNumber('comparison')->whereIn('decision', ['confirm', 'cancel'])->middleware('throttle:20,1,procurement-decision');
    Route::get('/proposals', [ProposalController::class, 'index'])->name('proposals');
    Route::post('/proposals/blocks', [ProposalController::class, 'saveBlock'])->middleware('throttle:30,1,proposal-block');
    Route::patch('/proposals/blocks/{block}', [ProposalController::class, 'saveBlock'])->whereNumber('block')->middleware('throttle:30,1,proposal-block');
    Route::delete('/proposals/blocks/{block}', [ProposalController::class, 'deleteBlock'])->whereNumber('block');
    Route::get('/tenders/{tender}/proposal', [ProposalController::class, 'show'])->name('tenders.proposal');
    Route::get('/tenders/{tender}/cost-check', [CostGapController::class, 'show'])->name('tenders.cost-check');
    Route::get('/tenders/{tender}/questions', [TenderQuestionController::class, 'index'])->name('tenders.questions');
    Route::post('/tenders/{tender}/questions/compose', [TenderQuestionController::class, 'compose'])->middleware('throttle:20,1,question-compose');
    Route::patch('/tenders/{tender}/questions/{question}', [TenderQuestionController::class, 'update'])->whereNumber('question')->middleware('throttle:60,1,question-edit');
    Route::post('/tenders/{tender}/questions/{question}/sent', [TenderQuestionController::class, 'markSent'])->whereNumber('question')->middleware('throttle:30,1,question-sent');
    Route::patch('/tenders/{tender}/questions/{question}/answer', [TenderQuestionController::class, 'answer'])->whereNumber('question')->middleware('throttle:30,1,question-answer');
    Route::patch('/tenders/{tender}/questions/{question}/archive', [TenderQuestionController::class, 'archive'])->whereNumber('question');
    Route::get('/tenders/{tender}/questions/{question}/export', [TenderQuestionController::class, 'export'])->whereNumber('question')->name('questions.export');
    Route::get('/tenders/{tender}/questions/{question}/history', [TenderQuestionController::class, 'history'])->whereNumber('question');
    Route::post('/tenders/{tender}/cost-check/analyze', [CostGapController::class, 'analyze'])->middleware('throttle:5,1,cost-check-analyze');
    Route::patch('/tenders/{tender}/cost-check/review', [CostGapController::class, 'review'])->middleware('throttle:60,1,cost-check-review');
    Route::post('/tenders/{tender}/cost-check/transfer', [CostGapController::class, 'transfer'])->middleware('throttle:30,1,cost-check-transfer');
    Route::post('/tenders/{tender}/proposal/compose', [ProposalController::class, 'compose'])->middleware('throttle:20,1,proposal-compose');
    Route::patch('/tenders/{tender}/proposal', [ProposalController::class, 'save']);
    Route::get('/tenders/{tender}/proposal/export', [ProposalController::class, 'export'])->name('proposal.export');
    Route::get('/catalog', [PriceCatalogController::class, 'index'])->name('catalog');
    Route::get('/catalog/template', [PriceCatalogController::class, 'template']);
    Route::post('/catalog/import', [PriceCatalogController::class, 'import'])->middleware('throttle:5,1,catalog-import');
    Route::post('/catalog/items', [PriceCatalogController::class, 'saveItem'])->middleware('throttle:30,1,catalog-item');
    Route::patch('/catalog/items/{item}', [PriceCatalogController::class, 'saveItem'])->whereNumber('item')->middleware('throttle:30,1,catalog-item');
    Route::delete('/catalog/items/{item}', [PriceCatalogController::class, 'deleteItem'])->whereNumber('item')->middleware('throttle:30,1,catalog-item');
    Route::delete('/catalog', [PriceCatalogController::class, 'clear'])->middleware('throttle:10,1,catalog-clear');
    Route::get('/catalog/recommendations', [PriceCatalogController::class, 'recommendations'])->middleware('throttle:10,1,catalog-search');
    Route::get('/catalog/tenders/{tender}', [PriceCatalogController::class, 'show'])->middleware('throttle:30,1,catalog-comparison');
    Route::post('/catalog/tenders/{tender}/specification', [PriceCatalogController::class, 'specification'])->middleware('throttle:5,1,catalog-specification');
    Route::delete('/catalog/tenders/{tender}/specification', [PriceCatalogController::class, 'clearSpecification'])->middleware('throttle:10,1,catalog-specification');
    Route::post('/catalog/tenders/{tender}/reviews', [PriceCatalogController::class, 'review'])->middleware('throttle:60,1,catalog-review');
    Route::get('/support/admin', [AdminSupportController::class, 'index'])
        ->middleware('super_admin')->name('support.admin.index');
    Route::get('/support/admin/{ticket}', [AdminSupportController::class, 'show'])
        ->middleware('super_admin')->name('support.admin.show');
    Route::patch('/support/admin/{ticket}', [AdminSupportController::class, 'update'])
        ->middleware('super_admin')->name('support.admin.update');
    Route::post('/support/admin/{ticket}/reply', [AdminSupportController::class, 'reply'])
        ->middleware(['super_admin', 'throttle:10,1'])->name('support.admin.reply');
    Route::post('/support/admin/{ticket}/access', [AdminSupportController::class, 'grantAccess'])
        ->middleware(['super_admin', 'throttle:5,1'])->name('support.admin.access.grant');
    Route::delete('/support/admin/{ticket}/access', [AdminSupportController::class, 'revokeAccess'])
        ->middleware(['super_admin', 'throttle:5,1'])->name('support.admin.access.revoke');
    Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportTicketController::class, 'store'])
        ->middleware('throttle:5,60')->name('support.store');
    Route::get('/support/{ticket}', [SupportTicketController::class, 'show'])->name('support.show');
    Route::post('/support/{ticket}/reply', [SupportTicketController::class, 'reply'])
        ->middleware('throttle:10,1')->name('support.reply');
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
    Route::get('/consents', fn (TemporaryFullAccessService $temporary) => Inertia::render('Consents', [
        'temporaryFullAccessEnabled' => ! request()->user()?->is_demo_guest && $temporary->isActive(),
    ]))->name('consents');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/tenders', [TenderFeedController::class, 'index'])->name('tenders');
    Route::get('/tenders/{tender}', TenderDetailController::class)->name('tenders.show');
    Route::get('/participation', [TenderWorkController::class, 'index'])->name('participation');
    Route::post('/participation/remove', [ParticipationLifecycleController::class, 'remove'])->middleware('throttle:30,1');
    Route::post('/participation/restore', [ParticipationLifecycleController::class, 'restore'])->middleware('throttle:30,1');
    Route::get('/participation/analytics', [ParticipationAnalyticsController::class, 'index'])->name('participation.analytics');
    Route::get('/participation/analytics/export', [ParticipationAnalyticsController::class, 'export'])->name('participation.analytics.export');
    Route::get('/calendar', [TenderCalendarController::class, 'index'])->name('calendar');
    Route::get('/calendar/export', [TenderCalendarController::class, 'index'])->name('calendar.export');
    Route::post('/calendar/subscription', [CalendarSubscriptionController::class, 'store'])->name('calendar.subscription.store');
    Route::delete('/calendar/subscription', [CalendarSubscriptionController::class, 'destroy'])->name('calendar.subscription.destroy');
    Route::get('/tenders/{tender}/work', [TenderWorkController::class, 'show'])->name('tenders.work');
    Route::put('/tenders/{tender}/participation', [TenderWorkController::class, 'update'])->name('tenders.participation');
    Route::patch('/tenders/{tender}/economics', [ParticipationEconomicsController::class, 'update'])->name('tenders.economics');
    Route::get('/tenders/{tender}/execution', [ParticipationExecutionController::class, 'show'])->name('tenders.execution');
    Route::post('/tenders/{tender}/execution', [ParticipationExecutionController::class, 'save'])->middleware('throttle:30,1,execution');
    Route::patch('/tenders/{tender}/execution', [ParticipationExecutionController::class, 'save'])->middleware('throttle:30,1,execution');
    Route::post('/tenders/{tender}/approval-requests', [ParticipationApprovalController::class, 'store'])->name('tenders.approvals.store');
    Route::patch('/tenders/{tender}/approval-requests/{approval}/vote', [ParticipationApprovalController::class, 'vote'])->name('tenders.approvals.vote');
    Route::post('/tenders/{tender}/comments', [ParticipationCommentController::class, 'store'])->name('tenders.comments.store');
    Route::patch('/tenders/{tender}/comments/{comment}', [ParticipationCommentController::class, 'update'])->name('tenders.comments.update');
    Route::delete('/tenders/{tender}/comments/{comment}', [ParticipationCommentController::class, 'destroy'])->name('tenders.comments.destroy');
    Route::post('/tenders/{tender}/checklist', [TenderWorkController::class, 'storeItem'])->name('tenders.checklist.store');
    Route::patch('/tenders/{tender}/checklist/{item}', [TenderWorkController::class, 'updateItem'])->name('tenders.checklist.update');
    Route::delete('/tenders/{tender}/checklist/{item}', [TenderWorkController::class, 'destroyItem'])->name('tenders.checklist.destroy');
    Route::post('/tenders/{tender}/documents', [ParticipationDocumentController::class, 'store'])->name('tenders.documents.store');
    Route::patch('/tenders/{tender}/documents/{document}', [ParticipationDocumentController::class, 'update'])->name('tenders.documents.update');
    Route::post('/tenders/{tender}/documents/{document}/versions', [ParticipationDocumentController::class, 'replace'])->name('tenders.documents.replace');
    Route::patch('/tenders/{tender}/documents/{document}/archive', [ParticipationDocumentController::class, 'archive'])->name('tenders.documents.archive');
    Route::delete('/tenders/{tender}/documents/{document}', [ParticipationDocumentController::class, 'destroy'])->name('tenders.documents.destroy');
    Route::get('/tenders/{tender}/documents/{document}/versions/{version}', [ParticipationDocumentController::class, 'download'])->name('tenders.documents.download');
    Route::post('/tender-feed-views', [TenderFeedViewController::class, 'store'])
        ->name('tender-feed-views.store');
    Route::delete('/tender-feed-views/{view}', [TenderFeedViewController::class, 'destroy'])
        ->name('tender-feed-views.destroy');
    Route::patch('/tenders/{tender}/state', TenderPersonalStateController::class)
        ->name('tenders.state');
    Route::get('/profile', ProfileController::class)->name('profile');
    Route::put('/profile/developer-plan', [DeveloperPlanController::class, 'update'])
        ->middleware('throttle:20,1')->name('profile.developer-plan');
    Route::put('/profile/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('profile.notification-preferences.update');
    Route::get('/plans', [BillingController::class, 'index'])->name('plans');
    Route::get('/operations', [OperationsDashboardController::class, 'show'])
        ->middleware('super_admin')
        ->name('operations.dashboard');
    Route::redirect('/operations-demo', '/operations')
        ->middleware('super_admin')
        ->name('operations.demo');
    Route::get('/queries', [SearchQueryController::class, 'index'])->name('queries.index');
    Route::post('/queries/preview', [SearchQueryController::class, 'preview'])->middleware('throttle:monitoring-preview')->name('queries.preview');
    Route::post('/tenders/{tender}/feedback', [TenderFeedbackController::class, 'store'])->middleware('throttle:local-mvp-preview')->name('tenders.feedback');
    Route::get('/tenders/{tender}/changes', [TenderFeedbackController::class, 'changes'])->name('tenders.changes');
    Route::post('/queries', [SearchQueryController::class, 'store'])->name('queries.store');
    Route::post('/queries/quick', [SearchQueryController::class, 'quick'])
        ->middleware('throttle:monitoring-preview')->name('queries.quick');
    Route::patch('/queries/{query}', [SearchQueryController::class, 'update'])->name('queries.update');
    Route::post('/queries/{query}/run', SavedSearchRunController::class)
        ->middleware('throttle:monitoring-preview')
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
    Route::post('/local/mvp/tenders/export', TenderExportController::class)
        ->middleware('throttle:local-mvp-preview')
        ->name('local.mvp.tenders.export');
    Route::patch('/local/mvp/tenders/{tender}/annotation', LocalMvpTenderAnnotationController::class)
        ->name('local.mvp.tenders.annotation');
    Route::post('/local/mvp/tenders/{tender}/status', [LocalMvpTenderStateController::class, 'update'])
        ->name('local.mvp.tenders.status');
    Route::post('/local/mvp/tenders/status', [LocalMvpTenderStateController::class, 'bulkUpdate'])
        ->name('local.mvp.tenders.bulk-status');
    Route::get('/local/mvp/tenders/{tender}', [LocalMvpTenderDetailController::class, 'show'])
        ->name('local.mvp.tenders.show');
});
