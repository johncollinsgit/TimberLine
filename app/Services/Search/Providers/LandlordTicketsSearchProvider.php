<?php

namespace App\Services\Search\Providers;

use App\Models\TenantSupportTicket;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\LandlordSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LandlordTicketsSearchProvider implements LandlordSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        if (! Schema::hasTable('tenant_support_tickets')) {
            return [];
        }

        $normalized = trim($query);
        $rows = TenantSupportTicket::query()
            ->with('tenant:id,name,slug')
            ->select(['id', 'tenant_id', 'subject', 'category', 'priority', 'status', 'last_activity_at'])
            ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                $like = '%'.$normalized.'%';
                $builder->where(function (Builder $search) use ($like): void {
                    // Intentionally excludes support message bodies and attachments.
                    $search->where('subject', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('priority', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhereHas('tenant', fn (Builder $tenant) => $tenant
                            ->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like));
                });
            })
            ->latest('last_activity_at')
            ->limit($normalized === '' ? 3 : 7)
            ->get();

        return $rows->map(function (TenantSupportTicket $ticket) use ($normalized): array {
            $tenantName = (string) ($ticket->tenant?->name ?? 'Workspace');
            $state = Str::headline((string) $ticket->status);
            $priority = Str::headline((string) $ticket->priority);

            return $this->result([
                'type' => 'ticket',
                'subtype' => 'tenant_support_ticket',
                'title' => (string) $ticket->subject,
                'subtitle' => $tenantName.' • '.$state.' • '.$priority,
                'url' => route('landlord.support-tickets.show', ['ticket' => $ticket->id]),
                'badge' => 'Ticket',
                'score' => $this->matchScore($normalized, [
                    $ticket->subject,
                    $tenantName,
                    $ticket->tenant?->slug,
                    $ticket->category,
                    $ticket->priority,
                    $ticket->status,
                ], 300),
                'icon' => 'lifebuoy',
                'meta' => [
                    'ticket_id' => (int) $ticket->id,
                    'tenant_id' => (int) $ticket->tenant_id,
                    'control_plane_only' => true,
                ],
            ]);
        })->all();
    }
}
