<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\TemporaryFullAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class BrowserGuestSessionController extends Controller
{
    public function store(Request $request, TemporaryFullAccessService $temporary): RedirectResponse
    {
        abort_unless($temporary->isActive(), 404);

        if ($request->user() !== null) {
            return redirect()->route('welcome');
        }

        $guest = new User;
        $guest->forceFill([
            'name' => 'Гость '.bin2hex(random_bytes(4)),
            'role' => UserRole::Subscriber,
            'is_browser_guest' => true,
            'last_seen_at' => now(),
        ])->save();

        // The browser keeps its own account across visits. Access still ends
        // at the server deadline and the remember cookie is cleared then.
        Auth::login($guest, true);
        $request->session()->regenerate();

        return redirect()->route('consents');
    }
}
