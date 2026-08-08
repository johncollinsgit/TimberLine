<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;

/**
 * Resolves the business context that is allowed to shape a tenant workspace.
 *
 * Integrations and module state are intentionally not inputs here. They can
 * describe available data, but must never turn a service company into a
 * retail workspace. Existing tenants without an explicitly recorded profile
 * fail closed to generic/custom until an operator reviews them.
 */
class TenantWorkspaceCapabilityService
{
    /** @var array<string,array<string,mixed>> */
    protected array $cache = [];

    /**
     * @return array{workspace_profile:string,capability_packs:array<int,string>,legacy_overlays:array<int,string>,is_reviewed:bool}
     */
    public function forTenant(?Tenant $tenant): array
    {
        if (! $tenant instanceof Tenant) {
            return $this->generic();
        }

        $cacheKey = (string) $tenant->id;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $slug = strtolower(trim((string) $tenant->slug));

        // These two workspaces are explicitly approved launch contexts. This
        // protects them while every other legacy workspace remains fail-closed
        // until its blueprint is reviewed and saved with a profile.
        if ($slug === 'modern-forestry') {
            return $this->cache[$cacheKey] = [
                'workspace_profile' => 'maker_production',
                'capability_packs' => ['retail_commerce'],
                'legacy_overlays' => ['modern_forestry_legacy'],
                'is_reviewed' => true,
            ];
        }

        if ($slug === 'collins-electric') {
            return $this->cache[$cacheKey] = [
                'workspace_profile' => 'field_service_trades',
                'capability_packs' => ['service_reputation'],
                'legacy_overlays' => [],
                'is_reviewed' => true,
            ];
        }

        $blueprint = is_array(data_get($tenant->accessProfile?->metadata, 'tenant_blueprint'))
            ? (array) data_get($tenant->accessProfile?->metadata, 'tenant_blueprint')
            : [];
        $allowedProfiles = array_keys((array) config('tenant_blueprints.workspace_profiles', []));
        $profile = strtolower(trim((string) ($blueprint['workspace_profile'] ?? '')));
        $reviewStatus = strtolower(trim((string) ($blueprint['blueprint_review_status'] ?? 'unreviewed')));

        // A selection captured during onboarding is useful context, but it is
        // not an entitlement. An operator must confirm it before any vertical
        // or commerce capability is allowed to shape the workspace.
        if (! in_array($profile, $allowedProfiles, true) || $reviewStatus !== 'reviewed') {
            return $this->cache[$cacheKey] = $this->generic();
        }

        $packs = collect((array) ($blueprint['capability_packs'] ?? []))
            ->map(fn (mixed $pack): string => strtolower(trim((string) $pack)))
            ->filter(fn (string $pack): bool => in_array($pack, ['retail_commerce', 'service_reputation'], true))
            ->values()
            ->all();

        // Retail is intrinsic only to the retail profile. Makers opt in to it
        // explicitly because many makers use production-only workspaces.
        if ($profile === 'retail_commerce' && ! in_array('retail_commerce', $packs, true)) {
            $packs[] = 'retail_commerce';
        }

        if ($profile === 'field_service_trades' && ! in_array('service_reputation', $packs, true)) {
            $packs[] = 'service_reputation';
        }

        return $this->cache[$cacheKey] = [
            'workspace_profile' => $profile,
            'capability_packs' => array_values(array_unique($packs)),
            'legacy_overlays' => [],
            'is_reviewed' => true,
        ];
    }

    /** @param array<string,mixed> $definition */
    public function supportsDefinition(?Tenant $tenant, array $definition): bool
    {
        $context = $this->forTenant($tenant);
        $profiles = collect((array) ($definition['workspace_profiles'] ?? []))
            ->map(fn (mixed $profile): string => strtolower(trim((string) $profile)))
            ->filter()
            ->values()
            ->all();
        $packs = collect((array) ($definition['required_capability_packs'] ?? []))
            ->map(fn (mixed $pack): string => strtolower(trim((string) $pack)))
            ->filter()
            ->values()
            ->all();
        $overlays = collect((array) ($definition['required_legacy_overlays'] ?? []))
            ->map(fn (mixed $overlay): string => strtolower(trim((string) $overlay)))
            ->filter()
            ->values()
            ->all();

        if ($profiles !== [] && ! in_array($context['workspace_profile'], $profiles, true)) {
            return false;
        }

        foreach ($packs as $pack) {
            if (! in_array($pack, $context['capability_packs'], true)) {
                return false;
            }
        }

        foreach ($overlays as $overlay) {
            if (! in_array($overlay, $context['legacy_overlays'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{workspace_profile:string,capability_packs:array<int,string>,legacy_overlays:array<int,string>,is_reviewed:bool} */
    protected function generic(): array
    {
        return [
            'workspace_profile' => 'generic_custom',
            'capability_packs' => [],
            'legacy_overlays' => [],
            'is_reviewed' => false,
        ];
    }
}
