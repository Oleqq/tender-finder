<?php

namespace App\Services;

final class LocalMvpFullAccessService
{
    public function isEnabled(): bool
    {
        return app()->environment('local', 'testing')
            && (bool) config('tender.local_mvp_full_access.enabled');
    }

    public function activeQueryLimit(): int
    {
        return max(1, (int) config('tender.local_mvp_full_access.active_query_limit'));
    }
}
