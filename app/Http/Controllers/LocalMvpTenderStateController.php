<?php

namespace App\Http\Controllers;

use App\Enums\TenderUserStatus;
use App\Models\Tender;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocalMvpTenderStateController extends Controller
{
    public function update(
        Request $request,
        Tender $tender,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);
        abort_unless($workspace->canAccessTender($request->user(), $tender), 404);

        $attributes = $request->validate([
            'status' => ['required', Rule::enum(TenderUserStatus::class)],
        ]);

        return response()->json([
            'tender' => $workspace->updateStatus(
                $request->user(),
                $tender,
                TenderUserStatus::from($attributes['status']),
            ),
        ]);
    }

    public function bulkUpdate(
        Request $request,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'tender_ids' => ['required', 'array', 'min:1', 'max:60'],
            'tender_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['nullable', Rule::enum(TenderUserStatus::class)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
            'next_action_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tenderIds = array_map('intval', $attributes['tender_ids']);

        abort_unless($workspace->hasOnlyAccessibleTenders($request->user(), $tenderIds), 404);

        if (! array_key_exists('status', $attributes)
            && ! array_key_exists('tags', $attributes)
            && ! array_key_exists('next_action_on', $attributes)
        ) {
            throw ValidationException::withMessages([
                'bulk' => 'Выберите статус, теги или дату следующего действия.',
            ]);
        }

        if (isset($attributes['status'])) {
            $workspace->updateStatuses(
                $request->user(),
                $tenderIds,
                TenderUserStatus::from($attributes['status']),
            );
        }

        $tenders = array_key_exists('tags', $attributes)
            || array_key_exists('next_action_on', $attributes)
            ? $workspace->updateAnnotations(
                $request->user(),
                $tenderIds,
                $this->tags($attributes['tags'] ?? []),
                $this->nullableText($attributes['next_action_on'] ?? null),
            )
            : $workspace->tendersForIds($request->user(), $tenderIds);

        return response()->json([
            'tenders' => $tenders,
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[mb_strtolower(trim($tag))] = trim($tag);
            }
        }

        return array_values($tags);
    }
}
