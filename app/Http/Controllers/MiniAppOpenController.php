<?php

namespace App\Http\Controllers;

use App\Enums\AccessState;
use App\Services\AccessService;
use App\Services\MiniAppDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MiniAppOpenController extends Controller
{
    public function __invoke(Request $request, AccessService $access): RedirectResponse|Response
    {
        // This public entry lets Telegram establish a fresh session before opening a protected screen.
        if ($request->user() === null) {
            return Inertia::render('Welcome');
        }

        $destination = MiniAppDestination::normalize($request->query());
        if ($access->snapshotFor($request->user())->state === AccessState::Preview
            && ! in_array($destination['screen'], ['plans', 'profile', 'support'], true)) {
            return redirect()->route('onboarding');
        }

        return redirect()->to(MiniAppDestination::path($destination));
    }
}
