<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingBlueprintProvisioning;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionTenantProvisioningService
{
    public const PROVISIONING_POLICY = 'one_production_tenant_per_final_blueprint';

    public function __construct(
        protected OnboardingBlueprintService $blueprintService,
        protected LandlordCommercialConfigService $commercialConfigService
    ) {
    }

    /**
     * Provision a fresh production tenant from a finalized onboarding blueprint snapshot.
     *
     * Explicit rules:
     * - Input must be a FINAL blueprint snapshot (not a draft).
     * - This creates a NEW tenant; it never converts the source (demo) tenant in-place.
     * - This does NOT copy operational/demo data (customers/orders/messages/etc).
     * - Repeated consumption policy: one production tenant per final blueprint by default.
     *
     * @return array{
     *   source_blueprint:array<string,mixed>,
     *   provisioned_tenant:array<string,mixed>,
     *   applied:array<string,mixed>,
     *   not_copied:array<string,mixed>,
     *   lineage:array<string,mixed>
     * }
     */
    public function provisionFromFinalBlueprint(TenantOnboardingBlueprint $finalBlueprint, User $actor): array
    {
        if ((string) ($finalBlueprint->status ?? '') !== 'final') {
            throw ValidationException::withMessages([
                'blueprint' => ['Only finalized blueprints may be consumed to provision a production tenant.'],
            ]);
        }

        $existing = TenantOnboardingBlueprintProvisioning::query()
            ->where('source_blueprint_id', (int) $finalBlueprint->id)
            ->first();

        if ($existing instanceof TenantOnboardingBlueprintProvisioning) {
            throw ValidationException::withMessages([
                'blueprint' => ['This finalized blueprint has already been consumed to provision a production tenant.'],
            ]);
        }

        $payload = is_array($finalBlueprint->payload ?? null) ? (array) $finalBlueprint->payload : [];
        $validated = $this->blueprintService->validateFinal([
            ...$payload,
            'account_mode' => (string) ($payload['account_mode'] ?? $finalBlueprint->account_mode ?? 'demo'),
        ]);

        if ((string) ($validated['tenant_creation_policy'] ?? '') !== 'create_fresh_production_tenant') {
            throw ValidationException::withMessages([
                'blueprint' => ['This blueprint is not eligible for fresh production tenant provisioning.'],
            ]);
        }

        $sourceTenantId = (int) ($finalBlueprint->tenant_id ?? 0);
        if ($sourceTenantId <= 0) {
            throw ValidationException::withMessages([
                'blueprint' => ['Blueprint is missing a source tenant id.'],
            ]);
        }

        $rail = strtolower(trim((string) ($validated['rail'] ?? 'direct')));
        $operatingMode = $rail === 'shopify' ? 'shopify' : 'direct';

        $templateKey = trim((string) ($validated['template_key'] ?? ''));
        $displayTemplate = $templateKey !== ''
            ? Str::title(str_replace(['_', '-'], ' ', $templateKey))
            : 'New';

        $tenantName = $displayTemplate.' Workspace';
        $tenantSlug = $this->uniqueTenantSlug($tenantName);

        $provisionedTenant = null;
        $provisioning = null;

        DB::transaction(function () use (
            $tenantName,
            $tenantSlug,
            $operatingMode,
            $validated,
            $finalBlueprint,
            $sourceTenantId,
            $actor,
            &$provisionedTenant,
            &$provisioning
        ): void {
            $tenant = Tenant::query()->create([
                'name' => $tenantName,
                'slug' => $tenantSlug,
            ]);

            $tenantId = (int) $tenant->id;

            $profile = $this->commercialConfigService->assignTenantPlan(
                tenantId: $tenantId,
                planKey: (string) config('entitlements.default_plan', 'starter'),
                operatingMode: $operatingMode,
                source: 'onboarding_blueprint_provisioning',
                actorId: (int) $actor->id
            );

            $metadata = is_array($profile->metadata ?? null) ? (array) $profile->metadata : [];
            $metadata['account_mode'] = 'production';
            $metadata['onboarding'] = array_replace(
                is_array($metadata['onboarding'] ?? null) ? (array) $metadata['onboarding'] : [],
                [
                    'template_key' => $validated['template_key'] ?? null,
                    'selected_modules' => is_array($validated['selected_modules'] ?? null) ? array_values((array) $validated['selected_modules']) : [],
                    'data_source' => $validated['data_source'] ?? null,
                    'desired_outcome_first' => $validated['desired_outcome_first'] ?? null,
                    'setup_preferences' => is_array($validated['setup_preferences'] ?? null) ? (array) $validated['setup_preferences'] : [],
                    'mobile_intent' => is_array($validated['mobile_intent'] ?? null) ? (array) $validated['mobile_intent'] : [],
                    'provisioned_from' => [
                        'policy' => self::PROVISIONING_POLICY,
                        'source_blueprint_id' => (int) $finalBlueprint->id,
                        'source_tenant_id' => $sourceTenantId,
                        'finalized_at' => $finalBlueprint->created_at?->toIso8601String(),
                        'no_demo_data_migrated' => true,
                    ],
                ]
            );

            $profile->metadata = $metadata;
            $profile->save();

            $tenant->users()->syncWithoutDetaching([
                (int) $actor->id => [
                    'role' => 'admin',
                ],
            ]);

            $templateKey = trim((string) ($validated['template_key'] ?? ''));
            if ($templateKey !== '') {
                $this->commercialConfigService->updateTenantCommercialOverride($tenantId, [
                    'template_key' => $templateKey,
                    'metadata' => [
                        'source' => 'onboarding_blueprint_provisioning',
                        'source_blueprint_id' => (int) $finalBlueprint->id,
                        'source_tenant_id' => $sourceTenantId,
                        'no_demo_data_migrated' => true,
                    ],
                ], (int) $actor->id);
            }

            $provisioning = TenantOnboardingBlueprintProvisioning::query()->create([
                'tenant_id' => $sourceTenantId,
                'source_blueprint_id' => (int) $finalBlueprint->id,
                'provisioned_tenant_id' => $tenantId,
                'created_by_user_id' => (int) $actor->id,
                'status' => 'completed',
                'metadata' => [
                    'policy' => self::PROVISIONING_POLICY,
                    'source' => 'onboarding_blueprint_provisioning',
                    'no_demo_data_migrated' => true,
                    'applied' => [
                        'operating_mode' => $operatingMode,
                        'template_key' => $templateKey !== '' ? $templateKey : null,
                        'selected_modules' => is_array($validated['selected_modules'] ?? null) ? array_values((array) $validated['selected_modules']) : [],
                        'data_source' => $validated['data_source'] ?? null,
                    ],
                ],
            ]);

            $provisionedTenant = $tenant;
        });

        if (! $provisionedTenant instanceof Tenant || ! $provisioning instanceof TenantOnboardingBlueprintProvisioning) {
            throw ValidationException::withMessages([
                'blueprint' => ['Provisioning did not complete.'],
            ]);
        }

        return [
            'source_blueprint' => [
                'id' => (int) $finalBlueprint->id,
                'tenant_id' => (int) $finalBlueprint->tenant_id,
                'status' => (string) ($finalBlueprint->status ?? ''),
                'account_mode' => (string) ($finalBlueprint->account_mode ?? ''),
                'rail' => (string) ($finalBlueprint->rail ?? ''),
                'finalized_at' => $finalBlueprint->created_at?->toIso8601String(),
                'payload' => $validated,
            ],
            'provisioned_tenant' => [
                'id' => (int) $provisionedTenant->id,
                'name' => (string) $provisionedTenant->name,
                'slug' => (string) $provisionedTenant->slug,
                'account_mode' => 'production',
                'operating_mode' => $operatingMode,
            ],
            'applied' => [
                'template_key' => $validated['template_key'] ?? null,
                'selected_modules' => is_array($validated['selected_modules'] ?? null) ? array_values((array) $validated['selected_modules']) : [],
                'data_source' => $validated['data_source'] ?? null,
                'desired_outcome_first' => $validated['desired_outcome_first'] ?? null,
                'setup_preferences' => is_array($validated['setup_preferences'] ?? null) ? (array) $validated['setup_preferences'] : [],
                'mobile_intent' => is_array($validated['mobile_intent'] ?? null) ? (array) $validated['mobile_intent'] : [],
                'tenant_creation_policy' => (string) ($validated['tenant_creation_policy'] ?? ''),
            ],
            'not_copied' => [
                'operational_demo_data' => true,
                'note' => 'Only blueprint configuration is applied; no seeded demo records are migrated.',
            ],
            'lineage' => [
                'policy' => self::PROVISIONING_POLICY,
                'provisioning_id' => (int) $provisioning->id,
                'source_blueprint_id' => (int) $finalBlueprint->id,
                'source_tenant_id' => (int) $finalBlueprint->tenant_id,
                'provisioned_tenant_id' => (int) $provisionedTenant->id,
                'no_demo_data_migrated' => true,
            ],
        ];
    }

    protected function uniqueTenantSlug(string $base, ?int $ignoreTenantId = null): string
    {
        $slugBase = Str::slug($base);
        if ($slugBase === '') {
            $slugBase = 'tenant';
        }

        $candidate = $slugBase;
        $counter = 2;

        while (Tenant::query()
            ->when($ignoreTenantId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreTenantId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $slugBase.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }
}
