<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class MvpOperatorWorkspaceResponseService
{
    public function __construct(private readonly LocalMvpOperatorService $operator) {}

    public function open(Request $request): RedirectResponse
    {
        $user = $this->operator->provision();

        if ($request->user()?->id !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard');
    }
}
