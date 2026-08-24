<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Models\Entitlement;

final readonly class AccessSnapshot
{
    public function __construct(
        public AccessState $state,
        public ?string $planCode,
        public ?int $activeQueryLimit,
        public ?string $endsAt,
    ) {}

    /** @return array{state: string, plan_code: ?string, active_query_limit: ?int, ends_at: ?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'plan_code' => $this->planCode,
            'active_query_limit' => $this->activeQueryLimit,
            'ends_at' => $this->endsAt,
        ];
    }

    public static function fromEntitlement(Entitlement $entitlement, AccessState $state): self
    {
        return new self(
            state: $state,
            planCode: $entitlement->plan?->code,
            activeQueryLimit: $entitlement->code === 'active_queries' ? $entitlement->value : null,
            endsAt: $entitlement->ends_at?->toAtomString(),
        );
    }
}
