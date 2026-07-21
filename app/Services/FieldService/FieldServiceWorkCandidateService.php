<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceWorkCandidate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FieldServiceWorkCandidateService
{
    /** @return Collection<int,FieldServiceWorkCandidate> */
    public function pending(Tenant $tenant): Collection
    {
        $this->syncFromFinancialDocuments($tenant);

        return FieldServiceWorkCandidate::query()->forTenantId((int) $tenant->id)
            ->where('status', 'pending')->with('financialDocument.customer:id,tenant_id,first_name,last_name,email,phone')
            ->latest('updated_at')->limit(200)->get();
    }

    public function syncFromFinancialDocuments(Tenant $tenant): int
    {
        $created = 0;
        FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
            ->whereNull('field_service_job_id')
            ->whereIn('document_type', ['estimate', 'invoice'])
            ->where(function ($query): void {
                $query->whereNull('status')->orWhereNotIn(DB::raw('lower(status)'), ['paid', 'void', 'voided', 'closed', 'deleted', 'canceled', 'cancelled']);
            })
            ->with('customer:id,tenant_id,first_name,last_name')
            ->orderBy('id')->chunkById(200, function ($documents) use ($tenant, &$created): void {
                foreach ($documents as $document) {
                    $candidate = FieldServiceWorkCandidate::query()->firstOrNew([
                        'tenant_id' => (int) $tenant->id,
                        'source' => (string) $document->source,
                        'source_type' => (string) $document->document_type,
                        'external_id' => (string) $document->external_id,
                    ]);
                    if ($candidate->exists && $candidate->status !== 'pending') {
                        continue;
                    }
                    $wasNew = ! $candidate->exists;
                    $customer = trim(implode(' ', array_filter([$document->customer?->first_name, $document->customer?->last_name])));
                    $candidate->fill([
                        'field_service_financial_document_id' => (int) $document->id,
                        'status' => 'pending',
                        'title' => ucfirst((string) $document->document_type).' '.($document->document_number ?: $document->external_id),
                        'customer_name' => $customer ?: null,
                        'amount' => $document->total_amount,
                        'balance' => $document->balance,
                        'description' => $document->customer_memo ?: $document->private_note,
                        'payload' => ['document_number' => $document->document_number, 'transaction_date' => $document->transaction_date?->toDateString(), 'due_date' => $document->due_date?->toDateString()],
                    ])->save();
                    $created += $wasNew ? 1 : 0;
                }
            });

        return $created;
    }

    public function createJob(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate): FieldServiceJob
    {
        $this->assertPending($tenant, $candidate);

        return DB::transaction(function () use ($tenant, $actor, $candidate): FieldServiceJob {
            $candidate->refresh();
            $this->assertPending($tenant, $candidate);
            $document = $candidate->financialDocument;
            $job = FieldServiceJob::query()->create([
                'tenant_id' => (int) $tenant->id,
                'marketing_profile_id' => $document?->marketing_profile_id,
                'title' => $candidate->title ?: 'Potential work',
                'status' => 'open',
                'operational_status' => 'needs_details',
                'status_source' => 'system',
                'customer_name' => $candidate->customer_name,
                'description' => $candidate->description,
                'external_source' => $candidate->source,
                'external_id' => $candidate->external_id,
                'last_financial_activity_at' => now(),
                'metadata' => ['candidate_id' => (int) $candidate->id, 'source_type' => $candidate->source_type],
            ]);
            $document?->forceFill(['field_service_job_id' => (int) $job->id])->save();
            $candidate->forceFill(['status' => 'converted', 'converted_job_id' => (int) $job->id, 'reviewed_by_user_id' => (int) $actor->id, 'reviewed_at' => now()])->save();

            return $job;
        });
    }

    public function link(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate, FieldServiceJob $job): FieldServiceJob
    {
        $this->assertPending($tenant, $candidate);
        abort_unless((int) $job->tenant_id === (int) $tenant->id, 404);

        return DB::transaction(function () use ($actor, $candidate, $job): FieldServiceJob {
            $candidate->financialDocument?->forceFill(['field_service_job_id' => (int) $job->id])->save();
            $candidate->forceFill(['status' => 'linked', 'converted_job_id' => (int) $job->id, 'reviewed_by_user_id' => (int) $actor->id, 'reviewed_at' => now()])->save();

            return $job;
        });
    }

    public function dismiss(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate): void
    {
        $this->assertPending($tenant, $candidate);
        $candidate->forceFill(['status' => 'dismissed', 'reviewed_by_user_id' => (int) $actor->id, 'reviewed_at' => now()])->save();
    }

    protected function assertPending(Tenant $tenant, FieldServiceWorkCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        abort_unless($candidate->status === 'pending', 409, 'This work candidate was already reviewed.');
    }
}
