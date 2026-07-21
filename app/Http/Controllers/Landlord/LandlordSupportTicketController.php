<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\TenantBudSetting;
use App\Models\TenantSupportTicket;
use App\Models\User;
use App\Services\Bud\TenantBudService;
use App\Services\Mobile\TenantMobileSupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandlordSupportTicketController extends Controller
{
    public function __construct(private TenantMobileSupportService $support, private TenantBudService $bud) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'open');
        $payload = $this->support->landlordIndex($status);
        $query = trim((string) $request->query('q', ''));
        $tickets = collect($payload['tickets']);
        if ($query !== '') $tickets = $tickets->filter(fn (array $ticket): bool => str($ticket['subject'].' '.$ticket['tenant'].' '.$ticket['creator'])->contains($query, true));
        return view('landlord.support-tickets.index', ['tickets' => $tickets->values(), 'status' => $status, 'query' => $query]);
    }

    public function show(int $ticket): View
    {
        return view('landlord.support-tickets.show', ['payload' => $this->support->landlordShow($ticket), 'operators' => User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name'])]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:8000']]);
        $payload = $this->support->landlordShow($ticket);
        $this->support->reply((int) $payload['ticket']['tenant_id'], $ticket, $request->user(), $data['body'], 'landlord');
        return back()->with('success', 'Reply sent to the workspace thread.');
    }

    public function update(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,waiting_on_tenant,resolved,closed'], 'priority' => ['required', 'in:low,normal,high,urgent'], 'assign_to_me' => ['nullable', 'boolean'], 'resolution_summary' => ['nullable', 'string', 'max:8000']]);
        $this->support->triage($ticket, $request->user(), $data);
        return back()->with('success', 'Ticket updated.');
    }

    public function reviewBud(Request $request, TenantBudSetting $setting): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,decline'], 'review_notes' => ['nullable', 'string', 'max:2000']]);
        $this->bud->review($setting, $request->user(), $data['decision'] === 'approve', $data['review_notes'] ?? null);
        return back()->with('success', 'Bud access decision saved.');
    }
}
