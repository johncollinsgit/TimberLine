<?php

namespace App\Http\Controllers;

use App\Models\CustomModuleRequest;
use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomModuleRequestController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);

        return view('custom-module-requests.index', [
            'tenant' => $tenant,
            'requests' => CustomModuleRequest::query()
                ->forTenantId((int) $tenant->id)
                ->latest('id')
                ->get(),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function create(Request $request, TenantModuleCatalogService $catalogService): View
    {
        $tenant = $this->tenant($request);
        $relatedModuleKey = $this->safeRelatedModuleKey((string) $request->query('related_module_key', ''), $catalogService);

        return view('custom-module-requests.create', [
            'tenant' => $tenant,
            'relatedModuleKey' => $relatedModuleKey,
            'relatedModuleLabel' => $this->relatedModuleLabel($relatedModuleKey),
            'mobileRelevanceOptions' => $this->mobileRelevanceLabels(),
            'urgencyOptions' => $this->urgencyOptions(),
            'frequencyOptions' => $this->frequencyOptions(),
            'budgetRangeOptions' => $this->budgetRangeOptions(),
        ]);
    }

    public function store(Request $request, TenantModuleCatalogService $catalogService): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'problem_summary' => ['required', 'string', 'max:4000'],
            'current_workaround' => ['nullable', 'string', 'max:3000'],
            'desired_outcome' => ['nullable', 'string', 'max:3000'],
            'tools_involved' => ['nullable', 'string', 'max:1000'],
            'users_impacted' => ['nullable', 'string', 'max:500'],
            'frequency' => ['nullable', 'string', Rule::in(array_keys($this->frequencyOptions()))],
            'urgency' => ['nullable', 'string', Rule::in(array_keys($this->urgencyOptions()))],
            'budget_range' => ['nullable', 'string', Rule::in(array_keys($this->budgetRangeOptions()))],
            'reusable_module_interest' => ['nullable', 'boolean'],
            'mobile_relevance' => ['nullable', 'string', Rule::in(array_keys($this->mobileRelevanceLabels()))],
            'related_module_key' => ['nullable', 'string', 'max:120'],
        ]);

        $relatedModuleKey = $this->safeRelatedModuleKey((string) ($validated['related_module_key'] ?? ''), $catalogService);
        if (trim((string) ($validated['related_module_key'] ?? '')) !== '' && $relatedModuleKey === null) {
            throw ValidationException::withMessages([
                'related_module_key' => 'Related module must be a safe tenant-visible module.',
            ]);
        }

        $customRequest = CustomModuleRequest::query()->create([
            'tenant_id' => (int) $tenant->id,
            'requested_by_user_id' => $request->user()?->id,
            'related_module_key' => $relatedModuleKey,
            'title' => $this->text($validated['title'] ?? '', 160),
            'problem_summary' => $this->text($validated['problem_summary'] ?? '', 4000),
            'current_workaround' => $this->nullableText($validated['current_workaround'] ?? null, 3000),
            'desired_outcome' => $this->nullableText($validated['desired_outcome'] ?? null, 3000),
            'tools_involved' => $this->nullableText($validated['tools_involved'] ?? null, 1000),
            'users_impacted' => $this->nullableText($validated['users_impacted'] ?? null, 500),
            'frequency' => $this->nullableOption($validated['frequency'] ?? null, array_keys($this->frequencyOptions())),
            'urgency' => $this->nullableOption($validated['urgency'] ?? null, array_keys($this->urgencyOptions())),
            'budget_range' => $this->nullableOption($validated['budget_range'] ?? null, array_keys($this->budgetRangeOptions())),
            'reusable_module_interest' => (bool) ($validated['reusable_module_interest'] ?? false),
            'mobile_relevance' => $this->nullableOption($validated['mobile_relevance'] ?? null, array_keys($this->mobileRelevanceLabels())) ?: 'undecided',
            'status' => 'new',
            'next_action' => 'Everbranch will review this request and follow up if discovery is needed.',
        ]);

        return redirect()
            ->route('custom-module-requests.show', ['customModuleRequest' => $customRequest, 'tenant' => (string) $tenant->slug])
            ->with('status', 'Custom module request submitted for Everbranch review.');
    }

    public function show(Request $request, CustomModuleRequest $customModuleRequest): View
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $customModuleRequest->tenant_id === (int) $tenant->id, 403);

        return view('custom-module-requests.show', [
            'tenant' => $tenant,
            'customRequest' => $customModuleRequest,
            'relatedModuleLabel' => $this->relatedModuleLabel($customModuleRequest->related_module_key),
            'statusLabels' => $this->statusLabels(),
            'mobileRelevanceOptions' => $this->mobileRelevanceLabels(),
        ]);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function safeRelatedModuleKey(string $moduleKey, TenantModuleCatalogService $catalogService): ?string
    {
        $normalized = $catalogService->canonicalModuleKey($moduleKey);
        if ($normalized === '') {
            return null;
        }

        $definition = config('module_catalog.modules.'.$normalized);
        if (! is_array($definition) || ! $catalogService->isSafeForSurface((array) $definition, 'app_store')) {
            return null;
        }

        return $normalized;
    }

    protected function relatedModuleLabel(?string $moduleKey): ?string
    {
        $normalized = strtolower(trim((string) $moduleKey));
        if ($normalized === '') {
            return null;
        }

        $definition = config('module_catalog.modules.'.$normalized);

        return is_array($definition)
            ? (string) ($definition['display_name'] ?? $definition['label'] ?? Str::headline($normalized))
            : Str::headline($normalized);
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return collect(CustomModuleRequest::STATUSES)
            ->mapWithKeys(static fn (string $status): array => [$status => Str::headline(str_replace('_', ' ', $status))])
            ->all();
    }

    /**
     * @return array<string,string>
     */
    protected function mobileRelevanceLabels(): array
    {
        return [
            'none' => 'No mobile relevance',
            'future_mobile_companion' => 'Future mobile companion planning',
            'android' => 'Android planning',
            'ios' => 'iPhone/iOS planning',
            'both' => 'Android and iOS planning',
            'field_work' => 'Field work/mobile workflow',
            'undecided' => 'Undecided',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function urgencyOptions(): array
    {
        return [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'urgent' => 'Urgent',
            'undecided' => 'Undecided',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function frequencyOptions(): array
    {
        return [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'occasionally' => 'Occasionally',
            'one_time' => 'One-time',
            'undecided' => 'Undecided',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function budgetRangeOptions(): array
    {
        return [
            'not_sure' => 'Not sure yet',
            'under_1000' => 'Under $1,000',
            '1000_5000' => '$1,000-$5,000',
            '5000_15000' => '$5,000-$15,000',
            '15000_plus' => '$15,000+',
            'prefer_discussion' => 'Prefer to discuss',
        ];
    }

    protected function text(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<int,string>  $allowed
     */
    protected function nullableOption(mixed $value, array $allowed): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }
}
