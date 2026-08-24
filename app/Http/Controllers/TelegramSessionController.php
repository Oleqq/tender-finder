<?php

namespace App\Http\Controllers;

use App\Services\AccessService;
use App\Services\TelegramIdentityService;
use App\Telegram\TelegramInitDataException;
use App\Telegram\TelegramInitDataVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TelegramSessionController extends Controller
{
    public function store(
        Request $request,
        TelegramInitDataVerifier $verifier,
        TelegramIdentityService $identityService,
        AccessService $access,
    ): JsonResponse {
        $validated = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $identity = $verifier->verify($validated['init_data']);
        } catch (TelegramInitDataException) {
            throw ValidationException::withMessages([
                'init_data' => 'Не удалось подтвердить Telegram-сессию. Откройте приложение заново в Telegram.',
            ]);
        }

        $user = $identityService->findOrCreate($identity);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
            ],
            'access' => $access->snapshotFor($user)->toArray(),
        ]);
    }
}
