<?php

namespace App\Services\Onboarding;

use App\Models\CustomModuleRequest;
use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Tenancy\TenantBlueprintProfileService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantSetupStatusService
{
    /**
     * @return array<string,array<string,string>>
     */
    public function options(): array
    {
        return [
            'business_profile_statuses' => $this->labels(TenantSetupStatus::BUSINESS_PROFILE_STATUSES),
            'import_paths' => [
                'shopify' => 'Shopify',
                'square' => 'Square',
                'csv' => 'CSV import',
                'manual' => 'Manual entry',
                'other' => 'Other',
                'undecided' => 'Undecided',
            ],
            'square_statuses' => [
                'not_requested' => 'Not requested',
                'requested' => 'Requested',
                'manual_setup' => 'Manual setup',
                'planned' => 'Planned',
            ],
            'csv_manual_statuses' => [
                'not_started' => 'Not started',
                'requested' => 'Requested',
                'in_progress' => 'In progress',
                'ready' => 'Ready',
            ],
            'mobile_interests' => [
                'none' => 'No mobile interest',
                'android' => 'Android',
                'ios' => 'iOS',
                'both' => 'Android and iOS',
                'undecided' => 'Undecided',
            ],
            'plan_interests' => $this->planInterestOptions(),
            'billing_lane_interests' => [
                'shopify_app_store' => 'Shopify App Store Billing',
                'stripe_direct' => 'Stripe Direct Billing',
                'manual_invoice' => 'Manual invoice/service billing',
                'free_internal_demo' => 'Free/internal/demo',
                'undecided' => 'Undecided',
            ],
            'commercial_review_statuses' => [
                'pending_review' => 'Pending review',
                'reviewed' => 'Reviewed',
                'waiting_on_tenant' => 'Waiting on tenant',
                'waiting_on_everbranch' => 'Waiting on Everbranch',
            ],
            'landlord_review_statuses' => [
                'pending_review' => 'Pending review',
                'reviewed' => 'Reviewed',
                'waiting_on_tenant' => 'Waiting on tenant',
                'waiting_on_everbranch' => 'Waiting on Everbranch',
            ],
            'module_interests' => $this->moduleInterestOptions(),
        ];
    }

    /**
     * @return array<string,string>
     */
    public function intakeFilterOptions(): array
    {
        return [
            'all' => 'All',
            'waiting_on_everbranch_review' => 'Waiting on Everbranch review',
            'shopify_selected_not_connected' => 'Shopify selected, not connected',
            'square_selected' => 'Square selected',
            'csv_selected' => 'CSV selected',
            'manual_selected' => 'Manual selected',
            'undecided_import_path' => 'Undecided import path',
            'mobile_interest' => 'Mobile interest',
            'reviewed' => 'Reviewed',
        ];
    }

    public function forTenant(Tenant $tenant): TenantSetupStatus
    {
        /** @var TenantSetupStatus $status */
        $status = TenantSetupStatus::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                'business_profile_status' => 'not_started',
                'import_path' => 'undecided',
                'shopify_connection_status' => 'not_connected',
                'square_status' => 'not_requested',
                'csv_manual_status' => 'not_started',
                'module_interests' => [],
                'mobile_interest' => 'undecided',
                'plan_interest' => 'undecided',
                'billing_lane_interest' => 'undecided',
                'implementation_help_interest' => false,
                'commercial_review_status' => 'pending_review',
                'landlord_review_status' => 'pending_review',
            ]
        );

        $shopifyStatus = $this->shopifyConnectionStatus((int) $tenant->id);
        if ((string) $status->shopify_connection_status !== $shopifyStatus) {
            $status->forceFill(['shopify_connection_status' => $shopifyStatus])->save();
        }

        if (blank($status->next_recommended_action)) {
            $status->forceFill([
                'next_recommended_action' => $this->recommendedAction($status),
            ])->save();
        }

        if (blank($status->commercial_next_action)) {
            $status->forceFill([
                'commercial_next_action' => $this->commercialRecommendedAction($status),
            ])->save();
        }

        return $status->refresh();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function updateTenantStatus(Tenant $tenant, array $input): TenantSetupStatus
    {
        $status = $this->forTenant($tenant);
        $modules = $this->normalizeModuleInterests((array) ($input['module_interests'] ?? []));
        $originalCommercialAction = $this->commercialRecommendedAction($status);
        $currentCommercialAction = trim((string) ($status->commercial_next_action ?? ''));

        $status->fill([
            'business_profile_status' => $this->optionOrDefault((string) ($input['business_profile_status'] ?? ''), TenantSetupStatus::BUSINESS_PROFILE_STATUSES, 'not_started'),
            'import_path' => $this->optionOrDefault((string) ($input['import_path'] ?? ''), TenantSetupStatus::IMPORT_PATH_OPTIONS, 'undecided'),
            'square_status' => $this->optionOrDefault((string) ($input['square_status'] ?? ''), TenantSetupStatus::SQUARE_STATUSES, 'not_requested'),
            'csv_manual_status' => $this->optionOrDefault((string) ($input['csv_manual_status'] ?? ''), TenantSetupStatus::CSV_MANUAL_STATUSES, 'not_started'),
            'module_interests' => $modules,
            'mobile_interest' => $this->optionOrDefault((string) ($input['mobile_interest'] ?? ''), TenantSetupStatus::MOBILE_INTEREST_OPTIONS, 'undecided'),
            'plan_interest' => $this->optionOrDefault((string) ($input['plan_interest'] ?? ''), TenantSetupStatus::PLAN_INTEREST_OPTIONS, 'undecided'),
            'billing_lane_interest' => $this->optionOrDefault((string) ($input['billing_lane_interest'] ?? ''), TenantSetupStatus::BILLING_LANE_INTEREST_OPTIONS, 'undecided'),
            'implementation_help_interest' => (bool) ($input['implementation_help_interest'] ?? false),
            'commercial_notes' => $this->nullableText((string) ($input['commercial_notes'] ?? ''), 5000),
        ]);

        $status->next_recommended_action = $this->recommendedAction($status);
        if ($currentCommercialAction === '' || $currentCommercialAction === $originalCommercialAction) {
            $status->commercial_next_action = $this->commercialRecommendedAction($status);
        }
        $status->save();

        return $status->refresh();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function updateLandlordStatus(Tenant $tenant, array $input, User $operator): TenantSetupStatus
    {
        $status = $this->forTenant($tenant);
        $reviewStatus = $this->optionOrDefault((string) ($input['landlord_review_status'] ?? ''), TenantSetupStatus::LANDLORD_REVIEW_STATUSES, 'pending_review');
        $commercialReviewStatus = $this->optionOrDefault(
            (string) ($input['commercial_review_status'] ?? ''),
            TenantSetupStatus::LANDLORD_REVIEW_STATUSES,
            (string) ($status->commercial_review_status ?: 'pending_review')
        );

        $status->fill([
            'landlord_review_status' => $reviewStatus,
            'next_recommended_action' => $this->nullableText((string) ($input['next_recommended_action'] ?? ''), 500)
                ?: $this->recommendedAction($status),
            'internal_notes' => $this->nullableText((string) ($input['internal_notes'] ?? ''), 5000),
            'commercial_review_status' => $commercialReviewStatus,
            'commercial_next_action' => $this->nullableText((string) ($input['commercial_next_action'] ?? ''), 500)
                ?: $this->commercialRecommendedAction($status),
        ]);

        if ($reviewStatus === 'reviewed') {
            $status->reviewed_by = (int) $operator->id;
            $status->reviewed_at = now();
        }

        if ($commercialReviewStatus === 'reviewed') {
            $status->commercial_reviewed_by = (int) $operator->id;
            $status->commercial_reviewed_at = now();
        }

        $status->save();

        return $status->refresh();
    }

    public function seedFromAccessRequest(Tenant $tenant, CustomerAccessRequest $request): TenantSetupStatus
    {
        $status = $this->forTenant($tenant);
        $metadata = (array) ($request->metadata ?? []);

        $originalRecommendedAction = $this->recommendedAction($status);
        $importPath = $this->optionOrDefault(
            (string) data_get($metadata, 'import_path', ''),
            TenantSetupStatus::IMPORT_PATH_OPTIONS,
            'undecided'
        );
        $mobileInterest = $this->optionOrDefault(
            (string) data_get($metadata, 'mobile_interest', ''),
            TenantSetupStatus::MOBILE_INTEREST_OPTIONS,
            'undecided'
        );
        $moduleInterests = $this->normalizeModuleInterests((array) (
            data_get($metadata, 'module_interests')
                ?? data_get($metadata, 'addons_interest')
                ?? []
        ));

        if ((string) $status->import_path === 'undecided') {
            $status->import_path = $importPath;
        }

        if ((string) $status->mobile_interest === 'undecided') {
            $status->mobile_interest = $mobileInterest;
        }

        $planInterest = $this->optionOrDefault(
            (string) data_get($metadata, 'plan_interest', ''),
            TenantSetupStatus::PLAN_INTEREST_OPTIONS,
            'undecided'
        );
        $billingLaneInterest = $this->optionOrDefault(
            (string) data_get($metadata, 'billing_lane_interest', ''),
            TenantSetupStatus::BILLING_LANE_INTEREST_OPTIONS,
            'undecided'
        );

        if ((string) ($status->plan_interest ?? 'undecided') === 'undecided') {
            $status->plan_interest = $planInterest;
        }

        if ((string) ($status->billing_lane_interest ?? 'undecided') === 'undecided') {
            $status->billing_lane_interest = $billingLaneInterest;
        }

        if ((array) ($status->module_interests ?? []) === [] && $moduleInterests !== []) {
            $status->module_interests = $moduleInterests;
        }

        if ((string) $status->business_profile_status === 'not_started' && $this->hasBusinessContext($request)) {
            $status->business_profile_status = 'in_progress';
        }

        if ((string) $status->landlord_review_status === 'pending_review') {
            $status->landlord_review_status = 'waiting_on_everbranch';
        }

        $currentNextAction = trim((string) ($status->next_recommended_action ?? ''));
        if ($currentNextAction === '' || $currentNextAction === $originalRecommendedAction) {
            $status->next_recommended_action = 'Review seeded access request details, confirm the setup path, and keep billing checkout disabled.';
        }

        $status->internal_notes = $this->accessRequestInternalNotes($status, $request, $importPath, $mobileInterest, $moduleInterests);
        $status->save();

        return $status->refresh();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function landlordRows(): array
    {
        return Tenant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant): array {
                return $this->payload($tenant, $this->forTenant($tenant), includeInternal: true);
            })
            ->values()
            ->all();
    }

    /**
     * @return array{active_filter:string,filter_options:array<string,string>,summary:array<string,int>,rows:array<int,array<string,mixed>>}
     */
    public function intakeQueue(string $filter = 'all'): array
    {
        $filterOptions = $this->intakeFilterOptions();
        $activeFilter = array_key_exists($filter, $filterOptions) ? $filter : 'all';

        $rows = Tenant::query()
            ->with('setupStatus')
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant): array {
                return $this->payload($tenant, $this->forTenant($tenant), includeInternal: true);
            })
            ->values();

        $summary = [];
        foreach (array_keys($filterOptions) as $key) {
            $summary[$key] = $rows
                ->filter(fn (array $row): bool => $this->intakeRowMatchesFilter($row, $key))
                ->count();
        }

        return [
            'active_filter' => $activeFilter,
            'filter_options' => $filterOptions,
            'summary' => $summary,
            'rows' => $rows
                ->filter(fn (array $row): bool => $this->intakeRowMatchesFilter($row, $activeFilter))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{summary:array<string,mixed>,plan_counts:array<string,int>,billing_lane_counts:array<string,int>,rows:array<int,array<string,mixed>>}
     */
    public function commercialIntentGate(): array
    {
        $rows = Tenant::query()
            ->with('setupStatus')
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant): array {
                $status = $this->forTenant($tenant);
                $customRequestCount = $this->customModuleRequestCount((int) $tenant->id);
                $decisionStatus = $this->billingLaneDecisionStatus($status, $customRequestCount);
                $row = $this->payload($tenant, $status, includeInternal: true);

                $row['has_commercial_intent'] = $status->hasCommercialIntent();
                $row['needs_commercial_review'] = $status->needsCommercialReview();
                $row['wants_implementation_help'] = $status->wantsImplementationHelp();
                $row['custom_module_request_count'] = $customRequestCount;
                $row['billing_lane_decision_status'] = $decisionStatus;
                $row['billing_lane_decision_label'] = $this->billingLaneDecisionLabel($decisionStatus);
                $row['billing_lane_blockers'] = $this->billingLaneBlockers($status, $customRequestCount);

                return $row;
            })
            ->values();

        return [
            'summary' => [
                'total_tenants' => $rows->count(),
                'tenants_with_commercial_intent' => $rows->filter(fn (array $row): bool => (bool) ($row['has_commercial_intent'] ?? false))->count(),
                'needs_commercial_review' => $rows->filter(fn (array $row): bool => (bool) ($row['needs_commercial_review'] ?? false))->count(),
                'wants_implementation_help' => $rows->filter(fn (array $row): bool => (bool) ($row['wants_implementation_help'] ?? false))->count(),
                'with_custom_module_requests' => $rows->filter(fn (array $row): bool => (int) ($row['custom_module_request_count'] ?? 0) > 0)->count(),
                'missing_plan_or_lane' => $rows->filter(fn (array $row): bool => in_array((string) ($row['billing_lane_decision_status'] ?? ''), ['intent_only', 'needs_billing_lane_decision'], true))->count(),
                'blocked_by_shopify_evidence' => $rows->filter(fn (array $row): bool => (string) ($row['billing_lane_decision_status'] ?? '') === 'blocked_shopify_evidence_pending')->count(),
                'blocked_by_billing_disabled' => $rows->filter(fn (array $row): bool => collect((array) ($row['billing_lane_blockers'] ?? []))->contains('Billing remains disabled by readiness gate.'))->count(),
            ],
            'plan_counts' => $this->countRowsByLabel($rows->all(), 'plan_interest_label'),
            'billing_lane_counts' => $this->countRowsByLabel($rows->all(), 'billing_lane_interest_label'),
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(Tenant $tenant, TenantSetupStatus $status, bool $includeInternal = false): array
    {
        $options = $this->options();
        $tenantBlueprint = app(TenantBlueprintProfileService::class)->payloadForTenant($tenant);

        $payload = [
            'tenant' => [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
            ],
            'business_profile_status' => (string) $status->business_profile_status,
            'business_profile_label' => (string) data_get($options, 'business_profile_statuses.'.$status->business_profile_status, 'Not started'),
            'setup_phase_label' => $status->setupPhaseLabel(),
            'import_path' => (string) $status->import_path,
            'import_path_label' => (string) data_get($options, 'import_paths.'.$status->import_path, 'Undecided'),
            'import_path_guidance' => $status->importPathGuidance(),
            'shopify_connection_status' => (string) $status->shopify_connection_status,
            'shopify_connection_label' => $status->shopify_connection_status === 'connected' ? 'Connected' : 'Not connected',
            'shopify_connection_guidance' => $this->shopifyConnectionGuidance($status),
            'square_status' => (string) $status->square_status,
            'square_label' => (string) data_get($options, 'square_statuses.'.$status->square_status, 'Not requested'),
            'csv_manual_status' => (string) $status->csv_manual_status,
            'csv_manual_label' => (string) data_get($options, 'csv_manual_statuses.'.$status->csv_manual_status, 'Not started'),
            'module_interests' => array_values((array) $status->module_interests),
            'module_interest_labels' => $this->moduleInterestLabels((array) $status->module_interests),
            'module_interest_summary' => $this->moduleInterestSummary((array) $status->module_interests),
            'module_interest_guidance' => 'Module interests help Everbranch prepare the right setup conversation. They do not enable, install, or bill modules by themselves.',
            'mobile_interest' => (string) $status->mobile_interest,
            'mobile_interest_label' => (string) data_get($options, 'mobile_interests.'.$status->mobile_interest, 'Undecided'),
            'mobile_interest_guidance' => $status->mobileInterestGuidance(),
            'plan_interest' => (string) ($status->plan_interest ?: 'undecided'),
            'plan_interest_label' => $status->planInterestLabel(),
            'plan_selection_guidance' => $status->planSelectionGuidance(),
            'billing_lane_interest' => (string) ($status->billing_lane_interest ?: 'undecided'),
            'billing_lane_interest_label' => $status->billingLaneInterestLabel(),
            'billing_lane_guidance' => $status->billingLaneGuidance(),
            'implementation_help_interest' => (bool) $status->implementation_help_interest,
            'implementation_help_label' => (bool) $status->implementation_help_interest ? 'Implementation help requested' : 'No implementation help requested',
            'commercial_notes' => (string) ($status->commercial_notes ?? ''),
            'commercial_review_status' => (string) ($status->commercial_review_status ?: 'pending_review'),
            'commercial_review_label' => (string) data_get($options, 'commercial_review_statuses.'.($status->commercial_review_status ?: 'pending_review'), 'Pending review'),
            'commercial_next_action' => (string) ($status->commercial_next_action ?: $this->commercialRecommendedAction($status)),
            'commercial_intent_summary' => $status->commercialIntentSummary(),
            'landlord_review_status' => (string) $status->landlord_review_status,
            'landlord_review_label' => (string) data_get($options, 'landlord_review_statuses.'.$status->landlord_review_status, 'Pending review'),
            'next_recommended_action' => (string) ($status->next_recommended_action ?: $this->recommendedAction($status)),
            'everbranch_review_guidance' => $this->everbranchReviewGuidance($status),
            'tenant_blueprint' => $tenantBlueprint,
            'updated_at' => optional($status->updated_at)->toDateTimeString(),
            'needs_everbranch_review' => $status->needsEverbranchReview(),
            'shopify_selected_not_connected' => $status->shopifySelectedButNotConnected(),
            'has_manual_import_path' => $status->hasManualImportPath(),
            'has_mobile_interest' => $status->hasMobileInterest(),
            'inactive_capabilities' => [
                'Self-service checkout and paid module activation are not active from this setup page.',
                'Square automation and CSV import execution are not active from this setup page.',
                'Generic Everbranch mobile app access is not active; mobile interest is captured for future planning.',
            ],
        ];

        if ($includeInternal) {
            $sourceRequest = $this->latestAccessRequest($tenant);
            $payload['internal_notes'] = (string) ($status->internal_notes ?? '');
            $payload['reviewed_at'] = optional($status->reviewed_at)->toDateTimeString();
            $payload['reviewed_by'] = $status->reviewed_by;
            $payload['commercial_reviewed_at'] = optional($status->commercial_reviewed_at)->toDateTimeString();
            $payload['commercial_reviewed_by'] = $status->commercial_reviewed_by;
            $payload['source_access_request_id'] = $sourceRequest?->id;
            $payload['source_access_request_label'] = $sourceRequest
                ? 'Seeded from access request #'.$sourceRequest->id
                : null;
        }

        return $payload;
    }

    protected function latestAccessRequest(Tenant $tenant): ?CustomerAccessRequest
    {
        if (! Schema::hasTable('customer_access_requests')) {
            return null;
        }

        return CustomerAccessRequest::query()
            ->where('tenant_id', (int) $tenant->id)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByRaw("case when status = 'approved' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();
    }

    public function billingLaneDecisionStatus(TenantSetupStatus $status, int $customRequestCount = 0): string
    {
        $plan = (string) ($status->plan_interest ?: 'undecided');
        $lane = (string) ($status->billing_lane_interest ?: 'undecided');

        if (! $status->hasCommercialIntent()) {
            return 'intent_only';
        }

        if ($plan === 'undecided' || $lane === 'undecided') {
            return 'needs_billing_lane_decision';
        }

        if ($status->needsCommercialReview()) {
            return 'needs_landlord_review';
        }

        if ($lane === 'shopify_app_store') {
            return 'blocked_shopify_evidence_pending';
        }

        if ($lane === 'stripe_direct') {
            return 'blocked_billing_disabled';
        }

        if ($lane === 'manual_invoice' || $lane === 'free_internal_demo') {
            return 'ready_for_manual_follow_up';
        }

        return 'not_ready';
    }

    public function billingLaneDecisionLabel(string $status): string
    {
        return match ($status) {
            'intent_only' => 'Intent only',
            'needs_landlord_review' => 'Needs landlord review',
            'needs_billing_lane_decision' => 'Needs billing lane decision',
            'blocked_billing_disabled' => 'Blocked: billing disabled',
            'blocked_shopify_evidence_pending' => 'Blocked: Shopify evidence pending',
            'blocked_scope_or_branding_review' => 'Blocked: scope or branding review',
            'ready_for_manual_follow_up' => 'Ready for manual follow-up',
            default => 'Not ready',
        };
    }

    /**
     * @return array<int,string>
     */
    public function billingLaneBlockers(TenantSetupStatus $status, int $customRequestCount = 0): array
    {
        $blockers = [];
        $plan = (string) ($status->plan_interest ?: 'undecided');
        $lane = (string) ($status->billing_lane_interest ?: 'undecided');

        if (! $status->hasCommercialIntent()) {
            $blockers[] = 'No plan interest has been captured yet.';
        }

        if ($plan === 'undecided') {
            $blockers[] = 'Plan interest is undecided.';
        }

        if ($lane === 'undecided') {
            $blockers[] = 'Billing lane decision is missing.';
        }

        if ($status->needsCommercialReview()) {
            $blockers[] = 'Commercial review is not complete.';
        }

        if (! (bool) config('commercial.billing_readiness.checkout_active', false)
            || ! (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false)
        ) {
            $blockers[] = 'Billing remains disabled by readiness gate.';
        }

        if ($lane === 'shopify_app_store') {
            $blockers[] = 'Shopify Partner Dashboard / CLI / dev-store evidence is still pending.';
            $blockers[] = 'Shopify scope review and app branding decision remain pending.';
            $blockers[] = 'Shopify Billing/App Pricing is not implemented yet.';
        }

        if ($lane === 'stripe_direct') {
            $blockers[] = 'Stripe direct billing requires a future explicit activation PR.';
            $blockers[] = 'Tenant self-service Stripe checkout remains disabled.';
        }

        if ($lane === 'manual_invoice') {
            $blockers[] = 'Manual quote/invoice workflow remains operator-managed and is not automated here.';
        }

        if ($customRequestCount > 0) {
            $blockers[] = 'Custom module requests require operator review before commercial activation.';
        }

        return array_values(array_unique($blockers));
    }

    protected function customModuleRequestCount(int $tenantId): int
    {
        if (! Schema::hasTable('custom_module_requests')) {
            return 0;
        }

        return CustomModuleRequest::query()
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    protected function countRowsByLabel(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$key] ?? 'Undecided'));
            $label = $label !== '' ? $label : 'Undecided';
            $counts[$label] = (int) ($counts[$label] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    protected function intakeRowMatchesFilter(array $row, string $filter): bool
    {
        return match ($filter) {
            'waiting_on_everbranch_review' => in_array((string) ($row['landlord_review_status'] ?? ''), ['pending_review', 'waiting_on_everbranch'], true),
            'shopify_selected_not_connected' => (bool) ($row['shopify_selected_not_connected'] ?? false),
            'square_selected' => (string) ($row['import_path'] ?? '') === 'square',
            'csv_selected' => (string) ($row['import_path'] ?? '') === 'csv',
            'manual_selected' => (string) ($row['import_path'] ?? '') === 'manual',
            'undecided_import_path' => (string) ($row['import_path'] ?? '') === 'undecided',
            'mobile_interest' => (bool) ($row['has_mobile_interest'] ?? false),
            'reviewed' => (string) ($row['landlord_review_status'] ?? '') === 'reviewed',
            default => true,
        };
    }

    protected function shopifyConnectionStatus(int $tenantId): string
    {
        if (! Schema::hasTable('shopify_stores')) {
            return 'not_connected';
        }

        return ShopifyStore::query()
            ->where('tenant_id', $tenantId)
            ->exists()
            ? 'connected'
            : 'not_connected';
    }

    protected function shopifyConnectionGuidance(TenantSetupStatus $status): string
    {
        if ((string) $status->import_path !== 'shopify') {
            return 'Shopify remains available as a primary supported integration path if you choose it later.';
        }

        return (string) $status->shopify_connection_status === 'connected'
            ? 'A Shopify store connection is present for this tenant. Everbranch still reviews setup before expanding automation.'
            : 'Shopify has been selected but no store connection is present yet. Use the existing Shopify setup path with Everbranch guidance.';
    }

    protected function everbranchReviewGuidance(TenantSetupStatus $status): string
    {
        return match ((string) $status->landlord_review_status) {
            'reviewed' => 'Everbranch has reviewed this setup status. Any connector, import, module, or billing work still follows the readiness gates.',
            'waiting_on_tenant' => 'Everbranch is waiting on tenant details before setup can move forward.',
            'waiting_on_everbranch' => 'Everbranch needs to review this setup path before any manual import, connector, module, or mobile work proceeds.',
            default => 'Everbranch has not completed setup review yet.',
        };
    }

    protected function recommendedAction(TenantSetupStatus $status): string
    {
        if ((string) $status->business_profile_status !== 'ready') {
            return 'Finish the business profile so setup guidance can stay specific.';
        }

        if ((string) $status->import_path === 'undecided') {
            return 'Choose a primary import path: Shopify, Square, CSV, manual, or other.';
        }

        if ((string) $status->import_path === 'shopify' && (string) $status->shopify_connection_status !== 'connected') {
            return 'Open Shopify setup with Everbranch support; Shopify remains the flagship integration path.';
        }

        if ((array) ($status->module_interests ?? []) === []) {
            return 'Select module interests so Everbranch can prepare the right setup checklist.';
        }

        if ((string) $status->mobile_interest === 'undecided') {
            return 'Confirm whether Android, iOS, both, or no mobile companion is needed.';
        }

        return 'Waiting on Everbranch review. Billing and checkout remain disabled until readiness gates pass.';
    }

    protected function commercialRecommendedAction(TenantSetupStatus $status): string
    {
        if ((string) ($status->plan_interest ?: 'undecided') === 'undecided') {
            return 'Capture plan interest as a planning signal; checkout and billing remain disabled.';
        }

        if ((string) ($status->billing_lane_interest ?: 'undecided') === 'undecided') {
            return 'Confirm the likely billing lane without activating checkout or subscriptions.';
        }

        if ((bool) $status->implementation_help_interest) {
            return 'Review implementation help needs and keep any quote, invoice, or billing work manual.';
        }

        return 'Review plan interest with the tenant before any billing, checkout, or access work.';
    }

    /**
     * @param  array<int,string>  $keys
     * @return array<int,string>
     */
    protected function moduleInterestLabels(array $keys): array
    {
        $options = $this->moduleInterestOptions();

        return array_values(array_map(
            static fn (string $key): string => (string) ($options[$key] ?? Str::headline($key)),
            array_values(array_filter(array_map(static fn (mixed $key): string => strtolower(trim((string) $key)), $keys)))
        ));
    }

    /**
     * @param  array<int,string>  $keys
     */
    protected function moduleInterestSummary(array $keys): string
    {
        $labels = $this->moduleInterestLabels($keys);

        return $labels === []
            ? 'No module interests have been selected yet.'
            : implode(', ', $labels);
    }

    /**
     * @return array<string,string>
     */
    protected function moduleInterestOptions(): array
    {
        $modules = [];
        foreach ((array) config('module_catalog.modules', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) $key));
            if ($moduleKey === '') {
                continue;
            }

            $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));
            $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
            $visible = (bool) data_get($definition, 'visibility.app_store', false);
            if (! $visible || $marketState !== 'SAFE_TO_MARKET' || ! in_array($status, ['live', 'beta'], true)) {
                continue;
            }

            $modules[$moduleKey] = (string) ($definition['display_name'] ?? $definition['label'] ?? Str::headline($moduleKey));
        }

        asort($modules);

        return $modules;
    }

    /**
     * @return array<string,string>
     */
    protected function planInterestOptions(): array
    {
        $plans = [];
        foreach ((array) config('commercial.plans', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $planKey = strtolower(trim((string) $key));
            if ($planKey === '' || ! in_array($planKey, ['starter', 'growth', 'pro'], true)) {
                continue;
            }

            $plans[$planKey] = (string) ($definition['name'] ?? Str::headline($planKey));
        }

        foreach ([
            'starter' => 'Starter',
            'growth' => 'Growth',
            'pro' => 'Pro',
        ] as $key => $fallback) {
            $plans[$key] ??= $fallback;
        }

        $plans['custom'] = 'Custom';
        $plans['undecided'] = 'Undecided';

        return $plans;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function normalizeModuleInterests(array $values): array
    {
        $allowed = array_keys($this->moduleInterestOptions());

        return array_values(array_unique(array_filter(array_map(
            function (mixed $value) use ($allowed): ?string {
                $key = strtolower(trim((string) $value));

                return in_array($key, $allowed, true) ? $key : null;
            },
            $values
        ))));
    }

    /**
     * @param  array<int,string>  $values
     */
    protected function labels(array $values): array
    {
        return collect($values)
            ->mapWithKeys(static fn (string $value): array => [$value => Str::headline(str_replace('_', ' ', $value))])
            ->all();
    }

    /**
     * @param  array<int,string>  $options
     */
    protected function optionOrDefault(string $value, array $options, string $default): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, $options, true) ? $normalized : $default;
    }

    protected function nullableText(string $value, int $limit): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, $limit, '');
    }

    protected function hasBusinessContext(CustomerAccessRequest $request): bool
    {
        $metadata = (array) ($request->metadata ?? []);

        foreach ([
            $request->company,
            $request->message,
            data_get($metadata, 'business_type'),
            data_get($metadata, 'team_size'),
            data_get($metadata, 'timeline'),
            data_get($metadata, 'website'),
        ] as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $moduleInterests
     */
    protected function accessRequestInternalNotes(
        TenantSetupStatus $status,
        CustomerAccessRequest $request,
        string $importPath,
        string $mobileInterest,
        array $moduleInterests
    ): string {
        $existing = trim((string) ($status->internal_notes ?? ''));
        $source = 'Access request #'.$request->id;

        if ($existing !== '' && str_contains($existing, $source)) {
            return $existing;
        }

        $metadata = (array) ($request->metadata ?? []);
        $lines = [
            $source.' seeded this setup status.',
            'Requested by: '.trim((string) ($request->name ?: 'Unknown')).' <'.trim((string) $request->email).'>',
            'Company: '.(trim((string) $request->company) !== '' ? trim((string) $request->company) : 'n/a'),
            'Import path: '.$importPath,
            'Mobile interest: '.$mobileInterest,
            'Plan interest: '.(string) ($status->plan_interest ?: 'undecided'),
            'Billing lane interest: '.(string) ($status->billing_lane_interest ?: 'undecided'),
            'Module interests: '.($moduleInterests !== [] ? implode(', ', $moduleInterests) : 'none captured'),
        ];

        foreach ([
            'business_type' => 'Business type',
            'team_size' => 'Team size',
            'timeline' => 'Timeline',
            'website' => 'Website',
        ] as $key => $label) {
            $value = trim((string) data_get($metadata, $key, ''));
            if ($value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        $message = trim((string) ($request->message ?? ''));
        if ($message !== '') {
            $lines[] = 'Request note: '.Str::limit($message, 500, '');
        }

        $note = implode("\n", $lines);

        return $existing !== ''
            ? Str::limit($existing."\n\n".$note, 5000, '')
            : Str::limit($note, 5000, '');
    }
}
