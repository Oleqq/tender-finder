<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketPresenter;
use App\Services\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    public function index(Request $request, SupportTicketPresenter $presenter): Response
    {
        $user = $this->user($request);

        return Inertia::render('Support', [
            'tickets' => SupportTicket::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (SupportTicket $ticket): array => $presenter->summary($ticket))
                ->all(),
        ]);
    }

    public function store(Request $request, SupportTicketService $service): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'category' => ['required', Rule::in(['access', 'monitoring', 'notifications', 'other'])],
            'body' => ['required', 'string', 'min:20', 'max:2000'],
        ]);
        $ticket = $service->create($user, $data['category'], $data['body']);

        return redirect()->route('support.show', $ticket);
    }

    public function show(Request $request, SupportTicket $ticket, SupportTicketPresenter $presenter): Response
    {
        $this->assertOwns($ticket, $this->user($request));

        return Inertia::render('SupportTicket', [
            'ticket' => $presenter->detail($ticket, false),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $user = $this->user($request);
        $this->assertOwns($ticket, $user);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $service->reply($ticket, $user, $data['body'], false);

        return redirect()->route('support.show', $ticket);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function assertOwns(SupportTicket $ticket, User $user): void
    {
        abort_unless($ticket->user_id === $user->id, 404);
    }
}
