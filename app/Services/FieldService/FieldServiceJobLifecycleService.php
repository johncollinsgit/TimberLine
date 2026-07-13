<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FieldServiceJobLifecycleService
{
    /** @return array<string,int> */
    public function reconcileTenant(Tenant $tenant, bool $dryRun = false): array
    {
        $summary = ['active' => 0, 'needs_details' => 0, 'blocked' => 0, 'quote' => 0, 'complete' => 0, 'history' => 0, 'unchanged' => 0, 'updated' => 0];

        FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->with('financialDocuments:id,tenant_id,field_service_job_id,document_type,status,transaction_date,total_amount,balance')
            ->orderBy('id')
            ->chunkById(200, function (Collection $jobs) use (&$summary, $dryRun): void {
                foreach ($jobs as $job) {
                    $resolution = $this->resolve($job);
                    $summary[$resolution['status']] = ($summary[$resolution['status']] ?? 0) + 1;
                    $changed = $job->operational_status !== $resolution['status']
                        || optional($job->last_financial_activity_at)?->toDateString() !== $resolution['last_financial_activity_at']?->toDateString()
                        || (bool) $job->archived_at !== ($resolution['status'] === 'history');
                    $summary[$changed ? 'updated' : 'unchanged']++;
                    if ($dryRun || ! $changed || $job->status_source === 'manual') {
                        continue;
                    }
                    $job->forceFill([
                        'operational_status' => $resolution['status'],
                        'status_source' => $job->external_source === 'quickbooks' ? 'quickbooks' : 'system',
                        'last_financial_activity_at' => $resolution['last_financial_activity_at'],
                        'archived_at' => $resolution['status'] === 'history' ? ($job->archived_at ?? now()) : null,
                        'completed_at' => $resolution['status'] === 'complete' ? ($job->completed_at ?? $resolution['last_financial_activity_at'] ?? now()) : $job->completed_at,
                    ])->save();
                }
            });

        return $summary;
    }

    /** @return array{status:string,last_financial_activity_at:?Carbon,reason:string} */
    public function resolve(FieldServiceJob $job): array
    {
        if ($job->status_source === 'manual') {
            return [
                'status' => $job->operational_status ?: $this->manualStatus($job),
                'last_financial_activity_at' => $job->last_financial_activity_at,
                'reason' => 'manual_override',
            ];
        }

        $documents = $job->relationLoaded('financialDocuments')
            ? $job->financialDocuments
            : FieldServiceFinancialDocument::query()->forTenantId((int) $job->tenant_id)->where('field_service_job_id', $job->id)->get();
        if ($documents->isEmpty()) {
            return [
                'status' => $this->manualStatus($job),
                'last_financial_activity_at' => $job->last_financial_activity_at,
                'reason' => 'workspace_job',
            ];
        }

        $latest = $documents->pluck('transaction_date')->filter()->sortDesc()->first();
        $latestAt = $latest ? Carbon::parse($latest)->startOfDay() : null;
        if ($latestAt?->lt(now()->subYear()->startOfDay())) {
            return ['status' => 'history', 'last_financial_activity_at' => $latestAt, 'reason' => 'older_than_one_year'];
        }

        $estimates = $documents->where('document_type', 'estimate');
        $invoices = $documents->where('document_type', 'invoice');
        $accepted = $estimates->contains(fn ($document): bool => in_array(strtolower((string) $document->status), ['accepted', 'converted'], true))
            || $invoices->isNotEmpty();
        $pending = $estimates->contains(fn ($document): bool => strtolower((string) $document->status) === 'pending');
        $rejected = $estimates->isNotEmpty() && $estimates->every(fn ($document): bool => in_array(strtolower((string) $document->status), ['closed', 'rejected'], true));
        if ($rejected) {
            return ['status' => 'history', 'last_financial_activity_at' => $latestAt, 'reason' => 'closed_or_rejected'];
        }
        if (! $accepted && $pending) {
            return ['status' => 'quote', 'last_financial_activity_at' => $latestAt, 'reason' => 'pending_estimate'];
        }

        $invoiceTotal = (float) $invoices->sum('total_amount');
        $invoiceBalance = (float) $invoices->sum('balance');
        $estimateTotal = (float) $estimates->whereIn('status', ['accepted', 'converted'])->sum('total_amount');
        $unfinished = $accepted && ($invoices->isEmpty() || $invoiceBalance > 0 || ($estimateTotal > 0 && $invoiceTotal + 0.01 < $estimateTotal));
        if ($unfinished) {
            $missingDetails = blank($job->scheduled_for) || blank($job->assigned_user_id)
                || blank($job->service_address_line_1);

            return [
                'status' => $missingDetails ? 'needs_details' : 'active',
                'last_financial_activity_at' => $latestAt,
                'reason' => $invoiceBalance > 0 ? 'unpaid_invoice' : ($invoices->isEmpty() ? 'accepted_not_invoiced' : 'not_fully_invoiced'),
            ];
        }

        if ($invoices->isNotEmpty() && $invoiceBalance <= 0) {
            return ['status' => 'complete', 'last_financial_activity_at' => $latestAt, 'reason' => 'paid_and_fully_invoiced'];
        }

        return ['status' => $this->manualStatus($job), 'last_financial_activity_at' => $latestAt, 'reason' => 'manual_fallback'];
    }

    public function setManualStatus(FieldServiceJob $job, string $status): FieldServiceJob
    {
        $normalized = match ($status) {
            'done', 'complete' => 'complete',
            'quoted', 'quote' => 'quote',
            'blocked' => 'blocked',
            'scheduled', 'open', 'in_progress', 'active' => 'active',
            default => $status,
        };

        $job->forceFill([
            'operational_status' => $normalized,
            'status_source' => 'manual',
            'completed_at' => $normalized === 'complete' ? ($job->completed_at ?? now()) : null,
            'archived_at' => $normalized === 'history' ? ($job->archived_at ?? now()) : null,
        ])->save();

        return $job;
    }

    protected function manualStatus(FieldServiceJob $job): string
    {
        return match (strtolower((string) $job->status)) {
            'done', 'complete', 'completed' => 'complete',
            'quoted', 'quote', 'estimate', 'estimated' => 'quote',
            'cancelled', 'canceled', 'closed' => 'history',
            'blocked' => 'blocked',
            default => 'active',
        };
    }
}
