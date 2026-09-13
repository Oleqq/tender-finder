<?php

use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function teamFixture(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $viewer = User::factory()->create();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Компания']);
    foreach ([$owner->id => 'owner', $member->id => 'member', $viewer->id => 'viewer'] as $id => $role) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $id, 'role' => $role]);
    }
    $query = SearchQuery::query()->create(['user_id' => $owner->id, 'name' => 'Поиск', 'keywords' => ['сервер'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'rostender', 'external_id' => 'team-1', 'canonical_url' => 'https://example.test/team-1', 'canonical_url_hash' => hash('sha256', 'team-1'), 'title' => 'Общая закупка', 'deadline_at' => '2026-09-20 12:00:00']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return [$owner, $member, $viewer, $team, $tender];
}
