<?php

namespace App\Services\Discovery;

class BrandDiscoveryGraphService
{
    public function __construct(
        protected TenantDiscoveryProfileService $discoveryProfileService,
        protected DomainCanonicalResolver $domainCanonicalResolver,
        protected DiscoveryStructuredDataService $structuredDataService,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function buildForTenant(?int $tenantId, array $context = []): array
    {
        $profile = $this->discoveryProfileService->resolveForTenant($tenantId);
        $brand = (array) ($profile['brand_identity'] ?? []);
        $domainMap = (array) ($profile['domain_relationships'] ?? []);
        $audience = (array) ($profile['audience'] ?? []);
        $geography = (array) ($profile['geography'] ?? []);
        $trust = (array) ($profile['trust'] ?? []);
        $merchantSignals = (array) ($profile['merchant_signals'] ?? []);
        $pages = is_array($profile['discovery_pages'] ?? null) ? (array) $profile['discovery_pages'] : [];

        $pageCanonicalMap = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageKey = $this->nullableString($page['page_key'] ?? null);
            if ($pageKey === null) {
                continue;
            }

            $pageCanonicalMap[$pageKey] = $this->domainCanonicalResolver->resolveForDiscoveryPage($tenantId, $pageKey);
        }

        $organization = [
            'name' => $brand['primary_brand_name'] ?? null,
            'alternate_names' => $this->stringList($brand['alternate_brand_names'] ?? []),
            'summary' => $brand['short_summary'] ?? null,
            'description' => $brand['long_description'] ?? null,
            'contact' => $brand['contact'] ?? [],
            'logo_url' => $brand['logo_url'] ?? null,
            'social_profiles' => $this->stringList($brand['social_profiles'] ?? []),
            'keywords' => $this->stringList($brand['brand_keywords'] ?? []),
            'why_choose_us' => $this->stringList($brand['why_choose_us'] ?? []),
        ];

        $websiteEntities = $this->websiteEntities($domainMap, $organization);
        $faqRefs = $this->faqRefs($pages, $pageCanonicalMap);
        $entrypoints = $this->entrypoints($pages, $pageCanonicalMap);
        $audiencePaths = $this->audiencePaths($audience, $pageCanonicalMap);
        $serviceRegions = $this->serviceRegions($geography);
        $merchantPolicies = $this->merchantPolicies($trust, $geography);
        $trustSignals = $this->trustSignals($trust, $merchantSignals);
        $structuredData = $this->structuredDataService->contractsForTenant($tenantId, $context);
        $catalogEntrypoints = $this->catalogEntrypoints($entrypoints, $audiencePaths);

        return [
            'generated_at' => now()->toIso8601String(),
            'tenant' => $profile['tenant'] ?? [],
            'organization' => $organization,
            'websites' => $websiteEntities,
            'domains' => $this->domainEntities($domainMap),
            'domain_relationships' => $domainMap['relationships'] ?? [],
            'audience_paths' => $audiencePaths,
            'service_regions' => $serviceRegions,
            'merchant_policies' => $merchantPolicies,
            'trust_signals' => $trustSignals,
            'faq_refs' => $faqRefs,
            'product_catalog_entrypoints' => $catalogEntrypoints,
            'wholesale_entrypoints' => $entrypoints['wholesale'],
            'retail_entrypoints' => $entrypoints['retail'],
            'notable_entities' => [
                'primary_brand' => $organization['name'] ?? null,
                'alternate_names' => $organization['alternate_names'] ?? [],
                'handles' => $organization['social_profiles'] ?? [],
                'shopify_admin_identity' => $domainMap['shopify_admin_domain'] ?? null,
            ],
            'merchant_signals' => $merchantSignals,
            'canonical_map' => $pageCanonicalMap,
            'structured_data_contracts' => $structuredData,
            'well_known_documents' => [
                'brand_discovery' => '/.well-known/brand-discovery.json',
                'structured_data' => '/api/public/discovery/structured',
                'sitemap' => '/sitemaps/discovery.xml',
            ],
            'placeholders' => $profile['placeholders'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $domainMap
     * @param array<string,mixed> $organization
     * @return array<int,array<string,mixed>>
     */
    protected function websiteEntities(array $domainMap, array $organization): array
    {
        $relationships = is_array($domainMap['relationships'] ?? null) ? (array) $domainMap['relationships'] : [];
        $rows = [];

        foreach ($relationships as $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $domain = $this->nullableString($relationship['domain'] ?? null);
            $role = $this->nullableString($relationship['role'] ?? null);
            if ($domain === null || $role === null) {
                continue;
            }

            $rows[] = [
                'domain' => $domain,
                'role' => $role,
                'visibility' => $relationship['visibility'] ?? 'public_facing',
                'audiences' => $this->stringList($relationship['audiences'] ?? []),
                'purpose' => $this->stringList($relationship['purpose'] ?? []),
                'organization' => $organization['name'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,array<string,mixed>> $pageCanonicalMap
     * @return array<int,array<string,mixed>>
     */
    protected function faqRefs(array $pages, array $pageCanonicalMap): array
    {
        $rows = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $faqItems = is_array($page['faq_items'] ?? null) ? (array) $page['faq_items'] : [];
            if ($faqItems === []) {
                continue;
            }

            $pageKey = $this->nullableString($page['page_key'] ?? null);
            if ($pageKey === null) {
                continue;
            }

            $rows[] = [
                'page_key' => $pageKey,
                'title' => $page['title'] ?? null,
                'url' => data_get($pageCanonicalMap, $pageKey.'.canonical_url'),
                'faq_count' => count($faqItems),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,array<string,mixed>> $pageCanonicalMap
     * @return array{retail:array<int,array<string,mixed>>,wholesale:array<int,array<string,mixed>>}
     */
    protected function entrypoints(array $pages, array $pageCanonicalMap): array
    {
        $retail = [];
        $wholesale = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageKey = $this->nullableString($page['page_key'] ?? null);
            if ($pageKey === null) {
                continue;
            }

            $entry = [
                'page_key' => $pageKey,
                'title' => $page['title'] ?? null,
                'summary' => $page['summary'] ?? null,
                'intent' => $page['intent_label'] ?? null,
                'audience_type' => $page['audience_type'] ?? null,
                'cta_label' => $page['cta_label'] ?? null,
                'cta_url' => $page['cta_url'] ?? null,
                'canonical_url' => data_get($pageCanonicalMap, $pageKey.'.canonical_url'),
                'recommended_domain_role' => $page['recommended_domain_role'] ?? null,
            ];

            if ((string) ($page['recommended_domain_role'] ?? '') === 'wholesale_storefront') {
                $wholesale[] = $entry;
                continue;
            }

            if ((string) ($page['recommended_domain_role'] ?? '') === 'retail_storefront') {
                $retail[] = $entry;
            }
        }

        return [
            'retail' => array_values($retail),
            'wholesale' => array_values($wholesale),
        ];
    }

    /**
     * @param array<string,mixed> $audience
     * @param array<string,array<string,mixed>> $pageCanonicalMap
     * @return array<string,array<string,mixed>>
     */
    protected function audiencePaths(array $audience, array $pageCanonicalMap): array
    {
        $paths = is_array($audience['paths'] ?? null) ? (array) $audience['paths'] : [];
        $resolved = [];

        foreach ($paths as $audienceType => $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->nullableString($audienceType);
            if ($key === null) {
                continue;
            }

            $resolved[$key] = [
                'summary' => $row['summary'] ?? null,
                'cta_label' => $row['cta_label'] ?? null,
                'recommended_domain_role' => $row['recommended_domain_role'] ?? null,
                'recommended_path' => $row['recommended_path'] ?? null,
                'cta_url' => $row['cta_url'] ?? null,
                'canonical_hint' => $this->canonicalHintForAudience($key, $pageCanonicalMap),
            ];
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $geography
     * @return array<string,mixed>
     */
    protected function serviceRegions(array $geography): array
    {
        return [
            'primary_state' => $geography['primary_state'] ?? null,
            'primary_region_code' => $geography['primary_region_code'] ?? null,
            'primary_city' => $geography['primary_city'] ?? null,
            'domestic_shipping_available' => $geography['domestic_shipping_available'] ?? null,
            'international_shipping_available' => $geography['international_shipping_available'] ?? null,
            'international_policy_mode' => $geography['international_policy_mode'] ?? null,
            'wholesale_service_regions' => $this->stringList($geography['wholesale_service_regions'] ?? []),
            'contact_before_ordering_regions' => $this->stringList($geography['contact_before_ordering_regions'] ?? []),
            'restrictions' => $this->stringList($geography['restrictions'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $trust
     * @param array<string,mixed> $geography
     * @return array<string,mixed>
     */
    protected function merchantPolicies(array $trust, array $geography): array
    {
        return [
            'return_policy_url' => $trust['return_policy_url'] ?? null,
            'shipping_policy_url' => $trust['shipping_policy_url'] ?? null,
            'faq_url' => $trust['faq_url'] ?? null,
            'domestic_shipping_available' => $geography['domestic_shipping_available'] ?? null,
            'international_shipping_available' => $geography['international_shipping_available'] ?? null,
            'international_policy_mode' => $geography['international_policy_mode'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $trust
     * @param array<string,mixed> $merchantSignals
     * @return array<string,mixed>
     */
    protected function trustSignals(array $trust, array $merchantSignals): array
    {
        return [
            'review_source_strategy' => $trust['review_source_strategy'] ?? null,
            'aggregate_review' => $trust['aggregate_review'] ?? [],
            'review_aggregate_settings_available' => (bool) ($trust['review_aggregate_settings_available'] ?? false),
            'merchant_contact_facts' => $trust['merchant_contact_facts'] ?? [],
            'lead_time_notes' => $merchantSignals['lead_time_notes'] ?? null,
        ];
    }

    /**
     * @param array{retail:array<int,array<string,mixed>>,wholesale:array<int,array<string,mixed>>} $entrypoints
     * @param array<string,array<string,mixed>> $audiencePaths
     * @return array<string,mixed>
     */
    protected function catalogEntrypoints(array $entrypoints, array $audiencePaths): array
    {
        return [
            'retail' => $entrypoints['retail'] ?? [],
            'wholesale' => $entrypoints['wholesale'] ?? [],
            'audience_routes' => $audiencePaths,
        ];
    }

    /**
     * @param array<string,mixed> $domainMap
     * @return array<int,array<string,mixed>>
     */
    protected function domainEntities(array $domainMap): array
    {
        $relationships = is_array($domainMap['relationships'] ?? null) ? (array) $domainMap['relationships'] : [];
        $rows = [];

        foreach ($relationships as $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $domain = $this->nullableString($relationship['domain'] ?? null);
            if ($domain === null) {
                continue;
            }

            $rows[] = [
                'domain' => $domain,
                'role' => $relationship['role'] ?? null,
                'visibility' => $relationship['visibility'] ?? null,
                'audiences' => $this->stringList($relationship['audiences'] ?? []),
                'purpose' => $this->stringList($relationship['purpose'] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,array<string,mixed>> $pageCanonicalMap
     * @return array<string,mixed>|null
     */
    protected function canonicalHintForAudience(string $audienceType, array $pageCanonicalMap): ?array
    {
        $match = collect($pageCanonicalMap)
            ->first(function (array $canonical, string $pageKey) use ($audienceType): bool {
                if ($audienceType === 'retail_customer') {
                    return str_contains($pageKey, 'retail');
                }

                if (in_array($audienceType, ['wholesale_buyer', 'stockist_retailer', 'event_hospitality_gifting', 'interior_design_corporate_buyer'], true)) {
                    return str_contains($pageKey, 'wholesale') || str_contains($pageKey, 'stockist');
                }

                return false;
            });

        return is_array($match) ? [
            'target_domain' => $match['target_domain'] ?? null,
            'canonical_url' => $match['canonical_url'] ?? null,
            'target_role' => $match['target_role'] ?? null,
        ] : null;
    }

    /**
     * @return array<int,string>
     */
    protected function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->nullableString($item);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
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
