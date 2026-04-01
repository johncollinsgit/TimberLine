<?php

namespace App\Services\Search\Providers;

use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use App\Services\Tenancy\TenantModuleCatalogService;

class ModulesSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function __construct(
        protected TenantModuleCatalogService $moduleCatalogService
    ) {
    }

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        $surface = (string) ($context['surface'] ?? 'marketing');
        if ($surface !== 'shopify' && (! $user || ! method_exists($user, 'canAccessMarketing') || ! $user->canAccessMarketing())) {
            return [];
        }

        $payload = $tenantId !== null
            ? $this->moduleCatalogService->tenantStorePayload($tenantId, $surface === 'shopify' ? 'shopify' : 'marketing')
            : ['modules' => []];

        $normalized = trim($query);

        return collect((array) ($payload['modules'] ?? []))
            ->filter(function (array $module) use ($normalized): bool {
                if ($normalized === '') {
                    return true;
                }

                return $this->matchScore($normalized, [
                    (string) ($module['display_name'] ?? ''),
                    (string) ($module['description'] ?? ''),
                ]) > 0;
            })
            ->take(5)
            ->map(function (array $module) use ($normalized, $surface): array {
                $moduleKey = (string) ($module['module_key'] ?? '');
                return $this->result([
                    'type' => 'module',
                    'subtype' => 'catalog',
                    'title' => (string) ($module['display_name'] ?? $moduleKey),
                    'subtitle' => (string) data_get($module, 'module_state.reason_description', $module['description'] ?? ''),
                    'url' => $surface === 'shopify'
                        ? route('shopify.app.store', ['module' => $moduleKey], false)
                        : route('marketing.modules', ['module' => $moduleKey]),
                    'badge' => (string) data_get($module, 'module_state.state_label', 'Module'),
                    'score' => $this->matchScore($normalized, [
                        (string) ($module['display_name'] ?? ''),
                        (string) ($module['description'] ?? ''),
                    ], 240),
                    'icon' => 'squares-plus',
                    'meta' => [
                        'module_key' => $moduleKey,
                        'cta' => (string) data_get($module, 'module_state.cta', 'none'),
                    ],
                ]);
            })
            ->all();
    }
}
