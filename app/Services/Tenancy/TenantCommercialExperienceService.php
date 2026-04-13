<?php

namespace App\Services\Tenancy;

use App\Models\CustomerExternalProfile;
use App\Models\CustomerAccessRequest;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\ShopifyImportRun;
use App\Models\ShopifyStore;
use App\Models\TenantOnboardingBlueprintProvisioning;
use App\Models\TenantAccessAddon;
use App\Models\TenantBillingFulfillment;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use App\Services\Billing\TenantBillingNextStepResolver;
use App\Support\Tenancy\TenantModuleUi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantCommercialExperienceService
{
    protected const JOURNEY_PAYLOAD_TYPES = [
        'onboarding',
        'merchant_journey',
        'plans',
        'integrations',
    ];

    /**
     * @var array<string,bool>
     */
    protected array $schemaTableCache = [];

    /**
     * @var array<string,bool>
     */
    protected array $schemaColumnCache = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $journeySummaryCache = [];

    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected LandlordCommercialConfigService $commercialConfigService,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected TenantEmailSettingsService $tenantEmailSettingsService,
        protected TwilioSenderConfigService $twilioSenderConfigService,
        protected OnboardingJourneyTelemetryService $journeyTelemetryService,
        protected TenantBillingNextStepResolver $billingNextStepResolver,
    ) {
    }

    /**
     * @return array{promo:array<string,mixed>,plan_cards:array<int,array<string,mixed>>}
     */
    public function promoPayload(): array
    {
        $promo = (array) config('product_surfaces.promo', []);
        $planCards = $this->planCards(
            cardsConfig: (array) config('product_surfaces.plans.cards', []),
            preferredOrder: $this->normalizeKeys((array) ($promo['plan_order'] ?? [])),
            activePlanKey: null
        );

        return [
            'promo' => $promo,
            'plan_cards' => $planCards,
        ];
    }

    /**
     * Public (unauthenticated) compare-plans payload. Read-only and config-driven.
     *
     * @return array{
     *   content:array<string,mixed>,
     *   plan_cards:array<int,array<string,mixed>>,
     *   addon_cards:array<int,array<string,mixed>>,
     *   recommended_plan_key:string
     * }
     */
    public function publicPlansPayload(): array
    {
        $content = (array) config('product_surfaces.plans', []);
        $planCards = $this->planCards(
            cardsConfig: (array) ($content['cards'] ?? []),
            preferredOrder: $this->normalizeKeys((array) ($content['plan_order'] ?? [])),
            activePlanKey: null
        );

        $addonCatalog = (array) config('entitlements.addons', []);
        $addonCards = [];
        foreach ($addonCatalog as $addonKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $normalizedAddonKey = strtolower(trim((string) $addonKey));
            if ($normalizedAddonKey === '') {
                continue;
            }

            $contentDefinition = is_array(($content['addons'][$normalizedAddonKey] ?? null))
                ? (array) $content['addons'][$normalizedAddonKey]
                : [];
            $includeKeys = $this->normalizeKeys((array) ($definition['includes'] ?? []));

            $modules = array_map(function (string $moduleKey): array {
                $definition = is_array(config('entitlements.modules.'.$moduleKey)) ? (array) config('entitlements.modules.'.$moduleKey) : [];
                $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));

                return [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'status' => $status,
                    'coming_soon' => in_array($status, ['placeholder', 'roadmap'], true),
                ];
            }, $includeKeys);

            $addonCards[] = [
                'addon_key' => $normalizedAddonKey,
                'label' => (string) ($contentDefinition['name'] ?? $definition['label'] ?? Str::title(str_replace('_', ' ', $normalizedAddonKey))),
                'price_display' => (string) ($contentDefinition['price_display'] ?? 'Add-on pricing'),
                'summary' => (string) ($contentDefinition['summary'] ?? ''),
                'modules' => $modules,
            ];
        }

        return [
            'content' => $content,
            'plan_cards' => $planCards,
            'addon_cards' => $addonCards,
            'recommended_plan_key' => 'growth',
        ];
    }

    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   plan:array{key:string,label:string,track:string,operating_mode:string},
     *   commercial_context:array<string,mixed>,
     *   module_states:array<string,array<string,mixed>>,
     *   module_order:array<int,string>,
     *   checklist:array<string,mixed>,
     *   recommended_actions:array<int,array<string,mixed>>
     * }
     */
    public function onboardingPayload(?int $tenantId): array
    {
        $payload = $this->rememberJourneyPayload($tenantId, 'onboarding', function () use ($tenantId): array {
            $content = (array) config('product_surfaces.onboarding', []);
        $moduleOrder = $this->normalizeKeys((array) ($content['module_order'] ?? []));

        if ($moduleOrder === []) {
            $moduleOrder = array_keys((array) config('entitlements.modules', []));
        }

        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleOrder);
        $moduleStates = $this->applyDisplayLabels(
            tenantId: $tenantId,
            moduleStates: (array) ($resolved['modules'] ?? [])
        );
        $content = $this->applyContentLabelTokens($content, $moduleStates, $tenantId);
        $checklist = TenantModuleUi::checklist($moduleStates, $moduleOrder);
        $onboarding = $this->onboardingMeta($tenantId, $checklist);

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planDefinition = is_array(config('entitlements.plans.'.$planKey))
            ? (array) config('entitlements.plans.'.$planKey)
            : [];

            return [
                'content' => $content,
                'tenant_id' => $tenantId,
                'onboarding' => $onboarding,
                'plan' => [
                    'key' => $planKey,
                    'label' => (string) ($planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                    'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                    'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
                ],
                'commercial_context' => $this->commercialContext($tenantId, $moduleStates),
                'module_states' => $moduleStates,
                'module_order' => $moduleOrder,
                'checklist' => $checklist,
                'recommended_actions' => $this->recommendedActions(
                    actions: (array) ($content['recommended_actions'] ?? []),
                    moduleStates: $moduleStates
                ),
            ];
        });

        try {
            $this->journeyTelemetryService->observeTenantJourneyPayload($tenantId, 'onboarding', is_array($payload) ? (array) $payload : []);
        } catch (\Throwable) {
            // Best-effort telemetry must never interrupt payload delivery.
        }

        return $payload;
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   plan:array{key:string,label:string,track:string,operating_mode:string},
     *   module_states:array<string,array<string,mixed>>,
     *   module_order:array<int,string>,
     *   checklist:array<string,mixed>,
     *   recommended_actions:array<int,array<string,mixed>>,
     *   customer_summary:array{
     *     total_profiles:int,
     *     reachable_profiles:int,
     *     linked_external_profiles:int
     *   },
     *   import_summary:array<string,mixed>,
     *   active_now:array<int,array<string,mixed>>,
     *   available_next:array<int,array<string,mixed>>,
     *   purchasable:array<int,array<string,mixed>>
     * }
     */
    public function merchantJourneyPayload(?int $tenantId): array
    {
        $payload = $this->rememberJourneyPayload($tenantId, 'merchant_journey', function () use ($tenantId): array {
            $content = (array) config('product_surfaces.onboarding', []);
        $moduleOrder = $this->normalizeKeys((array) ($content['module_order'] ?? []));

        if ($moduleOrder === []) {
            $moduleOrder = array_keys((array) config('entitlements.modules', []));
        }

        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleOrder);
        $moduleStates = $this->applyDisplayLabels(
            tenantId: $tenantId,
            moduleStates: (array) ($resolved['modules'] ?? [])
        );
        $content = $this->applyContentLabelTokens($content, $moduleStates, $tenantId);
        $checklist = TenantModuleUi::checklist($moduleStates, $moduleOrder);

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planDefinition = is_array(config('entitlements.plans.'.$planKey))
            ? (array) config('entitlements.plans.'.$planKey)
            : [];

            $summary = $this->merchantJourneySummary($tenantId);
            $customerSummary = $summary['customer_summary'];
            $importSummary = $summary['import_summary'];
            $onboarding = $this->onboardingMeta($tenantId, $checklist, $importSummary);

            $billingInterest = $this->billingInterestPayload($tenantId);
            $commercialSummary = $this->commercialSummaryPayload(
                tenantId: $tenantId,
                billingInterest: $billingInterest,
                localPlanKey: (string) ($resolved['plan_key'] ?? ''),
                localOperatingMode: (string) ($resolved['operating_mode'] ?? '')
            );

            return [
                'tenant_id' => $tenantId,
                'onboarding' => $onboarding,
                'plan' => [
                    'key' => $planKey,
                    'label' => (string) ($planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                    'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                    'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
                ],
                'module_states' => $moduleStates,
                'module_order' => $moduleOrder,
                'checklist' => $checklist,
                'recommended_actions' => $this->recommendedActions(
                    actions: (array) ($content['recommended_actions'] ?? []),
                    moduleStates: $moduleStates
                ),
                'customer_summary' => $customerSummary,
                'import_summary' => $importSummary,
                'active_now' => array_values((array) ($checklist['active'] ?? [])),
                'available_next' => array_values((array) ($checklist['setup'] ?? [])),
                'purchasable' => array_values(array_filter(
                    (array) ($checklist['locked'] ?? []),
                    static fn (array $module): bool => (bool) ($module['upgrade_prompt_eligible'] ?? false)
                )),
                'billing_interest' => $billingInterest,
                'billing_next_step' => $this->billingNextStepResolver->resolveForTenantId($tenantId, $billingInterest),
                'commercial_summary' => $commercialSummary,
            ];
        });

        try {
            $this->journeyTelemetryService->observeTenantJourneyPayload($tenantId, 'merchant_journey', is_array($payload) ? (array) $payload : []);
        } catch (\Throwable) {
            // Best-effort telemetry must never interrupt payload delivery.
        }

        return $payload;
    }

    /**
     * @return array{
     *   preferred_plan_key:?string,
     *   addons_interest:array<int,string>,
     *   source:?string,
     *   captured_at:?string,
     *   access_request_id:?int
     * }
     */
    protected function billingInterestPayload(?int $tenantId): array
    {
        if ($tenantId === null || $tenantId < 1) {
            return [
                'preferred_plan_key' => null,
                'addons_interest' => [],
                'source' => null,
                'captured_at' => null,
                'access_request_id' => null,
            ];
        }

        if (! $this->hasTable('customer_access_requests')) {
            return [
                'preferred_plan_key' => null,
                'addons_interest' => [],
                'source' => null,
                'captured_at' => null,
                'access_request_id' => null,
            ];
        }

        $request = CustomerAccessRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->first();

        if (! $request) {
            return [
                'preferred_plan_key' => null,
                'addons_interest' => [],
                'source' => null,
                'captured_at' => null,
                'access_request_id' => null,
            ];
        }

        $meta = is_array($request->metadata ?? null) ? (array) $request->metadata : [];
        $preferredPlanKey = strtolower(trim((string) data_get($meta, 'preferred_plan_key', '')));

        $addonsInterest = array_values(array_filter(array_map(static function (mixed $value): ?string {
            $token = strtolower(trim((string) $value));

            return $token !== '' ? $token : null;
        }, (array) data_get($meta, 'addons_interest', []))));

        $allowedPlanKeys = array_keys((array) config('module_catalog.plans', []));
        if ($preferredPlanKey !== '' && ! in_array($preferredPlanKey, $allowedPlanKeys, true)) {
            $preferredPlanKey = '';
        }

        $allowedAddonKeys = array_keys((array) config('module_catalog.addons', []));
        $addonsInterest = array_values(array_filter(
            $addonsInterest,
            static fn (string $addonKey) => in_array($addonKey, $allowedAddonKeys, true)
        ));

        return [
            'preferred_plan_key' => $preferredPlanKey !== '' ? $preferredPlanKey : null,
            'addons_interest' => $addonsInterest,
            'source' => 'customer_access_request',
            'captured_at' => $request->created_at?->toIso8601String(),
            'access_request_id' => (int) $request->id,
        ];
    }

    /**
     * @param  array{
     *   preferred_plan_key:?string,
     *   addons_interest:array<int,string>,
     *   source:?string,
     *   captured_at:?string,
     *   access_request_id:?int
     * }  $billingInterest
     * @param  array{
     *   tenant_id:?int,
     *   operating_mode:string,
     *   plan_key:string,
     *   modules:array<string,array<string,mixed>>
     * }  $resolved
     * @return array{
     *   lifecycle_state:string,
     *   billing_interest:array<string,mixed>,
     *   local_access:array{plan_key:string,operating_mode:string},
     *   stripe:array<string,mixed>,
     *   fulfillment:array<string,mixed>,
     *   flags:array<string,mixed>
     * }
     */
    public function commercialSupportSummaryForTenant(int $tenantId, string $localPlanKey, string $localOperatingMode): array
    {
        $billingInterest = $this->billingInterestPayload($tenantId);

        return $this->commercialSummaryPayload(
            tenantId: $tenantId,
            billingInterest: $billingInterest,
            localPlanKey: $localPlanKey,
            localOperatingMode: $localOperatingMode
        );
    }

    protected function commercialSummaryPayload(?int $tenantId, array $billingInterest, string $localPlanKey, string $localOperatingMode): array
    {
        $flags = [
            'hosted_checkout_enabled' => (bool) config('commercial.billing_readiness.checkout_active', false),
            'lifecycle_mutations_enabled' => (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false),
        ];

        $localAccess = [
            'plan_key' => $localPlanKey,
            'operating_mode' => $localOperatingMode,
        ];

        $stripe = [];
        $fulfillment = [
            'status' => 'none',
            'last_attempted_at' => null,
            'last_applied_at' => null,
            'state_hash' => null,
            'plan_key' => null,
            'addon_keys' => [],
            'triggered_by' => null,
        ];

        if ($tenantId !== null && $tenantId > 0 && Schema::hasTable('tenant_commercial_overrides')) {
            $commercialProfile = $this->commercialConfigService->tenantCommercialProfile($tenantId);
            $mapping = is_array($commercialProfile['billing_mapping'] ?? null) ? (array) $commercialProfile['billing_mapping'] : [];
            $stripe = is_array($mapping['stripe'] ?? null) ? (array) $mapping['stripe'] : [];

                $stripe = [
                    'customer_reference' => (string) ($stripe['customer_reference'] ?? ''),
                    'subscription_reference' => (string) ($stripe['subscription_reference'] ?? ''),
                    'subscription_status' => (string) ($stripe['subscription_status'] ?? ''),
                    'subscription_deleted_at' => $stripe['subscription_deleted_at'] ?? null,
                    'checkout_session_id' => (string) ($stripe['checkout_session_id'] ?? ''),
                    'checkout_completed_at' => $stripe['checkout_completed_at'] ?? null,
                    'checkout_failed_at' => $stripe['checkout_failed_at'] ?? null,
                    'checkout_payment_status' => (string) ($stripe['checkout_payment_status'] ?? ''),
                    'billing_confirmed_at' => $stripe['billing_confirmed_at'] ?? null,
                    'billing_ended_at' => $stripe['billing_ended_at'] ?? null,
                    'confirmed_plan_key' => $stripe['confirmed_plan_key'] ?? null,
                    'confirmed_addon_keys' => is_array($stripe['confirmed_addon_keys'] ?? null) ? (array) $stripe['confirmed_addon_keys'] : [],
                    'action_required' => (bool) ($stripe['action_required'] ?? false),
                    'last_invoice_paid_at' => $stripe['last_invoice_paid_at'] ?? null,
                    'last_invoice_payment_failed_at' => $stripe['last_invoice_payment_failed_at'] ?? null,
                'last_webhook_event_id' => (string) ($stripe['last_webhook_event_id'] ?? ''),
                'last_webhook_event_type' => (string) ($stripe['last_webhook_event_type'] ?? ''),
                'last_webhook_received_at' => $stripe['last_webhook_received_at'] ?? null,
            ];
        }

        if ($tenantId !== null && $tenantId > 0 && Schema::hasTable('tenant_billing_fulfillments')) {
            $last = TenantBillingFulfillment::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'stripe')
                ->orderByDesc('id')
                ->first();

            if ($last) {
                $fulfillment = [
                    'status' => (string) ($last->status ?? 'attempted'),
                    'last_attempted_at' => $last->attempted_at?->toIso8601String(),
                    'last_applied_at' => $last->applied_at?->toIso8601String(),
                    'state_hash' => (string) ($last->state_hash ?? ''),
                    'plan_key' => (string) ($last->desired_plan_key ?? ''),
                    'addon_keys' => is_array($last->desired_addon_keys) ? $last->desired_addon_keys : [],
                    'triggered_by' => (string) ($last->triggered_by ?? ''),
                ];
            }
        }

        $lifecycleState = 'interest_captured';
        $interestPresent = filled($billingInterest['preferred_plan_key'] ?? null) || ((array) ($billingInterest['addons_interest'] ?? [])) !== [];

        $billingConfirmed = filled($stripe['billing_confirmed_at'] ?? null) || filled($stripe['checkout_completed_at'] ?? null);
        $hasStripeCustomer = filled($stripe['customer_reference'] ?? null);
        $hasStripeSubscription = filled($stripe['subscription_reference'] ?? null);
        $actionRequired = (bool) ($stripe['action_required'] ?? false);
        $subscriptionStatus = strtolower(trim((string) ($stripe['subscription_status'] ?? '')));
        $subscriptionInactive = in_array($subscriptionStatus, ['canceled', 'unpaid', 'incomplete_expired'], true);

        if (! $interestPresent) {
            $lifecycleState = 'unavailable';
        } elseif ($actionRequired) {
            $lifecycleState = 'action_required';
        } elseif ($subscriptionInactive) {
            $lifecycleState = 'action_required';
        } elseif (($fulfillment['status'] ?? null) === 'applied') {
            $lifecycleState = 'fulfilled';
        } elseif ($billingConfirmed) {
            $lifecycleState = $flags['lifecycle_mutations_enabled'] ? 'billing_confirmed_pending_fulfillment' : 'billing_confirmed';
        } elseif ($hasStripeCustomer || $hasStripeSubscription || filled($stripe['checkout_session_id'] ?? null)) {
            $lifecycleState = 'checkout_in_progress';
        } else {
            $lifecycleState = 'billing_handoff_ready';
        }

        $narrative = $this->commercialLifecycleNarrative(
            lifecycleState: $lifecycleState,
            billingInterest: $billingInterest,
            stripe: $stripe,
            fulfillment: $fulfillment,
            flags: $flags
        );

        return [
            'lifecycle_state' => $lifecycleState,
            'state' => $lifecycleState,
            'reason' => (string) ($narrative['reason'] ?? ''),
            'customer_message' => is_array($narrative['customer_message'] ?? null) ? (array) $narrative['customer_message'] : [],
            'operator_note' => is_array($narrative['operator_note'] ?? null) ? (array) $narrative['operator_note'] : [],
            'action_required' => (bool) ($narrative['action_required'] ?? false),
            'operator_action_required' => (bool) ($narrative['operator_action_required'] ?? false),
            'billing_interest' => $billingInterest,
            'local_access' => $localAccess,
            'stripe' => $stripe,
            'fulfillment' => $fulfillment,
            'flags' => $flags,
        ];
    }

    /**
     * @param  array{
     *   preferred_plan_key:?string,
     *   addons_interest:array<int,string>,
     *   source:?string,
     *   captured_at:?string,
     *   access_request_id:?int
     * }  $billingInterest
     * @param  array<string,mixed>  $stripe
     * @param  array<string,mixed>  $fulfillment
     * @param  array<string,mixed>  $flags
     * @return array{
     *   reason:string,
     *   action_required:bool,
     *   operator_action_required:bool,
     *   customer_message:array{title:string,body:string},
     *   operator_note:array{title:string,body:string}
     * }
     */
    protected function commercialLifecycleNarrative(string $lifecycleState, array $billingInterest, array $stripe, array $fulfillment, array $flags): array
    {
        $preferredPlanKey = strtolower(trim((string) ($billingInterest['preferred_plan_key'] ?? '')));
        $subscriptionStatus = strtolower(trim((string) ($stripe['subscription_status'] ?? '')));
        $paymentStatus = strtolower(trim((string) ($stripe['checkout_payment_status'] ?? '')));
        $actionRequired = (bool) ($stripe['action_required'] ?? false);

        $reason = 'unknown';
        $customerTitle = 'Billing';
        $customerBody = 'Billing status is being prepared.';
        $operatorTitle = 'Billing lifecycle';
        $operatorBody = 'Review billing mapping and fulfillment records.';
        $customerActionRequired = false;
        $operatorActionRequired = false;

        if ($lifecycleState === 'unavailable') {
            $reason = 'no_interest_captured';
            $customerTitle = 'Billing unavailable';
            $customerBody = 'No billing intent is saved for this tenant yet. Compare plans to continue.';
            $operatorBody = 'No approved access-request commercial interest is present.';
        } elseif ($lifecycleState === 'billing_handoff_ready') {
            $reason = 'checkout_ready';
            $customerTitle = 'Continue to billing';
            $customerBody = $preferredPlanKey !== ''
                ? 'Your plan selection is saved. Continue to secure checkout to activate billing.'
                : 'Continue to secure checkout to activate billing.';
            $operatorBody = 'Hosted checkout is available; no Stripe confirmation has been recorded yet.';
        } elseif ($lifecycleState === 'checkout_in_progress') {
            $reason = 'checkout_in_progress';
            $customerTitle = 'Checkout in progress';
            $customerBody = $paymentStatus === 'unpaid'
                ? 'Checkout started. Payment is still pending—return to checkout or try again.'
                : 'Checkout started. Once Stripe confirms payment, access updates automatically.';
            $operatorBody = 'Stripe references exist but billing_confirmed_at is not set. Verify webhook delivery and Stripe event history.';
            $operatorActionRequired = true;
        } elseif ($lifecycleState === 'billing_confirmed') {
            $reason = 'billing_confirmed';
            $customerTitle = 'Billing confirmed';
            $customerBody = 'Billing is confirmed. Access updates may still be finalized by the team.';
            $operatorBody = 'Billing is confirmed but lifecycle mutations are disabled; fulfillment requires an operator decision.';
            $operatorActionRequired = true;
        } elseif ($lifecycleState === 'billing_confirmed_pending_fulfillment') {
            $reason = 'pending_fulfillment';
            $customerTitle = 'Activating access';
            $customerBody = 'Billing is confirmed. Access is being activated now—refresh in a moment.';
            $operatorBody = 'Billing confirmed; fulfillment not applied yet. Check tenant_billing_fulfillments and consider landlord reconcile.';
            $operatorActionRequired = true;
        } elseif ($lifecycleState === 'fulfilled') {
            $reason = 'fulfilled';
            $customerTitle = 'Billing active';
            $customerBody = 'Billing is confirmed and access is active.';
            $operatorBody = 'Fulfillment applied successfully. If customer reports mismatch, compare confirmed_plan_key/confirmed_addon_keys with local plan/add-ons.';
        } elseif ($lifecycleState === 'action_required') {
            $reason = $actionRequired ? 'stripe_action_required' : 'subscription_inactive';
            $customerTitle = 'Action required';
            $customerBody = $actionRequired
                ? 'Billing needs attention before access can be fully activated. Update your payment method or contact the team.'
                : 'Billing is no longer active for this tenant. Restart checkout or contact the team.';
            $operatorBody = $reason === 'subscription_inactive'
                ? 'Stripe subscription is inactive (status: '.$subscriptionStatus.'). Confirm whether access should be downgraded or restored.'
                : 'Stripe signaled a payment issue. Verify invoice/payment state and advise the customer to update billing.';
            $customerActionRequired = true;
            $operatorActionRequired = true;
        }

        return [
            'reason' => $reason,
            'action_required' => $customerActionRequired,
            'operator_action_required' => $operatorActionRequired,
            'customer_message' => [
                'title' => $customerTitle,
                'body' => $customerBody,
            ],
            'operator_note' => [
                'title' => $operatorTitle,
                'body' => $operatorBody,
            ],
        ];
    }


    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   current_plan:array<string,mixed>,
     *   commercial_context:array<string,mixed>,
     *   module_states:array<string,array<string,mixed>>,
     *   checklist:array<string,mixed>,
     *   current_plan_modules:array<string,array<string,mixed>>,
     *   locked_modules:array<int,array<string,mixed>>,
     *   add_on_capable_modules:array<int,array<string,mixed>>,
     *   plan_cards:array<int,array<string,mixed>>,
     *   addon_cards:array<int,array<string,mixed>>,
     *   enabled_addon_keys:array<int,string>
     * }
     */
    public function plansPayload(?int $tenantId): array
    {
        $payload = $this->rememberJourneyPayload($tenantId, 'plans', function () use ($tenantId): array {
            $content = (array) config('product_surfaces.plans', []);
        $moduleCatalog = (array) config('entitlements.modules', []);
        $moduleKeys = array_keys($moduleCatalog);
        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleKeys);

        $moduleStates = $this->applyDisplayLabels(
            tenantId: $tenantId,
            moduleStates: (array) ($resolved['modules'] ?? [])
        );
        $content = $this->applyContentLabelTokens($content, $moduleStates, $tenantId);
        $checklist = TenantModuleUi::checklist($moduleStates, $moduleKeys);
        $onboarding = $this->onboardingMeta($tenantId, $checklist);

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planCatalog = (array) config('entitlements.plans', []);
        $currentPlan = is_array($planCatalog[$planKey] ?? null) ? (array) $planCatalog[$planKey] : [];
        $currentPlanIncludes = $this->normalizeKeys((array) ($currentPlan['includes'] ?? []));

        $currentPlanModules = [];
        foreach ($currentPlanIncludes as $moduleKey) {
            if (! isset($moduleStates[$moduleKey]) || ! is_array($moduleStates[$moduleKey])) {
                continue;
            }

            $currentPlanModules[$moduleKey] = $moduleStates[$moduleKey];
        }

        $enabledAddonKeys = $this->enabledAddonKeys($tenantId);

        $addonCatalog = (array) config('entitlements.addons', []);
        $addonCards = [];
        foreach ($addonCatalog as $addonKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $normalizedAddonKey = strtolower(trim((string) $addonKey));
            if ($normalizedAddonKey === '') {
                continue;
            }

            $contentDefinition = is_array(($content['addons'][$normalizedAddonKey] ?? null))
                ? (array) $content['addons'][$normalizedAddonKey]
                : [];
            $includeKeys = $this->normalizeKeys((array) ($definition['includes'] ?? []));

            $modules = array_map(function (string $moduleKey) use ($moduleStates): array {
                $state = is_array($moduleStates[$moduleKey] ?? null) ? $moduleStates[$moduleKey] : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];

                return [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'state' => TenantModuleUi::present($state, $this->moduleLabel($moduleKey)),
                ];
            }, $includeKeys);

            $addonCards[] = [
                'addon_key' => $normalizedAddonKey,
                'label' => (string) ($contentDefinition['name'] ?? $definition['label'] ?? Str::title(str_replace('_', ' ', $normalizedAddonKey))),
                'price_display' => (string) ($contentDefinition['price_display'] ?? 'Add-on pricing'),
                'summary' => (string) ($contentDefinition['summary'] ?? ''),
                'enabled' => in_array($normalizedAddonKey, $enabledAddonKeys, true),
                'modules' => $modules,
            ];
        }

            return [
                'content' => $content,
                'tenant_id' => $tenantId,
                'onboarding' => $onboarding,
                'current_plan' => [
                    'key' => $planKey,
                    'label' => (string) ($currentPlan['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                    'track' => (string) ($currentPlan['track'] ?? 'shopify'),
                    'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
                    'includes' => $currentPlanIncludes,
                ],
                'commercial_context' => $this->commercialContext($tenantId, $moduleStates),
                'module_states' => $moduleStates,
                'checklist' => $checklist,
                'current_plan_modules' => $currentPlanModules,
                'locked_modules' => array_values((array) ($checklist['locked'] ?? [])),
                'add_on_capable_modules' => $this->addOnCapableModules($moduleStates, $addonCatalog),
                'plan_cards' => $this->planCards(
                    cardsConfig: (array) ($content['cards'] ?? []),
                    preferredOrder: $this->normalizeKeys((array) ($content['plan_order'] ?? [])),
                    activePlanKey: $planKey
                ),
                'addon_cards' => $addonCards,
                'enabled_addon_keys' => $enabledAddonKeys,
            ];
        });

        try {
            $this->journeyTelemetryService->observeTenantJourneyPayload($tenantId, 'plans', is_array($payload) ? (array) $payload : []);
        } catch (\Throwable) {
            // Best-effort telemetry must never interrupt payload delivery.
        }

        return $payload;
    }

    /**
     * @return array{
     *   content:array<string,mixed>,
     *   tenant_id:?int,
     *   plan:array{key:string,label:string,track:string,operating_mode:string},
     *   commercial_context:array<string,mixed>,
     *   module_states:array<string,array<string,mixed>>,
     *   cards:array<int,array<string,mixed>>,
     *   status_registry:array<string,array<string,mixed>>,
     *   categories:array<int,array{key:string,label:string,cards:array<int,array<string,mixed>>}>,
     *   counts:array{total:int,connected:int,setup_needed:int,locked:int,coming_soon:int}
     * }
     */
    public function integrationsPayload(?int $tenantId): array
    {
        $payload = $this->rememberJourneyPayload($tenantId, 'integrations', function () use ($tenantId): array {
            $content = (array) config('product_surfaces.integrations', []);
        $cardsConfig = (array) ($content['cards'] ?? []);
        $moduleKeys = [];

        foreach ($cardsConfig as $card) {
            if (! is_array($card)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($card['module_key'] ?? '')));
            if ($moduleKey !== '') {
                $moduleKeys[] = $moduleKey;
            }
        }

        $moduleKeys = $this->normalizeKeys($moduleKeys);
        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleKeys);
        $moduleStates = $this->applyDisplayLabels(
            tenantId: $tenantId,
            moduleStates: (array) ($resolved['modules'] ?? [])
        );
        $content = $this->applyContentLabelTokens($content, $moduleStates, $tenantId);
        $statusContext = $this->integrationStatusContext($tenantId);
        $onboarding = $this->onboardingMeta($tenantId);

        $cards = $this->integrationCards(
            cardsConfig: $cardsConfig,
            moduleStates: $moduleStates,
            content: $content,
            statusContext: $statusContext
        );
        $categoriesConfig = (array) ($content['categories'] ?? []);
        $groupedCards = [];
        foreach ($cards as $card) {
            $category = strtolower(trim((string) ($card['category'] ?? 'other')));
            if (! isset($groupedCards[$category])) {
                $groupedCards[$category] = [];
            }

            $groupedCards[$category][] = $card;
        }

        $categories = [];
        foreach ($categoriesConfig as $categoryKey => $categoryLabel) {
            $normalized = strtolower(trim((string) $categoryKey));
            if ($normalized === '' || ! isset($groupedCards[$normalized])) {
                continue;
            }

            $categories[] = [
                'key' => $normalized,
                'label' => (string) $categoryLabel,
                'cards' => $groupedCards[$normalized],
            ];
            unset($groupedCards[$normalized]);
        }

        foreach ($groupedCards as $categoryKey => $categoryCards) {
            $categories[] = [
                'key' => $categoryKey,
                'label' => $this->integrationCategoryLabel($categoryKey, $categoriesConfig),
                'cards' => $categoryCards,
            ];
        }

        $statusRegistry = [];
        foreach ($cards as $card) {
            $key = strtolower(trim((string) ($card['key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $statusRegistry[$key] = is_array($card['status_registry'] ?? null)
                ? (array) $card['status_registry']
                : [];
        }

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? '')));
        $planDefinition = is_array(config('entitlements.plans.'.$planKey))
            ? (array) config('entitlements.plans.'.$planKey)
            : [];

            return [
                'content' => $content,
                'tenant_id' => $tenantId,
                'onboarding' => $onboarding,
                'plan' => [
                    'key' => $planKey,
                    'label' => (string) ($planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey ?: 'unknown'))),
                    'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                    'operating_mode' => (string) ($resolved['operating_mode'] ?? config('entitlements.default_operating_mode', 'shopify')),
                ],
                'commercial_context' => $this->commercialContext($tenantId, $moduleStates),
                'module_states' => $moduleStates,
                'cards' => $cards,
                'status_registry' => $statusRegistry,
                'categories' => $categories,
                'counts' => [
                    'total' => count($cards),
                    'connected' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'connected')),
                    'setup_needed' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'setup_needed')),
                    'locked' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'locked')),
                    'coming_soon' => count(array_filter($cards, static fn (array $card): bool => ($card['state'] ?? '') === 'coming_soon')),
                ],
            ];
        });

        try {
            $this->journeyTelemetryService->observeTenantJourneyPayload($tenantId, 'integrations', is_array($payload) ? (array) $payload : []);
        } catch (\Throwable) {
            // Best-effort telemetry must never interrupt payload delivery.
        }

        return $payload;
    }

    /**
     * Additive, read-only onboarding meta fields derived from canonical provisioning + module/import readiness.
     *
     * @param  array<string,mixed>  $checklist
     * @param  array<string,mixed>  $importSummary
     * @return array{
     *   first_open_acknowledged:bool,
     *   first_opened_at:?string,
     *   is_first_touch:bool,
     *   recommended_phase:string
     * }
     */
    protected function onboardingMeta(?int $tenantId, array $checklist = [], array $importSummary = []): array
    {
        $signal = $this->firstOpenSignal($tenantId);

        $setupCount = (int) data_get($checklist, 'counts.setup', 0);
        $importState = strtolower(trim((string) ($importSummary['state'] ?? '')));
        $needsSetup = $setupCount > 0 || ($importState !== '' && $importState !== 'imported');

        if ($signal['is_first_touch']) {
            $recommendedPhase = 'handoff';
        } elseif ($signal['first_open_acknowledged']) {
            $recommendedPhase = $needsSetup ? 'ongoing_setup' : 'first_session';
        } else {
            // Default for non-provisioned tenants: no handoff/first-touch mode.
            $recommendedPhase = $needsSetup ? 'ongoing_setup' : 'first_session';
        }

        return [
            'first_open_acknowledged' => (bool) ($signal['first_open_acknowledged'] ?? false),
            'first_opened_at' => $signal['first_opened_at'] ?? null,
            'is_first_touch' => (bool) ($signal['is_first_touch'] ?? false),
            'recommended_phase' => $recommendedPhase,
        ];
    }

    /**
     * @return array{first_open_acknowledged:bool,first_opened_at:?string,is_first_touch:bool}
     */
    protected function firstOpenSignal(?int $tenantId): array
    {
        if ($tenantId === null || $tenantId <= 0) {
            return [
                'first_open_acknowledged' => false,
                'first_opened_at' => null,
                'is_first_touch' => false,
            ];
        }

        if (! $this->hasTable('tenant_onboarding_blueprint_provisionings')) {
            return [
                'first_open_acknowledged' => false,
                'first_opened_at' => null,
                'is_first_touch' => false,
            ];
        }

        if (! $this->hasColumn('tenant_onboarding_blueprint_provisionings', 'provisioned_tenant_id')) {
            return [
                'first_open_acknowledged' => false,
                'first_opened_at' => null,
                'is_first_touch' => false,
            ];
        }

        /** @var TenantOnboardingBlueprintProvisioning|null $provisioning */
        $provisioning = TenantOnboardingBlueprintProvisioning::query()
            ->where('provisioned_tenant_id', $tenantId)
            ->orderBy('id')
            ->first();

        if (! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            return [
                'first_open_acknowledged' => false,
                'first_opened_at' => null,
                'is_first_touch' => false,
            ];
        }

        $ack = $provisioning->first_opened_at !== null;

        return [
            'first_open_acknowledged' => $ack,
            'first_opened_at' => $provisioning->first_opened_at?->toIso8601String(),
            'is_first_touch' => ! $ack,
        ];
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    protected function rememberJourneyPayload(?int $tenantId, string $payloadType, callable $resolver)
    {
        $ttlSeconds = $this->journeyCacheTtlSeconds();
        if ($ttlSeconds <= 0) {
            return $resolver();
        }

        try {
            return Cache::remember(
                $this->journeyCacheKey($tenantId, $payloadType),
                now()->addSeconds($ttlSeconds),
                $resolver
            );
        } catch (\Throwable) {
            return $resolver();
        }
    }

    public function forgetTenantCache(?int $tenantId): void
    {
        foreach (self::JOURNEY_PAYLOAD_TYPES as $payloadType) {
            try {
                Cache::forget($this->journeyCacheKey($tenantId, $payloadType));
            } catch (\Throwable) {
                // Best effort cache invalidation for label/commercial updates.
            }
        }

        $summaryCacheKey = $tenantId === null ? 'tenant:none' : 'tenant:'.$tenantId;
        unset($this->journeySummaryCache[$summaryCacheKey]);
    }

    protected function journeyCacheKey(?int $tenantId, string $payloadType): string
    {
        $tenantCacheKey = $tenantId === null ? 'none' : (string) max(0, $tenantId);

        return sprintf(
            'shopify:embedded:tenant:%s:%s:v1',
            $tenantCacheKey,
            strtolower(trim($payloadType))
        );
    }

    protected function journeyCacheTtlSeconds(): int
    {
        return max(0, (int) config('shopify_embedded.journey_cache_ttl_seconds', 60));
    }

    /**
     * @return array{
     *   customer_summary:array<string,mixed>,
     *   import_summary:array<string,mixed>
     * }
     */
    protected function merchantJourneySummary(?int $tenantId): array
    {
        $cacheKey = $tenantId === null ? 'tenant:none' : 'tenant:'.$tenantId;
        if (isset($this->journeySummaryCache[$cacheKey])) {
            return $this->journeySummaryCache[$cacheKey];
        }

        $customerSummary = $this->customerSummary($tenantId);
        $importSummary = $this->importSummary($tenantId, (int) ($customerSummary['total_profiles'] ?? 0));

        return $this->journeySummaryCache[$cacheKey] = [
            'customer_summary' => $customerSummary,
            'import_summary' => $importSummary,
        ];
    }

    protected function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->schemaTableCache)) {
            return $this->schemaTableCache[$table];
        }

        return $this->schemaTableCache[$table] = Schema::hasTable($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        $cacheKey = $table.':'.$column;
        if (array_key_exists($cacheKey, $this->schemaColumnCache)) {
            return $this->schemaColumnCache[$cacheKey];
        }

        return $this->schemaColumnCache[$cacheKey] = Schema::hasColumn($table, $column);
    }

    /**
     * @param  array<string,mixed>  $cardsConfig
     * @param  array<int,string>  $preferredOrder
     * @return array<int,array<string,mixed>>
     */
    protected function planCards(array $cardsConfig, array $preferredOrder, ?string $activePlanKey): array
    {
        $catalog = (array) config('entitlements.plans', []);
        $orderedKeys = $preferredOrder !== []
            ? $preferredOrder
            : $this->normalizeKeys(array_keys($cardsConfig));

        if ($orderedKeys === []) {
            $orderedKeys = $this->normalizeKeys(array_keys($catalog));
        }

        $cards = [];
        foreach ($orderedKeys as $planKey) {
            $planDefinition = is_array($catalog[$planKey] ?? null) ? (array) $catalog[$planKey] : [];
            $cardContent = is_array($cardsConfig[$planKey] ?? null) ? (array) $cardsConfig[$planKey] : [];

            if ($planDefinition === [] && $cardContent === []) {
                continue;
            }

            $includeKeys = $this->normalizeKeys((array) ($planDefinition['includes'] ?? []));
            $moduleLabels = array_map(fn (string $moduleKey): string => $this->moduleLabel($moduleKey), $includeKeys);

            $cards[] = [
                'plan_key' => $planKey,
                'label' => (string) ($cardContent['name'] ?? $planDefinition['label'] ?? Str::title(str_replace('_', ' ', $planKey))),
                'price_display' => (string) ($cardContent['price_display'] ?? 'Custom pricing'),
                'summary' => (string) ($cardContent['summary'] ?? ''),
                'highlights' => array_values(array_map('strval', (array) ($cardContent['highlights'] ?? []))),
                'track' => (string) ($planDefinition['track'] ?? 'shopify'),
                'modules' => $moduleLabels,
                'includes' => $includeKeys,
                'cta' => is_array($cardContent['cta'] ?? null) ? (array) $cardContent['cta'] : null,
                'is_current' => $activePlanKey !== null && $activePlanKey === $planKey,
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<string,array<string,mixed>>
     */
    protected function applyDisplayLabels(?int $tenantId, array $moduleStates): array
    {
        if ($moduleStates === []) {
            return $moduleStates;
        }

        $labels = $this->displayLabelResolver->moduleLabels($tenantId);
        if ($labels === []) {
            return $moduleStates;
        }

        foreach ($moduleStates as $moduleKey => $state) {
            if (! is_array($state)) {
                continue;
            }

            $normalizedKey = strtolower(trim((string) ($state['module_key'] ?? $moduleKey)));
            $label = trim((string) ($labels[$normalizedKey] ?? ''));
            if ($label === '') {
                continue;
            }

            $moduleStates[$moduleKey]['label'] = $label;
        }

        return $moduleStates;
    }

    /**
     * @param  array<int,array<string,mixed>>  $actions
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<int,array<string,mixed>>
     */
    protected function recommendedActions(array $actions, array $moduleStates): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $title = trim((string) ($action['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($action['module_key'] ?? '')));
            $moduleState = null;
            if ($moduleKey !== '' && is_array($moduleStates[$moduleKey] ?? null)) {
                $moduleState = TenantModuleUi::present($moduleStates[$moduleKey], $this->moduleLabel($moduleKey));
            }

            $normalized[] = [
                'title' => $title,
                'description' => trim((string) ($action['description'] ?? '')),
                'href' => trim((string) ($action['href'] ?? '')),
                'module_key' => $moduleKey !== '' ? $moduleKey : null,
                'module_state' => $moduleState,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<string,mixed>  $addonCatalog
     * @return array<int,array<string,mixed>>
     */
    protected function addOnCapableModules(array $moduleStates, array $addonCatalog): array
    {
        $moduleKeys = [];
        foreach ($addonCatalog as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach ($this->normalizeKeys((array) ($definition['includes'] ?? [])) as $moduleKey) {
                $moduleKeys[$moduleKey] = true;
            }
        }

        $rows = [];
        foreach (array_keys($moduleKeys) as $moduleKey) {
            $state = is_array($moduleStates[$moduleKey] ?? null)
                ? $moduleStates[$moduleKey]
                : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];

            $rows[] = TenantModuleUi::present($state, $this->moduleLabel($moduleKey));
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            strtolower(trim((string) ($left['label'] ?? ''))),
            strtolower(trim((string) ($right['label'] ?? '')))
        ));

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $cardsConfig
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @param  array<string,mixed>  $content
     * @return array<int,array<string,mixed>>
     */
    protected function integrationCards(
        array $cardsConfig,
        array $moduleStates,
        array $content,
        array $statusContext
    ): array
    {
        $cards = [];
        foreach ($cardsConfig as $cardKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $integrationKey = strtolower(trim((string) ($definition['key'] ?? $cardKey)));
            if ($integrationKey === '') {
                continue;
            }

            $moduleKey = strtolower(trim((string) ($definition['module_key'] ?? 'integrations')));
            $moduleState = is_array($moduleStates[$moduleKey] ?? null)
                ? $moduleStates[$moduleKey]
                : [
                    'module_key' => $moduleKey,
                    'label' => $this->moduleLabel($moduleKey),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                    'coming_soon' => false,
                    'upgrade_prompt_eligible' => true,
                ];
            $moduleStateUi = TenantModuleUi::present($moduleState, $this->moduleLabel($moduleKey));

            $availability = strtolower(trim((string) ($definition['availability'] ?? 'available')));
            if (! in_array($availability, ['available', 'locked', 'coming_soon'], true)) {
                $availability = 'available';
            }

            $fallbackMode = strtolower(trim((string) ($definition['fallback_mode'] ?? 'none')));
            if (! in_array($fallbackMode, ['manual_import', 'csv_upload', 'none'], true)) {
                $fallbackMode = 'none';
            }

            $statusDefinition = is_array($definition['status'] ?? null) ? (array) $definition['status'] : [];
            $connected = (bool) ($definition['mock_connected'] ?? false);
            if (! $connected && $this->integrationBuiltInConnected($integrationKey, $fallbackMode, $statusDefinition)) {
                $connected = true;
            }
            $state = $this->integrationResolvedState($availability, $moduleStateUi, $connected);
            $stateLabel = match ($state) {
                'connected' => 'Connected',
                'setup_needed' => 'Setup Needed',
                'locked' => 'Locked',
                default => 'Coming Soon',
            };

            $fallbackHref = trim((string) ($definition['fallback_href'] ?? ''));
            $fallbackLabel = $this->integrationFallbackLabel($fallbackMode);
            $ctas = is_array($definition['ctas'] ?? null) ? (array) $definition['ctas'] : [];
            $setup = is_array($definition['setup'] ?? null) ? (array) $definition['setup'] : [];
            $setupSteps = $this->normalizeStringList((array) ($setup['setup_steps'] ?? []));
            $requiredFields = $this->normalizeStringList((array) ($setup['required_fields'] ?? []));
            $fallbackOptions = $this->normalizeStringList((array) ($setup['fallback_options'] ?? []));
            $notes = $this->normalizeStringList((array) ($setup['notes'] ?? []));
            $upgradeMessage = trim((string) ($setup['upgrade_message'] ?? ''));

            if ($setupSteps === []) {
                $setupSteps = [
                    'Review module state and fallback options for this integration.',
                ];
            }
            if ($requiredFields === []) {
                $requiredFields = ['No required fields defined yet.'];
            }
            if ($fallbackOptions === []) {
                $fallbackOptions = [
                    'You can still use this system without this integration.',
                    $fallbackMode !== 'none'
                        ? $fallbackLabel
                        : 'Continue with manual operations until this integration is available.',
                ];
            }
            if ($upgradeMessage === '') {
                $upgradeMessage = 'Upgrade and module entitlement determine when this integration can be activated.';
            }

            $cta = $this->integrationCtaForState(
                integrationKey: $integrationKey,
                state: $state,
                ctas: $ctas,
                content: $content,
                fallbackMode: $fallbackMode,
                fallbackHref: $fallbackHref,
                fallbackLabel: $fallbackLabel
            );
            $statusRegistry = $this->integrationStatusRegistry(
                integrationKey: $integrationKey,
                state: $state,
                fallbackMode: $fallbackMode,
                statusDefinition: $statusDefinition,
                moduleStateUi: $moduleStateUi,
                statusContext: $statusContext
            );

            $cards[] = [
                'key' => $integrationKey,
                'module_key' => $moduleKey,
                'title' => (string) ($definition['title'] ?? Str::headline($integrationKey)),
                'description' => (string) ($definition['description'] ?? ''),
                'category' => strtolower(trim((string) ($definition['category'] ?? 'other'))),
                'availability' => $availability,
                'plan_requirement' => trim((string) ($definition['plan_requirement'] ?? '')),
                'state' => $state,
                'state_label' => $stateLabel,
                'connected' => $state === 'connected',
                'module_state' => $moduleStateUi,
                'fallback' => [
                    'mode' => $fallbackMode,
                    'label' => $fallbackLabel,
                    'href' => $fallbackHref !== '' ? $fallbackHref : null,
                    'available' => $fallbackMode !== 'none',
                ],
                'setup' => [
                    'setup_steps' => $setupSteps,
                    'required_fields' => $requiredFields,
                    'fallback_options' => $fallbackOptions,
                    'notes' => $notes,
                    'upgrade_message' => $upgradeMessage,
                ],
                'status_registry' => $statusRegistry,
                'cta' => $cta,
            ];
        }

        return $cards;
    }

    protected function integrationResolvedState(string $availability, array $moduleStateUi, bool $connected): string
    {
        if ($availability === 'coming_soon') {
            return 'coming_soon';
        }

        if ($availability === 'locked') {
            return 'locked';
        }

        if (($moduleStateUi['ui_state'] ?? '') === 'coming_soon') {
            return 'coming_soon';
        }

        if (($moduleStateUi['ui_state'] ?? '') === 'locked') {
            return 'locked';
        }

        return $connected ? 'connected' : 'setup_needed';
    }

    /**
     * @param  array<string,mixed>  $ctas
     * @param  array<string,mixed>  $content
     * @return array{label:string,href:string,kind:string}
     */
    protected function integrationCtaForState(
        string $integrationKey,
        string $state,
        array $ctas,
        array $content,
        string $fallbackMode,
        string $fallbackHref,
        string $fallbackLabel
    ): array {
        $upgradeCta = is_array($content['upgrade_cta'] ?? null) ? (array) $content['upgrade_cta'] : [];
        $contactCta = is_array($content['contact_cta'] ?? null) ? (array) $content['contact_cta'] : [];

        if ($state === 'locked') {
            return [
                'label' => trim((string) ($ctas['upgrade_label'] ?? $upgradeCta['label'] ?? 'Upgrade to unlock')),
                'href' => trim((string) ($upgradeCta['href'] ?? '/shopify/app/plans')),
                'kind' => 'upgrade',
            ];
        }

        if ($state === 'coming_soon') {
            return [
                'label' => trim((string) ($ctas['coming_soon_label'] ?? $contactCta['label'] ?? 'Learn more')),
                'href' => trim((string) ($contactCta['href'] ?? '/platform/contact?intent=integrations')),
                'kind' => 'coming_soon',
            ];
        }

        if ($state === 'setup_needed' && $fallbackMode !== 'none' && $fallbackHref !== '') {
            return [
                'label' => trim((string) ($ctas['manual_label'] ?? $fallbackLabel)),
                'href' => $fallbackHref,
                'kind' => 'fallback',
            ];
        }

        if ($state === 'connected') {
            return [
                'label' => trim((string) ($ctas['manage_label'] ?? 'Connected')),
                'href' => trim((string) ($fallbackHref !== '' ? $fallbackHref : '/shopify/app/integrations?integration='.$integrationKey)),
                'kind' => 'connected',
            ];
        }

        return [
            'label' => trim((string) ($ctas['connect_label'] ?? 'Connect (Placeholder)')),
            'href' => '/shopify/app/integrations?integration='.$integrationKey,
            'kind' => 'connect',
        ];
    }

    protected function integrationFallbackLabel(string $fallbackMode): string
    {
        return match ($fallbackMode) {
            'manual_import' => 'Import manually',
            'csv_upload' => 'Upload CSV fallback',
            default => 'No fallback configured',
        };
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   email:array<string,mixed>,
     *   sms:array{
     *     supported:bool,
     *     default_sender_key:?string,
     *     default_sender_label:?string
     *   }
     * }
     */
    protected function integrationStatusContext(?int $tenantId): array
    {
        $email = $this->tenantEmailSettingsService->resolvedForTenant($tenantId);
        $smsDefaultSender = $this->twilioSenderConfigService->defaultSender();

        return [
            'tenant_id' => $tenantId,
            'email' => $email,
            'sms' => [
                'supported' => $this->twilioSenderConfigService->smsSupported(),
                'default_sender_key' => $this->nullableString($smsDefaultSender['key'] ?? null),
                'default_sender_label' => $this->nullableString($smsDefaultSender['label'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $moduleStateUi
     * @param  array<string,mixed>  $statusContext
     * @return array{
     *   key:string,
     *   state:string,
     *   status_label:string,
     *   source_label:string,
     *   last_checked_at:?string,
     *   setup_mode:string,
     *   notes:array<int,string>,
     *   can_configure:bool,
     *   is_mocked:bool,
     *   configured_in_app:bool,
     *   using_fallback:bool,
     *   summary:string
     * }
     */
    protected function integrationStatusRegistry(
        string $integrationKey,
        string $state,
        string $fallbackMode,
        array $statusDefinition,
        array $moduleStateUi,
        array $statusContext
    ): array {
        $setupMode = $this->integrationSetupMode($integrationKey, $state, $fallbackMode, $statusDefinition);
        $configuredInApp = $this->integrationConfiguredInApp(
            integrationKey: $integrationKey,
            state: $state,
            setupMode: $setupMode,
            moduleStateUi: $moduleStateUi,
            statusContext: $statusContext
        );
        $sourceLabel = $this->integrationSourceLabel(
            integrationKey: $integrationKey,
            state: $state,
            setupMode: $setupMode,
            fallbackMode: $fallbackMode,
            configuredInApp: $configuredInApp,
            statusDefinition: $statusDefinition,
            statusContext: $statusContext
        );
        $lastCheckedAt = $this->integrationLastCheckedAt(
            integrationKey: $integrationKey,
            statusDefinition: $statusDefinition,
            statusContext: $statusContext
        );
        $usingFallback = in_array($setupMode, ['manual', 'csv'], true) || in_array($fallbackMode, ['manual_import', 'csv_upload'], true);

        $notes = $this->normalizeStringList((array) ($statusDefinition['notes'] ?? []));
        if ($state === 'coming_soon') {
            $notes[] = 'This connector is roadmap-visible only in the current phase.';
        } elseif ($state === 'locked') {
            $notes[] = 'Access is controlled by tenant entitlement and plan/add-on profile.';
        } elseif (! $configuredInApp) {
            $notes[] = 'Status is derived from local configuration and entitlement context only.';
        }
        $notes = array_values(array_unique($this->normalizeStringList($notes)));

        $statusLabel = match ($state) {
            'connected' => $configuredInApp ? 'Configured' : 'Available',
            'setup_needed' => 'Setup Needed',
            'locked' => 'Locked',
            default => 'Coming Soon',
        };

        $summary = match ($state) {
            'connected' => $usingFallback
                ? 'This integration path is ready through built-in fallback workflow.'
                : 'This integration is marked as configured in local app context.',
            'setup_needed' => $usingFallback
                ? 'Manual/CSV fallback is available while setup remains incomplete.'
                : 'Connector setup is still required before this path is considered configured.',
            'locked' => 'Unavailable for this tenant profile until plan/add-on access changes.',
            default => 'Roadmap placeholder. No live connector behavior is active yet.',
        };

        return [
            'key' => $integrationKey,
            'state' => $state,
            'status_label' => $statusLabel,
            'source_label' => $sourceLabel,
            'last_checked_at' => $lastCheckedAt,
            'setup_mode' => $setupMode,
            'notes' => $notes,
            'can_configure' => in_array($state, ['connected', 'setup_needed'], true),
            'is_mocked' => $this->integrationIsMocked($integrationKey, $statusDefinition, $setupMode),
            'configured_in_app' => $configuredInApp,
            'using_fallback' => $usingFallback,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationBuiltInConnected(string $integrationKey, string $fallbackMode, array $statusDefinition): bool
    {
        if (array_key_exists('built_in_connected', $statusDefinition)) {
            return (bool) $statusDefinition['built_in_connected'];
        }

        if ($integrationKey === 'manual_entry') {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationSetupMode(
        string $integrationKey,
        string $state,
        string $fallbackMode,
        array $statusDefinition
    ): string {
        $configuredMode = strtolower(trim((string) ($statusDefinition['setup_mode'] ?? '')));
        if (in_array($configuredMode, ['manual', 'csv', 'direct', 'placeholder'], true)) {
            return $configuredMode;
        }

        if ($state === 'coming_soon') {
            return 'placeholder';
        }

        if ($fallbackMode === 'manual_import') {
            return 'manual';
        }

        if ($fallbackMode === 'csv_upload' || $integrationKey === 'csv_import') {
            return 'csv';
        }

        return 'direct';
    }

    /**
     * @param  array<string,mixed>  $moduleStateUi
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationConfiguredInApp(
        string $integrationKey,
        string $state,
        string $setupMode,
        array $moduleStateUi,
        array $statusContext
    ): bool {
        if (! in_array($state, ['connected', 'setup_needed'], true)) {
            return false;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            $email = is_array($statusContext['email'] ?? null) ? $statusContext['email'] : [];
            $providerStatus = strtolower(trim((string) ($email['provider_status'] ?? 'not_configured')));
            $enabled = (bool) ($email['email_enabled'] ?? false);

            return $enabled && in_array($providerStatus, ['configured', 'healthy'], true);
        }

        if ($integrationKey === 'sms_gateway') {
            return (bool) data_get($statusContext, 'sms.supported', false);
        }

        if ($integrationKey === 'manual_entry') {
            return true;
        }

        if ($setupMode === 'csv') {
            return false;
        }

        return strtolower(trim((string) ($moduleStateUi['setup_status'] ?? ''))) === 'configured'
            || $state === 'connected';
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationSourceLabel(
        string $integrationKey,
        string $state,
        string $setupMode,
        string $fallbackMode,
        bool $configuredInApp,
        array $statusDefinition,
        array $statusContext
    ): string {
        if ($state === 'locked') {
            return 'Plan entitlement';
        }

        if ($state === 'coming_soon') {
            return 'Roadmap placeholder';
        }

        $configured = trim((string) ($statusDefinition['source_label'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            $source = strtolower(trim((string) data_get($statusContext, 'email.source', 'config_fallback')));

            return $source === 'tenant_email_settings'
                ? 'Tenant email settings'
                : 'Fallback email config';
        }

        if ($integrationKey === 'sms_gateway') {
            if ((bool) data_get($statusContext, 'sms.supported', false)) {
                $senderLabel = trim((string) data_get($statusContext, 'sms.default_sender_label', ''));

                return $senderLabel !== ''
                    ? 'Twilio sender · '.$senderLabel
                    : 'Twilio sender configuration';
            }

            return 'No live SMS sender configured';
        }

        if ($setupMode === 'manual') {
            return $configuredInApp ? 'Built-in manual workflow' : 'Manual workflow';
        }

        if ($setupMode === 'csv') {
            return 'CSV upload fallback';
        }

        if ($integrationKey === 'shopify_orders') {
            return 'Shopify embedded app context';
        }

        if ($fallbackMode === 'none' && ! $configuredInApp) {
            return 'Placeholder direct connector';
        }

        return 'Direct connector profile';
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     * @param  array<string,mixed>  $statusContext
     */
    protected function integrationLastCheckedAt(
        string $integrationKey,
        array $statusDefinition,
        array $statusContext
    ): ?string {
        $configured = $this->nullableString($statusDefinition['last_checked_at'] ?? null);
        if ($configured !== null) {
            return $configured;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            return $this->nullableString(data_get($statusContext, 'email.last_tested_at'));
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $statusDefinition
     */
    protected function integrationIsMocked(string $integrationKey, array $statusDefinition, string $setupMode): bool
    {
        if (array_key_exists('is_mocked', $statusDefinition)) {
            return (bool) $statusDefinition['is_mocked'];
        }

        if (in_array($integrationKey, ['manual_entry', 'csv_import', 'sms_gateway'], true)) {
            return false;
        }

        if ($integrationKey === 'email' || $integrationKey === 'klaviyo') {
            return false;
        }

        return $setupMode !== 'manual' && $setupMode !== 'csv';
    }

    /**
     * @param  array<string,mixed>  $categories
     */
    protected function integrationCategoryLabel(string $categoryKey, array $categories): string
    {
        $resolved = strtolower(trim($categoryKey));

        if ($resolved !== '' && array_key_exists($resolved, $categories)) {
            return (string) $categories[$resolved];
        }

        return Str::headline($resolved === '' ? 'other' : $resolved);
    }

    /**
     * @return array{
     *   total_profiles:int,
     *   reachable_profiles:int,
     *   customers_with_points:int,
     *   linked_external_profiles:int
     * }
     */
    protected function customerSummary(?int $tenantId): array
    {
        if (! $this->hasTable('marketing_profiles')) {
            return [
                'total_profiles' => 0,
                'reachable_profiles' => 0,
                'customers_with_points' => 0,
                'linked_external_profiles' => 0,
            ];
        }

        $profilesQuery = MarketingProfile::query();
        if ($tenantId === null) {
            $profilesQuery->whereNull('tenant_id');
        } else {
            $profilesQuery->where('tenant_id', $tenantId);
        }

        $totalProfiles = (int) (clone $profilesQuery)->count();
        $reachableProfiles = (int) (clone $profilesQuery)
            ->where(function ($query): void {
                $query
                    ->where(function ($emailQuery): void {
                        $emailQuery
                            ->whereNotNull('normalized_email')
                            ->where('normalized_email', '!=', '');
                    })
                    ->orWhere(function ($phoneQuery): void {
                        $phoneQuery
                            ->whereNotNull('normalized_phone')
                            ->where('normalized_phone', '!=', '');
                    });
            })
            ->count();
        $customersWithPoints = 0;
        if ($this->hasTable('candle_cash_balances')) {
            $customersWithPoints = (int) (clone $profilesQuery)
                ->whereIn('id', function ($query): void {
                    $query->from('candle_cash_balances')
                        ->select('marketing_profile_id')
                        ->where('balance', '>', 0);
                })
                ->count();
        }

        $linkedExternalProfiles = 0;
        if ($this->hasTable('customer_external_profiles')) {
            $externalQuery = CustomerExternalProfile::query();

            if ($this->hasColumn('customer_external_profiles', 'tenant_id')) {
                if ($tenantId === null) {
                    $externalQuery->whereNull('tenant_id');
                } else {
                    $externalQuery->where('tenant_id', $tenantId);
                }

                $linkedExternalProfiles = (int) $externalQuery->count();
            } else {
                $profileIds = (clone $profilesQuery)->pluck('id')->all();
                $linkedExternalProfiles = $profileIds === []
                    ? 0
                    : (int) $externalQuery
                        ->whereIn('marketing_profile_id', $profileIds)
                        ->count();
            }
        }

        return [
            'total_profiles' => $totalProfiles,
            'reachable_profiles' => $reachableProfiles,
            'customers_with_points' => $customersWithPoints,
            'linked_external_profiles' => $linkedExternalProfiles,
        ];
    }

    /**
     * @return array{
     *   state:string,
     *   label:string,
     *   description:string,
     *   progress_note:string,
     *   cta:array{label:string,href:string},
     *   latest_run:?array<string,mixed>,
     *   is_stale:bool,
     *   stale_after_days:int
     * }
     */
    protected function importSummary(?int $tenantId, int $profileCount): array
    {
        $latestMarketingRun = $this->latestMarketingImportRun($tenantId);
        $latestShopifyRun = $this->latestShopifyImportRun($tenantId);
        $latestRun = $this->latestImportRunPayload($latestMarketingRun, $latestShopifyRun);

        $status = strtolower(trim((string) ($latestRun['status'] ?? '')));
        $isRunning = in_array($status, ['running', 'queued', 'pending'], true)
            || (($latestRun['source'] ?? null) === 'shopify_import'
                && ! empty($latestRun['started_at'])
                && empty($latestRun['finished_at']));
        $isFailed = in_array($status, ['failed', 'error', 'blocked'], true);

        $state = 'not_started';
        if ($profileCount > 0) {
            $state = 'imported';
        } elseif ($latestRun !== null && $isRunning) {
            $state = 'in_progress';
        } elseif ($latestRun !== null && $isFailed) {
            $state = 'attention';
        }

        $staleAfterDays = max(1, (int) config('shopify_embedded.sync_stale_after_days', 3));
        $latestSyncAtRaw = (string) ($latestRun['finished_at'] ?? $latestRun['started_at'] ?? '');
        $latestSyncAt = null;
        if ($latestSyncAtRaw !== '') {
            try {
                $latestSyncAt = \Carbon\CarbonImmutable::parse($latestSyncAtRaw);
            } catch (\Throwable) {
                $latestSyncAt = null;
            }
        }
        $isStale = $state === 'imported'
            && $latestSyncAt !== null
            && $latestSyncAt->lessThanOrEqualTo(now()->subDays($staleAfterDays));

        $label = match ($state) {
            'imported' => 'Synced',
            'in_progress' => 'Syncing',
            'attention' => 'Sync issue',
            default => 'Not synced',
        };

        $description = match ($state) {
            'imported' => $isStale
                ? 'Customer sync needs refresh.'
                : 'Customers are synced and ready.',
            'in_progress' => 'Customer sync is running.',
            'attention' => 'The last sync did not complete.',
            default => 'Customer sync has not started yet.',
        };

        $progressNote = match ($state) {
            'imported' => $isStale
                ? (! empty($latestRun['finished_at_display'])
                    ? 'Last synced '.$latestRun['finished_at_display'].'.'
                    : (! empty($latestRun['started_at_display'])
                        ? 'Last sync started '.$latestRun['started_at_display'].'.'
                        : 'Last sync is stale.'))
                : number_format($profileCount).' customer profile'.($profileCount === 1 ? '' : 's').' loaded.',
            'in_progress' => ! empty($latestRun['started_at_display'])
                ? 'Started '.$latestRun['started_at_display'].'.'
                : 'Sync in progress.',
            'attention' => ! empty($latestRun['status_label'])
                ? 'Latest status: '.$latestRun['status_label'].'.'
                : 'Retry sync to continue.',
            default => $latestRun !== null
                ? 'No customer profiles are available yet.'
                : 'No sync run found yet.',
        };

        $cta = match (true) {
            $state === 'imported' && $isStale => ['label' => 'Retry sync', 'href' => route('shopify.app.integrations', [], false)],
            $state === 'imported' => ['label' => 'Open customers', 'href' => route('shopify.app.customers.manage', [], false)],
            $state === 'in_progress' => ['label' => 'View sync status', 'href' => route('shopify.app.integrations', [], false)],
            $state === 'attention' => ['label' => 'Retry sync', 'href' => route('shopify.app.integrations', [], false)],
            default => ['label' => 'Sync customers', 'href' => route('shopify.app.integrations', [], false)],
        };

        return [
            'state' => $state,
            'label' => $label,
            'description' => $description,
            'progress_note' => $progressNote,
            'cta' => $cta,
            'latest_run' => $latestRun,
            'is_stale' => $isStale,
            'stale_after_days' => $staleAfterDays,
        ];
    }

    protected function latestMarketingImportRun(?int $tenantId): ?MarketingImportRun
    {
        if (! $this->hasTable('marketing_import_runs')) {
            return null;
        }

        $query = MarketingImportRun::query()->orderByDesc('id');

        if ($this->hasColumn('marketing_import_runs', 'tenant_id')) {
            if ($tenantId === null) {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $tenantId);
            }
        } elseif ($tenantId !== null) {
            return null;
        }

        return $query->first();
    }

    protected function latestShopifyImportRun(?int $tenantId): ?ShopifyImportRun
    {
        if (! $this->hasTable('shopify_import_runs') || ! $this->hasTable('shopify_stores')) {
            return null;
        }

        $storeKeys = $this->shopifyStoreKeysForTenant($tenantId);
        if ($storeKeys === []) {
            return null;
        }

        return ShopifyImportRun::query()
            ->whereIn('store_key', $storeKeys)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int,string>
     */
    protected function shopifyStoreKeysForTenant(?int $tenantId): array
    {
        if (! $this->hasTable('shopify_stores')) {
            return [];
        }

        $query = ShopifyStore::query()->select('store_key');

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        return $query->pluck('store_key')
            ->map(static fn ($key): string => strtolower(trim((string) $key)))
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function latestImportRunPayload(?MarketingImportRun $marketingRun, ?ShopifyImportRun $shopifyRun): ?array
    {
        if ($marketingRun === null && $shopifyRun === null) {
            return null;
        }

        $marketingMoment = $marketingRun
            ? ($marketingRun->finished_at ?? $marketingRun->started_at ?? $marketingRun->updated_at ?? $marketingRun->created_at)
            : null;
        $shopifyMoment = $shopifyRun
            ? ($shopifyRun->finished_at ?? $shopifyRun->started_at ?? $shopifyRun->updated_at ?? $shopifyRun->created_at)
            : null;

        if ($marketingRun !== null && ($shopifyMoment === null || ($marketingMoment !== null && $marketingMoment->greaterThanOrEqualTo($shopifyMoment)))) {
            return $this->marketingImportRunPayload($marketingRun);
        }

        return $shopifyRun !== null
            ? $this->shopifyImportRunPayload($shopifyRun)
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function marketingImportRunPayload(MarketingImportRun $run): array
    {
        $summary = is_array($run->summary) ? $run->summary : [];
        $status = strtolower(trim((string) ($run->status ?? 'unknown'))) ?: 'unknown';
        $processed = data_get($summary, 'checkpoint.processed')
            ?? data_get($summary, 'processed')
            ?? data_get($summary, 'candidates_scanned')
            ?? data_get($summary, 'created')
            ?? data_get($summary, 'rows_processed')
            ?? null;
        $errors = data_get($summary, 'checkpoint.errors')
            ?? data_get($summary, 'errors')
            ?? null;

        return [
            'source' => 'marketing_import',
            'source_label' => (string) ($run->source_label ?: 'Customer import'),
            'status' => $status,
            'status_label' => Str::headline(str_replace('_', ' ', $status)),
            'started_at' => optional($run->started_at)->toIso8601String(),
            'started_at_display' => optional($run->started_at)->format('M j, g:i A'),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'finished_at_display' => optional($run->finished_at)->format('M j, g:i A'),
            'processed' => $processed !== null ? (int) $processed : null,
            'errors' => $errors !== null ? (int) $errors : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function shopifyImportRunPayload(ShopifyImportRun $run): array
    {
        $status = $run->finished_at === null && $run->started_at !== null ? 'running' : 'completed';

        return [
            'source' => 'shopify_import',
            'source_label' => 'Shopify sync',
            'status' => $status,
            'status_label' => Str::headline($status),
            'started_at' => optional($run->started_at)->toIso8601String(),
            'started_at_display' => optional($run->started_at)->format('M j, g:i A'),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'finished_at_display' => optional($run->finished_at)->format('M j, g:i A'),
            'processed' => (int) (($run->imported_count ?? 0) + ($run->updated_count ?? 0)),
            'errors' => (int) ($run->mapping_exceptions_count ?? 0),
            'imported_count' => (int) ($run->imported_count ?? 0),
            'updated_count' => (int) ($run->updated_count ?? 0),
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function enabledAddonKeys(?int $tenantId): array
    {
        if ($tenantId === null || ! $this->hasTable('tenant_access_addons')) {
            return [];
        }

        return TenantAccessAddon::query()
            ->forTenantId($tenantId)
            ->where('enabled', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->pluck('addon_key')
            ->map(static fn ($value): string => strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function moduleLabel(string $moduleKey): string
    {
        $key = strtolower(trim($moduleKey));

        return (string) data_get(
            config('entitlements.modules', []),
            $key.'.label',
            Str::title(str_replace('_', ' ', $key))
        );
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<string,mixed>
     */
    protected function applyContentLabelTokens(array $content, array $moduleStates, ?int $tenantId = null): array
    {
        $resolvedLabels = $this->displayLabelResolver->resolve($tenantId);
        $tokenMap = is_array($resolvedLabels['token_map'] ?? null)
            ? (array) $resolvedLabels['token_map']
            : [];

        foreach (['rewards', 'birthdays', 'customers', 'campaigns', 'integrations', 'settings'] as $moduleKey) {
            $stateLabel = trim((string) data_get($moduleStates, $moduleKey.'.label', ''));
            if ($stateLabel !== '') {
                $tokenMap['{{'.$moduleKey.'_label}}'] = $stateLabel;
            }
        }

        return $this->replaceTokensInArray($content, $tokenMap);
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array{
     *   template_key:?string,
     *   label_source:string,
     *   labels:array<string,string>,
     *   template_missing:bool
     * }
     */
    protected function commercialContext(?int $tenantId, array $moduleStates): array
    {
        $resolved = $this->displayLabelResolver->resolve($tenantId);
        $labels = is_array($resolved['labels'] ?? null)
            ? (array) $resolved['labels']
            : [];

        foreach (['rewards', 'birthdays', 'customers', 'campaigns', 'integrations', 'settings'] as $moduleKey) {
            $stateLabel = trim((string) data_get($moduleStates, $moduleKey.'.label', ''));
            if ($stateLabel !== '') {
                $labels[$moduleKey] = $stateLabel;
                $labels[$moduleKey.'_label'] = $stateLabel;
            }
        }

        return [
            'template_key' => $resolved['template_key'] ?? null,
            'label_source' => (string) ($resolved['source'] ?? 'global_fallback'),
            'labels' => $labels,
            'template_missing' => (bool) ($resolved['template_missing'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $items
     * @param  array<string,string>  $tokenMap
     * @return array<string,mixed>
     */
    protected function replaceTokensInArray(array $items, array $tokenMap): array
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $items[$key] = $this->replaceTokensInArray($value, $tokenMap);
                continue;
            }

            if (is_string($value)) {
                $items[$key] = strtr($value, $tokenMap);
            }
        }

        return $items;
    }

    /**
     * @param  array<int,mixed>  $keys
     * @return array<int,string>
     */
    protected function normalizeKeys(array $keys): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $keys
        ))));
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    protected function normalizeStringList(array $items): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $items
        ), static fn (string $item): bool => $item !== ''));
    }

    protected function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
