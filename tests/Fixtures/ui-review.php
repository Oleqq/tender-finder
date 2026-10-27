<?php

use App\Models\ChecklistTemplate;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\LocalMvpOperatorService;
use App\Services\SupportTicketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Reproducible local visual-review data. Run after migrating an isolated SQLite DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local', 'testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), '/tmp/tenderfinder-ui-')) {
    throw new RuntimeException('Use an isolated /tmp/tenderfinder-ui- SQLite database.');
}
if (Tender::query()->exists()) {
    throw new RuntimeException('Fixture requires an empty database.');
}
DB::beginTransaction();
$owner = app(LocalMvpOperatorService::class)->provision();
$owner->forceFill(['name' => 'Анна · тендерный отдел', 'telegram_id' => '900000001'])->save();
SourceFeed::query()->create([
    'source' => 'workspace_ru', 'canonical_url' => 'https://workspace.ru/tenders/rss/',
    'url_hash' => hash('sha256', 'workspace_ru:https://workspace.ru/tenders/rss/'),
    'status' => 'active', 'next_poll_at' => now()->addYear(),
    'poll_interval_seconds' => 3600,
]);
$member = User::factory()->create(['name' => 'Михаил Александров · специалист по закупкам']);
$team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Бюро цифровых закупок']);
foreach ([$owner->id => 'owner', $member->id => 'member'] as $id => $role) {
    DB::table('team_members')->insert([
        'team_id' => $team->id, 'user_id' => $id, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$query = SearchQuery::query()->create([
    'user_id' => $owner->id, 'name' => 'Сайты и цифровые сервисы', 'keywords' => ['сайт', 'поддержка'],
    'status' => 'active', 'monitoring_started_at' => now()->subDays(5), 'last_run_at' => now()->subMinutes(20),
]);
SearchQuery::query()->create(['user_id' => $owner->id, 'name' => 'Поставка оборудования для образовательных организаций', 'keywords' => ['сервер'], 'status' => 'paused']);
DB::table('team_search_queries')->insert([
    'team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id,
    'created_at' => now(), 'updated_at' => now(),
]);
$titles = ['Разработка и техническая поддержка официального сайта государственного учреждения',
    'Поставка серверного оборудования и настройка инфраструктуры', 'Сопровождение информационной системы закупок',
    'Разработка мобильного приложения для городских сервисов', 'Модернизация портала образовательной организации',
    'Услуги по аудиту информационной безопасности', 'Поддержка и развитие цифровой платформы'];
foreach ($titles as $index => $title) {
    $tender = Tender::query()->create([
        'source' => 'workspace_ru', 'external_id' => 'ui-review-'.$index, 'reg_number' => '0260000000'.($index + 1),
        'canonical_url' => 'https://example.test/tender/'.$index, 'canonical_url_hash' => hash('sha256', 'ui-review-'.$index),
        'title' => $title, 'description' => str_repeat('Требуется выполнить работы, подготовить документацию и обеспечить гарантийное сопровождение. ', 5),
        'region' => 'Москва', 'budget_amount' => $index === 5 ? null : 1250000 + $index * 200000,
        'currency' => 'RUB', 'published_at' => now()->subDays(3), 'deadline_at' => $index === 5 ? null : now()->addDays($index + 1),
        'metadata' => ['customer' => 'Государственное бюджетное учреждение «Центр цифрового развития»', 'delivery_place' => 'Москва, территория заказчика'],
    ]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id,
        'matched_at' => now()->subHours($index), 'match_reasons' => ['keywords' => ['сайт'], 'region' => 'matched']]);
    TenderUserState::query()->create(['user_id' => $owner->id, 'tender_id' => $tender->id,
        'status' => $index % 2 === 0 ? 'favorite' : 'new', 'tags' => ['Приоритет'], 'note' => 'Проверить техническое задание и сроки.', 'next_action_on' => now()->addDay()->toDateString()]);
    if ($index < 4) {
        $participation = TenderParticipation::query()->create(['user_id' => $owner->id, 'tender_id' => $tender->id,
            'stage' => ['preparing', 'studying', 'submitted', 'won'][$index], 'version' => 1,
            'planned_revenue' => 1200000, 'planned_cost' => 750000, 'participation_decision' => 'go']);
        $participation->items()->create(['title' => 'Подготовить коммерческое предложение и проверить условия договора', 'due_on' => now()->addDay()->toDateString()]);
        $participation->items()->create(['title' => 'Проверить электронную подпись', 'completed_at' => now()]);
    }
}
ChecklistTemplate::query()->create(['user_id' => $owner->id, 'name' => 'Подготовка заявки', 'items' => ['Проверить документацию', 'Подготовить расчёт цены'], 'version' => 1]);
$preview = Tender::query()->firstOrFail()->replicate();
$preview->fill(['source' => 'tenderguru_preview', 'external_id' => 'ui-preview', 'canonical_url' => 'https://example.test/preview', 'canonical_url_hash' => hash('sha256', 'ui-preview')])->save();
LocalMvpSearchSnapshot::query()->create(['user_id' => $owner->id, 'query' => 'Сайт', 'source' => 'tenderguru_preview', 'tender_ids' => [$preview->id], 'items_seen' => 1, 'items_matched' => 1]);
$ticket = app(SupportTicketService::class)->create($owner, 'monitoring', 'Не вижу новые результаты мониторинга после последней проверки.');
app(SupportTicketService::class)->reply($ticket, $owner, 'Проверяем источник и время последней синхронизации.', true);
NotificationDelivery::query()->create(['user_id' => $owner->id, 'type' => 'tender_card', 'status' => 'failed', 'failure_code' => 'telegram_network_unavailable', 'idempotency_key' => 'ui-review-failed', 'payload' => ['title' => $titles[0]], 'scheduled_at' => now()]);
DB::table('team_invitations')->insert(['team_id' => $team->id,
    'token_hash' => hash('sha256', 'ui-review-invitation'), 'role' => 'member', 'expires_at' => now()->addWeek(), 'created_at' => now(), 'updated_at' => now()]);
DB::commit();
echo json_encode(['owner' => $owner->id, 'team' => $team->id, 'ticket' => $ticket->id, 'invitation' => '/team-invitations/ui-review-invitation'], JSON_UNESCAPED_UNICODE).PHP_EOL;
