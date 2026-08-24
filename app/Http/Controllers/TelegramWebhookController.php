<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('tender.telegram.webhook_secret');
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($secret) || $secret === '' || ! is_string($providedSecret) || ! hash_equals($secret, $providedSecret)) {
            abort(403);
        }

        $validated = $request->validate([
            'update_id' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'array'],
            'message.text' => ['nullable', 'string', 'max:4096'],
            'message.chat.id' => ['nullable'],
        ]);

        $created = false;

        DB::transaction(function () use ($validated, &$created): void {
            $now = now();
            $created = DB::table('telegram_updates')->insertOrIgnore([
                'telegram_update_id' => $validated['update_id'],
                'type' => isset($validated['message']) ? 'message' : 'unsupported',
                'status' => 'received',
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            if (! $created) {
                return;
            }

            $update = TelegramUpdate::query()
                ->where('telegram_update_id', $validated['update_id'])
                ->firstOrFail();

            $command = $this->commandFrom($validated['message']['text'] ?? null);
            $chatId = $validated['message']['chat']['id'] ?? null;

            if ($command === null || ! (is_int($chatId) || (is_string($chatId) && preg_match('/\A-?\d+\z/', $chatId)))) {
                $update->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

                return;
            }

            ProcessTelegramUpdate::dispatch($validated['update_id'], $command, (string) $chatId)->afterCommit();
        });

        return response()->json(['ok' => true, 'duplicate' => ! $created]);
    }

    private function commandFrom(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $command = strtolower((string) preg_replace('/\s.+\z/u', '', trim($text)));
        $command = (string) preg_replace('/@[^\s]+\z/u', '', $command);

        return in_array($command, ['/start', '/help'], true) ? $command : null;
    }
}
