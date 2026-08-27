<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpSubscriberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalMvpSubscriberSessionController extends Controller
{
    public function store(Request $request, LocalMvpSubscriberService $subscriber): RedirectResponse
    {
        abort_unless($subscriber->isEnabled(), 404);

        $user = $subscriber->provision();

        if ($request->user()?->id !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('onboarding');
    }
}
