<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceEstimate;
use App\Models\FieldServicePriceBookCandidate;
use App\Models\FieldServicePriceBookItem;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceEstimateService;
use App\Services\FieldService\PriceBookCandidateService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FieldServiceEstimatorController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantFinancialAccess $access): View
    {
        $this->authorizeOwner($request, $tenant, $access);

        return view('estimator.index', [
            'tenant' => $tenant,
            'candidates' => FieldServicePriceBookCandidate::query()->forTenantId((int) $tenant->id)->latest('sample_count')->limit(100)->get(),
            'items' => FieldServicePriceBookItem::query()->forTenantId((int) $tenant->id)->where('source', 'curated')->where('active', true)->orderBy('name')->get(),
            'estimates' => FieldServiceEstimate::query()->forTenantId((int) $tenant->id)->withCount('lines')->latest()->limit(50)->get(),
            'customers' => MarketingProfile::query()->forTenantId((int) $tenant->id)->orderBy('first_name')->limit(200)->get(['id', 'first_name', 'last_name', 'email']),
            'jobs' => $tenant->fieldServiceJobs()->latest()->limit(200)->get(['id', 'title']),
        ]);
    }

    public function rebuild(Request $request, Tenant $tenant, TenantFinancialAccess $access, PriceBookCandidateService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        $result = $service->rebuild($tenant);

        return back()->with('status', $result['candidates'].' price candidates refreshed.');
    }

    public function approve(Request $request, Tenant $tenant, FieldServicePriceBookCandidate $candidate, TenantFinancialAccess $access, PriceBookCandidateService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        $validated = $request->validate(['unit_price' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        $service->approve($tenant, $candidate, $request->user(), (float) $validated['unit_price']);

        return back()->with('status', 'Price-book item approved.');
    }

    public function archiveCandidate(Request $request, Tenant $tenant, FieldServicePriceBookCandidate $candidate, TenantFinancialAccess $access): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        $candidate->forceFill(['status' => 'archived', 'reviewed_by_user_id' => (int) $request->user()->id, 'reviewed_at' => now()])->save();

        return back()->with('status', 'Candidate archived.');
    }

    public function storeItem(Request $request, Tenant $tenant, TenantFinancialAccess $access): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        $validated = $this->validatedItem($request);
        FieldServicePriceBookItem::query()->create([
            'tenant_id' => (int) $tenant->id,
            'source' => 'curated',
            'external_id' => 'manual:'.Str::ulid(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'item_type' => 'service',
            'unit_price' => $validated['unit_price'],
            'active' => true,
            'metadata' => ['created_manually' => true, 'created_by_user_id' => (int) $request->user()->id],
        ]);

        return back()->with('status', 'Price-book item added.');
    }

    public function updateItem(Request $request, Tenant $tenant, FieldServicePriceBookItem $item, TenantFinancialAccess $access): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        abort_unless((int) $item->tenant_id === (int) $tenant->id && $item->source === 'curated', 404);
        $validated = $this->validatedItem($request);
        $item->forceFill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'unit_price' => $validated['unit_price'],
            'active' => (bool) ($validated['active'] ?? false),
        ])->save();

        return back()->with('status', 'Price-book item updated.');
    }

    public function store(Request $request, Tenant $tenant, TenantFinancialAccess $access, FieldServiceEstimateService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        $validated = $this->validatedEstimate($request, $tenant);
        $estimate = FieldServiceEstimate::query()->create([
            'tenant_id' => (int) $tenant->id,
            'marketing_profile_id' => $validated['marketing_profile_id'] ?? null,
            'field_service_job_id' => $validated['field_service_job_id'] ?? null,
            'created_by_user_id' => (int) $request->user()->id,
            'estimate_number' => 'EST-'.now()->format('Y').'-'.strtoupper(substr((string) Str::ulid(), -6)),
            'status' => 'draft',
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'tax_amount' => $validated['tax_amount'] ?? 0,
        ]);
        $service->saveLines($tenant, $estimate, (array) ($validated['lines'] ?? []));

        return redirect()->route('estimator.show', ['tenant' => $tenant, 'estimate' => $estimate])->with('status', 'Estimate draft created.');
    }

    public function show(Request $request, Tenant $tenant, FieldServiceEstimate $estimate, TenantFinancialAccess $access): View
    {
        $this->authorizeOwner($request, $tenant, $access);
        abort_unless((int) $estimate->tenant_id === (int) $tenant->id, 404);

        return view('estimator.show', [
            'tenant' => $tenant,
            'estimate' => $estimate->load(['customer', 'job', 'lines']),
            'items' => FieldServicePriceBookItem::query()->forTenantId((int) $tenant->id)->where('source', 'curated')->where('active', true)->orderBy('name')->get(),
            'customers' => MarketingProfile::query()->forTenantId((int) $tenant->id)->orderBy('first_name')->limit(200)->get(['id', 'first_name', 'last_name', 'email']),
            'jobs' => $tenant->fieldServiceJobs()->latest()->limit(200)->get(['id', 'title']),
        ]);
    }

    public function update(Request $request, Tenant $tenant, FieldServiceEstimate $estimate, TenantFinancialAccess $access, FieldServiceEstimateService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        abort_unless((int) $estimate->tenant_id === (int) $tenant->id, 404);
        $validated = $this->validatedEstimate($request, $tenant);
        $estimate->forceFill([
            'marketing_profile_id' => $validated['marketing_profile_id'] ?? null,
            'field_service_job_id' => $validated['field_service_job_id'] ?? null,
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'tax_amount' => $validated['tax_amount'] ?? 0,
        ])->save();
        $service->saveLines($tenant, $estimate, (array) ($validated['lines'] ?? []));

        return back()->with('status', 'Estimate draft updated.');
    }

    public function duplicate(Request $request, Tenant $tenant, FieldServiceEstimate $estimate, TenantFinancialAccess $access, FieldServiceEstimateService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $tenant, $access);
        abort_unless((int) $estimate->tenant_id === (int) $tenant->id, 404);
        $estimate->load('lines');
        $copy = $estimate->replicate(['estimate_number', 'status', 'subtotal', 'total_amount']);
        $copy->estimate_number = 'EST-'.now()->format('Y').'-'.strtoupper(substr((string) Str::ulid(), -6));
        $copy->status = 'draft';
        $copy->created_by_user_id = (int) $request->user()->id;
        $copy->save();
        $service->saveLines($tenant, $copy, $estimate->lines->map(fn ($line): array => [
            'price_book_item_id' => $line->field_service_price_book_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'source_snapshot' => $line->source_snapshot,
        ])->all());

        return redirect()->route('estimator.show', ['tenant' => $tenant, 'estimate' => $copy])->with('status', 'Estimate duplicated.');
    }

    /** @return array<string,mixed> */
    protected function validatedEstimate(Request $request, Tenant $tenant): array
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
    protected function validatedItem(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    protected function authorizeOwner(Request $request, Tenant $tenant, TenantFinancialAccess $access): void
    {
        abort_unless($access->allows($request->user(), $tenant), 403);
    }
}
