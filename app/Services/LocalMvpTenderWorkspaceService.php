<?php

namespace App\Services;

use App\Enums\TenderUserStatus;
use App\Models\Tender;
use App\Models\TenderUserState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LocalMvpTenderWorkspaceService
{
    /** @var list<string> */
    private const LOCAL_MVP_SOURCES = ['tenderguru_preview', 'eis_rss'];

    public function __construct(private readonly LocalMvpSearchSnapshotService $snapshots) {}

    /** @return list<array<string, mixed>> */
    /** @param list<string> $externalIds
     * @param  array<string, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>  $matchReasonsByExternalId
     * @return list<array<string, mixed>>
     */
    public function tendersForSourceExternalIds(
        User $user,
        string $source,
        array $externalIds,
        array $matchReasonsByExternalId = [],
    ): array {
        if (! in_array($source, self::LOCAL_MVP_SOURCES, true) || $externalIds === []) {
            return [];
        }

        return $this->tenderQueryFor($user)
            ->where('source', $source)
            ->whereIn('external_id', $externalIds)
            ->orderByDesc('published_at')
            ->latest('id')
            ->get()
            ->map(fn (Tender $tender): array => $this->tenderDto(
                $tender,
                $matchReasonsByExternalId[$tender->external_id] ?? null,
            ))
            ->all();
    }

    /** @param list<int> $tenderIds
     * @param  array<int, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>  $matchReasonsByTenderId
     * @return list<array<string, mixed>>
     */
    public function tendersForIds(
        User $user,
        array $tenderIds,
        array $matchReasonsByTenderId = [],
    ): array {
        $tenderIds = array_values(array_unique(array_filter(
            $tenderIds,
            fn (int $id): bool => $id > 0,
        )));

        if ($tenderIds === []) {
            return [];
        }

        $accessibleIds = array_flip($this->snapshots->accessibleTenderIdsFor($user));
        $tenderIds = array_values(array_filter(
            $tenderIds,
            fn (int $id): bool => isset($accessibleIds[$id]),
        ));

        if ($tenderIds === []) {
            return [];
        }

        return $this->tenderQueryFor($user)
            ->whereKey($tenderIds)
            ->orderByDesc('published_at')
            ->latest('id')
            ->get()
            ->map(fn (Tender $tender): array => $this->tenderDto(
                $tender,
                $matchReasonsByTenderId[$tender->id] ?? null,
            ))
            ->all();
    }

    /** @param list<int> $tenderIds
     * @param  array<int, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>  $matchReasonsByTenderId
     * @return list<array<string, mixed>>
     */
    public function historyTendersFor(
        User $user,
        array $tenderIds,
        array $matchReasonsByTenderId = [],
    ): array {
        $tenderIds = array_values(array_unique(array_filter(
            $tenderIds,
            fn (int $id): bool => $id > 0,
        )));

        if ($tenderIds === []) {
            return [];
        }

        return $this->tenderQueryFor($user)
            ->whereKey($tenderIds)
            ->orderByDesc('published_at')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Tender $tender): array => $this->tenderDto(
                $tender,
                $matchReasonsByTenderId[$tender->id] ?? null,
            ))
            ->all();
    }

    /** @return array<string, mixed> */
    public function updateStatus(User $user, Tender $tender, TenderUserStatus $status): array
    {
        return $this->updateStatuses($user, [$tender->id], $status)[0];
    }

    /**
     * @param  list<int>  $tenderIds
     * @return list<array<string, mixed>>
     */
    public function updateStatuses(User $user, array $tenderIds, TenderUserStatus $status): array
    {
        $tenderIds = array_values(array_unique($tenderIds));

        DB::transaction(function () use ($user, $tenderIds, $status): void {
            if ($status === TenderUserStatus::New) {
                $states = TenderUserState::query()
                    ->where('user_id', $user->id)
                    ->whereIn('tender_id', $tenderIds)
                    ->get();

                foreach ($states as $state) {
                    if ($this->hasAnnotation($state)) {
                        $state->forceFill(['status' => $status])->save();
                    } else {
                        $state->delete();
                    }
                }

                return;
            }

            foreach ($tenderIds as $tenderId) {
                TenderUserState::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'tender_id' => $tenderId,
                    ],
                    ['status' => $status],
                );
            }
        });

        $matchReasons = $this->snapshots->matchReasonsFor($this->snapshots->currentFor($user));

        return $this->tenderQueryFor($user)
            ->whereKey($tenderIds)
            ->orderByDesc('published_at')
            ->latest('id')
            ->get()
            ->map(fn (Tender $tender): array => $this->tenderDto(
                $tender,
                $matchReasons[$tender->id] ?? null,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public function updateAnnotation(
        User $user,
        Tender $tender,
        ?string $note,
        array $tags,
        ?string $nextActionOn,
    ): array {
        $state = TenderUserState::query()->firstOrNew([
            'user_id' => $user->id,
            'tender_id' => $tender->id,
        ]);
        $state->status ??= TenderUserStatus::New;
        $state->forceFill([
            'note' => $note,
            'tags' => $tags === [] ? null : $tags,
            'next_action_on' => $nextActionOn,
        ]);

        if ($state->status === TenderUserStatus::New
            && $note === null
            && $tags === []
            && $nextActionOn === null
        ) {
            if ($state->exists) {
                $state->delete();
            }
        } else {
            $state->save();
        }

        $updated = $this->tenderQueryFor($user)->whereKey($tender->id)->firstOrFail();
        $matchReasons = $this->snapshots->matchReasonsFor($this->snapshots->currentFor($user));

        return $this->tenderDto($updated, $matchReasons[$updated->id] ?? null);
    }

    /**
     * @param  list<int>  $tenderIds
     * @param  list<string>  $tags
     * @return list<array<string, mixed>>
     */
    public function updateAnnotations(
        User $user,
        array $tenderIds,
        array $tags,
        ?string $nextActionOn,
    ): array {
        $tenderIds = array_values(array_unique($tenderIds));

        DB::transaction(function () use ($user, $tenderIds, $tags, $nextActionOn): void {
            foreach ($tenderIds as $tenderId) {
                $state = TenderUserState::query()->firstOrNew([
                    'user_id' => $user->id,
                    'tender_id' => $tenderId,
                ]);
                $state->status ??= TenderUserStatus::New;
                $state->forceFill([
                    'tags' => $tags === [] ? null : $tags,
                    'next_action_on' => $nextActionOn,
                ]);

                if ($state->status === TenderUserStatus::New && ! $this->hasAnnotation($state)) {
                    if ($state->exists) {
                        $state->delete();
                    }
                } else {
                    $state->save();
                }
            }
        });

        $matchReasons = $this->snapshots->matchReasonsFor($this->snapshots->currentFor($user));

        return $this->tenderQueryFor($user)
            ->whereKey($tenderIds)
            ->orderByDesc('published_at')
            ->latest('id')
            ->get()
            ->map(fn (Tender $tender): array => $this->tenderDto(
                $tender,
                $matchReasons[$tender->id] ?? null,
            ))
            ->values()
            ->all();
    }

    public function canAccessTender(User $user, Tender $tender): bool
    {
        return in_array($tender->source, self::LOCAL_MVP_SOURCES, true)
            && in_array($tender->id, $this->snapshots->accessibleTenderIdsFor($user), true);
    }

    /** @param list<int> $tenderIds */
    public function hasOnlyAccessibleTenders(User $user, array $tenderIds): bool
    {
        $tenderIds = array_values(array_unique($tenderIds));
        $accessibleIds = array_flip($this->snapshots->accessibleTenderIdsFor($user));

        return $tenderIds !== []
            && count($tenderIds) === count(array_filter(
                $tenderIds,
                fn (int $id): bool => isset($accessibleIds[$id]),
            ));
    }

    /** @return array<string, mixed>|null */
    public function tenderDetailFor(User $user, Tender $tender): ?array
    {
        if (! $this->canAccessTender($user, $tender)) {
            return null;
        }

        $tender = $this->tenderQueryFor($user)
            ->whereKey($tender->id)
            ->first();

        if ($tender === null) {
            return null;
        }

        /** @var mixed $rawMetadata */
        $rawMetadata = $tender->getAttribute('metadata');
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];

        $matchReasons = $this->snapshots->matchReasonsFor($this->snapshots->currentFor($user));

        return [
            ...$this->tenderDto($tender, $matchReasons[$tender->id] ?? null),
            'reg_number' => $tender->reg_number,
            'customer' => $this->nullableMetadataText($metadata['customer'] ?? null),
            'category' => $this->nullableMetadataText($metadata['category'] ?? null),
            'procurement_law' => $this->nullableMetadataText($metadata['procurement_law'] ?? null),
            'delivery_place' => $this->nullableMetadataText($metadata['delivery_place'] ?? null),
            'contact_name' => $this->nullableMetadataText($metadata['contact_name'] ?? null),
            'contact_email' => $this->nullableMetadataText($metadata['contact_email'] ?? null),
            'contact_phone' => $this->nullableMetadataText($metadata['contact_phone'] ?? null),
            'postal_address' => $this->nullableMetadataText($metadata['postal_address'] ?? null),
            'application_security' => $this->nullableMetadataText($metadata['application_security'] ?? null),
            'contract_security' => $this->nullableMetadataText($metadata['contract_security'] ?? null),
            'enriched_at' => $this->nullableMetadataText($metadata['enriched_at'] ?? null),
            'can_enrich' => $tender->source === 'eis_rss'
                && is_string($tender->reg_number)
                && preg_match('/^\d{19,20}$/', $tender->reg_number) === 1,
            'source_label' => match ($tender->source) {
                'eis_rss' => 'ЕИС · государственные закупки · RSS-поиск',
                default => 'TenderGuru public preview · ручной запрос',
            },
            'attachments' => $this->attachmentDtos($metadata['attachments'] ?? null),
        ];
    }

    /** @return Builder<Tender> */
    private function tenderQueryFor(User $user): Builder
    {
        return Tender::query()
            ->whereIn('source', self::LOCAL_MVP_SOURCES)
            ->with([
                'userStates' => fn ($query) => $query->where('user_id', $user->id),
            ]);
    }

    /** @return array<string, mixed> */
    /**
     * @param  array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}|null  $matchReason
     * @return array<string, mixed>
     */
    private function tenderDto(Tender $tender, ?array $matchReason = null): array
    {
        $state = $tender->userStates->first();
        /** @var mixed $rawMetadata */
        $rawMetadata = $tender->getAttribute('metadata');
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];

        return [
            'id' => $tender->id,
            'title' => $tender->title,
            'description' => $tender->description,
            'region' => $tender->region,
            'budget_amount' => $tender->budget_amount,
            'currency' => $tender->currency,
            'published_at' => $tender->published_at?->toAtomString(),
            'deadline_at' => $tender->deadline_at?->toAtomString(),
            'reg_number' => $tender->reg_number,
            'customer' => $this->nullableMetadataText($metadata['customer'] ?? null),
            'category' => $this->nullableMetadataText($metadata['category'] ?? null),
            'procurement_law' => $this->nullableMetadataText($metadata['procurement_law'] ?? null),
            'delivery_place' => $this->nullableMetadataText($metadata['delivery_place'] ?? null),
            'contact_name' => $this->nullableMetadataText($metadata['contact_name'] ?? null),
            'contact_email' => $this->nullableMetadataText($metadata['contact_email'] ?? null),
            'contact_phone' => $this->nullableMetadataText($metadata['contact_phone'] ?? null),
            'application_security' => $this->nullableMetadataText($metadata['application_security'] ?? null),
            'contract_security' => $this->nullableMetadataText($metadata['contract_security'] ?? null),
            'enriched_at' => $this->nullableMetadataText($metadata['enriched_at'] ?? null),
            'canonical_url' => $tender->canonical_url,
            'status' => $state?->status->value ?? TenderUserStatus::New->value,
            'note' => $state?->note,
            'tags' => is_array($state?->tags) ? array_values(array_filter($state->tags, 'is_string')) : [],
            'next_action_on' => $state?->next_action_on?->format('Y-m-d'),
            'match_reason' => $matchReason,
        ];
    }

    private function hasAnnotation(TenderUserState $state): bool
    {
        return filled($state->note)
            || (is_array($state->tags) && $state->tags !== [])
            || $state->next_action_on !== null;
    }

    private function nullableMetadataText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{label: string, url: string, mime_type: string|null, size_bytes: int|null}>
     */
    private function attachmentDtos(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $result = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $url = $this->nullableMetadataText($attachment['url'] ?? null);

            if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $label = $this->nullableMetadataText($attachment['label'] ?? null)
                ?? 'Документ источника';
            $mimeType = $this->nullableMetadataText($attachment['mime_type'] ?? null);
            $sizeBytes = is_int($attachment['size_bytes'] ?? null)
                ? $attachment['size_bytes']
                : null;

            $result[] = [
                'label' => $label,
                'url' => $url,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
            ];
        }

        return $result;
    }
}
