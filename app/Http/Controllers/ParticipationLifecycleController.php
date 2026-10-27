<?php

namespace App\Http\Controllers;

use App\Models\TenderUserState;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ParticipationLifecycleController extends Controller
{
    public function remove(Request $request): JsonResponse
    {
        return $this->mutate($request, false);
    }

    public function restore(Request $request): JsonResponse
    {
        return $this->mutate($request, true);
    }

    private function mutate(Request $request, bool $restore): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, true);
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.version' => ['required', 'integer', 'min:1'],
        ]);
        $expected = collect($request->array('items'))->keyBy('id');
        $updated = DB::transaction(function () use ($user, $team, $scope, $expected, $restore): array {
            $scope->lock($user, $team);
            $rows = app(TenderWorkService::class)->participations($user, $team)->withTrashed()
                ->whereKey($expected->keys())->orderBy('id')->lockForUpdate()->get();
            // Validate the entire batch before touching any row, including foreign IDs.
            abort_unless($rows->count() === $expected->count(), 404);
            foreach ($rows as $row) {
                abort_if($row->version !== (int) $expected[$row->id]['version'] || $row->trashed() !== $restore,
                    409, 'Список изменился в другой вкладке. Обновите его и повторите действие.');
            }
            foreach ($rows as $row) {
                $row->version++;
                if ($restore) {
                    $row->restore();
                } else {
                    $row->save();
                    $row->delete();
                    // Old queued jobs must remain silent even after a later restore.
                    $row->items()->where('reminder_enabled', true)->update([
                        'reminder_enabled' => false, 'version' => DB::raw('version + 1'),
                    ]);
                    $row->approvalRequests()->where('status', 'pending')->update([
                        'status' => 'superseded', 'version' => DB::raw('version + 1'), 'resolved_at' => now(),
                    ]);
                    DB::table('participation_comment_mentions')->whereIn('comment_id',
                        DB::table('participation_comments')->where('participation_id', $row->id)->select('id'))
                        ->whereNull('read_at')->update(['read_at' => now()]);
                    if ($team === null) {
                        TenderUserState::query()->where('user_id', $user->id)->where('tender_id', $row->tender_id)->update([
                            'deadline_reminders_enabled' => false, 'action_reminder_enabled' => false,
                            'watch_changes' => false, 'watch_started_at' => null,
                        ]);
                    }
                }
                if ($team) {
                    app(TeamActivityService::class)->record($team, $user, $restore ? 'participation_restored' : 'participation_removed', [
                        'participation_id' => $row->id, 'tender_id' => $row->tender_id,
                    ]);
                }
            }

            return $rows->map(fn ($row): array => ['id' => $row->id, 'version' => $row->version])->all();
        });

        return response()->json(['items' => $updated, 'count' => count($updated)]);
    }
}
