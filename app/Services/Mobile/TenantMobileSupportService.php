<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Models\TenantSupportTicket;
use App\Models\TenantSupportTicketMessage;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;

class TenantMobileSupportService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @return array<string,mixed> */
    public function index(int $tenantId): array
    {
        return ['tickets' => TenantSupportTicket::withoutGlobalScopes()->where('tenant_id', $tenantId)->with(['creator:id,name', 'assignee:id,name'])->withCount('messages')->latest('last_activity_at')->latest('id')->limit(100)->get()->map(fn (TenantSupportTicket $ticket): array => $this->summary($ticket))->values()];
    }

    /** @return array<string,mixed> */
    public function show(int $tenantId, int $ticketId): array
    {
        $ticket = TenantSupportTicket::withoutGlobalScopes()->where('tenant_id', $tenantId)->with(['creator:id,name', 'assignee:id,name', 'messages.user:id,name'])->findOrFail($ticketId);

        return ['ticket' => $this->summary($ticket), 'messages' => $ticket->messages->map(fn (TenantSupportTicketMessage $message): array => [
            'id' => (int) $message->id,
            'body' => (string) $message->body,
            'author_context' => (string) $message->author_context,
            'author' => (string) ($message->user?->name ?: ($message->author_context === 'landlord' ? 'Everbranch Support' : 'Workspace user')),
            'created_at' => optional($message->created_at)->toIso8601String(),
        ])->values()];
    }

    /** @return array<string,mixed> */
    public function create(Tenant $tenant, User $user, array $input): array
    {
        $ticket = DB::transaction(function () use ($tenant, $user, $input): TenantSupportTicket {
            $ticket = TenantSupportTicket::withoutGlobalScopes()->create([
                'tenant_id' => (int) $tenant->id,
                'created_by_user_id' => (int) $user->id,
                'subject' => $input['subject'],
                'category' => $input['category'],
                'priority' => $input['priority'],
                'status' => 'open',
                'last_activity_at' => now(),
            ]);
            TenantSupportTicketMessage::withoutGlobalScopes()->create(['tenant_support_ticket_id' => $ticket->id, 'tenant_id' => (int) $tenant->id, 'user_id' => (int) $user->id, 'author_context' => 'tenant', 'body' => $input['body']]);

            return $ticket;
        });
        $this->audit->record((int) $tenant->id, (int) $user->id, 'tenant.support_ticket.created', targetType: 'tenant_support_ticket', targetId: $ticket->id, context: ['surface' => 'everbranch_mobile'], afterState: ['status' => 'open', 'priority' => $ticket->priority]);

        return $this->show((int) $tenant->id, (int) $ticket->id);
    }

    /** @return array<string,mixed> */
    public function reply(int $tenantId, int $ticketId, User $user, string $body, string $context): array
    {
        $ticket = TenantSupportTicket::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($ticketId);
        TenantSupportTicketMessage::withoutGlobalScopes()->create(['tenant_support_ticket_id' => $ticket->id, 'tenant_id' => $tenantId, 'user_id' => (int) $user->id, 'author_context' => $context, 'body' => $body]);
        $ticket->forceFill(['last_activity_at' => now(), 'status' => $context === 'landlord' && $ticket->status === 'open' ? 'in_progress' : $ticket->status])->save();

        return $this->show($tenantId, $ticketId);
    }

    /** @return array<string,mixed> */
    public function landlordIndex(string $status = 'open'): array
    {
        $tickets = TenantSupportTicket::withoutGlobalScopes()->with(['tenant:id,name', 'creator:id,name', 'assignee:id,name'])->withCount('messages');
        if ($status === 'open') {
            $tickets->whereNotIn('status', ['resolved', 'closed']);
        } elseif ($status !== 'all') {
            $tickets->where('status', $status);
        }

        return ['tickets' => $tickets->latest('last_activity_at')->latest('id')->limit(150)->get()->map(fn (TenantSupportTicket $ticket): array => [...$this->summary($ticket), 'tenant' => $ticket->tenant?->name])->values()];
    }

    /** @return array<string,mixed> */
    public function landlordShow(int $ticketId): array
    {
        $ticket = TenantSupportTicket::withoutGlobalScopes()->findOrFail($ticketId);

        return $this->show((int) $ticket->tenant_id, (int) $ticket->id);
    }

    /** @return array<string,mixed> */
    public function triage(int $ticketId, User $actor, array $input): array
    {
        $ticket = TenantSupportTicket::withoutGlobalScopes()->findOrFail($ticketId);
        $before = ['status' => $ticket->status, 'priority' => $ticket->priority, 'assigned_to_user_id' => $ticket->assigned_to_user_id];
        $ticket->forceFill(array_filter([
            'status' => $input['status'] ?? null,
            'priority' => $input['priority'] ?? null,
            'assigned_to_user_id' => ($input['assign_to_me'] ?? false) ? (int) $actor->id : null,
            'last_activity_at' => now(),
        ], fn (mixed $value, string $key): bool => $key === 'assigned_to_user_id' ? (bool) ($input['assign_to_me'] ?? false) : $value !== null, ARRAY_FILTER_USE_BOTH))->save();
        $this->audit->record((int) $ticket->tenant_id, (int) $actor->id, 'tenant.support_ticket.triaged', targetType: 'tenant_support_ticket', targetId: $ticket->id, context: ['surface' => 'everbranch_mobile_landlord'], beforeState: $before, afterState: ['status' => $ticket->status, 'priority' => $ticket->priority, 'assigned_to_user_id' => $ticket->assigned_to_user_id]);

        return $this->landlordShow((int) $ticket->id);
    }

    /** @return array<string,mixed> */
    protected function summary(TenantSupportTicket $ticket): array
    {
        return ['id' => (int) $ticket->id, 'tenant_id' => (int) $ticket->tenant_id, 'subject' => (string) $ticket->subject, 'category' => (string) $ticket->category, 'priority' => (string) $ticket->priority, 'status' => (string) $ticket->status, 'creator' => $ticket->creator?->name, 'assignee' => $ticket->assignee?->name, 'messages_count' => (int) ($ticket->messages_count ?? $ticket->messages->count()), 'last_activity_at' => optional($ticket->last_activity_at)->toIso8601String()];
    }
}
