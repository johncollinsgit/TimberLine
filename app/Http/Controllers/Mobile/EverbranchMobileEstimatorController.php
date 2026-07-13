<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceEstimate;
use App\Models\FieldServicePriceBookCandidate;
use App\Models\FieldServicePriceBookItem;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceEstimateService;
use App\Services\FieldService\PriceBookCandidateService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EverbranchMobileEstimatorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$tenant] = $this->authorizeEstimator($request);
        $search = trim((string) $request->query('q', ''));

        return response()->json([
            'catalog' => FieldServicePriceBookItem::query()->forTenantId((int) $tenant->id)
                ->where('source', 'curated')->where('active', true)
                ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')))
                ->orderBy('name')->limit(100)->get()->map(fn (FieldServicePriceBookItem $item): array => $this->item($item))->values(),
            'candidates' => FieldServicePriceBookCandidate::query()->forTenantId((int) $tenant->id)
                ->where('status', 'suggested')->orderByDesc('sample_count')->limit(100)->get()
                ->map(fn (FieldServicePriceBookCandidate $candidate): array => [
                    'id' => (int) $candidate->id,
                    'name' => (string) $candidate->name,
                    'samples' => (int) $candidate->sample_count,
                    'median' => (float) $candidate->median_unit_price,
                    'minimum' => (float) $candidate->minimum_unit_price,
                    'maximum' => (float) $candidate->maximum_unit_price,
                    'recent' => (float) $candidate->recent_unit_price,
                    'high_variance' => (bool) $candidate->high_variance,
                ])->values(),
            'drafts' => FieldServiceEstimate::query()->forTenantId((int) $tenant->id)
                ->withCount('lines')->latest()->limit(100)->get()->map(fn (FieldServiceEstimate $estimate): array => $this->summary($estimate))->values(),
            'customers' => MarketingProfile::query()->forTenantId((int) $tenant->id)->orderBy('first_name')->limit(300)->get()
                ->map(fn (MarketingProfile $profile): array => ['id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: 'Customer')])->values(),
            'jobs' => $tenant->fieldServiceJobs()->whereNotIn('operational_status', ['history'])->latest()->limit(300)->get(['id', 'title'])
                ->map(fn ($job): array => ['id' => (int) $job->id, 'title' => (string) $job->title])->values(),
        ]);
    }

    public function show(Request $request, string $tenant, FieldServiceEstimate $estimate): JsonResponse
    {
        [$tenantModel] = $this->authorizeEstimator($request);
        abort_unless((int) $estimate->tenant_id === (int) $tenantModel->id, 404);

        return response()->json(['draft' => $this->draft($estimate->load(['customer', 'job', 'lines']))]);
    }

    public function store(Request $request, FieldServiceEstimateService $service): JsonResponse
    {
        [$tenant, $user] = $this->authorizeEstimator($request);
        $validated = $this->validateDraft($request, $tenant);
        $estimate = FieldServiceEstimate::query()->create([
            'tenant_id' => (int) $tenant->id,
            'marketing_profile_id' => $validated['marketing_profile_id'] ?? null,
            'field_service_job_id' => $validated['field_service_job_id'] ?? null,
            'created_by_user_id' => (int) $user->id,
            'estimate_number' => 'EST-'.now()->format('Y').'-'.strtoupper(substr((string) Str::ulid(), -6)),
            'status' => 'draft',
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'tax_amount' => $validated['tax_amount'] ?? 0,
        ]);

        return response()->json(['draft' => $this->draft($service->saveLines($tenant, $estimate, $validated['lines']))], 201);
    }

    public function update(Request $request, string $tenant, FieldServiceEstimate $estimate, FieldServiceEstimateService $service): JsonResponse
    {
        [$tenantModel] = $this->authorizeEstimator($request);
        abort_unless((int) $estimate->tenant_id === (int) $tenantModel->id, 404);
        $validated = $this->validateDraft($request, $tenantModel);
        $estimate->forceFill([
            'marketing_profile_id' => $validated['marketing_profile_id'] ?? null,
            'field_service_job_id' => $validated['field_service_job_id'] ?? null,
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'tax_amount' => $validated['tax_amount'] ?? 0,
        ])->save();

        return response()->json(['draft' => $this->draft($service->saveLines($tenantModel, $estimate, $validated['lines']))]);
    }

    public function duplicate(Request $request, string $tenant, FieldServiceEstimate $estimate, FieldServiceEstimateService $service): JsonResponse
    {
        [$tenantModel, $user] = $this->authorizeEstimator($request);
        abort_unless((int) $estimate->tenant_id === (int) $tenantModel->id, 404);
        $estimate->load('lines');
        $copy = $estimate->replicate(['estimate_number', 'status', 'subtotal', 'total_amount']);
        $copy->estimate_number = 'EST-'.now()->format('Y').'-'.strtoupper(substr((string) Str::ulid(), -6));
        $copy->status = 'draft';
        $copy->created_by_user_id = (int) $user->id;
        $copy->save();
        $copy = $service->saveLines($tenantModel, $copy, $estimate->lines->map(fn ($line): array => [
            'price_book_item_id' => $line->field_service_price_book_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'source_snapshot' => $line->source_snapshot,
        ])->all());

        return response()->json(['draft' => $this->draft($copy)], 201);
    }

    public function approveCandidate(Request $request, string $tenant, FieldServicePriceBookCandidate $candidate, PriceBookCandidateService $service): JsonResponse
    {
        [$tenantModel, $user] = $this->authorizeEstimator($request);
        abort_unless((int) $candidate->tenant_id === (int) $tenantModel->id && $candidate->status === 'suggested', 404);
        $validated = $request->validate(['unit_price' => ['required', 'numeric', 'min:0', 'max:1000000']]);

        return response()->json(['item' => $this->item($service->approve($tenantModel, $candidate, $user, (float) $validated['unit_price']))], 201);
    }

    /** @return array{0:Tenant,1:User} */
    protected function authorizeEstimator(Request $request): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->is_active !== false, 401);
        abort_unless(app(TenantFinancialAccess::class)->allows($user, $tenant), 403);
        abort_unless(collect(app(TenantMobileModuleRegistry::class)->manifest((int) $tenant->id))->contains('module_key', 'estimator'), 404);

        return [$tenant, $user];
    }

    /** @return array<string,mixed> */
    protected function validateDraft(Request $request, Tenant $tenant): array
    {
        $validated = $request->validate([
            'marketing_profile_id' => ['nullable', 'integer'],
            'field_service_job_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.price_book_item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        if (isset($validated['marketing_profile_id'])) {
            abort_unless(MarketingProfile::query()->forTenantId((int) $tenant->id)->whereKey($validated['marketing_profile_id'])->exists(), 422);
        }
        if (isset($validated['field_service_job_id'])) {
            abort_unless($tenant->fieldServiceJobs()->whereKey($validated['field_service_job_id'])->exists(), 422);
        }

        return $validated;
    }

    /** @return array<string,mixed> */
    protected function item(FieldServicePriceBookItem $item): array
    {
        return ['id' => (int) $item->id, 'name' => (string) $item->name, 'description' => $item->description, 'unit_price' => (float) $item->unit_price];
    }

    /** @return array<string,mixed> */
    protected function summary(FieldServiceEstimate $estimate): array
    {
        return ['id' => (int) $estimate->id, 'number' => $estimate->estimate_number, 'title' => $estimate->title ?: $estimate->estimate_number, 'status' => $estimate->status, 'line_count' => (int) ($estimate->lines_count ?? 0), 'total' => (float) $estimate->total_amount, 'updated_at' => $estimate->updated_at?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    protected function draft(FieldServiceEstimate $estimate): array
    {
        return [
            ...$this->summary($estimate),
            'customer' => $estimate->customer ? ['id' => (int) $estimate->customer->id, 'name' => trim($estimate->customer->first_name.' '.$estimate->customer->last_name)] : null,
            'job' => $estimate->job ? ['id' => (int) $estimate->job->id, 'title' => (string) $estimate->job->title] : null,
            'notes' => $estimate->notes,
            'subtotal' => (float) $estimate->subtotal,
            'discount' => (float) $estimate->discount_amount,
            'tax' => (float) $estimate->tax_amount,
            'lines' => $estimate->lines->map(fn ($line): array => ['id' => (int) $line->id, 'price_book_item_id' => $line->field_service_price_book_item_id, 'description' => $line->description, 'quantity' => (float) $line->quantity, 'unit_price' => (float) $line->unit_price, 'total' => (float) $line->line_total])->values(),
            'print_url' => route('estimator.show', ['tenant' => Tenant::query()->findOrFail((int) $estimate->tenant_id), 'estimate' => $estimate->id], false),
        ];
    }
}
