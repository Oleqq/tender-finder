<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

final class DemoCheckoutGate
{
    public function enabled(): bool
    {
        return (bool) config('tender.payments.demo_enabled', false);
    }

    public function authorizeEntry(Request $request): void
    {
        abort_unless($this->enabled(), 404);
        abort_unless(app()->environment(['local', 'testing'])
            || (int) $request->session()->get('demo_reviewer_until', 0) > now()->timestamp, 403);
    }

    public function authorize(Request $request): User
    {
        $this->authorizeEntry($request);
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_demo_guest && $user->is_browser_guest && $user->telegram_id === null, 403);

        return $user;
    }
}
