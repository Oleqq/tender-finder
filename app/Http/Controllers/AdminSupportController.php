<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportAccessService;
use App\Services\SupportDiagnosticsService;
use App\Services\SupportTicketPresenter;
use App\Services\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminSupportController extends Controller
{
    public function index(Request $request, SupportTicketPresenter $presenter): Response
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['open', 'in_progress', 'resolved'], true) ? $status : null;

        $tickets = SupportTicket::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate(30)
            ->through(fn (SupportTicket $ticket): array => $presenter->summary($ticket));

        return Inertia::render('AdminSupport', [
            'tickets' => $tickets,
            'statusFilter' => $status,
        ]);
    }

    public function show(SupportTicket $ticket, SupportTicketPresenter $presenter, SupportDiagnosticsService $diagnostics, SupportAccessService $supportAccess): Response
    {
        $grant = $supportAccess->activeGrantFor($ticket->user);

        return Inertia::render('AdminSupportTicket', [
            'ticket' => $presenter->detail($ticket, true),
            'diagnostics' => $diagnostics->forUser($ticket->user),
            'manualGrant' => $grant === null ? null : [
                'id' => $grant->id,
                'ends_at' => $grant->ends_at?->toAtomString(),
            ],
            'grantBlockReason' => $supportAccess->grantBlockReason($ticket->user),
            'assignees' => User::query()->where('role', UserRole::SuperAdmin->value)
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->all(),
        ]);
    }

    public function update(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'resolved'])],
            'assignee_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $service->updateWorkflow(
            $ticket,
            $this->user($request),
            $data['status'],
            $data['assignee_id'] ?? null,
            $data['reason'],
        );

        return redirect()->route('support.admin.show', $ticket);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $service->reply($ticket, $this->user($request), $data['body'], true);

        return redirect()->route('support.admin.show', $ticket);
    }

    public function grantAccess(Request $request, SupportTicket $ticket, SupportAccessService $service): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'between:1,7'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $service->grant($ticket, $this->user($request), (int) $data['days'], $data['reason']);

        return redirect()->route('support.admin.show', $ticket);
    }

    public function revokeAccess(Request $request, SupportTicket $ticket, SupportAccessService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ]);
        $service->revoke($ticket, $this->user($request), $data['reason']);

        return redirect()->route('support.admin.show', $ticket);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
