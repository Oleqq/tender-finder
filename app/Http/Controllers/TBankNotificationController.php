<?php

namespace App\Http\Controllers;

use App\Services\TBankToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class TBankNotificationController extends Controller
{
    public function __invoke(Request $request, TBankToken $token): Response
    {
        // Independent switch: stopping new sales must not drop notifications for existing payments.
        abort_unless(config('tender.payments.tbank.notifications_enabled') === true, 503);
        $password = config('tender.payments.tbank.password');
        $terminal = config('tender.payments.tbank.terminal_key');
        abort_unless(is_string($password) && $password !== '' && is_string($terminal) && $terminal !== '', 503);
        abort_unless($request->isJson() && strlen($request->getContent()) <= 65536, 400);
        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            abort(400);
        }
        abort_unless(is_array($data) && ! array_is_list($data), 400);
        abort_unless(($data['TerminalKey'] ?? null) === $terminal && $token->verify($data, $password), 403);
        $validator = Validator::make($data, [
            'OrderId' => ['required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9-]+\z/D'],
            'PaymentId' => ['required', 'regex:/\A[0-9]{1,20}\z/D'],
            'Amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'Status' => ['required', 'string', 'max:40', 'regex:/\A[A-Z0-9_]+\z/D'],
            'Success' => ['required', 'boolean'],
            'ErrorCode' => ['required', 'regex:/\A[0-9]{1,20}\z/D'],
        ]);
        abort_if($validator->fails() || ! is_bool($data['Success'] ?? null), 400);
        // Deliberately store only the operational envelope. No Token, Pan, ExpDate, email or RebillId.
        $event = [
            'terminal_key' => $terminal, 'payment_id' => (string) $data['PaymentId'],
            'order_id' => $data['OrderId'], 'amount_minor' => (int) $data['Amount'],
            'payment_status' => $data['Status'], 'success' => $data['Success'],
            'error_code' => (string) $data['ErrorCode'],
        ];
        $fingerprint = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $inserted = DB::table('tbank_notifications')->insertOrIgnore($event + [
            'event_key' => $fingerprint, 'state' => 'received',
            'request_id' => $request->attributes->get('request_id'), 'received_at' => now(),
        ]);
        Log::info('payments.tbank.notification_received', [
            'event_key' => $fingerprint, 'payment_status' => $event['payment_status'],
            'duplicate' => $inserted === 0, 'request_id' => $request->attributes->get('request_id'),
        ]);

        // Acknowledge only a durable inbox write; a future reconciler must verify GetState and the local order.
        return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
