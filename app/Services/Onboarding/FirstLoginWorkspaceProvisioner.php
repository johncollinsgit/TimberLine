<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates a brand-new self-serve workspace (tenant) for a first-login user and
 * personalizes it from their onboarding answers.
 *
 * Doctrine: the module picks and blueprint are INTENT/RECOMMENDATION ONLY. This
 * provisioner records the picks as `setup_status.module_interests` and never
 * enables modules, grants entitlements, runs imports, or starts billing. The
 * workspace is created on the free `base` plan (whatever it enables by default).
 */
class FirstLoginWorkspaceProvisioner
{
    public function __construct(
        private readonly TenantBlueprintProfileService $blueprintService,
        private readonly LandlordCommercialConfigService $commercialService,
        private readonly TenantSetupStatusService $setupStatusService,
        private readonly TenantOnboardingBlueprintStore $blueprintStore,
        private readonly TenantModuleCatalogService $moduleCatalogService,
    ) {}

    /**
     * @param  array<int,string>  $selectedModuleKeys  module picks, recorded as interests only
     * @param  array<string,mixed>  $guideAnswers
     * @return array{tenant_id:int,tenant_slug:string}
     */
    public function provision(
        User $user,
        string $workspaceName,
        string $templateKey,
        array $selectedModuleKeys,
        array $guideAnswers
    ): array {
        $tenantId = null;
        $tenantSlug = null;

        DB::transaction(function () use (
            $user,
            $workspaceName,
            $templateKey,
            $selectedModuleKeys,
            $guideAnswers,
            &$tenantId,
            &$tenantSlug
        ): void {
            if (! in_array((string) $user->role, ['admin', 'manager', 'marketing_manager', 'platform_admin'], true)) {
                $user->forceFill([
                    'role' => 'admin',
                    'is_active' => true,
                    'approved_at' => $user->approved_at ?? now(),
                ])->save();
            }

            if (Schema::hasColumn('users', 'onboarding_guide_answers')) {
                $user->forceFill(['onboarding_guide_answers' => $guideAnswers])->save();
            }

            $tenant = Tenant::query()->create([
                'name' => $workspaceName,
                'slug' => $this->uniqueTenantSlug($workspaceName),
            ]);
            $tenantId = (int) $tenant->id;
            $tenantSlug = (string) $tenant->slug;

            $tenant->users()->syncWithoutDetaching([
                (int) $user->id => [
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $profile = $this->commercialService->assignTenantPlan(
                tenantId: $tenantId,
                planKey: 'base',
                operatingMode: 'direct',
                source: 'self_serve_first_login',
                actorId: (int) $user->id
            );

            $normalizedTemplate = $this->blueprintService->blueprintFromInput([
                'business_template' => $templateKey,
            ])['business_template'] ?? $templateKey;

            $blueprint = $this->blueprintService->blueprintFromInput([
                'business_template' => $normalizedTemplate,
                'operating_mode' => 'direct',
                'data_source_preference' => 'manual',
            ]);
            $blueprint['blueprint_review_status'] = 'reviewed';
            $blueprint['blueprint_review_status_label'] = 'Reviewed';
            $blueprint['blueprint_reviewed_by'] = (int) $user->id;
            $blueprint['blueprint_reviewed_at'] = now()->toIso8601String();

            $setupStatus = $this->setupStatusService->forTenant($tenant);
            $this->blueprintService->applyBlueprint($tenant, $profile->refresh(), $setupStatus, $blueprint, 'production', true);

            // Module picks are INTENT ONLY — recorded as interests, never enabled.
            if ($selectedModuleKeys !== []) {
                $setupStatus->refresh();
                $setupStatus->module_interests = array_values(array_unique($selectedModuleKeys));
                $setupStatus->save();
            }

            $finalPayload = $this->finalBlueprintPayload($tenant, $normalizedTemplate, $blueprint, $guideAnswers, $selectedModuleKeys);
            $this->blueprintStore->finalize($tenantId, $finalPayload, (int) $user->id, [
                'source' => 'self_serve_first_login',
            ]);
        });

        if (! is_int($tenantId) || $tenantId <= 0 || ! is_string($tenantSlug)) {
            throw new RuntimeException('Workspace creation did not complete.');
        }

        return ['tenant_id' => $tenantId, 'tenant_slug' => $tenantSlug];
    }

    public function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'workspace';
        $slug = $base;
        $index = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $guideAnswers
     * @param  array<int,string>  $selectedModuleKeys
     * @return array<string,mixed>
     */
    protected function finalBlueprintPayload(Tenant $tenant, string $templateKey, array $blueprint, array $guideAnswers, array $selectedModuleKeys): array
    {
        $selectedModules = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($blueprint['starter_modules'] ?? [])
        ))));
        $selectedModules = array_values(array_unique(array_merge($selectedModules, $selectedModuleKeys)));
        $selectedModules = array_values(array_intersect($selectedModules, [
            'customers', 'field_service', 'reporting', 'messaging', 'lead_capture',
            'email', 'sms', 'integrations', 'uploads', 'shopify', 'quickbooks',
            'square', 'workflow_automations', 'ai', 'notifications', 'campaigns',
            'settings', 'mobile_connection',
        ]));
        $selectedModules = array_values(array_intersect($selectedModules, $this->safePublicModuleKeys()));

        return [
            'rail' => 'direct',
            'account_mode' => 'production',
            'template_key' => $templateKey,
            'desired_outcome_first' => (string) ($blueprint['primary_outcome'] ?? 'Launch the workspace'),
            'selected_modules' => $selectedModules,
            'data_source' => 'manual',
            'setup_preferences' => [
                'label_overrides' => [],
                'client_brand' => [
                    'display_name' => (string) $tenant->name,
                    'logo_alt' => (string) $tenant->name,
                ],
            ],
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['field_staff'],
                'mobile_jobs_requested' => ['prioritize_work', 'update_production_progress', 'photos_uploads', 'quick_notes'],
                'mobile_priority' => 'high',
            ],
            'first_login_guide' => $guideAnswers,
        ];
    }

    /**
     * Final onboarding blueprints can only reference modules visible on the
     * safe public catalog. Roadmap connector requests remain in the user's guide
     * answers and setup interests for concierge follow-up.
     *
     * @return array<int,string>
     */
    protected function safePublicModuleKeys(): array
    {
        $payload = $this->moduleCatalogService->publicCatalogPayload();

        return collect((array) ($payload['modules'] ?? []))
            ->map(fn (mixed $module): string => is_array($module) ? strtolower(trim((string) ($module['key'] ?? ''))) : '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
