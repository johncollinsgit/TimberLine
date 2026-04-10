<?php

namespace App\Services\Discovery;

class DomainCanonicalResolver
{
    public function __construct(
        protected TenantDiscoveryProfileService $discoveryProfileService,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function resolveForPage(?int $tenantId, string $pageType, array $context = []): array
    {
        $profile = $this->discoveryProfileService->resolveForTenant($tenantId);
        $domainMap = (array) ($profile['domain_relationships'] ?? []);
        $pages = (array) ($profile['discovery_pages'] ?? []);
        $pageType = $this->normalizeKey($pageType);

        $pageKey = $this->normalizeKey($context['page_key'] ?? null);
        $pageMetadata = $pageKey !== null
            ? collect($pages)->first(fn (array $row): bool => $this->normalizeKey($row['page_key'] ?? null) === $pageKey)
            : null;

        $recommendedRole = $this->normalizeKey(data_get($pageMetadata, 'recommended_domain_role'));
        $canonicalRules = is_array($domainMap['canonical_preference_rules'] ?? null)
            ? (array) $domainMap['canonical_preference_rules']
            : [];

        $ruleKey = $this->ruleKeyForPageType($pageType, $recommendedRole);
        $targetRole = $recommendedRole
            ?? $this->normalizeKey($canonicalRules[$ruleKey] ?? null)
            ?? $this->fallbackRoleForPageType($pageType);

        $resolvedDomain = $this->domainForRole($domainMap, $targetRole);
        $path = $this->resolvedPath($context, is_array($pageMetadata) ? $pageMetadata : null);
        $indexable = $targetRole !== 'admin_only' && (bool) ($context['indexable'] ?? data_get($pageMetadata, 'is_indexable', true));
        $canonicalUrl = $indexable ? $this->canonicalUrl($resolvedDomain, $path) : null;

        return [
            'tenant_id' => $tenantId,
            'page_type' => $pageType,
            'page_key' => $pageKey,
            'rule_key' => $ruleKey,
            'target_role' => $targetRole,
            'target_domain' => $resolvedDomain,
            'canonical_path' => $path,
            'canonical_url' => $canonicalUrl,
            'indexable' => $indexable,
            'policy' => [
                'cross_domain_linking_allowed' => true,
                'canonical_cannibalization_protection' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveForDiscoveryPage(?int $tenantId, string $pageKey): array
    {
        return $this->resolveForPage($tenantId, 'discovery_page', [
            'page_key' => $pageKey,
        ]);
    }

    protected function ruleKeyForPageType(string $pageType, ?string $recommendedRole): string
    {
        if ($recommendedRole === 'wholesale_storefront') {
            if (str_contains($pageType, 'faq')) {
                return 'wholesale_faq';
            }

            if (str_contains($pageType, 'policy') || str_contains($pageType, 'international')) {
                return 'wholesale_policy';
            }

            return 'wholesale_landing';
        }

        if ($recommendedRole === 'retail_storefront') {
            if (str_contains($pageType, 'faq')) {
                return 'retail_faq';
            }

            if (str_contains($pageType, 'policy')) {
                return 'retail_policy';
            }

            return 'retail_collection';
        }

        if ($recommendedRole === 'brand_story_site') {
            return 'brand_page';
        }

        if ($recommendedRole === 'admin_only') {
            return 'admin_only';
        }

        return match ($pageType) {
            'homepage', 'home', 'retail_home' => 'homepage',
            'retail_collection', 'collection', 'retail_category' => 'retail_collection',
            'retail_product', 'product' => 'retail_product',
            'retail_policy', 'policy_page' => 'retail_policy',
            'retail_faq', 'faq_page' => 'retail_faq',
            'wholesale_home', 'wholesale_landing', 'regional_wholesale', 'stockist_hospitality_gifting' => 'wholesale_landing',
            'wholesale_collection' => 'wholesale_collection',
            'wholesale_product' => 'wholesale_product',
            'wholesale_policy', 'international_wholesale_info' => 'wholesale_policy',
            'wholesale_faq' => 'wholesale_faq',
            'brand_value_page', 'brand_page' => 'brand_page',
            'admin_only' => 'admin_only',
            default => 'brand_page',
        };
    }

    protected function fallbackRoleForPageType(string $pageType): string
    {
        if (str_contains($pageType, 'wholesale')) {
            return 'wholesale_storefront';
        }

        if (str_contains($pageType, 'retail')) {
            return 'retail_storefront';
        }

        if (str_contains($pageType, 'admin')) {
            return 'admin_only';
        }

        return 'brand_story_site';
    }

    /**
     * @param array<string,mixed> $domainMap
     */
    protected function domainForRole(array $domainMap, string $role): ?string
    {
        $relationships = is_array($domainMap['relationships'] ?? null) ? (array) $domainMap['relationships'] : [];

        foreach ($relationships as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->normalizeKey($row['role'] ?? null) === $role) {
                $domain = $this->normalizeHost($row['domain'] ?? null);
                if ($domain !== null) {
                    return $domain;
                }
            }
        }

        return match ($role) {
            'retail_storefront' => $this->normalizeHost($domainMap['primary_retail_domain'] ?? null),
            'wholesale_storefront' => $this->normalizeHost($domainMap['primary_wholesale_domain'] ?? null),
            'admin_only' => $this->normalizeHost($domainMap['shopify_admin_domain'] ?? null),
            default => $this->normalizeHost($domainMap['primary_retail_domain'] ?? null)
                ?? $this->normalizeHost($domainMap['primary_wholesale_domain'] ?? null),
        };
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $pageMetadata
     */
    protected function resolvedPath(array $context, ?array $pageMetadata): string
    {
        $explicitUrl = $this->nullableString($context['explicit_url'] ?? null);
        if ($explicitUrl !== null && (str_starts_with($explicitUrl, 'http://') || str_starts_with($explicitUrl, 'https://'))) {
            $parsed = parse_url($explicitUrl, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                return $this->normalizePath($parsed);
            }
        }

        $candidatePath = $this->nullableString($context['path'] ?? null)
            ?? $this->nullableString($pageMetadata['canonical_path'] ?? null)
            ?? '/';

        return $this->normalizePath($candidatePath);
    }

    protected function canonicalUrl(?string $domain, string $path): ?string
    {
        if ($domain === null) {
            return null;
        }

        return 'https://'.$domain.$path;
    }

    protected function normalizePath(string $path): string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return '/';
        }

        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        return $normalized;
    }

    protected function normalizeHost(mixed $value): ?string
    {
        $host = $this->nullableString($value);
        if ($host === null) {
            return null;
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : null;
        }

        if ($host === null) {
            return null;
        }

        return strtolower(trim($host, '/'));
    }

    protected function normalizeKey(mixed $value): ?string
    {
        $normalized = $this->nullableString($value);

        return $normalized !== null ? strtolower(trim($normalized)) : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
