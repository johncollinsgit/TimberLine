<?php

namespace App\Services\Tenancy;

use App\Models\CandleCashTransaction;
use App\Models\LandlordCatalogEntry;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantUsageCounter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LandlordCommercialConfigService
{
    public function __construct(
        protected LandlordOperatorActionAuditService $auditService
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function catalog(string $type): array
    {
        $normalizedType = $this->normalizeType($type);
        $defaults = $this->defaultCatalog($normalizedType);
        $rows = $this->entryQuery($normalizedType)->get();

        $resolved = [];
        foreach ($defaults as $key => $definition) {
            $resolved[$key] = $this->normalizeCatalogRow(
                type: $normalizedType,
                key: $key,
                row: $definition
            );
        }

        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->entry_key));
            if ($key === '') {
                continue;
            }

            $resolved[$key] = array_replace_recursive(
                $resolved[$key] ?? [],
                $this->rowFromEntry($row)
            );
        }

        $values = array_values($resolved);
        usort($values, static fn (array $left, array $right): int => ((int) ($left['position'] ?? 100)) <=> ((int) ($right['position'] ?? 100)));

        return $values;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function upsertCatalogEntry(string $type, array $input, ?int $actorId = null): LandlordCatalogEntry
    {
        $normalizedType = $this->normalizeType($type);
        $key = strtolower(trim((string) ($input['entry_key'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($key === '' || $name === '') {
            throw new \InvalidArgumentException('Catalog entry key and name are required.');
        }

        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

        return LandlordCatalogEntry::query()->updateOrCreate(
            [
                'entry_type' => $normalizedType,
                'entry_key' => $key,
            ],
            [
                'name' => $name,
                'status' => strtolower(trim((string) ($input['status'] ?? 'active'))) ?: 'active',
                'is_active' => (bool) ($input['is_active'] ?? true),
                'is_public' => (bool) ($input['is_public'] ?? true),
                'position' => max(0, (int) ($input['position'] ?? 100)),
                'currency' => strtoupper(trim((string) ($input['currency'] ?? 'USD'))) ?: 'USD',
                'recurring_price_cents' => $this->nullableInt($input['recurring_price_cents'] ?? null),
                'recurring_interval' => strtolower(trim((string) ($input['recurring_interval'] ?? 'month'))) ?: 'month',
                'setup_price_cents' => $this->nullableInt($input['setup_price_cents'] ?? null),
                'payload' => $payload,
                'updated_by' => $actorId,
                'created_by' => $actorId,
            ]
        );
    }

    public function duplicateTemplate(string $sourceKey, string $newKey, ?int $actorId = null): LandlordCatalogEntry
    {
        $source = LandlordCatalogEntry::query()
            ->where('entry_type', LandlordCatalogEntry::TYPE_TEMPLATE)
            ->where('entry_key', strtolower(trim($sourceKey)))
            ->firstOrFail();

        $targetKey = strtolower(trim($newKey));
        if ($targetKey === '') {
            $targetKey = strtolower(trim($source->entry_key)).'_copy';
        }

        $targetName = trim($source->name).' (Copy)';

        return LandlordCatalogEntry::query()->updateOrCreate(
            [
                'entry_type' => LandlordCatalogEntry::TYPE_TEMPLATE,
                'entry_key' => $targetKey,
            ],
            [
                'name' => $targetName,
                'status' => 'active',
                'is_active' => true,
                'is_public' => (bool) $source->is_public,
                'position' => ((int) $source->position) + 1,
                'currency' => (string) ($source->currency ?: 'USD'),
                'recurring_price_cents' => $source->recurring_price_cents,
                'recurring_interval' => (string) ($source->recurring_interval ?: 'month'),
                'setup_price_cents' => $source->setup_price_cents,
                'payload' => is_array($source->payload) ? $source->payload : [],
                'updated_by' => $actorId,
                'created_by' => $actorId,
            ]
        );
    }

    public function setTemplateState(string $templateKey, string $state, ?int $actorId = null): void
    {
        $row = LandlordCatalogEntry::query()
            ->where('entry_type', LandlordCatalogEntry::TYPE_TEMPLATE)
            ->where('entry_key', strtolower(trim($templateKey)))
            ->first();

        if (! $row) {
            return;
        }

        $normalizedState = strtolower(trim($state));
        $row->status = in_array($normalizedState, ['active', 'inactive', 'archived'], true)
            ? $normalizedState
            : 'active';
        $row->is_active = $row->status === 'active';
        $row->updated_by = $actorId;
        $row->save();
    }

    /**
     * @param  array<int,string>  $orderedTemplateKeys
     */
    public function reorderTemplates(array $orderedTemplateKeys, ?int $actorId = null): void
    {
        $position = 10;
        foreach ($orderedTemplateKeys as $key) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            LandlordCatalogEntry::query()
                ->where('entry_type', LandlordCatalogEntry::TYPE_TEMPLATE)
                ->where('entry_key', $normalizedKey)
                ->update([
                    'position' => $position,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            $position += 10;
        }
    }

    public function assignTenantPlan(
        int $tenantId,
        string $planKey,
        string $operatingMode = 'shopify',
        string $source = 'landlord_console',
        ?int $actorId = null
    ): TenantAccessProfile
    {
        $before = TenantAccessProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        $profile = TenantAccessProfile::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
            ],
            [
                'plan_key' => strtolower(trim($planKey)),
                'operating_mode' => strtolower(trim($operatingMode)) ?: 'shopify',
                'source' => $source,
                'metadata' => [
                    'assigned_via' => 'landlord_commercial_console',
                ],
            ]
        );

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_plan_assignment_update',
            targetType: 'tenant_access_profile',
            targetId: $profile->id,
            context: [
                'tenant_id' => $tenantId,
                'plan_key' => strtolower(trim($planKey)),
                'operating_mode' => strtolower(trim($operatingMode)) ?: 'shopify',
                'source' => $source,
            ],
            beforeState: $before ? [
                'plan_key' => (string) $before->plan_key,
                'operating_mode' => (string) $before->operating_mode,
                'source' => (string) $before->source,
            ] : null,
            afterState: [
                'plan_key' => (string) $profile->plan_key,
                'operating_mode' => (string) $profile->operating_mode,
                'source' => (string) $profile->source,
            ],
            result: [
                'billing_impact' => [
                    'kind' => 'plan_change',
                    'may_require_billing_sync' => ! $before
                        || (string) $before->plan_key !== (string) $profile->plan_key,
                ],
            ]
        );

        return $profile;
    }

    public function setTenantAddonState(
        int $tenantId,
        string $addonKey,
        bool $enabled,
        string $source = 'landlord_console',
        ?int $actorId = null
    ): TenantAccessAddon
    {
        $normalizedAddonKey = strtolower(trim($addonKey));
        if (! is_array(config('module_catalog.addons.'.$normalizedAddonKey)) && ! is_array(config('entitlements.addons.'.$normalizedAddonKey))) {
            throw ValidationException::withMessages([
                'addon_key' => 'Unknown add-on key.',
            ]);
        }

        $before = TenantAccessAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('addon_key', $normalizedAddonKey)
            ->first();

        $addon = TenantAccessAddon::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'addon_key' => $normalizedAddonKey,
            ],
            [
                'enabled' => $enabled,
                'source' => $source,
            ]
        );

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_addon_entitlement_update',
            targetType: 'tenant_access_addon',
            targetId: $addon->id,
            context: [
                'tenant_id' => $tenantId,
                'addon_key' => $normalizedAddonKey,
                'source' => $source,
            ],
            beforeState: $before ? [
                'enabled' => (bool) $before->enabled,
                'source' => (string) $before->source,
                'starts_at' => optional($before->starts_at)->toIso8601String(),
                'ends_at' => optional($before->ends_at)->toIso8601String(),
            ] : null,
            afterState: [
                'enabled' => (bool) $addon->enabled,
                'source' => (string) $addon->source,
                'starts_at' => optional($addon->starts_at)->toIso8601String(),
                'ends_at' => optional($addon->ends_at)->toIso8601String(),
            ],
            result: [
                'billing_impact' => [
                    'kind' => 'addon_toggle',
                    'addon_key' => $normalizedAddonKey,
                    'may_require_billing_sync' => ! $before || (bool) $before->enabled !== (bool) $addon->enabled,
                ],
            ]
        );

        return $addon;
    }

    public function setTenantModuleState(
        int $tenantId,
        string $moduleKey,
        ?bool $enabledOverride,
        ?string $setupStatus = null,
        ?int $actorId = null
    ): TenantModuleState {
        $normalizedModuleKey = strtolower(trim($moduleKey));
        if (! is_array(config('module_catalog.modules.'.$normalizedModuleKey))) {
            throw ValidationException::withMessages([
                'module_key' => 'Unknown module key.',
            ]);
        }

        $before = TenantModuleState::query()
            ->where('tenant_id', $tenantId)
            ->where('module_key', $normalizedModuleKey)
            ->first();

        $payload = [
            'enabled_override' => $enabledOverride,
        ];

        if ($setupStatus !== null && trim($setupStatus) !== '') {
            $payload['setup_status'] = strtolower(trim($setupStatus));
        }

        $state = TenantModuleState::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'module_key' => $normalizedModuleKey,
            ],
            $payload
        );

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_module_state_update',
            targetType: 'tenant_module_state',
            targetId: $state->id,
            context: [
                'tenant_id' => $tenantId,
                'module_key' => $normalizedModuleKey,
            ],
            beforeState: $before ? [
                'enabled_override' => $before->getRawOriginal('enabled_override') === null
                    ? null
                    : (bool) $before->enabled_override,
                'setup_status' => (string) ($before->setup_status ?? 'not_started'),
                'coming_soon_override' => $before->getRawOriginal('coming_soon_override') === null
                    ? null
                    : (bool) $before->coming_soon_override,
                'upgrade_prompt_override' => $before->getRawOriginal('upgrade_prompt_override') === null
                    ? null
                    : (bool) $before->upgrade_prompt_override,
            ] : null,
            afterState: [
                'enabled_override' => $state->getRawOriginal('enabled_override') === null
                    ? null
                    : (bool) $state->enabled_override,
                'setup_status' => (string) ($state->setup_status ?? 'not_started'),
                'coming_soon_override' => $state->getRawOriginal('coming_soon_override') === null
                    ? null
                    : (bool) $state->coming_soon_override,
                'upgrade_prompt_override' => $state->getRawOriginal('upgrade_prompt_override') === null
                    ? null
                    : (bool) $state->upgrade_prompt_override,
            ],
            result: [
                'billing_impact' => [
                    'kind' => 'module_override',
                    'module_key' => $normalizedModuleKey,
                    'may_require_billing_review' => $enabledOverride !== null,
                ],
            ]
        );

        return $state;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function setTenantModuleEntitlement(
        int $tenantId,
        string $moduleKey,
        array $input,
        ?int $actorId = null
    ): TenantModuleEntitlement {
        $normalizedModuleKey = strtolower(trim($moduleKey));
        $normalizedInput = $this->validatedTenantModuleEntitlementInput($normalizedModuleKey, $input);
        $before = TenantModuleEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('module_key', $normalizedModuleKey)
            ->first();

        $entitlement = TenantModuleEntitlement::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'module_key' => $normalizedModuleKey,
            ],
            [
                'availability_status' => (string) $normalizedInput['availability_status'],
                'enabled_status' => (string) $normalizedInput['enabled_status'],
                'billing_status' => $this->nullableString($normalizedInput['billing_status'] ?? null),
                'price_override_cents' => $this->nullableInt($normalizedInput['price_override_cents'] ?? null),
                'currency' => strtoupper(trim((string) ($normalizedInput['currency'] ?? 'USD'))) ?: 'USD',
                'entitlement_source' => $this->nullableString($normalizedInput['entitlement_source'] ?? 'override'),
                'price_source' => $this->nullableString($normalizedInput['price_source'] ?? null),
                'starts_at' => $normalizedInput['starts_at'] ?? null,
                'ends_at' => $normalizedInput['ends_at'] ?? null,
                'notes' => $this->nullableString($normalizedInput['notes'] ?? null),
                'metadata' => $this->normalizeJsonArray($normalizedInput['metadata'] ?? []),
                'updated_by' => $actorId,
                'created_by' => $before?->created_by ?? $actorId,
            ]
        );

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_module_entitlement_update',
            targetType: 'tenant_module_entitlement',
            targetId: $entitlement->id,
            context: [
                'tenant_id' => $tenantId,
                'module_key' => $normalizedModuleKey,
            ],
            beforeState: $before ? $this->tenantModuleEntitlementState($before) : null,
            afterState: $this->tenantModuleEntitlementState($entitlement),
            result: [
                'billing_impact' => [
                    'kind' => 'module_entitlement',
                    'billing_status' => $this->nullableString($entitlement->billing_status),
                    'price_override_cents' => $entitlement->price_override_cents,
                    'may_require_billing_sync' => ! $before
                        || (string) $before->billing_status !== (string) $entitlement->billing_status
                        || (int) ($before->price_override_cents ?? 0) !== (int) ($entitlement->price_override_cents ?? 0),
                ],
            ]
        );

        return $entitlement;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function updateTenantCommercialOverride(int $tenantId, array $input, ?int $actorId = null): TenantCommercialOverride
    {
        $before = TenantCommercialOverride::query()->where('tenant_id', $tenantId)->first();

        $override = TenantCommercialOverride::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
            ],
            [
                'template_key' => $this->nullableString($input['template_key'] ?? null),
                'store_channel_allowance' => $this->nullableInt($input['store_channel_allowance'] ?? null),
                'plan_pricing_overrides' => $this->normalizeJsonArray($input['plan_pricing_overrides'] ?? []),
                'addon_pricing_overrides' => $this->normalizeJsonArray($input['addon_pricing_overrides'] ?? []),
                'included_usage_overrides' => $this->normalizeJsonArray($input['included_usage_overrides'] ?? []),
                'display_labels' => $this->normalizeJsonArray($input['display_labels'] ?? []),
                'billing_mapping' => $this->normalizeJsonArray($input['billing_mapping'] ?? []),
                'metadata' => $this->normalizeJsonArray($input['metadata'] ?? []),
            ]
        );

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_commercial_override_update',
            targetType: 'tenant_commercial_override',
            targetId: $override->id,
            context: [
                'tenant_id' => $tenantId,
                'template_key' => $this->nullableString($override->template_key),
            ],
            beforeState: $before ? $this->tenantCommercialOverrideState($before) : null,
            afterState: $this->tenantCommercialOverrideState($override),
            result: [
                'billing_impact' => [
                    'kind' => 'commercial_override',
                    'may_require_billing_sync' => ! $before
                        || $this->normalizeJsonArray($before->plan_pricing_overrides ?? []) !== $this->normalizeJsonArray($override->plan_pricing_overrides ?? [])
                        || $this->normalizeJsonArray($before->addon_pricing_overrides ?? []) !== $this->normalizeJsonArray($override->addon_pricing_overrides ?? [])
                        || $this->normalizeJsonArray($before->billing_mapping ?? []) !== $this->normalizeJsonArray($override->billing_mapping ?? []),
                ],
            ]
        );

        return $override;
    }

    /**
     * @return array<string,mixed>
     */
    public function tenantCommercialProfile(int $tenantId): array
    {
        $override = TenantCommercialOverride::query()->forTenantId($tenantId)->first();
        $templateKey = $this->nullableString($override?->template_key);
        $template = $templateKey ? $this->templateByKey($templateKey) : null;

        return [
            'template_key' => $templateKey,
            'template' => $template,
            'store_channel_allowance' => $override?->store_channel_allowance,
            'plan_pricing_overrides' => is_array($override?->plan_pricing_overrides) ? $override->plan_pricing_overrides : [],
            'addon_pricing_overrides' => is_array($override?->addon_pricing_overrides) ? $override->addon_pricing_overrides : [],
            'included_usage_overrides' => is_array($override?->included_usage_overrides) ? $override->included_usage_overrides : [],
            'display_labels' => is_array($override?->display_labels) ? $override->display_labels : [],
            'billing_mapping' => is_array($override?->billing_mapping) ? $override->billing_mapping : [],
            'metadata' => is_array($override?->metadata) ? $override->metadata : [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function templateByKey(string $templateKey): ?array
    {
        $normalized = strtolower(trim($templateKey));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->catalog(LandlordCatalogEntry::TYPE_TEMPLATE) as $template) {
            if (($template['entry_key'] ?? '') === $normalized) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function tenantUsageSummary(Tenant $tenant, bool $persist = false): array
    {
        $tenantId = (int) $tenant->id;

        $contacts = Schema::hasTable('marketing_profiles')
            ? MarketingProfile::query()->where('tenant_id', $tenantId)->count()
            : 0;

        $emails = Schema::hasTable('marketing_email_deliveries')
            ? MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->count()
            : 0;

        $sms = Schema::hasTable('marketing_sms_deliveries')
            ? (int) DB::table('marketing_sms_deliveries')->where('tenant_id', $tenantId)->count()
            : 0;
        $rewardsIssued = Schema::hasTable('candle_cash_transactions') && Schema::hasTable('marketing_profiles')
            ? CandleCashTransaction::query()
                ->whereHas('profile', function ($query) use ($tenantId): void {
                    $query->where('marketing_profiles.tenant_id', $tenantId);
                })
                ->where('candle_cash_delta', '>', 0)
                ->count()
            : 0;
        $rewardReminderSends = Schema::hasTable('marketing_automation_events')
            ? MarketingAutomationEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('trigger_key', 'tenant_rewards_expiration_reminder')
                ->where('status', 'sent')
                ->count()
            : 0;

        $values = [
            'contact_count' => (int) $contacts,
            'email_usage' => (int) $emails,
            'sms_usage' => (int) $sms,
            'rewards_issued' => (int) $rewardsIssued,
            'reward_reminder_sends' => (int) $rewardReminderSends,
        ];

        $limits = $this->tenantIncludedUsageLimits($tenantId);

        if ($persist) {
            foreach ($values as $metricKey => $metricValue) {
                TenantUsageCounter::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'metric_key' => $metricKey,
                    ],
                    [
                        'metric_value' => $metricValue,
                        'included_limit' => $this->nullableInt($limits[$metricKey] ?? null),
                        'source' => 'computed',
                        'last_recorded_at' => now(),
                    ]
                );
            }
        }

        return [
            'metrics' => $values,
            'included_limits' => $limits,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function tenantIncludedUsageLimits(int $tenantId): array
    {
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenantId)->first();
        $planKey = strtolower(trim((string) ($profile?->plan_key ?? config('entitlements.default_plan', 'starter'))));

        $alias = config('commercial.legacy_plan_aliases.'. $planKey);
        if (is_string($alias) && $alias !== '') {
            $planKey = strtolower(trim($alias));
        }

        $planDefaults = config('commercial.plans.'.$planKey.'.included_usage', []);
        $planDefaults = is_array($planDefaults) ? $planDefaults : [];

        $overrides = TenantCommercialOverride::query()->forTenantId($tenantId)->first();
        $usageOverrides = is_array($overrides?->included_usage_overrides) ? $overrides->included_usage_overrides : [];

        return array_replace($planDefaults, $usageOverrides);
    }

    /**
     * @return array<string,mixed>
     */
    public function billingReadinessOverview(): array
    {
        $billingReadiness = (array) config('commercial.billing_readiness', []);
        $guardedActions = is_array($billingReadiness['guarded_actions'] ?? null)
            ? (array) $billingReadiness['guarded_actions']
            : [];
        $stripeMap = (array) config('commercial.stripe_mapping', []);

        $planMap = is_array($stripeMap['tiers'] ?? null) ? (array) $stripeMap['tiers'] : [];
        $addonMap = is_array($stripeMap['addons'] ?? null) ? (array) $stripeMap['addons'] : [];
        $setupMap = is_array($stripeMap['setup_packages'] ?? null) ? (array) $stripeMap['setup_packages'] : [];
        $supportMap = is_array($stripeMap['support_tiers'] ?? null) ? (array) $stripeMap['support_tiers'] : [];
        $usageMap = is_array($stripeMap['usage_metrics'] ?? null) ? (array) $stripeMap['usage_metrics'] : [];
        $storeChannels = is_array($stripeMap['store_channels'] ?? null) ? (array) $stripeMap['store_channels'] : [];

        $missing = [];

        foreach ($this->normalizeKeys((array) config('commercial.public_tier_order', [])) as $planKey) {
            $row = is_array($planMap[$planKey] ?? null) ? (array) $planMap[$planKey] : [];
            foreach (['product_lookup_key', 'recurring_price_lookup_key', 'setup_price_lookup_key'] as $requiredField) {
                if (! $this->hasFilledArrayValue($row, $requiredField)) {
                    $missing[] = 'Missing Stripe tier mapping: '.$planKey.'.'.$requiredField;
                }
            }
        }

        foreach ($this->normalizeKeys(array_keys((array) config('commercial.addons', []))) as $addonKey) {
            $row = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            foreach (['product_lookup_key', 'recurring_price_lookup_key'] as $requiredField) {
                if (! $this->hasFilledArrayValue($row, $requiredField)) {
                    $missing[] = 'Missing Stripe add-on mapping: '.$addonKey.'.'.$requiredField;
                }
            }
        }

        foreach ($this->normalizeKeys(array_keys((array) config('commercial.setup_packages', []))) as $setupKey) {
            $row = is_array($setupMap[$setupKey] ?? null) ? (array) $setupMap[$setupKey] : [];
            if (! $this->hasFilledArrayValue($row, 'price_lookup_key')) {
                $missing[] = 'Missing Stripe setup mapping: '.$setupKey.'.price_lookup_key';
            }
        }

        foreach ($this->normalizeKeys(array_keys((array) config('commercial.support_tiers', []))) as $supportTierKey) {
            $row = is_array($supportMap[$supportTierKey] ?? null) ? (array) $supportMap[$supportTierKey] : [];
            foreach (['product_lookup_key', 'recurring_price_lookup_key'] as $requiredField) {
                if (! $this->hasFilledArrayValue($row, $requiredField)) {
                    $missing[] = 'Missing Stripe support-tier mapping: '.$supportTierKey.'.'.$requiredField;
                }
            }
        }

        foreach ($this->normalizeKeys(array_keys((array) config('commercial.usage_metrics', []))) as $metricKey) {
            $row = is_array($usageMap[$metricKey] ?? null) ? (array) $usageMap[$metricKey] : [];
            if (! $this->hasFilledArrayValue($row, 'meter_lookup_key')) {
                $missing[] = 'Missing Stripe usage-meter mapping: '.$metricKey.'.meter_lookup_key';
            }
        }

        if ((int) ($storeChannels['starter_included'] ?? 0) < 1) {
            $missing[] = 'Missing store-channel policy: starter_included must be >= 1.';
        }

        if (! $this->hasFilledArrayValue($storeChannels, 'additional_channels_addon_key')) {
            $missing[] = 'Missing store-channel policy: additional_channels_addon_key.';
        }

        if (! $this->hasFilledArrayValue($storeChannels, 'additional_channels_lookup_key')) {
            $missing[] = 'Missing store-channel policy: additional_channels_lookup_key.';
        }

        $checkoutActive = (bool) ($billingReadiness['checkout_active'] ?? false);
        $lifecycleMutationsEnabled = (bool) ($billingReadiness['lifecycle_mutations_enabled'] ?? false);

        return [
            'checkout_active' => $checkoutActive,
            'lifecycle_mutations_enabled' => $lifecycleMutationsEnabled,
            'lifecycle_disabled' => ! $checkoutActive && ! $lifecycleMutationsEnabled,
            'provider_priority' => array_values(array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                (array) ($billingReadiness['provider_priority'] ?? [])
            )),
            'providers' => is_array($billingReadiness['providers'] ?? null) ? (array) $billingReadiness['providers'] : [],
            'guarded_actions' => $guardedActions,
            'required_evidence_docs' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($billingReadiness['required_evidence_docs'] ?? [])
            ))),
            'required_tenant_billing_fields' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($billingReadiness['required_tenant_billing_fields'] ?? [])
            ))),
            'mapping_status' => trim((string) ($stripeMap['status'] ?? 'missing')) ?: 'missing',
            'missing_global_requirements' => array_values(array_unique($missing)),
            'stripe_mapping' => [
                'tiers' => $planMap,
                'addons' => $addonMap,
                'setup_packages' => $setupMap,
                'support_tiers' => $supportMap,
                'usage_metrics' => $usageMap,
                'store_channels' => $storeChannels,
                'tenant_override_fields' => array_values(array_filter(array_map(
                    static fn ($value): string => trim((string) $value),
                    (array) ($stripeMap['tenant_override_fields'] ?? [])
                ))),
            ],
        ];
    }

    /**
     * @param  array<string,bool>  $addonStates
     * @param  array<string,mixed>  $commercialProfile
     * @return array<string,mixed>
     */
    public function tenantBillingReadiness(
        int $tenantId,
        string $resolvedPlanKey,
        array $addonStates,
        array $commercialProfile
    ): array {
        $overview = $this->billingReadinessOverview();
        $stripeMap = is_array($overview['stripe_mapping'] ?? null) ? (array) $overview['stripe_mapping'] : [];
        $tierMap = is_array($stripeMap['tiers'] ?? null) ? (array) $stripeMap['tiers'] : [];
        $addonMap = is_array($stripeMap['addons'] ?? null) ? (array) $stripeMap['addons'] : [];

        $canonicalPlanKey = $this->canonicalCommercialPlanKey($resolvedPlanKey);
        $enabledAddonKeys = [];
        foreach ($addonStates as $addonKey => $enabled) {
            $normalizedAddonKey = strtolower(trim((string) $addonKey));
            if ($normalizedAddonKey === '' || ! $enabled) {
                continue;
            }
            $enabledAddonKeys[] = $normalizedAddonKey;
        }
        $enabledAddonKeys = $this->normalizeKeys($enabledAddonKeys);

        $missing = [];
        $planRow = is_array($tierMap[$canonicalPlanKey] ?? null) ? (array) $tierMap[$canonicalPlanKey] : [];
        foreach (['product_lookup_key', 'recurring_price_lookup_key', 'setup_price_lookup_key'] as $requiredField) {
            if (! $this->hasFilledArrayValue($planRow, $requiredField)) {
                $missing[] = 'Assigned plan mapping missing: '.$canonicalPlanKey.'.'.$requiredField;
            }
        }

        foreach ($enabledAddonKeys as $addonKey) {
            $addonRow = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            foreach (['product_lookup_key', 'recurring_price_lookup_key'] as $requiredField) {
                if (! $this->hasFilledArrayValue($addonRow, $requiredField)) {
                    $missing[] = 'Enabled add-on mapping missing: '.$addonKey.'.'.$requiredField;
                }
            }
        }

        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];

        foreach ((array) ($overview['required_tenant_billing_fields'] ?? []) as $requiredFieldPath) {
            $path = trim((string) $requiredFieldPath);
            if ($path === '') {
                continue;
            }

            if (! $this->hasFilledPath($billingMapping, $path)) {
                $missing[] = 'Tenant billing mapping missing: '.$path;
            }
        }

        $globalMissing = is_array($overview['missing_global_requirements'] ?? null)
            ? (array) $overview['missing_global_requirements']
            : [];

        $readyForActivationPrep = $globalMissing === [] && $missing === [];

        return [
            'tenant_id' => $tenantId,
            'resolved_plan_key' => $canonicalPlanKey,
            'enabled_addons' => $enabledAddonKeys,
            'config_only' => true,
            'lifecycle_disabled' => (bool) ($overview['lifecycle_disabled'] ?? true),
            'ready_for_activation_prep' => $readyForActivationPrep,
            'missing_requirements' => array_values(array_unique(array_merge($globalMissing, $missing))),
            'required_evidence_docs' => (array) ($overview['required_evidence_docs'] ?? []),
            'required_tenant_billing_fields' => (array) ($overview['required_tenant_billing_fields'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $commercialProfile
     * @param  array<string,mixed>|null  $billingOverview
     * @return array<string,mixed>
     */
    public function stripeCustomerSyncReadiness(
        Tenant $tenant,
        ?array $commercialProfile = null,
        ?array $billingOverview = null
    ): array {
        $overview = $billingOverview ?? $this->billingReadinessOverview();
        $commercialProfile = $commercialProfile ?? $this->tenantCommercialProfile((int) $tenant->id);

        $guardedAction = is_array(data_get($overview, 'guarded_actions.stripe_customer_sync'))
            ? (array) data_get($overview, 'guarded_actions.stripe_customer_sync')
            : [];

        $enabled = (bool) ($guardedAction['enabled'] ?? false);
        $requiresLifecycleDisabled = (bool) ($guardedAction['requires_lifecycle_disabled'] ?? true);
        $lifecycleDisabled = (bool) ($overview['lifecycle_disabled'] ?? true);
        $providerStatus = strtolower(trim((string) data_get($overview, 'providers.stripe.status', 'missing')));
        $providerRole = strtolower(trim((string) data_get($overview, 'providers.stripe.role', 'primary')));
        $stripeSecretConfigured = $this->stripeSecretConfigured();
        $stripeSecretFormatValid = $this->stripeSecretFormatValid();
        $stripeApiBase = $this->stripeApiBaseUrl();
        $stripeApiBaseValid = $this->stripeApiBaseValid($stripeApiBase);
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];

        $customerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));
        $syncMeta = is_array(data_get($metadata, 'billing_guarded_actions.stripe_customer_sync'))
            ? (array) data_get($metadata, 'billing_guarded_actions.stripe_customer_sync')
            : [];

        $reasons = [];
        if (! $enabled) {
            $reasons[] = 'Guarded Stripe customer-sync action is disabled by configuration.';
        }
        if ($requiresLifecycleDisabled && ! $lifecycleDisabled) {
            $reasons[] = 'Billing lifecycle flags are enabled. Keep lifecycle disabled for guarded prep actions.';
        }
        if (! in_array($providerStatus, ['guarded_customer_sync', 'configuration_only', 'configured'], true)) {
            $reasons[] = 'Stripe provider status is not ready for guarded customer sync.';
        }
        if ($providerRole !== 'primary') {
            $reasons[] = 'Stripe provider role is not marked as primary.';
        }
        if (! $stripeSecretConfigured) {
            $reasons[] = 'Stripe secret is missing (`services.stripe.secret`).';
        } elseif (! $stripeSecretFormatValid) {
            $reasons[] = 'Stripe secret format is invalid (`services.stripe.secret` should start with `sk_`).';
        }
        if (! $stripeApiBaseValid) {
            $reasons[] = 'Stripe API base URL is invalid (`services.stripe.api_base`). Use HTTPS for remote endpoints (HTTP allowed only for localhost/127.0.0.1/::1).';
        }

        return [
            'enabled' => $enabled,
            'ready' => $reasons === [],
            'not_ready_reasons' => array_values(array_unique($reasons)),
            'provider_status' => $providerStatus === '' ? 'missing' : $providerStatus,
            'provider_role' => $providerRole === '' ? 'primary' : $providerRole,
            'lifecycle_disabled' => $lifecycleDisabled,
            'requires_lifecycle_disabled' => $requiresLifecycleDisabled,
            'stripe_secret_configured' => $stripeSecretConfigured,
            'stripe_secret_format_valid' => $stripeSecretFormatValid,
            'stripe_api_base' => $stripeApiBase,
            'stripe_api_base_valid' => $stripeApiBaseValid,
            'customer_reference' => $customerReference !== '' ? $customerReference : null,
            'last_status' => trim((string) ($syncMeta['status'] ?? 'never')),
            'last_message' => $this->nullableString($syncMeta['message'] ?? null),
            'last_mode' => $this->nullableString($syncMeta['mode'] ?? null),
            'last_synced_at' => $this->nullableString($syncMeta['synced_at'] ?? null),
            'last_attempted_at' => $this->nullableString($syncMeta['attempted_at'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $commercialProfile
     * @param  array<string,mixed>|null  $billingOverview
     * @return array<string,mixed>
     */
    public function stripeSubscriptionPrepReadiness(
        Tenant $tenant,
        ?array $commercialProfile = null,
        ?array $billingOverview = null
    ): array {
        $tenantId = (int) $tenant->id;
        $overview = $billingOverview ?? $this->billingReadinessOverview();
        $commercialProfile = $commercialProfile ?? $this->tenantCommercialProfile($tenantId);

        $guardedAction = is_array(data_get($overview, 'guarded_actions.stripe_subscription_prep'))
            ? (array) data_get($overview, 'guarded_actions.stripe_subscription_prep')
            : [];

        $enabled = (bool) ($guardedAction['enabled'] ?? false);
        $requiresLifecycleDisabled = (bool) ($guardedAction['requires_lifecycle_disabled'] ?? true);
        $requiresCustomerReference = (bool) ($guardedAction['requires_customer_reference'] ?? true);
        $lifecycleDisabled = (bool) ($overview['lifecycle_disabled'] ?? true);
        $providerStatus = strtolower(trim((string) data_get($overview, 'providers.stripe.status', 'missing')));
        $providerRole = strtolower(trim((string) data_get($overview, 'providers.stripe.role', 'primary')));
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];

        $customerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));
        $subscriptionReference = trim((string) data_get($billingMapping, 'stripe.subscription_reference', ''));
        $existingCandidate = is_array(data_get($billingMapping, 'stripe.subscription_prep_candidate'))
            ? (array) data_get($billingMapping, 'stripe.subscription_prep_candidate')
            : [];
        $existingCandidateHash = trim((string) data_get($billingMapping, 'stripe.subscription_prep_hash', ''));

        $syncMeta = is_array(data_get($metadata, 'billing_guarded_actions.stripe_subscription_prep'))
            ? (array) data_get($metadata, 'billing_guarded_actions.stripe_subscription_prep')
            : [];

        $resolvedPlanKey = $this->canonicalCommercialPlanKey((string) optional(
            TenantAccessProfile::query()->where('tenant_id', $tenantId)->first()
        )->plan_key);
        $enabledAddonKeys = $this->enabledAddonKeysForTenant($tenantId);

        $stripeMap = is_array($overview['stripe_mapping'] ?? null) ? (array) $overview['stripe_mapping'] : [];
        $tierMap = is_array($stripeMap['tiers'] ?? null) ? (array) $stripeMap['tiers'] : [];
        $addonMap = is_array($stripeMap['addons'] ?? null) ? (array) $stripeMap['addons'] : [];

        $reasons = [];
        if (! $enabled) {
            $reasons[] = 'Guarded Stripe subscription-prep action is disabled by configuration.';
        }
        if ($requiresLifecycleDisabled && ! $lifecycleDisabled) {
            $reasons[] = 'Billing lifecycle flags are enabled. Keep lifecycle disabled for guarded prep actions.';
        }
        if (! in_array($providerStatus, ['guarded_customer_sync', 'configuration_only', 'configured'], true)) {
            $reasons[] = 'Stripe provider status is not ready for guarded subscription prep.';
        }
        if ($providerRole !== 'primary') {
            $reasons[] = 'Stripe provider role is not marked as primary.';
        }
        if ($requiresCustomerReference && $customerReference === '') {
            $reasons[] = 'Stripe customer reference is required before subscription prep.';
        }

        $planMapping = is_array($tierMap[$resolvedPlanKey] ?? null) ? (array) $tierMap[$resolvedPlanKey] : [];
        foreach (['product_lookup_key', 'recurring_price_lookup_key', 'setup_price_lookup_key'] as $requiredField) {
            if (! $this->hasFilledArrayValue($planMapping, $requiredField)) {
                $reasons[] = 'Assigned plan mapping missing: '.$resolvedPlanKey.'.'.$requiredField;
            }
        }

        foreach ($enabledAddonKeys as $addonKey) {
            $addonMapping = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            foreach (['product_lookup_key', 'recurring_price_lookup_key'] as $requiredField) {
                if (! $this->hasFilledArrayValue($addonMapping, $requiredField)) {
                    $reasons[] = 'Enabled add-on mapping missing: '.$addonKey.'.'.$requiredField;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'ready' => $reasons === [],
            'not_ready_reasons' => array_values(array_unique($reasons)),
            'provider_status' => $providerStatus === '' ? 'missing' : $providerStatus,
            'provider_role' => $providerRole === '' ? 'primary' : $providerRole,
            'lifecycle_disabled' => $lifecycleDisabled,
            'requires_lifecycle_disabled' => $requiresLifecycleDisabled,
            'requires_customer_reference' => $requiresCustomerReference,
            'customer_reference' => $customerReference !== '' ? $customerReference : null,
            'subscription_reference' => $subscriptionReference !== '' ? $subscriptionReference : null,
            'resolved_plan_key' => $resolvedPlanKey,
            'enabled_addons' => $enabledAddonKeys,
            'candidate' => $existingCandidate,
            'candidate_hash' => $existingCandidateHash !== '' ? $existingCandidateHash : null,
            'last_status' => trim((string) ($syncMeta['status'] ?? 'never')),
            'last_message' => $this->nullableString($syncMeta['message'] ?? null),
            'last_mode' => $this->nullableString($syncMeta['mode'] ?? null),
            'last_synced_at' => $this->nullableString($syncMeta['synced_at'] ?? null),
            'last_attempted_at' => $this->nullableString($syncMeta['attempted_at'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $commercialProfile
     * @param  array<string,mixed>|null  $billingOverview
     * @return array<string,mixed>
     */
    public function stripeLiveSubscriptionSyncReadiness(
        Tenant $tenant,
        ?array $commercialProfile = null,
        ?array $billingOverview = null
    ): array {
        $tenantId = (int) $tenant->id;
        $overview = $billingOverview ?? $this->billingReadinessOverview();
        $commercialProfile = $commercialProfile ?? $this->tenantCommercialProfile($tenantId);

        $guardedAction = is_array(data_get($overview, 'guarded_actions.stripe_live_subscription_sync'))
            ? (array) data_get($overview, 'guarded_actions.stripe_live_subscription_sync')
            : [];

        $enabled = (bool) ($guardedAction['enabled'] ?? false);
        $requiresLifecycleDisabled = (bool) ($guardedAction['requires_lifecycle_disabled'] ?? true);
        $requiresCustomerReference = (bool) ($guardedAction['requires_customer_reference'] ?? true);
        $requiresSubscriptionPrep = (bool) ($guardedAction['requires_subscription_prep'] ?? true);
        $requiresPrepHash = (bool) ($guardedAction['requires_prep_hash'] ?? true);
        $allowSyncExistingReference = (bool) ($guardedAction['allow_sync_existing_reference'] ?? true);
        $lifecycleDisabled = (bool) ($overview['lifecycle_disabled'] ?? true);
        $providerStatus = strtolower(trim((string) data_get($overview, 'providers.stripe.status', 'missing')));
        $providerRole = strtolower(trim((string) data_get($overview, 'providers.stripe.role', 'primary')));
        $stripeSecretConfigured = $this->stripeSecretConfigured();
        $stripeSecretFormatValid = $this->stripeSecretFormatValid();
        $stripeApiBase = $this->stripeApiBaseUrl();
        $stripeApiBaseValid = $this->stripeApiBaseValid($stripeApiBase);
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];
        $customerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));
        $subscriptionReference = trim((string) data_get($billingMapping, 'stripe.subscription_reference', ''));
        $prepCandidate = is_array(data_get($billingMapping, 'stripe.subscription_prep_candidate'))
            ? (array) data_get($billingMapping, 'stripe.subscription_prep_candidate')
            : [];
        $prepCandidateHash = trim((string) data_get($billingMapping, 'stripe.subscription_prep_hash', ''));
        $prepMeta = is_array(data_get($metadata, 'billing_guarded_actions.stripe_subscription_prep'))
            ? (array) data_get($metadata, 'billing_guarded_actions.stripe_subscription_prep')
            : [];
        $syncMeta = is_array(data_get($metadata, 'billing_guarded_actions.stripe_live_subscription_sync'))
            ? (array) data_get($metadata, 'billing_guarded_actions.stripe_live_subscription_sync')
            : [];

        $prepReadiness = $this->stripeSubscriptionPrepReadiness(
            tenant: $tenant,
            commercialProfile: $commercialProfile,
            billingOverview: $overview
        );

        $reasons = [];
        if (! $enabled) {
            $reasons[] = 'Guarded Stripe live subscription-sync action is disabled by configuration.';
        }
        if ($requiresLifecycleDisabled && ! $lifecycleDisabled) {
            $reasons[] = 'Billing lifecycle flags are enabled. Keep lifecycle disabled for this guarded live action.';
        }
        if (! in_array($providerStatus, ['guarded_customer_sync', 'configuration_only', 'configured'], true)) {
            $reasons[] = 'Stripe provider status is not ready for guarded live subscription sync.';
        }
        if ($providerRole !== 'primary') {
            $reasons[] = 'Stripe provider role is not marked as primary.';
        }
        if (! $stripeSecretConfigured) {
            $reasons[] = 'Stripe secret is missing (`services.stripe.secret`).';
        } elseif (! $stripeSecretFormatValid) {
            $reasons[] = 'Stripe secret format is invalid (`services.stripe.secret` should start with `sk_`).';
        }
        if (! $stripeApiBaseValid) {
            $reasons[] = 'Stripe API base URL is invalid (`services.stripe.api_base`). Use HTTPS for remote endpoints (HTTP allowed only for localhost/127.0.0.1/::1).';
        }
        if ($requiresCustomerReference && $customerReference === '') {
            $reasons[] = 'Stripe customer reference is required before live subscription sync.';
        }
        if (! $allowSyncExistingReference && $subscriptionReference !== '') {
            $reasons[] = 'Sync for existing subscription references is disabled by configuration.';
        }
        if ($requiresSubscriptionPrep && ! (bool) ($prepReadiness['ready'] ?? false)) {
            $reasons[] = 'Guarded Stripe subscription-prep readiness is not satisfied.';
        }

        $prepStatus = strtolower(trim((string) ($prepMeta['status'] ?? 'never')));
        if ($requiresSubscriptionPrep && $prepStatus !== 'succeeded') {
            $reasons[] = 'Stripe subscription prep must succeed before live subscription sync.';
        }

        if ($requiresPrepHash && $prepCandidateHash === '') {
            $reasons[] = 'Stripe subscription prep hash is missing.';
        }
        if ($requiresPrepHash && $prepCandidate === []) {
            $reasons[] = 'Stripe subscription prep candidate payload is missing.';
        }

        $computedCandidateHash = null;
        if (
            $requiresPrepHash
            && $prepCandidate !== []
            && (bool) ($prepReadiness['ready'] ?? false)
        ) {
            try {
                $computedCandidate = $this->stripeSubscriptionPrepCandidate(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    billingOverview: $overview,
                    readiness: $prepReadiness
                );
                $computedCandidateHash = $this->stripeSubscriptionPrepHash($computedCandidate);
            } catch (Throwable $e) {
                $reasons[] = 'Unable to compute subscription-prep hash for live sync validation.';
            }
        }

        if (
            $requiresPrepHash
            && $prepCandidateHash !== ''
            && $computedCandidateHash !== null
            && $computedCandidateHash !== ''
            && $prepCandidateHash !== $computedCandidateHash
        ) {
            $reasons[] = 'Stripe subscription prep state is stale. Re-run subscription prep before live sync.';
        }

        return [
            'enabled' => $enabled,
            'ready' => $reasons === [],
            'not_ready_reasons' => array_values(array_unique($reasons)),
            'provider_status' => $providerStatus === '' ? 'missing' : $providerStatus,
            'provider_role' => $providerRole === '' ? 'primary' : $providerRole,
            'lifecycle_disabled' => $lifecycleDisabled,
            'requires_lifecycle_disabled' => $requiresLifecycleDisabled,
            'requires_customer_reference' => $requiresCustomerReference,
            'requires_subscription_prep' => $requiresSubscriptionPrep,
            'requires_prep_hash' => $requiresPrepHash,
            'allow_sync_existing_reference' => $allowSyncExistingReference,
            'stripe_secret_configured' => $stripeSecretConfigured,
            'stripe_secret_format_valid' => $stripeSecretFormatValid,
            'stripe_api_base' => $stripeApiBase,
            'stripe_api_base_valid' => $stripeApiBaseValid,
            'customer_reference' => $customerReference !== '' ? $customerReference : null,
            'subscription_reference' => $subscriptionReference !== '' ? $subscriptionReference : null,
            'prep_candidate_hash' => $prepCandidateHash !== '' ? $prepCandidateHash : null,
            'prep_candidate' => $prepCandidate,
            'prep_last_status' => trim((string) ($prepMeta['status'] ?? 'never')),
            'last_status' => trim((string) ($syncMeta['status'] ?? 'never')),
            'last_message' => $this->nullableString($syncMeta['message'] ?? null),
            'last_mode' => $this->nullableString($syncMeta['mode'] ?? null),
            'last_synced_at' => $this->nullableString($syncMeta['synced_at'] ?? null),
            'last_attempted_at' => $this->nullableString($syncMeta['attempted_at'] ?? null),
        ];
    }

    /**
     * @return array{ok:bool,status:string,message:string,mode:?string,candidate_hash:?string,customer_reference:?string,not_ready_reasons:array<int,string>}
     */
    public function syncStripeSubscriptionPrepState(Tenant $tenant, ?int $actorId = null): array
    {
        $tenantId = (int) $tenant->id;
        $overview = $this->billingReadinessOverview();
        $commercialProfile = $this->tenantCommercialProfile($tenantId);
        $readiness = $this->stripeSubscriptionPrepReadiness(
            tenant: $tenant,
            commercialProfile: $commercialProfile,
            billingOverview: $overview
        );

        $notReadyReasons = is_array($readiness['not_ready_reasons'] ?? null)
            ? (array) $readiness['not_ready_reasons']
            : [];
        $customerReference = $this->nullableString($readiness['customer_reference'] ?? null);

        if (! (bool) ($readiness['ready'] ?? false)) {
            $message = 'Stripe subscription prep blocked: '.implode(' ', $notReadyReasons);
            $this->persistStripeSubscriptionPrepResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'blocked',
                message: $message,
                mode: null,
                candidate: [],
                candidateHash: null,
                actorId: $actorId
            );

            Log::warning('landlord.commercial.billing.stripe_subscription_prep.blocked', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'not_ready_reasons' => $notReadyReasons,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'blocked',
                'message' => $message,
                'mode' => null,
                'candidate_hash' => $this->nullableString($readiness['candidate_hash'] ?? null),
                'customer_reference' => $customerReference,
                'not_ready_reasons' => $notReadyReasons,
            ];
        }

        try {
            $candidate = $this->stripeSubscriptionPrepCandidate(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                billingOverview: $overview,
                readiness: $readiness
            );
            $candidateHash = $this->stripeSubscriptionPrepHash($candidate);
            $currentHash = trim((string) data_get($commercialProfile, 'billing_mapping.stripe.subscription_prep_hash', ''));
            $mode = $currentHash === $candidateHash ? 'noop' : 'sync';
            $message = $mode === 'noop'
                ? 'Stripe subscription prep state is already up to date.'
                : 'Stripe subscription prep state synced successfully.';

            $this->persistStripeSubscriptionPrepResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'succeeded',
                message: $message,
                mode: $mode,
                candidate: $candidate,
                candidateHash: $candidateHash,
                actorId: $actorId
            );

            Log::info('landlord.commercial.billing.stripe_subscription_prep.succeeded', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'candidate_hash' => $candidateHash,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => true,
                'status' => 'succeeded',
                'message' => $message,
                'mode' => $mode,
                'candidate_hash' => $candidateHash,
                'customer_reference' => $customerReference,
                'not_ready_reasons' => [],
            ];
        } catch (Throwable $e) {
            $errorMessage = 'Stripe subscription prep exception: '.$e->getMessage();
            $this->persistStripeSubscriptionPrepResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'failed',
                message: $errorMessage,
                mode: null,
                candidate: [],
                candidateHash: null,
                actorId: $actorId
            );

            Log::error('landlord.commercial.billing.stripe_subscription_prep.exception', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'exception' => $e,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'mode' => null,
                'candidate_hash' => null,
                'customer_reference' => $customerReference,
                'not_ready_reasons' => [],
            ];
        }
    }

    /**
     * @return array{ok:bool,status:string,message:string,mode:?string,subscription_reference:?string,subscription_status:?string,customer_reference:?string,prep_candidate_hash:?string,not_ready_reasons:array<int,string>}
     */
    public function syncStripeLiveSubscriptionReference(Tenant $tenant, ?int $actorId = null): array
    {
        $tenantId = (int) $tenant->id;
        $overview = $this->billingReadinessOverview();
        $commercialProfile = $this->tenantCommercialProfile($tenantId);
        $readiness = $this->stripeLiveSubscriptionSyncReadiness(
            tenant: $tenant,
            commercialProfile: $commercialProfile,
            billingOverview: $overview
        );

        $notReadyReasons = is_array($readiness['not_ready_reasons'] ?? null)
            ? (array) $readiness['not_ready_reasons']
            : [];
        $customerReference = $this->nullableString($readiness['customer_reference'] ?? null);
        $currentSubscriptionReference = $this->nullableString($readiness['subscription_reference'] ?? null);
        $prepCandidateHash = $this->nullableString($readiness['prep_candidate_hash'] ?? null);
        $prepCandidate = is_array($readiness['prep_candidate'] ?? null)
            ? (array) $readiness['prep_candidate']
            : [];

        if (! (bool) ($readiness['ready'] ?? false)) {
            $message = 'Stripe live subscription sync blocked: '.implode(' ', $notReadyReasons);
            $this->persistStripeLiveSubscriptionSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'blocked',
                message: $message,
                mode: null,
                customerReference: $customerReference,
                subscriptionReference: $currentSubscriptionReference,
                subscriptionStatus: null,
                prepCandidateHash: $prepCandidateHash,
                actorId: $actorId
            );

            Log::warning('landlord.commercial.billing.stripe_live_subscription_sync.blocked', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'not_ready_reasons' => $notReadyReasons,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'blocked',
                'message' => $message,
                'mode' => null,
                'subscription_reference' => $currentSubscriptionReference,
                'subscription_status' => null,
                'customer_reference' => $customerReference,
                'prep_candidate_hash' => $prepCandidateHash,
                'not_ready_reasons' => $notReadyReasons,
            ];
        }

        $endpoint = $this->stripeApiBaseUrl().'/v1/subscriptions';

        if ($currentSubscriptionReference !== null) {
            $mode = 'sync';
            $syncEndpoint = $endpoint.'/'.urlencode($currentSubscriptionReference);

            try {
                $response = $this->stripeRequest()->get($syncEndpoint);
                $json = is_array($response->json()) ? $response->json() : [];

                if ($response->failed()) {
                    $errorMessage = $this->stripeErrorMessage($json, $response->status());
                    $this->persistStripeLiveSubscriptionSyncResult(
                        tenantId: $tenantId,
                        commercialProfile: $commercialProfile,
                        status: 'failed',
                        message: $errorMessage,
                        mode: $mode,
                        customerReference: $customerReference,
                        subscriptionReference: $currentSubscriptionReference,
                        subscriptionStatus: null,
                        prepCandidateHash: $prepCandidateHash,
                        actorId: $actorId
                    );

                    Log::warning('landlord.commercial.billing.stripe_live_subscription_sync.failed', [
                        'tenant_id' => $tenantId,
                        'tenant_slug' => (string) ($tenant->slug ?? ''),
                        'mode' => $mode,
                        'http_status' => $response->status(),
                        'error_message' => $errorMessage,
                        'actor_id' => $actorId,
                    ]);

                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'message' => $errorMessage,
                        'mode' => $mode,
                        'subscription_reference' => $currentSubscriptionReference,
                        'subscription_status' => null,
                        'customer_reference' => $customerReference,
                        'prep_candidate_hash' => $prepCandidateHash,
                        'not_ready_reasons' => [],
                    ];
                }

                $resolvedSubscriptionReference = trim((string) ($json['id'] ?? $currentSubscriptionReference));
                if ($resolvedSubscriptionReference === '') {
                    $errorMessage = 'Stripe live subscription sync returned no subscription reference.';
                    $this->persistStripeLiveSubscriptionSyncResult(
                        tenantId: $tenantId,
                        commercialProfile: $commercialProfile,
                        status: 'failed',
                        message: $errorMessage,
                        mode: $mode,
                        customerReference: $customerReference,
                        subscriptionReference: $currentSubscriptionReference,
                        subscriptionStatus: null,
                        prepCandidateHash: $prepCandidateHash,
                        actorId: $actorId
                    );

                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'message' => $errorMessage,
                        'mode' => $mode,
                        'subscription_reference' => $currentSubscriptionReference,
                        'subscription_status' => null,
                        'customer_reference' => $customerReference,
                        'prep_candidate_hash' => $prepCandidateHash,
                        'not_ready_reasons' => [],
                    ];
                }

                $remoteCustomerReference = trim((string) ($json['customer'] ?? ''));
                if (
                    $customerReference !== null
                    && $remoteCustomerReference !== ''
                    && $remoteCustomerReference !== $customerReference
                ) {
                    $errorMessage = 'Stripe live subscription sync blocked: existing subscription customer does not match tenant customer reference.';
                    $this->persistStripeLiveSubscriptionSyncResult(
                        tenantId: $tenantId,
                        commercialProfile: $commercialProfile,
                        status: 'failed',
                        message: $errorMessage,
                        mode: $mode,
                        customerReference: $customerReference,
                        subscriptionReference: $resolvedSubscriptionReference,
                        subscriptionStatus: trim((string) ($json['status'] ?? '')),
                        prepCandidateHash: $prepCandidateHash,
                        actorId: $actorId
                    );

                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'message' => $errorMessage,
                        'mode' => $mode,
                        'subscription_reference' => $resolvedSubscriptionReference,
                        'subscription_status' => trim((string) ($json['status'] ?? '')) ?: null,
                        'customer_reference' => $customerReference,
                        'prep_candidate_hash' => $prepCandidateHash,
                        'not_ready_reasons' => [],
                    ];
                }

                $subscriptionStatus = trim((string) ($json['status'] ?? ''));
                $message = 'Stripe live subscription reference synced successfully.';

                $this->persistStripeLiveSubscriptionSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'succeeded',
                    message: $message,
                    mode: $mode,
                    customerReference: $customerReference,
                    subscriptionReference: $resolvedSubscriptionReference,
                    subscriptionStatus: $subscriptionStatus,
                    prepCandidateHash: $prepCandidateHash,
                    actorId: $actorId
                );

                Log::info('landlord.commercial.billing.stripe_live_subscription_sync.succeeded', [
                    'tenant_id' => $tenantId,
                    'tenant_slug' => (string) ($tenant->slug ?? ''),
                    'mode' => $mode,
                    'subscription_reference' => $resolvedSubscriptionReference,
                    'subscription_status' => $subscriptionStatus,
                    'actor_id' => $actorId,
                ]);

                return [
                    'ok' => true,
                    'status' => 'succeeded',
                    'message' => $message,
                    'mode' => $mode,
                    'subscription_reference' => $resolvedSubscriptionReference,
                    'subscription_status' => $subscriptionStatus !== '' ? $subscriptionStatus : null,
                    'customer_reference' => $customerReference,
                    'prep_candidate_hash' => $prepCandidateHash,
                    'not_ready_reasons' => [],
                ];
            } catch (Throwable $e) {
                $errorMessage = 'Stripe live subscription sync exception: '.$e->getMessage();
                $this->persistStripeLiveSubscriptionSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'failed',
                    message: $errorMessage,
                    mode: $mode,
                    customerReference: $customerReference,
                    subscriptionReference: $currentSubscriptionReference,
                    subscriptionStatus: null,
                    prepCandidateHash: $prepCandidateHash,
                    actorId: $actorId
                );

                Log::error('landlord.commercial.billing.stripe_live_subscription_sync.exception', [
                    'tenant_id' => $tenantId,
                    'tenant_slug' => (string) ($tenant->slug ?? ''),
                    'mode' => $mode,
                    'exception' => $e,
                    'actor_id' => $actorId,
                ]);

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'mode' => $mode,
                    'subscription_reference' => $currentSubscriptionReference,
                    'subscription_status' => null,
                    'customer_reference' => $customerReference,
                    'prep_candidate_hash' => $prepCandidateHash,
                    'not_ready_reasons' => [],
                ];
            }
        }

        $mode = 'create';
        $lookupKeys = $this->stripeRecurringLookupKeysFromPrepCandidate($prepCandidate);
        $resolvedPriceIds = $this->resolveStripeRecurringPriceIds($lookupKeys);
        $priceIdsByLookupKey = is_array($resolvedPriceIds['price_ids_by_lookup_key'] ?? null)
            ? (array) $resolvedPriceIds['price_ids_by_lookup_key']
            : [];

        if (! (bool) ($resolvedPriceIds['ok'] ?? false)) {
            $errorMessage = trim((string) ($resolvedPriceIds['message'] ?? ''));
            if ($errorMessage === '') {
                $errorMessage = 'Stripe price lookup resolution failed.';
            }

            $this->persistStripeLiveSubscriptionSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'failed',
                message: $errorMessage,
                mode: $mode,
                customerReference: $customerReference,
                subscriptionReference: null,
                subscriptionStatus: null,
                prepCandidateHash: $prepCandidateHash,
                actorId: $actorId
            );

            Log::warning('landlord.commercial.billing.stripe_live_subscription_sync.failed', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'error_message' => $errorMessage,
                'missing_lookup_keys' => (array) ($resolvedPriceIds['missing_lookup_keys'] ?? []),
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'mode' => $mode,
                'subscription_reference' => null,
                'subscription_status' => null,
                'customer_reference' => $customerReference,
                'prep_candidate_hash' => $prepCandidateHash,
                'not_ready_reasons' => [],
            ];
        }

        $itemPriceIds = $this->stripeSubscriptionItemPriceIds($prepCandidate, $priceIdsByLookupKey);
        if ($itemPriceIds === []) {
            $itemPriceIds = array_values(array_filter(array_unique(array_map(
                static fn ($value): string => trim((string) $value),
                array_values($priceIdsByLookupKey)
            )), static fn (string $value): bool => $value !== ''));
        }
        if ($itemPriceIds === []) {
            $errorMessage = trim((string) ($resolvedPriceIds['message'] ?? 'Stripe price lookup resolution failed.'));
            $this->persistStripeLiveSubscriptionSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'failed',
                message: $errorMessage,
                mode: $mode,
                customerReference: $customerReference,
                subscriptionReference: null,
                subscriptionStatus: null,
                prepCandidateHash: $prepCandidateHash,
                actorId: $actorId
            );

            Log::warning('landlord.commercial.billing.stripe_live_subscription_sync.failed', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'error_message' => $errorMessage,
                'missing_lookup_keys' => (array) ($resolvedPriceIds['missing_lookup_keys'] ?? []),
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'mode' => $mode,
                'subscription_reference' => null,
                'subscription_status' => null,
                'customer_reference' => $customerReference,
                'prep_candidate_hash' => $prepCandidateHash,
                'not_ready_reasons' => [],
            ];
        }

        $guardedAction = is_array(data_get($overview, 'guarded_actions.stripe_live_subscription_sync'))
            ? (array) data_get($overview, 'guarded_actions.stripe_live_subscription_sync')
            : [];
        $collectionMethod = strtolower(trim((string) ($guardedAction['collection_method'] ?? 'send_invoice')));
        if (! in_array($collectionMethod, ['send_invoice', 'charge_automatically'], true)) {
            $collectionMethod = 'send_invoice';
        }
        $daysUntilDue = max(1, (int) ($guardedAction['days_until_due'] ?? 30));
        $planKey = strtolower(trim((string) data_get($prepCandidate, 'plan.key', '')));

        $payload = [
            'customer' => (string) $customerReference,
            'collection_method' => $collectionMethod,
            'metadata[tenant_id]' => (string) $tenantId,
            'metadata[tenant_slug]' => trim((string) ($tenant->slug ?? '')),
            'metadata[source]' => 'landlord_commercial_guarded_live_subscription_sync',
            'metadata[plan_key]' => $planKey,
            'metadata[prep_candidate_hash]' => (string) ($prepCandidateHash ?? ''),
        ];

        if ($collectionMethod === 'send_invoice') {
            $payload['days_until_due'] = $daysUntilDue;
        }

        $payload = array_merge($payload, $this->stripeSubscriptionItemsPayload($itemPriceIds));

        try {
            $response = $this->stripeRequest()
                ->withHeaders([
                    'Idempotency-Key' => $this->stripeSubscriptionIdempotencyKey(
                        tenantId: $tenantId,
                        prepCandidateHash: (string) ($prepCandidateHash ?? ''),
                        mode: $mode
                    ),
                ])
                ->post($endpoint, $payload);

            $json = is_array($response->json()) ? $response->json() : [];
            if ($response->failed()) {
                $errorMessage = $this->stripeErrorMessage($json, $response->status());
                $this->persistStripeLiveSubscriptionSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'failed',
                    message: $errorMessage,
                    mode: $mode,
                    customerReference: $customerReference,
                    subscriptionReference: null,
                    subscriptionStatus: null,
                    prepCandidateHash: $prepCandidateHash,
                    actorId: $actorId
                );

                Log::warning('landlord.commercial.billing.stripe_live_subscription_sync.failed', [
                    'tenant_id' => $tenantId,
                    'tenant_slug' => (string) ($tenant->slug ?? ''),
                    'mode' => $mode,
                    'http_status' => $response->status(),
                    'error_message' => $errorMessage,
                    'actor_id' => $actorId,
                ]);

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'mode' => $mode,
                    'subscription_reference' => null,
                    'subscription_status' => null,
                    'customer_reference' => $customerReference,
                    'prep_candidate_hash' => $prepCandidateHash,
                    'not_ready_reasons' => [],
                ];
            }

            $resolvedSubscriptionReference = trim((string) ($json['id'] ?? ''));
            if ($resolvedSubscriptionReference === '') {
                $errorMessage = 'Stripe live subscription create returned no subscription reference.';
                $this->persistStripeLiveSubscriptionSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'failed',
                    message: $errorMessage,
                    mode: $mode,
                    customerReference: $customerReference,
                    subscriptionReference: null,
                    subscriptionStatus: null,
                    prepCandidateHash: $prepCandidateHash,
                    actorId: $actorId
                );

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'mode' => $mode,
                    'subscription_reference' => null,
                    'subscription_status' => null,
                    'customer_reference' => $customerReference,
                    'prep_candidate_hash' => $prepCandidateHash,
                    'not_ready_reasons' => [],
                ];
            }

            $subscriptionStatus = trim((string) ($json['status'] ?? ''));
            $message = 'Stripe live subscription reference created successfully.';

            $this->persistStripeLiveSubscriptionSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'succeeded',
                message: $message,
                mode: $mode,
                customerReference: $customerReference,
                subscriptionReference: $resolvedSubscriptionReference,
                subscriptionStatus: $subscriptionStatus,
                prepCandidateHash: $prepCandidateHash,
                actorId: $actorId
            );

            Log::info('landlord.commercial.billing.stripe_live_subscription_sync.succeeded', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'subscription_reference' => $resolvedSubscriptionReference,
                'subscription_status' => $subscriptionStatus,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => true,
                'status' => 'succeeded',
                'message' => $message,
                'mode' => $mode,
                'subscription_reference' => $resolvedSubscriptionReference,
                'subscription_status' => $subscriptionStatus !== '' ? $subscriptionStatus : null,
                'customer_reference' => $customerReference,
                'prep_candidate_hash' => $prepCandidateHash,
                'not_ready_reasons' => [],
            ];
        } catch (Throwable $e) {
            $errorMessage = 'Stripe live subscription create exception: '.$e->getMessage();
            $this->persistStripeLiveSubscriptionSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'failed',
                message: $errorMessage,
                mode: $mode,
                customerReference: $customerReference,
                subscriptionReference: null,
                subscriptionStatus: null,
                prepCandidateHash: $prepCandidateHash,
                actorId: $actorId
            );

            Log::error('landlord.commercial.billing.stripe_live_subscription_sync.exception', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'exception' => $e,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'mode' => $mode,
                'subscription_reference' => null,
                'subscription_status' => null,
                'customer_reference' => $customerReference,
                'prep_candidate_hash' => $prepCandidateHash,
                'not_ready_reasons' => [],
            ];
        }
    }

    /**
     * @return array{ok:bool,status:string,message:string,mode:?string,customer_reference:?string,not_ready_reasons:array<int,string>}
     */
    public function syncStripeCustomerReference(Tenant $tenant, ?int $actorId = null): array
    {
        $tenantId = (int) $tenant->id;
        $commercialProfile = $this->tenantCommercialProfile($tenantId);
        $readiness = $this->stripeCustomerSyncReadiness(
            tenant: $tenant,
            commercialProfile: $commercialProfile,
            billingOverview: $this->billingReadinessOverview()
        );

        $notReadyReasons = is_array($readiness['not_ready_reasons'] ?? null)
            ? (array) $readiness['not_ready_reasons']
            : [];

        if (! (bool) ($readiness['ready'] ?? false)) {
            $message = 'Stripe customer sync blocked: '.implode(' ', $notReadyReasons);
            $this->persistStripeCustomerSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'blocked',
                message: $message,
                mode: null,
                customerReference: $this->nullableString($readiness['customer_reference'] ?? null),
                actorId: $actorId
            );

            Log::warning('landlord.commercial.billing.stripe_customer_sync.blocked', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'not_ready_reasons' => $notReadyReasons,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'blocked',
                'message' => $message,
                'mode' => null,
                'customer_reference' => $this->nullableString($readiness['customer_reference'] ?? null),
                'not_ready_reasons' => $notReadyReasons,
            ];
        }

        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $currentReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));
        $mode = $currentReference === '' ? 'create' : 'update';

        $planKey = $this->canonicalCommercialPlanKey((string) optional(
            TenantAccessProfile::query()->where('tenant_id', $tenantId)->first()
        )->plan_key);

        $payload = [
            'name' => trim((string) ($tenant->name ?? '')) !== ''
                ? trim((string) $tenant->name)
                : 'Tenant #'.$tenantId,
            'description' => 'Forestry Backstage tenant billing prep reference',
            'metadata' => [
                'tenant_id' => (string) $tenantId,
                'tenant_slug' => trim((string) ($tenant->slug ?? '')),
                'tenant_name' => trim((string) ($tenant->name ?? '')),
                'plan_key' => $planKey,
                'source' => 'landlord_commercial_guarded_action',
            ],
        ];

        $endpoint = $mode === 'update'
            ? $this->stripeApiBaseUrl().'/v1/customers/'.urlencode($currentReference)
            : $this->stripeApiBaseUrl().'/v1/customers';

        try {
            $response = $this->stripeRequest()
                ->withHeaders([
                    'Idempotency-Key' => $this->stripeIdempotencyKey($tenantId, $mode),
                ])
                ->post($endpoint, $payload);

            $json = is_array($response->json()) ? $response->json() : [];
            if ($response->failed()) {
                $errorMessage = $this->stripeErrorMessage($json, $response->status());
                $this->persistStripeCustomerSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'failed',
                    message: $errorMessage,
                    mode: $mode,
                    customerReference: $this->nullableString($currentReference),
                    actorId: $actorId
                );

                Log::warning('landlord.commercial.billing.stripe_customer_sync.failed', [
                    'tenant_id' => $tenantId,
                    'tenant_slug' => (string) ($tenant->slug ?? ''),
                    'mode' => $mode,
                    'http_status' => $response->status(),
                    'error_message' => $errorMessage,
                    'actor_id' => $actorId,
                ]);

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'mode' => $mode,
                    'customer_reference' => $this->nullableString($currentReference),
                    'not_ready_reasons' => [],
                ];
            }

            $resolvedReference = trim((string) ($json['id'] ?? $currentReference));
            if ($resolvedReference === '') {
                $errorMessage = 'Stripe customer sync returned no customer reference.';
                $this->persistStripeCustomerSyncResult(
                    tenantId: $tenantId,
                    commercialProfile: $commercialProfile,
                    status: 'failed',
                    message: $errorMessage,
                    mode: $mode,
                    customerReference: $this->nullableString($currentReference),
                    actorId: $actorId
                );

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'mode' => $mode,
                    'customer_reference' => $this->nullableString($currentReference),
                    'not_ready_reasons' => [],
                ];
            }

            $this->persistStripeCustomerSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'succeeded',
                message: $mode === 'create'
                    ? 'Stripe customer reference created successfully.'
                    : 'Stripe customer reference synced successfully.',
                mode: $mode,
                customerReference: $resolvedReference,
                actorId: $actorId
            );

            Log::info('landlord.commercial.billing.stripe_customer_sync.succeeded', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'customer_reference' => $resolvedReference,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => true,
                'status' => 'succeeded',
                'message' => $mode === 'create'
                    ? 'Stripe customer reference created successfully.'
                    : 'Stripe customer reference synced successfully.',
                'mode' => $mode,
                'customer_reference' => $resolvedReference,
                'not_ready_reasons' => [],
            ];
        } catch (Throwable $e) {
            $errorMessage = 'Stripe customer sync exception: '.$e->getMessage();
            $this->persistStripeCustomerSyncResult(
                tenantId: $tenantId,
                commercialProfile: $commercialProfile,
                status: 'failed',
                message: $errorMessage,
                mode: $mode,
                customerReference: $this->nullableString($currentReference),
                actorId: $actorId
            );

            Log::error('landlord.commercial.billing.stripe_customer_sync.exception', [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) ($tenant->slug ?? ''),
                'mode' => $mode,
                'exception' => $e,
                'actor_id' => $actorId,
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'mode' => $mode,
                'customer_reference' => $this->nullableString($currentReference),
                'not_ready_reasons' => [],
            ];
        }
    }

    /**
     * @return array<int,\App\Models\Tenant>
     */
    public function tenantRowsForLandlord(): array
    {
        return Tenant::query()
            ->with(['accessProfile', 'accessAddons', 'moduleStates', 'moduleEntitlements', 'commercialOverride'])
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function normalizeCatalogRow(string $type, string $key, array $row): array
    {
        $payload = [];
        foreach (['modules', 'eligible_addons', 'recommended_modules', 'default_labels', 'dashboard_layout', 'navigation_emphasis', 'onboarding_checklist', 'included_usage'] as $payloadKey) {
            if (array_key_exists($payloadKey, $row)) {
                $payload[$payloadKey] = $row[$payloadKey];
            }
        }

        return [
            'entry_type' => $type,
            'entry_key' => strtolower(trim($key)),
            'name' => (string) ($row['name'] ?? Str::title(str_replace('_', ' ', $key))),
            'status' => (string) ($row['status'] ?? ($row['active'] ?? true ? 'active' : 'inactive')),
            'is_active' => (bool) ($row['active'] ?? true),
            'is_public' => (bool) ($row['is_public'] ?? true),
            'position' => max(0, (int) ($row['position'] ?? 100)),
            'currency' => strtoupper(trim((string) ($row['currency'] ?? 'USD'))) ?: 'USD',
            'recurring_price_cents' => $this->nullableInt($row['recurring_price_cents'] ?? null),
            'recurring_interval' => strtolower(trim((string) ($row['recurring_interval'] ?? 'month'))) ?: 'month',
            'setup_price_cents' => $this->nullableInt($row['setup_price_cents'] ?? null),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function rowFromEntry(LandlordCatalogEntry $row): array
    {
        return [
            'entry_type' => strtolower(trim((string) $row->entry_type)),
            'entry_key' => strtolower(trim((string) $row->entry_key)),
            'name' => (string) $row->name,
            'status' => (string) ($row->status ?: 'active'),
            'is_active' => (bool) $row->is_active,
            'is_public' => (bool) $row->is_public,
            'position' => (int) $row->position,
            'currency' => strtoupper(trim((string) ($row->currency ?: 'USD'))),
            'recurring_price_cents' => $this->nullableInt($row->recurring_price_cents),
            'recurring_interval' => strtolower(trim((string) ($row->recurring_interval ?: 'month'))),
            'setup_price_cents' => $this->nullableInt($row->setup_price_cents),
            'payload' => is_array($row->payload) ? $row->payload : [],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function defaultCatalog(string $type): array
    {
        return match ($type) {
            LandlordCatalogEntry::TYPE_PLAN => is_array(config('commercial.plans')) ? config('commercial.plans') : [],
            LandlordCatalogEntry::TYPE_ADDON => is_array(config('commercial.addons')) ? config('commercial.addons') : [],
            LandlordCatalogEntry::TYPE_TEMPLATE => is_array(config('commercial.templates')) ? config('commercial.templates') : [],
            LandlordCatalogEntry::TYPE_SETUP_PACKAGE => is_array(config('commercial.setup_packages')) ? config('commercial.setup_packages') : [],
            default => [],
        };
    }

    protected function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            LandlordCatalogEntry::TYPE_PLAN,
            LandlordCatalogEntry::TYPE_ADDON,
            LandlordCatalogEntry::TYPE_TEMPLATE,
            LandlordCatalogEntry::TYPE_SETUP_PACKAGE => $normalized,
            default => throw new \InvalidArgumentException('Unsupported catalog type: '.$type),
        };
    }

    protected function entryQuery(string $type)
    {
        return LandlordCatalogEntry::query()
            ->where('entry_type', $type)
            ->orderBy('position')
            ->orderBy('entry_key');
    }

    /**
     * @param  array<string,mixed>|string  $value
     * @return array<string,mixed>
     */
    protected function normalizeJsonArray(array|string $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function validatedTenantModuleEntitlementInput(string $moduleKey, array $input): array
    {
        $definition = config('module_catalog.modules.'.$moduleKey);
        if (! is_array($definition)) {
            throw ValidationException::withMessages([
                'module_key' => 'Unknown module key.',
            ]);
        }

        $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
        $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));
        $availabilityStatus = strtolower(trim((string) ($input['availability_status'] ?? 'available'))) ?: 'available';
        $enabledStatus = strtolower(trim((string) ($input['enabled_status'] ?? 'inherit'))) ?: 'inherit';
        $billingStatus = $this->nullableString($input['billing_status'] ?? null);
        $priceOverride = $this->nullableInt($input['price_override_cents'] ?? null);

        if ($status === 'disabled') {
            throw ValidationException::withMessages([
                'module_key' => 'Disabled catalog modules cannot receive entitlement overrides.',
            ]);
        }

        if ($enabledStatus === 'enabled' && ! in_array($status, ['live', 'beta'], true)) {
            throw ValidationException::withMessages([
                'enabled_status' => 'Only live or beta modules can be explicitly enabled.',
            ]);
        }

        if ($enabledStatus === 'enabled' && in_array($availabilityStatus, ['unavailable', 'disabled'], true)) {
            throw ValidationException::withMessages([
                'enabled_status' => 'Unavailable or disabled modules cannot be explicitly enabled.',
            ]);
        }

        if ($billingStatus === 'unavailable' && $availabilityStatus !== 'unavailable') {
            throw ValidationException::withMessages([
                'billing_status' => 'Unavailable billing status requires the module availability to be unavailable.',
            ]);
        }

        if ($priceOverride !== null && $billingStatus === null) {
            throw ValidationException::withMessages([
                'billing_status' => 'A billing status is required when a price override is provided.',
            ]);
        }

        if ($marketState === 'PLACEHOLDER' && $enabledStatus === 'enabled') {
            throw ValidationException::withMessages([
                'enabled_status' => 'Placeholder modules cannot be tenant-enabled yet.',
            ]);
        }

        return [
            ...$input,
            'availability_status' => $availabilityStatus,
            'enabled_status' => $enabledStatus,
            'billing_status' => $billingStatus,
            'price_override_cents' => $priceOverride,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantModuleEntitlementState(TenantModuleEntitlement $entitlement): array
    {
        return [
            'module_key' => (string) $entitlement->module_key,
            'availability_status' => (string) $entitlement->availability_status,
            'enabled_status' => (string) $entitlement->enabled_status,
            'billing_status' => $this->nullableString($entitlement->billing_status),
            'price_override_cents' => $entitlement->price_override_cents,
            'currency' => (string) ($entitlement->currency ?: 'USD'),
            'entitlement_source' => $this->nullableString($entitlement->entitlement_source),
            'price_source' => $this->nullableString($entitlement->price_source),
            'starts_at' => optional($entitlement->starts_at)->toIso8601String(),
            'ends_at' => optional($entitlement->ends_at)->toIso8601String(),
            'notes' => $this->nullableString($entitlement->notes),
            'metadata' => is_array($entitlement->metadata) ? $entitlement->metadata : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantCommercialOverrideState(TenantCommercialOverride $override): array
    {
        return [
            'template_key' => $this->nullableString($override->template_key),
            'store_channel_allowance' => $override->store_channel_allowance,
            'plan_pricing_overrides' => is_array($override->plan_pricing_overrides) ? $override->plan_pricing_overrides : [],
            'addon_pricing_overrides' => is_array($override->addon_pricing_overrides) ? $override->addon_pricing_overrides : [],
            'included_usage_overrides' => is_array($override->included_usage_overrides) ? $override->included_usage_overrides : [],
            'display_labels' => is_array($override->display_labels) ? $override->display_labels : [],
            'billing_mapping' => is_array($override->billing_mapping) ? $override->billing_mapping : [],
            'metadata' => is_array($override->metadata) ? $override->metadata : [],
        ];
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
        )), static fn (string $value): bool => $value !== ''));
    }

    protected function canonicalCommercialPlanKey(string $planKey): string
    {
        $normalized = strtolower(trim($planKey));
        if ($normalized === '') {
            $normalized = strtolower(trim((string) config('entitlements.default_plan', 'starter')));
        }

        $alias = config('commercial.legacy_plan_aliases.'.$normalized);
        if (is_string($alias) && trim($alias) !== '') {
            return strtolower(trim($alias));
        }

        return $normalized;
    }

    protected function hasFilledArrayValue(array $values, string $key): bool
    {
        $value = $values[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '';
    }

    protected function hasFilledPath(array $values, string $path): bool
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => trim($segment) !== ''));
        if ($segments === []) {
            return false;
        }

        $current = $values;
        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return is_scalar($current) && trim((string) $current) !== '';
    }

    /**
     * @return array<int,string>
     */
    protected function enabledAddonKeysForTenant(int $tenantId): array
    {
        return $this->normalizeKeys(
            TenantAccessAddon::query()
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
                ->all()
        );
    }

    /**
     * @param  array<string,mixed>  $commercialProfile
     * @param  array<string,mixed>  $billingOverview
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    protected function stripeSubscriptionPrepCandidate(
        int $tenantId,
        array $commercialProfile,
        array $billingOverview,
        array $readiness
    ): array {
        $stripeMap = is_array($billingOverview['stripe_mapping'] ?? null) ? (array) $billingOverview['stripe_mapping'] : [];
        $tierMap = is_array($stripeMap['tiers'] ?? null) ? (array) $stripeMap['tiers'] : [];
        $addonMap = is_array($stripeMap['addons'] ?? null) ? (array) $stripeMap['addons'] : [];
        $usageMap = is_array($stripeMap['usage_metrics'] ?? null) ? (array) $stripeMap['usage_metrics'] : [];
        $storeChannels = is_array($stripeMap['store_channels'] ?? null) ? (array) $stripeMap['store_channels'] : [];

        $resolvedPlanKey = strtolower(trim((string) ($readiness['resolved_plan_key'] ?? '')));
        $enabledAddonKeys = $this->normalizeKeys(
            is_array($readiness['enabled_addons'] ?? null) ? (array) $readiness['enabled_addons'] : []
        );

        $planMapping = is_array($tierMap[$resolvedPlanKey] ?? null) ? (array) $tierMap[$resolvedPlanKey] : [];
        $addonMappings = [];
        foreach ($enabledAddonKeys as $addonKey) {
            $addonMappings[$addonKey] = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
        }

        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];

        $includedUsage = $this->tenantIncludedUsageLimits($tenantId);

        return [
            'source' => 'landlord_commercial_guarded_action',
            'tenant_id' => $tenantId,
            'plan' => [
                'key' => $resolvedPlanKey,
                'product_lookup_key' => trim((string) ($planMapping['product_lookup_key'] ?? '')),
                'recurring_price_lookup_key' => trim((string) ($planMapping['recurring_price_lookup_key'] ?? '')),
                'setup_price_lookup_key' => trim((string) ($planMapping['setup_price_lookup_key'] ?? '')),
            ],
            'addons' => $addonMappings,
            'usage_metrics' => $usageMap,
            'store_channels' => [
                'policy' => $storeChannels,
                'included_limit' => $this->nullableInt($includedUsage['store_channels'] ?? null),
            ],
            'customer_reference' => trim((string) data_get($billingMapping, 'stripe.customer_reference', '')),
            'subscription_reference' => $this->nullableString(data_get($billingMapping, 'stripe.subscription_reference', null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    protected function stripeSubscriptionPrepHash(array $candidate): string
    {
        $normalized = $this->sortNestedArrayKeys($candidate);
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return '';
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    protected function sortNestedArrayKeys(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->sortNestedArrayKeys($nested);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<int,string>
     */
    protected function stripeRecurringLookupKeysFromPrepCandidate(array $candidate): array
    {
        $lookupKeys = [];

        $planRecurringLookupKey = strtolower(trim((string) data_get($candidate, 'plan.recurring_price_lookup_key', '')));
        if ($planRecurringLookupKey !== '') {
            $lookupKeys[] = $planRecurringLookupKey;
        }

        $addonMappings = data_get($candidate, 'addons', []);
        if (is_array($addonMappings)) {
            foreach ($addonMappings as $addonMapping) {
                if (! is_array($addonMapping)) {
                    continue;
                }

                $addonRecurringLookupKey = strtolower(trim((string) ($addonMapping['recurring_price_lookup_key'] ?? '')));
                if ($addonRecurringLookupKey !== '') {
                    $lookupKeys[] = $addonRecurringLookupKey;
                }
            }
        }

        return $this->normalizeKeys($lookupKeys);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,string>  $priceIdsByLookupKey
     * @return array<int,string>
     */
    protected function stripeSubscriptionItemPriceIds(array $candidate, array $priceIdsByLookupKey): array
    {
        $items = [];

        $planRecurringLookupKey = strtolower(trim((string) data_get($candidate, 'plan.recurring_price_lookup_key', '')));
        if ($planRecurringLookupKey !== '' && isset($priceIdsByLookupKey[$planRecurringLookupKey])) {
            $items[] = trim((string) $priceIdsByLookupKey[$planRecurringLookupKey]);
        }

        $addonMappings = data_get($candidate, 'addons', []);
        if (is_array($addonMappings)) {
            foreach ($addonMappings as $addonMapping) {
                if (! is_array($addonMapping)) {
                    continue;
                }

                $addonRecurringLookupKey = strtolower(trim((string) ($addonMapping['recurring_price_lookup_key'] ?? '')));
                if ($addonRecurringLookupKey === '' || ! isset($priceIdsByLookupKey[$addonRecurringLookupKey])) {
                    continue;
                }

                $items[] = trim((string) $priceIdsByLookupKey[$addonRecurringLookupKey]);
            }
        }

        return array_values(array_filter(array_unique($items), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<int,string>  $itemPriceIds
     * @return array<string,mixed>
     */
    protected function stripeSubscriptionItemsPayload(array $itemPriceIds): array
    {
        $payload = [];
        foreach ($itemPriceIds as $index => $priceId) {
            $normalizedPriceId = trim((string) $priceId);
            if ($normalizedPriceId === '') {
                continue;
            }

            $payload['items['.$index.'][price]'] = $normalizedPriceId;
            $payload['items['.$index.'][quantity]'] = 1;
        }

        return $payload;
    }

    /**
     * @param  array<int,string>  $lookupKeys
     * @return array{ok:bool,message:?string,price_ids_by_lookup_key:array<string,string>,missing_lookup_keys:array<int,string>}
     */
    protected function resolveStripeRecurringPriceIds(array $lookupKeys): array
    {
        $normalizedLookupKeys = $this->normalizeKeys($lookupKeys);
        if ($normalizedLookupKeys === []) {
            return [
                'ok' => false,
                'message' => 'No recurring Stripe lookup keys were available for subscription create.',
                'price_ids_by_lookup_key' => [],
                'missing_lookup_keys' => [],
            ];
        }

        $query = [
            'active' => 'true',
            'limit' => 100,
        ];
        foreach ($normalizedLookupKeys as $index => $lookupKey) {
            $query['lookup_keys['.$index.']'] = $lookupKey;
        }

        $response = $this->stripeRequest()
            ->get($this->stripeApiBaseUrl().'/v1/prices', $query);
        $json = is_array($response->json()) ? $response->json() : [];

        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => $this->stripeErrorMessage($json, $response->status()),
                'price_ids_by_lookup_key' => [],
                'missing_lookup_keys' => $normalizedLookupKeys,
            ];
        }

        $priceRows = is_array($json['data'] ?? null) ? (array) $json['data'] : [];
        $priceIdsByLookupKey = [];
        foreach ($priceRows as $index => $priceRow) {
            if (! is_array($priceRow)) {
                continue;
            }

            $lookupKey = strtolower(trim((string) ($priceRow['lookup_key'] ?? '')));
            if ($lookupKey === '' && isset($normalizedLookupKeys[$index])) {
                $lookupKey = $normalizedLookupKeys[$index];
            }
            $priceId = trim((string) ($priceRow['id'] ?? ''));
            if ($lookupKey === '' || $priceId === '') {
                continue;
            }

            $priceIdsByLookupKey[$lookupKey] = $priceId;
        }

        if (count($priceIdsByLookupKey) < count($normalizedLookupKeys)) {
            $rowPriceIds = array_values(array_filter(array_map(
                static fn ($priceRow): string => is_array($priceRow) ? trim((string) ($priceRow['id'] ?? '')) : '',
                $priceRows
            ), static fn (string $priceId): bool => $priceId !== ''));
            $usedPriceIds = array_values($priceIdsByLookupKey);

            foreach ($normalizedLookupKeys as $lookupKey) {
                if (isset($priceIdsByLookupKey[$lookupKey])) {
                    continue;
                }

                foreach ($rowPriceIds as $candidatePriceId) {
                    if (in_array($candidatePriceId, $usedPriceIds, true)) {
                        continue;
                    }

                    $priceIdsByLookupKey[$lookupKey] = $candidatePriceId;
                    $usedPriceIds[] = $candidatePriceId;
                    break;
                }
            }
        }

        $missingLookupKeys = array_values(array_filter(
            $normalizedLookupKeys,
            static fn (string $lookupKey): bool => ! array_key_exists($lookupKey, $priceIdsByLookupKey)
        ));

        if ($missingLookupKeys !== []) {
            return [
                'ok' => false,
                'message' => 'Missing Stripe price lookup keys: '.implode(', ', $missingLookupKeys).'. Verify recurring price mappings in `commercial.stripe_mapping` and Stripe price lookup keys.',
                'price_ids_by_lookup_key' => $priceIdsByLookupKey,
                'missing_lookup_keys' => $missingLookupKeys,
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'price_ids_by_lookup_key' => $priceIdsByLookupKey,
            'missing_lookup_keys' => [],
        ];
    }

    protected function stripeApiBaseUrl(): string
    {
        return rtrim(trim((string) config('services.stripe.api_base', 'https://api.stripe.com')), '/');
    }

    protected function stripeSecretConfigured(): bool
    {
        return $this->stripeSecret() !== '';
    }

    protected function stripeSecretFormatValid(): bool
    {
        $secret = $this->stripeSecret();
        if ($secret === '') {
            return false;
        }

        return str_starts_with($secret, 'sk_');
    }

    protected function stripeApiBaseValid(string $apiBase): bool
    {
        $normalized = trim($apiBase);
        if ($normalized === '') {
            return false;
        }

        $validated = filter_var($normalized, FILTER_VALIDATE_URL);
        if (! is_string($validated) || $validated === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($validated, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($validated, PHP_URL_HOST)));

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http') {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    protected function stripeSecret(): string
    {
        return trim((string) config('services.stripe.secret', ''));
    }

    protected function stripeRequest(): PendingRequest
    {
        $timeout = max(5, (int) config('services.stripe.timeout', 20));

        return Http::asForm()
            ->acceptJson()
            ->timeout($timeout)
            ->retry(1, 250, throw: false)
            ->withBasicAuth($this->stripeSecret(), '');
    }

    protected function stripeIdempotencyKey(int $tenantId, string $mode): string
    {
        $normalizedMode = strtolower(trim($mode)) === 'update' ? 'update' : 'create';

        return sprintf('tenant-%d-stripe-customer-%s-v1', $tenantId, $normalizedMode);
    }

    protected function stripeSubscriptionIdempotencyKey(int $tenantId, string $prepCandidateHash, string $mode): string
    {
        $normalizedMode = strtolower(trim($mode)) === 'sync' ? 'sync' : 'create';
        $normalizedHash = trim($prepCandidateHash) !== ''
            ? substr(trim($prepCandidateHash), 0, 24)
            : 'nohash';

        return sprintf('tenant-%d-stripe-subscription-%s-%s-v1', $tenantId, $normalizedMode, $normalizedHash);
    }

    /**
     * @param  array<string,mixed>  $json
     */
    protected function stripeErrorMessage(array $json, int $status): string
    {
        $message = trim((string) data_get($json, 'error.message', ''));
        if ($message === '') {
            $message = 'Stripe API request failed with status '.$status.'.';
        }

        return $message;
    }

    /**
     * @param  array<string,mixed>  $commercialProfile
     */
    protected function persistStripeCustomerSyncResult(
        int $tenantId,
        array $commercialProfile,
        string $status,
        string $message,
        ?string $mode,
        ?string $customerReference,
        ?int $actorId
    ): void {
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];

        if ($customerReference !== null && trim($customerReference) !== '') {
            data_set($billingMapping, 'stripe.customer_reference', trim($customerReference));
            data_set($billingMapping, 'stripe.customer_synced_at', now()->toIso8601String());
            if ($mode !== null) {
                data_set($billingMapping, 'stripe.customer_sync_mode', $mode);
            }
        }

        data_set($metadata, 'billing_guarded_actions.stripe_customer_sync', [
            'status' => strtolower(trim($status)) ?: 'unknown',
            'message' => trim($message),
            'mode' => $mode,
            'customer_reference' => $customerReference,
            'attempted_at' => now()->toIso8601String(),
            'synced_at' => strtolower(trim($status)) === 'succeeded' ? now()->toIso8601String() : null,
            'actor_user_id' => $actorId,
        ]);

        TenantCommercialOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'billing_mapping' => $billingMapping,
                'metadata' => $metadata,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $commercialProfile
     * @param  array<string,mixed>  $candidate
     */
    protected function persistStripeSubscriptionPrepResult(
        int $tenantId,
        array $commercialProfile,
        string $status,
        string $message,
        ?string $mode,
        array $candidate,
        ?string $candidateHash,
        ?int $actorId
    ): void {
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];

        if ($candidate !== []) {
            data_set($billingMapping, 'stripe.subscription_prep_candidate', $candidate);
            data_set($billingMapping, 'stripe.subscription_prep_hash', $candidateHash);
            data_set($billingMapping, 'stripe.subscription_prep_synced_at', now()->toIso8601String());
            if ($mode !== null) {
                data_set($billingMapping, 'stripe.subscription_prep_mode', $mode);
            }
        }

        data_set($metadata, 'billing_guarded_actions.stripe_subscription_prep', [
            'status' => strtolower(trim($status)) ?: 'unknown',
            'message' => trim($message),
            'mode' => $mode,
            'candidate_hash' => $candidateHash,
            'attempted_at' => now()->toIso8601String(),
            'synced_at' => strtolower(trim($status)) === 'succeeded' ? now()->toIso8601String() : null,
            'actor_user_id' => $actorId,
        ]);

        TenantCommercialOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'billing_mapping' => $billingMapping,
                'metadata' => $metadata,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $commercialProfile
     */
    protected function persistStripeLiveSubscriptionSyncResult(
        int $tenantId,
        array $commercialProfile,
        string $status,
        string $message,
        ?string $mode,
        ?string $customerReference,
        ?string $subscriptionReference,
        ?string $subscriptionStatus,
        ?string $prepCandidateHash,
        ?int $actorId
    ): void {
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $metadata = is_array($commercialProfile['metadata'] ?? null)
            ? (array) $commercialProfile['metadata']
            : [];

        if ($customerReference !== null && trim($customerReference) !== '') {
            data_set($billingMapping, 'stripe.customer_reference', trim($customerReference));
        }

        if ($subscriptionReference !== null && trim($subscriptionReference) !== '') {
            data_set($billingMapping, 'stripe.subscription_reference', trim($subscriptionReference));
            data_set($billingMapping, 'stripe.subscription_synced_at', now()->toIso8601String());
            data_set($billingMapping, 'stripe.subscription_status', $subscriptionStatus);
            if ($mode !== null) {
                data_set($billingMapping, 'stripe.subscription_sync_mode', $mode);
            }
        }

        if ($prepCandidateHash !== null && trim($prepCandidateHash) !== '') {
            data_set($billingMapping, 'stripe.subscription_reference_source_prep_hash', trim($prepCandidateHash));
        }

        data_set($metadata, 'billing_guarded_actions.stripe_live_subscription_sync', [
            'status' => strtolower(trim($status)) ?: 'unknown',
            'message' => trim($message),
            'mode' => $mode,
            'customer_reference' => $customerReference,
            'subscription_reference' => $subscriptionReference,
            'subscription_status' => $subscriptionStatus,
            'prep_candidate_hash' => $prepCandidateHash,
            'attempted_at' => now()->toIso8601String(),
            'synced_at' => strtolower(trim($status)) === 'succeeded' ? now()->toIso8601String() : null,
            'actor_user_id' => $actorId,
        ]);

        TenantCommercialOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'billing_mapping' => $billingMapping,
                'metadata' => $metadata,
            ]
        );
    }
}
