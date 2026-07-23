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
        return $this->forStatus($tenant, 'active');
    }

    /** @return Collection<int,FieldServiceWorkCandidate> */
    public function forStatus(Tenant $tenant, string $status): Collection
    {
        $this->syncFromFinancialDocuments($tenant);

        return FieldServiceWorkCandidate::query()->forTenantId((int) $tenant->id)
            ->where('status', $status === 'archived' ? 'dismissed' : 'pending')
            ->with(['financialDocument.customer:id,tenant_id,first_name,last_name,email,phone,address_line_1,address_line_2,city,state,postal_code,country', 'financialDocument.lines:id,tenant_id,field_service_financial_document_id,description,sort_order'])
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
            ->with(['customer:id,tenant_id,first_name,last_name,email,phone,address_line_1,address_line_2,city,state,postal_code,country', 'lines:id,tenant_id,field_service_financial_document_id,description,sort_order'])
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
                    $address = $this->addressForDocument($document);
                    $defaults = [
                        'field_service_financial_document_id' => (int) $document->id,
                        'status' => 'pending',
                        'title' => $customer !== '' ? $customer.' job' : 'New job',
                        'customer_name' => $customer ?: null,
                        'customer_email' => $document->customer?->email,
                        'customer_phone' => $document->customer?->phone,
                        'amount' => $document->total_amount,
                        'balance' => $document->balance,
                        'description' => $document->customer_memo ?: $document->lines->pluck('description')->filter()->take(5)->implode("\n"),
                        ...$address,
                        'payload' => ['document_number' => $document->document_number, 'transaction_date' => $document->transaction_date?->toDateString(), 'due_date' => $document->due_date?->toDateString()],
                    ];
                    if ($wasNew) {
                        $candidate->fill($defaults)->save();
                    } else {
                        $candidate->field_service_financial_document_id = (int) $document->id;
                        foreach (['customer_email', 'customer_phone', 'service_address_line_1', 'service_address_line_2', 'service_city', 'service_state', 'service_postal_code', 'service_country'] as $key) {
                            if (blank($candidate->{$key}) && filled($defaults[$key] ?? null)) {
                                $candidate->{$key} = $defaults[$key];
                            }
                        }
                        $candidate->save();
                    }
                    $created += $wasNew ? 1 : 0;
                }
            });

        return $created;
    }

    public function publish(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate): FieldServiceJob
    {
        $this->assertPending($tenant, $candidate);

        return DB::transaction(function () use ($tenant, $actor, $candidate): FieldServiceJob {
            $candidate->refresh();
            $this->assertPending($tenant, $candidate);
            $document = $candidate->financialDocument;
            $job = FieldServiceJob::query()->create([
                'tenant_id' => (int) $tenant->id,
                'marketing_profile_id' => $document?->marketing_profile_id,
                'assigned_user_id' => $candidate->assigned_user_id,
                'title' => $candidate->title ?: 'New job',
                'status' => 'open',
                'operational_status' => 'needs_details',
                'status_source' => 'system',
                'customer_name' => $candidate->customer_name,
                'customer_email' => $candidate->customer_email,
                'customer_phone' => $candidate->customer_phone,
                'project_manager_name' => $candidate->project_manager_name,
                'project_manager_company' => $candidate->project_manager_company,
                'project_manager_phone' => $candidate->project_manager_phone,
                'project_manager_email' => $candidate->project_manager_email,
                'description' => $candidate->description,
                'priority' => $candidate->priority ?: 'normal',
                'scheduled_for' => $candidate->scheduled_for,
                'scheduled_end_at' => $candidate->scheduled_end_at,
                'service_address_line_1' => $candidate->service_address_line_1,
                'service_address_line_2' => $candidate->service_address_line_2,
                'service_city' => $candidate->service_city,
                'service_state' => $candidate->service_state,
                'service_postal_code' => $candidate->service_postal_code,
                'service_country' => $candidate->service_country,
                'external_source' => $candidate->source,
                'external_id' => $candidate->external_id,
                'last_financial_activity_at' => now(),
                'metadata' => ['candidate_id' => (int) $candidate->id, 'source_type' => $candidate->source_type],
            ]);
            $document?->forceFill(['field_service_job_id' => (int) $job->id])->save();
            $participantIds = $tenant->users()->whereIn('users.id', (array) $candidate->participant_user_ids)->pluck('users.id')->map(fn ($id): int => (int) $id);
            $job->participants()->sync($participantIds->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id, 'role' => 'member', 'following' => true]])->all());
            $candidate->forceFill(['status' => 'converted', 'converted_job_id' => (int) $job->id, 'reviewed_by_user_id' => (int) $actor->id, 'reviewed_at' => now()])->save();

            return $job;
        });
    }

    public function createJob(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate): FieldServiceJob
    {
        return $this->publish($tenant, $actor, $candidate);
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
        $this->archive($tenant, $actor, $candidate);
    }

    /** @param array<string,mixed> $attributes */
    public function update(Tenant $tenant, FieldServiceWorkCandidate $candidate, array $attributes): FieldServiceWorkCandidate
    {
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        abort_unless(in_array($candidate->status, ['pending', 'dismissed'], true), 409, 'This job draft has already been published or linked.');
        $candidate->fill($attributes)->save();

        return $candidate->refresh();
    }

    public function archive(Tenant $tenant, User $actor, FieldServiceWorkCandidate $candidate): void
    {
        $this->assertPending($tenant, $candidate);
        $candidate->forceFill(['status' => 'dismissed', 'archived_at' => now(), 'reviewed_by_user_id' => (int) $actor->id, 'reviewed_at' => now()])->save();
    }

    public function restore(Tenant $tenant, FieldServiceWorkCandidate $candidate): FieldServiceWorkCandidate
    {
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        abort_unless($candidate->status === 'dismissed', 409, 'Only archived job drafts can be restored.');
        $candidate->forceFill(['status' => 'pending', 'archived_at' => null, 'reviewed_by_user_id' => null, 'reviewed_at' => null])->save();

        return $candidate->refresh();
    }

    protected function assertPending(Tenant $tenant, FieldServiceWorkCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        abort_unless($candidate->status === 'pending', 409, 'This job draft was already reviewed.');
    }

    /** @return array<string,?string> */
    protected function addressForDocument(FieldServiceFinancialDocument $document): array
    {
        $stored = (array) data_get($document->metadata, 'quickbooks.service_address', []);
        $customer = $document->customer;

        return [
            'service_address_line_1' => $stored['line_1'] ?? $customer?->address_line_1,
            'service_address_line_2' => $stored['line_2'] ?? $customer?->address_line_2,
            'service_city' => $stored['city'] ?? $customer?->city,
            'service_state' => $stored['state'] ?? $customer?->state,
            'service_postal_code' => $stored['postal_code'] ?? $customer?->postal_code,
            'service_country' => $stored['country'] ?? $customer?->country,
        ];
    }
}
