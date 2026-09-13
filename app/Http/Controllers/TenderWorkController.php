<?php

namespace App\Http\Controllers;

use App\Enums\ParticipationStage;
use App\Models\Tender;
use App\Models\TenderChecklistItem;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TenderWorkController extends Controller
{
    public function index(Request $request, TenderWorkService $work): Response
    {
        $user = $this->user($request);
        $filters = $request->validate(['stage' => ['nullable', Rule::enum(ParticipationStage::class)]]);
        $stage = $filters['stage'] ?? null;
        $base = TenderParticipation::query()->where('user_id', $user->id)
            ->whereIn('tender_id', $work->accessible($user)->select('tenders.id'));
        $counts = (clone $base)->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $rows = (clone $base)->when($stage, fn ($q) => $q->where('stage', $stage))
            ->with('tender')->withCount(['items', 'items as completed_count' => fn ($q) => $q->whereNotNull('completed_at')])
            ->latest('updated_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->through(fn (TenderParticipation $p): array => [
            'id' => $p->id, 'tender_id' => $p->tender_id, 'title' => $p->tender->title,
            'stage' => $p->stage->value, 'loss_reason' => $p->loss_reason,
            'deadline_at' => $p->tender->deadline_at?->toAtomString(),
            'items_count' => (int) $p->getAttribute('items_count'), 'completed_count' => (int) $p->getAttribute('completed_count'),
        ]);

        return Inertia::render('Participation', ['participations' => $rows, 'stage' => $stage, 'counts' => $counts]);
    }

    public function show(Request $request, Tender $tender, TenderWorkService $work): Response
    {
        $user = $this->user($request);
        $work->authorize($user, $tender);

        return Inertia::render('TenderWork', [
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'canonical_url' => $tender->canonical_url,
                'deadline_at' => $tender->deadline_at?->toAtomString()],
            'participation' => $work->present(TenderParticipation::query()->where('user_id', $user->id)->where('tender_id', $tender->id)->first()),
        ]);
    }

    public function update(Request $request, Tender $tender, TenderWorkService $work): JsonResponse
    {
        $user = $this->user($request);
        $work->authorize($user, $tender);
        $data = $request->validate([
            'stage' => ['required', Rule::enum(ParticipationStage::class)],
            'loss_reason' => ['required_if:stage,lost', 'nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $participation = DB::transaction(function () use ($user, $tender, $data): TenderParticipation {
            // Serializes creation as well as updates for this user's private workflow.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $p = TenderParticipation::query()->where('user_id', $user->id)->where('tender_id', $tender->id)->lockForUpdate()->first();
            $version = $p === null ? 0 : $p->version;
            abort_if((int) $data['version'] !== $version, 409, 'Данные изменились. Обновите страницу.');
            $old = $p?->stage->value;
            $reason = $data['stage'] === 'lost' ? trim($data['loss_reason']) : null;
            if ($p !== null && $old === $data['stage'] && $p->loss_reason === $reason) {
                return $p;
            }
            $p ??= new TenderParticipation(['user_id' => $user->id, 'tender_id' => $tender->id, 'version' => 0]);
            $p->fill(['stage' => $data['stage'], 'loss_reason' => $reason, 'version' => $p->version + 1])->save();
            DB::table('tender_participation_events')->insert(['participation_id' => $p->id, 'from_stage' => $old,
                'to_stage' => $p->stage->value, 'reason' => $reason, 'created_at' => now()]);

            return $p;
        });

        return response()->json(['participation' => $work->present($participation)]);
    }

    public function storeItem(Request $request, Tender $tender, TenderWorkService $work): JsonResponse
    {
        $user = $this->user($request);
        $work->authorize($user, $tender);
        $data = $request->validate(['title' => ['required', 'string', 'max:240'], 'due_on' => ['nullable', 'date_format:Y-m-d']]);
        $p = DB::transaction(function () use ($user, $tender, $data): TenderParticipation {
            $p = TenderParticipation::query()->where('user_id', $user->id)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            abort_if($p->items()->count() >= 100, 422, 'В чек-листе допускается до 100 задач.');
            $p->items()->create(['title' => trim($data['title']), 'due_on' => $data['due_on'] ?? null]);

            return $p;
        });

        return response()->json(['participation' => $work->present($p)], 201);
    }

    public function updateItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work): JsonResponse
    {
        return $this->mutateItem($request, $tender, $item, $work, false);
    }

    public function destroyItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work): JsonResponse
    {
        return $this->mutateItem($request, $tender, $item, $work, true);
    }

    private function mutateItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work, bool $delete): JsonResponse
    {
        $user = $this->user($request);
        $work->authorize($user, $tender);
        abort_unless($item->participation->user_id === $user->id && $item->participation->tender_id === $tender->id, 404);
        $data = $request->validate($delete ? ['version' => ['required', 'integer', 'min:1']] : [
            'title' => ['required', 'string', 'max:240'], 'due_on' => ['nullable', 'date_format:Y-m-d'],
            'completed' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($item, $data, $delete): void {
            $locked = TenderChecklistItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->version !== (int) $data['version'], 409, 'Задача изменена в другой вкладке. Обновите страницу.');
            if ($delete) {
                $locked->delete();
            } else {
                $locked->fill(['title' => trim($data['title']), 'due_on' => $data['due_on'] ?? null,
                    'completed_at' => $data['completed'] ? ($locked->completed_at ?? now()) : null,
                    'version' => $locked->version + 1])->save();
            }
        });

        return response()->json(['participation' => $work->present($item->participation->refresh())]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
