<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocalMvpTenderAnnotationController extends Controller
{
    public function __invoke(
        Request $request,
        Tender $tender,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);
        abort_unless($workspace->canAccessTender($request->user(), $tender), 404);

        $attributes = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
            'next_action_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $note = $this->nullableText($attributes['note'] ?? null);
        $tags = $this->tags($attributes['tags'] ?? []);
        $nextActionOn = $this->nullableText($attributes['next_action_on'] ?? null);

        $workspace->updateAnnotation(
            $request->user(),
            $tender,
            $note,
            $tags,
            $nextActionOn,
        );

        return response()->json([
            'tender' => $workspace->tenderDetailFor($request->user(), $tender),
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');

            if ($tag === '') {
                continue;
            }

            $result[mb_strtolower($tag)] = $tag;
        }

        return array_values($result);
    }
}
