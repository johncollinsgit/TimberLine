<?php

namespace App\Services\Search\Providers;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class FieldServiceSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function __construct(protected FieldServiceAccessService $fieldServiceAccess) {}

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user || ! method_exists($user, 'accessibleTenantIds')) {
            return [];
        }

        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        if ($tenantId === null
            || ! in_array($tenantId, $user->accessibleTenantIds(), true)
            || ! Schema::hasTable('field_service_jobs')) {
            return [];
        }

        $membership = $user->tenants()->whereKey($tenantId)->first();
        $tenantRole = strtolower(trim((string) ($membership?->pivot->role ?? '')));
        $includeFinancial = in_array($tenantRole, ['admin', 'owner', 'tenant_owner'], true);
        $normalized = trim($query);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $jobQuery = FieldServiceJob::query()
            ->forTenantId($tenantId)
            ->with([
                'notes' => fn ($notes) => $this->visibleNotes($notes, $includeFinancial),
                ...($includeFinancial ? ['financialDocuments.lines'] : []),
            ]);
        $this->fieldServiceAccess->scopeVisibleJobs($jobQuery, $user, $tenant);
        $rows = $jobQuery
            ->when($normalized !== '', function (Builder $builder) use ($normalized, $includeFinancial): void {
                $like = '%'.$normalized.'%';
                $builder->where(function (Builder $query) use ($like, $includeFinancial): void {
                    $query->where('title', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('lock_box_code', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('service_address_line_1', 'like', $like)
                        ->orWhere('service_city', 'like', $like)
                        ->orWhereHas('notes', fn (Builder $notes) => $this->visibleNotes($notes, $includeFinancial)->where('body', 'like', $like));
                    if ($includeFinancial) {
                        $query->orWhereHas('financialDocuments', function (Builder $documents) use ($like): void {
                            $documents->where('private_note', 'like', $like)
                                ->orWhere('customer_memo', 'like', $like)
                                ->orWhereHas('lines', fn (Builder $lines) => $lines
                                    ->where('item_name', 'like', $like)
                                    ->orWhere('description', 'like', $like));
                        });
                    }
                });
            })
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $results = $rows->map(function (FieldServiceJob $job) use ($normalized, $includeFinancial): array {
            $address = trim(implode(', ', array_filter([
                $job->service_address_line_1,
                $job->service_city,
                $job->service_state,
            ])));
            $latestNote = optional($job->notes->sortByDesc('noted_at')->first())->body;
            $financialContext = $includeFinancial ? $job->financialDocuments
                ->flatMap(fn ($document) => collect([
                    $document->customer_memo,
                    $document->private_note,
                    ...$document->lines->pluck('description')->all(),
                ]))
                ->filter()
                ->first(fn (mixed $value): bool => $normalized !== '' && str_contains(strtolower((string) $value), strtolower($normalized))) : null;

            return $this->result([
                'type' => 'work',
                'subtype' => 'field_service_job',
                'title' => (string) $job->title,
                'subtitle' => trim(implode(' • ', array_filter([
                    (string) ($job->customer_name ?: 'Service job'),
                    $job->customer_phone,
                    $address,
                    $latestNote ? str($latestNote)->limit(80)->toString() : null,
                    $financialContext ? str((string) $financialContext)->limit(80)->toString() : null,
                ]))),
                'url' => route('field-service.jobs.show', ['job' => $job->id]),
                'badge' => 'Job',
                'score' => $this->matchScore($normalized, [
                    $job->title,
                    $job->customer_name,
                    $job->customer_phone,
                    $job->service_address_line_1,
                    $job->lock_box_code,
                    $latestNote,
                    $financialContext,
                ], 300),
                'icon' => 'briefcase-business',
                'meta' => [
                    'job_id' => (int) $job->id,
                    'destination' => 'work',
                    'kind' => 'jobs',
                ],
            ]);
        });

        if ($includeFinancial && Schema::hasTable('field_service_financial_documents')) {
            $documents = FieldServiceFinancialDocument::query()
                ->forTenantId($tenantId)
                ->whereNull('field_service_job_id')
                ->with(['customer', 'lines'])
                ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                    $like = '%'.$normalized.'%';
                    $builder->where(function (Builder $query) use ($like): void {
                        $query->where('document_number', 'like', $like)
                            ->orWhere('private_note', 'like', $like)
                            ->orWhere('customer_memo', 'like', $like)
                            ->orWhereHas('customer', fn (Builder $customer) => $customer
                                ->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like))
                            ->orWhereHas('lines', fn (Builder $lines) => $lines
                                ->where('item_name', 'like', $like)
                                ->orWhere('description', 'like', $like));
                    });
                })
                ->latest('transaction_date')
                ->limit(6)
                ->get();

            $tenantSlug = (string) $membership?->slug;
            $results = $results->concat($documents->map(function (FieldServiceFinancialDocument $document) use ($normalized, $tenantSlug): array {
                $customerName = trim((string) ($document->customer?->first_name.' '.$document->customer?->last_name));
                $matchedContext = collect([
                    $document->customer_memo,
                    $document->private_note,
                    ...$document->lines->pluck('description')->all(),
                    ...$document->lines->pluck('item_name')->all(),
                ])->filter()->first(fn (mixed $value): bool => $normalized !== ''
                    && str_contains(strtolower((string) $value), strtolower($normalized)));

                return $this->result([
                    'type' => 'accounting',
                    'subtype' => 'quickbooks_document',
                    'title' => ucfirst((string) $document->document_type).' '.($document->document_number ?: $document->external_id),
                    'subtitle' => trim(implode(' • ', array_filter([
                        $customerName ?: 'QuickBooks customer',
                        optional($document->transaction_date)->format('M j, Y'),
                        $matchedContext ? str((string) $matchedContext)->limit(90)->toString() : null,
                    ]))),
                    'url' => route('integrations.quickbooks.documents.show', ['tenant' => $tenantSlug, 'document' => $document->id]),
                    'badge' => 'QuickBooks',
                    'score' => $this->matchScore($normalized, [
                        $document->document_number,
                        $customerName,
                        $matchedContext,
                    ], 260),
                    'icon' => 'file-text',
                    'meta' => [
                        'document_id' => (int) $document->id,
                        'destination' => 'quickbooks',
                        'kind' => 'financial_document',
                    ],
                ]);
            }));
        }

        if (Schema::hasTable('workspace_assets')) {
            $tenantSlug = (string) $membership?->slug;
            $visibleJobIds = null;
            if (! $includeFinancial) {
                $visibleJobs = FieldServiceJob::query()->forTenantId($tenantId);
                $this->fieldServiceAccess->scopeVisibleJobs($visibleJobs, $user, $tenant);
                $visibleJobIds = $visibleJobs->pluck('id');
            }
            $assets = WorkspaceAsset::query()->forTenantId($tenantId)
                ->with('jobs:id,title')
                ->when(! $includeFinancial, fn (Builder $builder) => $builder->where('visibility', 'team'))
                ->when($visibleJobIds !== null, fn (Builder $builder) => $builder->where(fn (Builder $visible) => $visible
                    ->whereDoesntHave('jobs')->orWhereHas('jobs', fn (Builder $jobs) => $jobs->whereIn('field_service_jobs.id', $visibleJobIds))))
                ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                    $like = '%'.$normalized.'%';
                    $builder->where(function (Builder $search) use ($like): void {
                        $search->where('file_name', 'like', $like)
                            ->orWhere('caption', 'like', $like)
                            ->orWhere('search_text', 'like', $like)
                            ->orWhereHas('jobs', fn (Builder $jobs) => $jobs->where('title', 'like', $like));
                    });
                })
                ->latest()->limit(6)->get();
            $results = $results->concat($assets->map(function (WorkspaceAsset $asset) use ($normalized, $tenantSlug): array {
                $jobs = $asset->jobs->pluck('title')->take(2)->implode(', ');

                return $this->result([
                    'type' => 'document',
                    'subtype' => 'workspace_asset',
                    'title' => (string) $asset->file_name,
                    'subtitle' => trim(implode(' • ', array_filter([$asset->caption, $jobs]))),
                    'url' => route('documents.download', ['tenant' => $tenantSlug, 'asset' => $asset->id]),
                    'badge' => $asset->visibility === 'owner' ? 'Owner document' : 'Document',
                    'score' => $this->matchScore($normalized, [$asset->file_name, $asset->caption, $asset->search_text, $jobs], 240),
                    'icon' => str_starts_with((string) $asset->mime_type, 'image/') ? 'image' : 'file-text',
                    'meta' => ['asset_id' => (int) $asset->id, 'destination' => 'documents', 'kind' => 'workspace_asset'],
                ]);
            }));
        }

        return $results->sortByDesc('score')->take(8)->values()->all();
    }

    protected function visibleNotes(mixed $query, bool $includeOwner): mixed
    {
        if ($includeOwner) {
            return $query;
        }

        return $query->where(function ($visibility): void {
            $visibility->whereNull('metadata->visibility')
                ->orWhere('metadata->visibility', '!=', 'owner');
        });
    }
}
