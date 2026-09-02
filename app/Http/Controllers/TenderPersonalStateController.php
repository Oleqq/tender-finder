<?php

namespace App\Http\Controllers;

use App\Enums\TenderUserStatus;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TenderPersonalStateController extends Controller
{
    public function __invoke(Request $request, Tender $tender): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless(TenderQueryMatch::query()
            ->where('tender_id', $tender->id)
            ->whereHas('searchQuery', fn ($query) => $query->where('user_id', $user->id))
            ->exists(), 404);

        $attributes = $request->validate([
            'status' => ['required', Rule::enum(TenderUserStatus::class)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
            'next_action_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tags = $this->tags($attributes['tags'] ?? []);
        $nextActionOn = $this->nullableText($attributes['next_action_on'] ?? null);
        $status = TenderUserStatus::from($attributes['status']);
        $state = TenderUserState::query()->firstOrNew([
            'user_id' => $user->id,
            'tender_id' => $tender->id,
        ]);
        $state->forceFill([
            'status' => $status,
            'tags' => $tags === [] ? null : $tags,
            'next_action_on' => $nextActionOn,
        ]);

        if ($status === TenderUserStatus::New
            && $state->note === null
            && $tags === []
            && $nextActionOn === null
        ) {
            if ($state->exists) {
                $state->delete();
            }
        } else {
            $state->save();
        }

        return response()->json([
            'state' => [
                'status' => $status->value,
                'tags' => $tags,
                'next_action_on' => $nextActionOn,
            ],
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
