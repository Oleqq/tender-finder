<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TeamActivityService
{
    /** @param array<string, bool|int|string|null> $context */
    public function record(Team $team, User $actor, string $action, array $context = []): void
    {
        DB::table('team_activity_logs')->insert([
            'team_id' => $team->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
