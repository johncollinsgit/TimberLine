<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordCatalogEntry;
use App\Models\Tenant;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LandlordCommercialConfigurationController extends Controller
{
    public function index(
        LandlordCommercialConfigService $service,
        TenantModuleAccessResolver $moduleAccessResolver
    ): View
    {
        $tenantRows = [];
        $moduleCatalogKeys = array_keys((array) config('entitlements.modules', []));
        $billingOverview = $service->billingReadinessOverview();

        foreach ($service->tenantRowsForLandlord() as $tenant) {
            $usage = $service->tenantUsageSummary($tenant, false);
            $commercial = $service->tenantCommercialProfile((int) $tenant->id);
            $templateKey = (string) ($commercial['template_key'] ?? '');
            $templateExists = is_array($commercial['template'] ?? null);
            $templateLabels = $this->normalizeDisplayLabels((array) data_get($commercial, 'template.payload.default_labels', []));
            $explicitLabels = $this->normalizeDisplayLabels((array) ($commercial['display_labels'] ?? []));
            $effectiveLabels = array_replace($templateLabels, $explicitLabels);
            $labelSource = 'entitlements_default';
            if ($explicitLabels !== []) {
                $labelSource = 'tenant_override';
            } elseif ($templateKey !== '' && $templateExists && $templateLabels !== []) {
                $labelSource = 'template_default';
            }
            $resolvedModuleState = $moduleAccessResolver->resolveForTenant((int) $tenant->id, $moduleCatalogKeys);
            $resolvedModules = is_array($resolvedModuleState['modules'] ?? null)
                ? (array) $resolvedModuleState['modules']
                : [];
            $moduleOverrides = [];
            foreach ($tenant->moduleStates as $moduleState) {
                $moduleKey = strtolower(trim((string) $moduleState->module_key));
                if ($moduleKey === '') {
                    continue;
                }

                $moduleOverrides[$moduleKey] = [
                    'enabled_override' => $moduleState->getRawOriginal('enabled_override') === null
                        ? null
                        : (bool) $moduleState->enabled_override,
                    'setup_status' => strtolower(trim((string) ($moduleState->setup_status ?? 'not_started'))),
                ];
            }

            $addonStates = [];
            foreach ($tenant->accessAddons as $addonState) {
                $addonKey = strtolower(trim((string) $addonState->addon_key));
                if ($addonKey === '') {
                    continue;
                }

                $addonStates[$addonKey] = (bool) $addonState->enabled;
            }

            $resolvedPlanKey = (string) ($resolvedModuleState['plan_key'] ?? config('entitlements.default_plan', 'starter'));

            $tenantRows[] = [
                'tenant' => $tenant,
                'plan_key' => (string) ($tenant->accessProfile?->plan_key ?? config('entitlements.default_plan', 'starter')),
                'resolved_plan_key' => $resolvedPlanKey,
                'operating_mode' => (string) ($tenant->accessProfile?->operating_mode ?? config('entitlements.default_operating_mode', 'shopify')),
                'template_key' => (string) ($commercial['template_key'] ?? ''),
                'store_channel_allowance' => $commercial['store_channel_allowance'] ?? null,
                'usage' => $usage,
                'module_overrides' => $moduleOverrides,
                'addon_states' => $addonStates,
                'resolved_module_states' => $resolvedModules,
                'plan_pricing_overrides_json' => $this->encodeJsonMap((array) ($commercial['plan_pricing_overrides'] ?? [])),
                'addon_pricing_overrides_json' => $this->encodeJsonMap((array) ($commercial['addon_pricing_overrides'] ?? [])),
                'included_usage_overrides_json' => $this->encodeJsonMap((array) ($commercial['included_usage_overrides'] ?? [])),
                'display_labels_json' => $this->encodeJsonMap((array) ($commercial['display_labels'] ?? [])),
                'effective_labels_json' => $this->encodeJsonMap($effectiveLabels),
                'billing_mapping_json' => $this->encodeJsonMap((array) ($commercial['billing_mapping'] ?? [])),
                'metadata_json' => $this->encodeJsonMap((array) ($commercial['metadata'] ?? [])),
                'template_default_labels_json' => $this->encodeJsonMap((array) data_get($commercial, 'template.payload.default_labels', [])),
                'label_source' => $labelSource,
                'template_missing' => $templateKey !== '' && ! $templateExists,
                'billing_readiness' => $service->tenantBillingReadiness(
                    tenantId: (int) $tenant->id,
                    resolvedPlanKey: $resolvedPlanKey,
                    addonStates: $addonStates,
                    commercialProfile: $commercial
                ),
                'stripe_customer_sync' => $service->stripeCustomerSyncReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
                'stripe_subscription_prep' => $service->stripeSubscriptionPrepReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
                'stripe_live_subscription_sync' => $service->stripeLiveSubscriptionSyncReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
            ];
        }

        return view('landlord.commercial.index', [
            'plans' => $service->catalog(LandlordCatalogEntry::TYPE_PLAN),
            'addons' => $service->catalog(LandlordCatalogEntry::TYPE_ADDON),
            'templates' => $service->catalog(LandlordCatalogEntry::TYPE_TEMPLATE),
            'setupPackages' => $service->catalog(LandlordCatalogEntry::TYPE_SETUP_PACKAGE),
            'tenants' => $tenantRows,
            'moduleCatalog' => (array) config('entitlements.modules', []),
            'addonCatalog' => (array) config('entitlements.addons', []),
            'billingReadiness' => $billingOverview,
            'usageMetrics' => (array) config('commercial.usage_metrics', []),
        ]);
    }

    public function upsertCatalogEntry(
        Request $request,
        string $type,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $validated = $request->validate([
            'entry_key' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:190'],
            'status' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recurring_price_cents' => ['nullable', 'integer', 'min:0'],
            'recurring_interval' => ['nullable', 'string', 'max:40'],
            'setup_price_cents' => ['nullable', 'integer', 'min:0'],
            'payload_json' => ['nullable', 'string', 'json'],
        ]);

        $service->upsertCatalogEntry(
            type: $type,
            input: [
                ...$validated,
                'payload' => $this->decodeJsonMap($validated['payload_json'] ?? ''),
            ],
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Catalog entry saved.');
    }

    public function duplicateTemplate(
        Request $request,
        string $entryKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $validated = $request->validate([
            'new_key' => ['nullable', 'string', 'max:120'],
        ]);

        $service->duplicateTemplate(
            sourceKey: $entryKey,
            newKey: (string) ($validated['new_key'] ?? ''),
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Template duplicated.');
    }

    public function setTemplateState(
        Request $request,
        string $entryKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:active,inactive,archived'],
        ]);

        $service->setTemplateState(
            templateKey: $entryKey,
            state: (string) $validated['state'],
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Template state updated.');
    }

    public function reorderTemplates(Request $request, LandlordCommercialConfigService $service): RedirectResponse
    {
        $validated = $request->validate([
            'ordered_keys' => ['required', 'array'],
            'ordered_keys.*' => ['string', 'max:120'],
        ]);

        $service->reorderTemplates((array) $validated['ordered_keys'], $request->user()?->id);

        return back()->with('status', 'Template order updated.');
    }

    public function assignTenantPlan(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $planKeys = array_values(array_filter(array_map(
            static fn (array $entry): string => strtolower(trim((string) ($entry['entry_key'] ?? ''))),
            $service->catalog(LandlordCatalogEntry::TYPE_PLAN)
        )));

        $validated = $request->validate([
            'plan_key' => ['required', 'string', 'max:120', Rule::in($planKeys)],
            'operating_mode' => ['nullable', 'string', 'max:80', Rule::in(['shopify', 'direct'])],
        ]);

        $service->assignTenantPlan(
            tenantId: (int) $tenant->id,
            planKey: (string) $validated['plan_key'],
            operatingMode: (string) ($validated['operating_mode'] ?? 'shopify'),
            source: 'landlord_console'
        );

        return back()->with('status', 'Tenant plan assignment saved.');
    }

    public function updateTenantModuleState(
        Request $request,
        Tenant $tenant,
        string $moduleKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $request->merge([
            'module_key' => strtolower(trim($moduleKey)),
        ]);

        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in(array_keys((array) config('entitlements.modules', [])))],
            'enabled_override' => ['required', 'string', 'in:inherit,enabled,disabled'],
            'setup_status' => ['nullable', 'string', 'in:not_started,in_progress,configured,blocked'],
        ]);

        $override = match ((string) $validated['enabled_override']) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };

        $service->setTenantModuleState(
            tenantId: (int) $tenant->id,
            moduleKey: (string) $validated['module_key'],
            enabledOverride: $override,
            setupStatus: (string) ($validated['setup_status'] ?? '')
        );

        return back()->with('status', 'Module state updated.');
    }

    public function updateTenantAddonState(
        Request $request,
        Tenant $tenant,
        string $addonKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $request->merge([
            'addon_key' => strtolower(trim($addonKey)),
        ]);

        $validated = $request->validate([
            'addon_key' => ['required', 'string', Rule::in(array_keys((array) config('entitlements.addons', [])))],
            'enabled' => ['required', 'boolean'],
        ]);

        $service->setTenantAddonState(
            tenantId: (int) $tenant->id,
            addonKey: (string) $validated['addon_key'],
            enabled: (bool) $validated['enabled'],
            source: 'landlord_console'
        );

        return back()->with('status', 'Addon state updated.');
    }

    public function updateTenantCommercialOverride(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $templateKeys = array_values(array_filter(array_map(
            static fn (array $entry): string => strtolower(trim((string) ($entry['entry_key'] ?? ''))),
            $service->catalog(LandlordCatalogEntry::TYPE_TEMPLATE)
        )));

        $validated = $request->validate([
            'template_key' => ['nullable', 'string', 'max:120', Rule::in(array_merge([''], $templateKeys))],
            'store_channel_allowance' => ['nullable', 'integer', 'min:1', 'max:500'],
            'plan_pricing_overrides_json' => ['nullable', 'string', 'json'],
            'addon_pricing_overrides_json' => ['nullable', 'string', 'json'],
            'included_usage_overrides_json' => ['nullable', 'string', 'json'],
            'display_labels_json' => ['nullable', 'string', 'json'],
            'billing_mapping_json' => ['nullable', 'string', 'json'],
            'metadata_json' => ['nullable', 'string', 'json'],
        ]);

        $service->updateTenantCommercialOverride((int) $tenant->id, [
            'template_key' => $validated['template_key'] ?? null,
            'store_channel_allowance' => $validated['store_channel_allowance'] ?? null,
            'plan_pricing_overrides' => $this->decodeJsonMap($validated['plan_pricing_overrides_json'] ?? ''),
            'addon_pricing_overrides' => $this->decodeJsonMap($validated['addon_pricing_overrides_json'] ?? ''),
            'included_usage_overrides' => $this->decodeJsonMap($validated['included_usage_overrides_json'] ?? ''),
            'display_labels' => $this->decodeJsonMap($validated['display_labels_json'] ?? ''),
            'billing_mapping' => $this->decodeJsonMap($validated['billing_mapping_json'] ?? ''),
            'metadata' => $this->decodeJsonMap($validated['metadata_json'] ?? ''),
        ]);

        return back()->with('status', 'Tenant commercial overrides updated.');
    }

    public function syncTenantStripeCustomer(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $result = $service->syncStripeCustomerReference($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe customer sync failed.'));
        }

        $customerReference = trim((string) ($result['customer_reference'] ?? ''));
        $statusMessage = $customerReference !== ''
            ? 'Stripe customer sync succeeded: '.$customerReference
            : 'Stripe customer sync succeeded.';

        return back()->with('status', $statusMessage);
    }

    public function syncTenantStripeSubscriptionPrep(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $result = $service->syncStripeSubscriptionPrepState($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe subscription prep sync failed.'));
        }

        $prepHash = trim((string) ($result['candidate_hash'] ?? ''));
        $statusMessage = $prepHash !== ''
            ? 'Stripe subscription prep synced: '.$prepHash
            : 'Stripe subscription prep synced.';

        return back()->with('status', $statusMessage);
    }

    public function syncTenantStripeLiveSubscription(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        $result = $service->syncStripeLiveSubscriptionReference($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe live subscription sync failed.'));
        }

        $subscriptionReference = trim((string) ($result['subscription_reference'] ?? ''));
        $subscriptionStatus = trim((string) ($result['subscription_status'] ?? ''));

        $statusMessage = $subscriptionReference !== ''
            ? 'Stripe live subscription sync succeeded: '.$subscriptionReference
            : 'Stripe live subscription sync succeeded.';

        if ($subscriptionStatus !== '') {
            $statusMessage .= ' (status: '.$subscriptionStatus.')';
        }

        return back()->with('status', $statusMessage);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeJsonMap(?string $raw): array
    {
        $normalized = trim((string) $raw);
        if ($normalized === '') {
            return [];
        }

        $decoded = json_decode($normalized, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function encodeJsonMap(array $value): string
    {
        if ($value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param  array<mixed,mixed>  $labels
     * @return array<string,string>
     */
    protected function normalizeDisplayLabels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) $key));
            if ($moduleKey === '' || ctype_digit($moduleKey)) {
                continue;
            }

            $label = trim((string) $value);
            if ($label === '') {
                continue;
            }

            $normalized[$moduleKey] = $label;
        }

        return $normalized;
    }
}
