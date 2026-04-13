<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantCommercialOverride;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StripeCommercialFulfillmentService
{
    public function __construct(
        protected LandlordCommercialConfigService $commercialConfigService,
        protected LandlordOperatorActionAuditService $auditService,
        protected TenantCommercialExperienceService $experienceService,
    ) {
    }

    /**
     * Reconcile Stripe-confirmed billing state into canonical local commercial access.
     *
     * IMPORTANT: This never trusts arbitrary client-submitted Stripe identifiers.
     * It only consumes server-written Stripe references/metadata from tenant commercial billing_mapping.
     *
     * @return array{ok:bool,status:string,tenant_id:int,plan_key:?string,addon_keys:array<int,string>,state_hash:?string,message:?string}
     */
    public function reconcileTenant(int $tenantId, string $triggeredBy = 'webhook', ?int $actorUserId = null, ?string $sourceEventId = null, ?string $sourceEventType = null): array
    {
        if (! (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false)) {
            return [
                'ok' => false,
                'status' => 'blocked_lifecycle_disabled',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => null,
                'message' => 'Billing lifecycle mutations are disabled.',
            ];
        }

        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_access_profiles')) {
            return [
                'ok' => false,
                'status' => 'blocked_schema_missing',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => null,
                'message' => 'Required commercial tables are missing.',
            ];
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return [
                'ok' => false,
                'status' => 'blocked_unknown_tenant',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => null,
                'message' => 'Tenant not found.',
            ];
        }

        $commercial = $this->commercialConfigService->tenantCommercialProfile($tenantId);
        $billingMapping = is_array($commercial['billing_mapping'] ?? null) ? (array) $commercial['billing_mapping'] : [];
        $stripe = is_array($billingMapping['stripe'] ?? null) ? (array) $billingMapping['stripe'] : [];

        $subscriptionStatus = strtolower(trim((string) ($stripe['subscription_status'] ?? '')));
        $subscriptionInactive = in_array($subscriptionStatus, ['canceled', 'unpaid', 'incomplete_expired'], true)
            || strtolower(trim((string) ($sourceEventType ?? ''))) === 'customer.subscription.deleted';

        $billingConfirmed = $this->stripeBillingConfirmed($stripe);
        if (! $subscriptionInactive && ! $billingConfirmed) {
            return [
                'ok' => false,
                'status' => 'blocked_billing_unconfirmed',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => null,
                'message' => 'Stripe billing is not confirmed yet.',
            ];
        }

        $desiredPlanKey = $this->nullableKey($stripe['confirmed_plan_key'] ?? null)
            ?? $this->nullableKey($stripe['checkout_plan_key'] ?? null)
            ?? $this->nullableKey($stripe['preferred_plan_key'] ?? null);

        if ($subscriptionInactive) {
            $desiredPlanKey = $this->downgradePlanKey();
        }

        if ($desiredPlanKey === null || ! array_key_exists($desiredPlanKey, (array) config('module_catalog.plans', []))) {
            return [
                'ok' => false,
                'status' => 'blocked_missing_plan_key',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => null,
                'message' => 'No canonical plan key is available for fulfillment.',
            ];
        }

        $desiredAddonKeys = $this->normalizeAddonKeys($stripe['confirmed_addon_keys'] ?? null);
        if ($desiredAddonKeys === []) {
            $desiredAddonKeys = $this->normalizeAddonKeys($stripe['checkout_addon_keys'] ?? null);
        }

        if ($subscriptionInactive) {
            $desiredAddonKeys = [];
        }

        $eligibleAddons = $this->eligibleAddonsForPlan($desiredPlanKey);
        $desiredAddonKeys = array_values(array_filter(
            $desiredAddonKeys,
            static fn (string $addonKey): bool => in_array($addonKey, $eligibleAddons, true)
        ));

        $subscriptionReference = trim((string) ($stripe['subscription_reference'] ?? ''));
        $customerReference = trim((string) ($stripe['customer_reference'] ?? ''));
        $checkoutSessionId = trim((string) ($stripe['checkout_session_id'] ?? ''));

        $operatingMode = $this->resolveOperatingMode($tenantId);
        $stateHash = $this->stateHash($tenantId, $subscriptionReference, $desiredPlanKey, $desiredAddonKeys, $operatingMode);
        $attemptMessage = $subscriptionInactive ? 'Stripe subscription inactive: '.$subscriptionStatus : 'Stripe billing confirmed; applying local access.';

        try {
            $result = DB::transaction(function () use (
                $tenantId,
                $desiredPlanKey,
                $desiredAddonKeys,
                $operatingMode,
                $subscriptionReference,
                $customerReference,
                $checkoutSessionId,
                $stateHash,
                $attemptMessage,
                $triggeredBy,
                $actorUserId,
                $sourceEventId,
                $sourceEventType
            ): array {
                $existing = TenantBillingFulfillment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'stripe')
                    ->where('state_hash', $stateHash)
                    ->orderByDesc('id')
                    ->first();

                if ($existing && in_array((string) $existing->status, ['applied', 'noop'], true)) {
                    return [
                        'ok' => true,
                        'status' => 'already_fulfilled',
                        'tenant_id' => $tenantId,
                        'plan_key' => $desiredPlanKey,
                        'addon_keys' => $desiredAddonKeys,
                        'state_hash' => $stateHash,
                        'message' => null,
                    ];
                }

                $beforePlanKey = (string) TenantAccessProfile::query()->where('tenant_id', $tenantId)->value('plan_key');
                $beforeAddons = TenantAccessAddon::query()
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->pluck('addon_key')
                    ->map(fn ($value) => strtolower(trim((string) $value)))
                    ->filter()
                    ->values()
                    ->all();

                $fulfillment = TenantBillingFulfillment::query()->create([
                    'tenant_id' => $tenantId,
                    'provider' => 'stripe',
                    'provider_customer_reference' => $customerReference !== '' ? $customerReference : null,
                    'provider_subscription_reference' => $subscriptionReference !== '' ? $subscriptionReference : null,
                    'provider_checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
                    'state_hash' => $stateHash,
                    'desired_plan_key' => $desiredPlanKey,
                    'desired_addon_keys' => $desiredAddonKeys,
                    'desired_operating_mode' => $operatingMode,
                    'status' => 'attempted',
                    'message' => $attemptMessage,
                    'source_event_id' => $sourceEventId,
                    'source_event_type' => $sourceEventType,
                    'triggered_by' => $triggeredBy,
                    'actor_user_id' => $actorUserId,
                    'attempted_at' => now(),
                    'applied_at' => null,
                ]);

                $this->commercialConfigService->assignTenantPlan(
                    tenantId: $tenantId,
                    planKey: $desiredPlanKey,
                    operatingMode: $operatingMode,
                    source: 'stripe_fulfillment',
                    actorId: $actorUserId
                );

                foreach ($desiredAddonKeys as $addonKey) {
                    $this->commercialConfigService->setTenantAddonState(
                        tenantId: $tenantId,
                        addonKey: $addonKey,
                        enabled: true,
                        source: 'stripe_fulfillment',
                        actorId: $actorUserId
                    );
                }

                $previousStripeAddons = TenantAccessAddon::query()
                    ->where('tenant_id', $tenantId)
                    ->where('source', 'stripe_fulfillment')
                    ->pluck('addon_key')
                    ->map(fn ($value) => strtolower(trim((string) $value)))
                    ->filter()
                    ->values()
                    ->all();

                foreach ($previousStripeAddons as $addonKey) {
                    if (! in_array($addonKey, $desiredAddonKeys, true)) {
                        $this->commercialConfigService->setTenantAddonState(
                            tenantId: $tenantId,
                            addonKey: $addonKey,
                            enabled: false,
                            source: 'stripe_fulfillment',
                            actorId: $actorUserId
                        );
                    }
                }

                $afterPlanKey = (string) TenantAccessProfile::query()->where('tenant_id', $tenantId)->value('plan_key');
                $afterAddons = TenantAccessAddon::query()
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->pluck('addon_key')
                    ->map(fn ($value) => strtolower(trim((string) $value)))
                    ->filter()
                    ->values()
                    ->all();

                $changed = $beforePlanKey !== $afterPlanKey || array_values(array_diff($beforeAddons, $afterAddons)) !== [] || array_values(array_diff($afterAddons, $beforeAddons)) !== [];

                $fulfillment->forceFill([
                    'status' => $changed ? 'applied' : 'noop',
                    'applied_at' => $changed ? now() : null,
                ])->save();

                $override = TenantCommercialOverride::query()->where('tenant_id', $tenantId)->first();
                $override = $override ?: new TenantCommercialOverride(['tenant_id' => $tenantId]);
                $mapping = is_array($override->billing_mapping ?? null) ? (array) $override->billing_mapping : [];
                $stripeMapping = is_array($mapping['stripe'] ?? null) ? (array) $mapping['stripe'] : [];

                $stripeMapping['fulfillment'] = [
                    'status' => $changed ? 'fulfilled' : 'noop',
                    'fulfilled_at' => $changed ? now()->toIso8601String() : ($stripeMapping['fulfillment']['fulfilled_at'] ?? null),
                    'state_hash' => $stateHash,
                    'plan_key' => $desiredPlanKey,
                    'addon_keys' => $desiredAddonKeys,
                    'message' => $attemptMessage,
                    'triggered_by' => $triggeredBy,
                    'source_event_id' => $sourceEventId,
                    'source_event_type' => $sourceEventType,
                    'record_id' => (int) $fulfillment->id,
                ];

                $mapping['stripe'] = $stripeMapping;
                $override->billing_mapping = $mapping;
                $override->save();

                $this->auditService->record(
                    tenantId: $tenantId,
                    actorUserId: $actorUserId,
                    actionType: 'tenant_billing.stripe_fulfillment',
                    status: $changed ? 'success' : 'noop',
                    targetType: 'tenant',
                    targetId: $tenantId,
                    context: [
                        'plan_key' => $desiredPlanKey,
                        'addon_keys' => $desiredAddonKeys,
                        'operating_mode' => $operatingMode,
                        'state_hash' => $stateHash,
                        'stripe_customer_reference' => $customerReference !== '' ? $customerReference : null,
                        'stripe_subscription_reference' => $subscriptionReference !== '' ? $subscriptionReference : null,
                        'source_event_id' => $sourceEventId,
                        'source_event_type' => $sourceEventType,
                        'triggered_by' => $triggeredBy,
                    ],
                    beforeState: [
                        'plan_key' => $beforePlanKey,
                        'enabled_addons' => $beforeAddons,
                    ],
                    afterState: [
                        'plan_key' => $afterPlanKey,
                        'enabled_addons' => $afterAddons,
                    ],
                );

                return [
                    'ok' => true,
                    'status' => $changed ? 'fulfilled' : 'noop',
                    'tenant_id' => $tenantId,
                    'plan_key' => $desiredPlanKey,
                    'addon_keys' => $desiredAddonKeys,
                    'state_hash' => $stateHash,
                    'message' => null,
                ];
            });
        } catch (\Throwable $exception) {
            Log::warning('stripe.fulfillment.failed', [
                'tenant_id' => $tenantId,
                'message' => $exception->getMessage(),
            ]);

            if (Schema::hasTable('tenant_billing_fulfillments')) {
                try {
                    $safeMessage = substr(trim((string) $exception->getMessage()), 0, 240);
                    TenantBillingFulfillment::query()->updateOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'provider' => 'stripe',
                            'state_hash' => $stateHash ?? 'unknown',
                        ],
                        [
                            'provider_customer_reference' => $customerReference !== '' ? $customerReference : null,
                            'provider_subscription_reference' => $subscriptionReference !== '' ? $subscriptionReference : null,
                            'provider_checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
                            'desired_plan_key' => $desiredPlanKey ?? 'starter',
                            'desired_addon_keys' => $desiredAddonKeys ?? [],
                            'desired_operating_mode' => $operatingMode ?? $this->resolveOperatingMode($tenantId),
                            'status' => 'failed_safe',
                            'message' => $safeMessage !== '' ? $safeMessage : 'Fulfillment failed safely.',
                            'source_event_id' => $sourceEventId,
                            'source_event_type' => $sourceEventType,
                            'triggered_by' => $triggeredBy,
                            'actor_user_id' => $actorUserId,
                            'attempted_at' => now(),
                            'applied_at' => null,
                        ]
                    );
                } catch (\Throwable) {
                    // Best-effort failure recording.
                }
            }

            return [
                'ok' => false,
                'status' => 'failed_safe',
                'tenant_id' => $tenantId,
                'plan_key' => null,
                'addon_keys' => [],
                'state_hash' => $stateHash ?? null,
                'message' => 'Fulfillment failed safely.',
            ];
        } finally {
            $this->experienceService->forgetTenantCache($tenantId);
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $stripe
     */
    protected function stripeBillingConfirmed(array $stripe): bool
    {
        $confirmedAt = trim((string) ($stripe['billing_confirmed_at'] ?? ''));
        if ($confirmedAt !== '') {
            return true;
        }

        $completedAt = trim((string) ($stripe['checkout_completed_at'] ?? ''));
        if ($completedAt === '') {
            return false;
        }

        $paymentStatus = strtolower(trim((string) ($stripe['checkout_payment_status'] ?? '')));
        if ($paymentStatus === '') {
            return true;
        }

        return in_array($paymentStatus, ['paid', 'no_payment_required'], true);
    }

    protected function resolveOperatingMode(int $tenantId): string
    {
        $mode = strtolower(trim((string) TenantAccessProfile::query()->where('tenant_id', $tenantId)->value('operating_mode')));

        return $mode !== '' ? $mode : (string) config('entitlements.default_operating_mode', 'shopify');
    }

    protected function stateHash(int $tenantId, string $subscriptionReference, string $planKey, array $addonKeys, string $operatingMode): string
    {
        $raw = json_encode([
            'tenant_id' => $tenantId,
            'subscription_reference' => $subscriptionReference,
            'plan_key' => $planKey,
            'addon_keys' => $addonKeys,
            'operating_mode' => $operatingMode,
        ]);

        return substr(hash('sha256', (string) $raw), 0, 64);
    }

    protected function nullableKey(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token === '' ? null : $token;
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeAddonKeys(mixed $value): array
    {
        $resolved = [];

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        if (is_array($value)) {
            $resolved = $value;
        } elseif (is_string($value)) {
            $resolved = array_filter(array_map('trim', explode(',', $value)));
        }

        $resolved = array_values(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            $resolved
        ), static fn (string $item): bool => $item !== ''));

        $allowed = array_keys((array) config('module_catalog.addons', []));
        $resolved = array_values(array_filter(
            $resolved,
            static fn (string $addonKey): bool => in_array($addonKey, $allowed, true)
        ));

        return array_values(array_unique($resolved));
    }

    /**
     * @return array<int,string>
     */
    protected function eligibleAddonsForPlan(string $planKey): array
    {
        $plan = is_array(config('module_catalog.plans.'.strtolower(trim($planKey)))) ? (array) config('module_catalog.plans.'.strtolower(trim($planKey))) : [];
        $eligible = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($plan['eligible_addons'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        if ($eligible !== []) {
            return array_values(array_unique($eligible));
        }

        return array_keys((array) config('module_catalog.addons', []));
    }

    protected function downgradePlanKey(): ?string
    {
        $plans = (array) config('module_catalog.plans', []);
        $candidates = [];

        foreach ($plans as $planKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $key = strtolower(trim((string) $planKey));
            if ($key === '') {
                continue;
            }

            $candidates[] = [
                'key' => $key,
                'position' => (int) ($definition['position'] ?? 100),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => ((int) ($left['position'] ?? 100)) <=> ((int) ($right['position'] ?? 100)));

        return isset($candidates[0]) ? (string) $candidates[0]['key'] : null;
    }
}
