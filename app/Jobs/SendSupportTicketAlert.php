<?php

namespace App\Jobs;

use App\Models\OperatorAlertLog;
use App\Models\TenantSupportTicket;
use App\Services\Operations\OperatorAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSupportTicketAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly int $ticketId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(OperatorAlertService $alerts): void
    {
        $ticket = TenantSupportTicket::withoutGlobalScopes()->with('tenant')->find($this->ticketId);
        if (! $ticket || ! $ticket->tenant) {
            return;
        }

        $key = 'support-ticket:'.$ticket->id;
        $existing = OperatorAlertLog::query()->where('dedupe_key', $key)->first();
        if ($existing) {
            $alerts->retryFailed((int) $existing->id);
        } else {
            // Keep free-text questions, transcripts, and credentials out of SMS.
            $host = trim((string) config('tenancy.landlord.primary_host', ''));
            $url = $host !== '' ? 'https://'.$host.'/landlord/tickets/'.$ticket->id : route('landlord.support-tickets.show', $ticket->id);
            $alerts->notify('support_ticket.created', 'Everbranch: '.strtoupper($ticket->priority).' support ticket #'.$ticket->id.' needs review. '.$url, [
                'dedupe_key' => $key,
                'tenant_id' => (int) $ticket->tenant_id,
                'target_type' => 'tenant_support_ticket',
                'target_id' => (int) $ticket->id,
            ]);
        }

        $log = OperatorAlertLog::query()->where('dedupe_key', $key)->first();
        if ($log?->status === 'failed' && ($log->metadata['retryable'] ?? false)) {
            throw new \RuntimeException('Operator support notification was rejected temporarily.');
        }
    }
}
