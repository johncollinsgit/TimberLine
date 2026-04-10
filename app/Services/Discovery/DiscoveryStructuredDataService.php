<?php

namespace App\Services\Discovery;

class DiscoveryStructuredDataService
{
    public function __construct(
        protected TenantDiscoveryProfileService $discoveryProfileService,
        protected DomainCanonicalResolver $domainCanonicalResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function contractsForTenant(?int $tenantId, array $context = []): array
    {
        $profile = $this->discoveryProfileService->resolveForTenant($tenantId);
        $brand = (array) ($profile['brand_identity'] ?? []);
        $domains = (array) ($profile['domain_relationships'] ?? []);
        $trust = (array) ($profile['trust'] ?? []);
        $geography = (array) ($profile['geography'] ?? []);
        $pages = (array) ($profile['discovery_pages'] ?? []);

        $organization = $this->organizationEntity($profile);
        $websites = $this->websiteEntities($profile);
        $contactPoint = $this->contactPointEntity($brand);
        $localBusiness = $this->localBusinessEntity($profile, $organization['@id'] ?? null);
        $merchantReturnPolicy = $this->merchantReturnPolicyEntity($trust, $organization['@id'] ?? null);
        $shippingDetails = $this->shippingDetailsEntity($profile, $organization['@id'] ?? null);

        $pageKey = $this->nullableString($context['page_key'] ?? null);
        $page = $pageKey !== null
            ? collect($pages)->first(fn (array $row): bool => (string) ($row['page_key'] ?? '') === $pageKey)
            : null;
        $pageCanonical = $pageKey !== null
            ? $this->domainCanonicalResolver->resolveForDiscoveryPage($tenantId, $pageKey)
            : null;

        $webPageEntity = is_array($page) ? $this->webPageEntity($page, is_array($pageCanonical) ? $pageCanonical : null, $organization['@id'] ?? null) : null;
        $collectionPageEntity = is_array($page) ? $this->collectionPageEntity($page, is_array($pageCanonical) ? $pageCanonical : null, $organization['@id'] ?? null) : null;
        $faqPageEntity = is_array($page) ? $this->faqPageEntity($page, is_array($pageCanonical) ? $pageCanonical : null) : null;
        $breadcrumbEntity = is_array($page) ? $this->breadcrumbEntity($page, is_array($pageCanonical) ? $pageCanonical : null, $domains) : null;

        return [
            'organization' => $organization,
            'websites' => $websites,
            'contact_point' => $contactPoint,
            'local_business' => $localBusiness,
            'merchant_return_policy' => $merchantReturnPolicy,
            'shipping_details' => $shippingDetails,
            'breadcrumb_list' => $breadcrumbEntity,
            'web_page' => $webPageEntity,
            'collection_page' => $collectionPageEntity,
            'faq_page' => $faqPageEntity,
            'member_program' => $this->memberProgramEntity($profile),
            'safety' => [
                'local_business_emitted' => $localBusiness !== null,
                'faq_page_emitted' => $faqPageEntity !== null,
                'faq_requires_real_content' => true,
                'no_fabricated_address' => $localBusiness === null || (bool) data_get($localBusiness, 'address'),
                'schema_contract_version' => 1,
            ],
            'meta' => [
                'brand_name' => $brand['primary_brand_name'] ?? null,
                'primary_state' => $geography['primary_state'] ?? null,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public function jsonLdGraphForTenant(?int $tenantId, array $context = []): array
    {
        $contracts = $this->contractsForTenant($tenantId, $context);
        $graph = [];

        foreach ([
            'organization',
            'contact_point',
            'local_business',
            'merchant_return_policy',
            'shipping_details',
            'breadcrumb_list',
            'web_page',
            'collection_page',
            'faq_page',
            'member_program',
        ] as $key) {
            $entity = $contracts[$key] ?? null;
            if (is_array($entity) && $entity !== []) {
                $graph[] = $entity;
            }
        }

        $websites = is_array($contracts['websites'] ?? null) ? (array) $contracts['websites'] : [];
        foreach ($websites as $website) {
            if (is_array($website) && $website !== []) {
                $graph[] = $website;
            }
        }

        return $graph;
    }

    /**
     * @return array<string,mixed>
     */
    protected function organizationEntity(array $profile): array
    {
        $brand = (array) ($profile['brand_identity'] ?? []);
        $domains = (array) ($profile['domain_relationships'] ?? []);
        $primaryDomain = $this->nullableString($domains['primary_retail_domain'] ?? null)
            ?? $this->nullableString($domains['primary_wholesale_domain'] ?? null)
            ?? 'example.com';

        $organizationId = 'https://'.$primaryDomain.'/#organization';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $brand['primary_brand_name'] ?? null,
            'alternateName' => $this->stringList($brand['alternate_brand_names'] ?? []),
            'description' => $brand['short_summary'] ?? null,
            'email' => data_get($brand, 'contact.email'),
            'telephone' => data_get($brand, 'contact.phone'),
            'logo' => $brand['logo_url'] ?? null,
            'sameAs' => $this->stringList($brand['social_profiles'] ?? []),
            'keywords' => implode(', ', $this->stringList($brand['brand_keywords'] ?? [])),
            'knowsAbout' => $this->stringList($brand['brand_keywords'] ?? []),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function websiteEntities(array $profile): array
    {
        $brand = (array) ($profile['brand_identity'] ?? []);
        $domains = (array) ($profile['domain_relationships'] ?? []);
        $relationships = is_array($domains['relationships'] ?? null) ? (array) $domains['relationships'] : [];
        $entities = [];

        foreach ($relationships as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = $this->nullableString($row['domain'] ?? null);
            $role = $this->nullableString($row['role'] ?? null);
            $visibility = strtolower((string) ($row['visibility'] ?? 'public_facing'));
            if ($domain === null || $role === null || $visibility === 'admin_only') {
                continue;
            }

            $entities[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => 'https://'.$domain.'/#website',
                'url' => 'https://'.$domain.'/',
                'name' => $this->websiteNameForRole($role, $brand),
                'about' => $brand['primary_brand_name'] ?? null,
            ];
        }

        return array_values($entities);
    }

    /**
     * @param array<string,mixed> $brand
     * @return array<string,mixed>|null
     */
    protected function contactPointEntity(array $brand): ?array
    {
        $email = $this->nullableString(data_get($brand, 'contact.email'));
        $phone = $this->nullableString(data_get($brand, 'contact.phone'));
        if ($email === null && $phone === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'email' => $email,
            'telephone' => $phone,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>|null
     */
    protected function localBusinessEntity(array $profile, ?string $organizationId): ?array
    {
        $geography = (array) ($profile['geography'] ?? []);
        $location = is_array($geography['location'] ?? null) ? (array) $geography['location'] : [];

        $street = $this->nullableString($location['street'] ?? null);
        $city = $this->nullableString($location['city'] ?? null);
        $region = $this->nullableString($location['region'] ?? null);
        $postalCode = $this->nullableString($location['postal_code'] ?? null);
        $country = $this->nullableString($location['country'] ?? null);

        // Guardrail: never emit LocalBusiness unless complete real address components are present.
        if ($street === null || $city === null || $region === null || $postalCode === null || $country === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => $organizationId ? $organizationId.'-local-business' : null,
            'name' => data_get($profile, 'brand_identity.primary_brand_name'),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $street,
                'addressLocality' => $city,
                'addressRegion' => $region,
                'postalCode' => $postalCode,
                'addressCountry' => $country,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $trust
     * @return array<string,mixed>|null
     */
    protected function merchantReturnPolicyEntity(array $trust, ?string $organizationId): ?array
    {
        $url = $this->nullableString($trust['return_policy_url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'MerchantReturnPolicy',
            '@id' => $organizationId ? $organizationId.'-return-policy' : null,
            'url' => $url,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>|null
     */
    protected function shippingDetailsEntity(array $profile, ?string $organizationId): ?array
    {
        $geography = (array) ($profile['geography'] ?? []);
        $merchantSignals = (array) ($profile['merchant_signals'] ?? []);
        $shippingPolicyUrl = $this->nullableString(data_get($profile, 'trust.shipping_policy_url'));
        $shippingRegions = $this->stringList($merchantSignals['shipping_regions'] ?? $geography['wholesale_service_regions'] ?? []);
        $domestic = $geography['domestic_shipping_available'] ?? null;
        $international = $geography['international_shipping_available'] ?? null;

        if ($shippingPolicyUrl === null && $shippingRegions === [] && $domestic === null && $international === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ShippingDeliveryTime',
            '@id' => $organizationId ? $organizationId.'-shipping' : null,
            'url' => $shippingPolicyUrl,
            'shippingRegions' => $shippingRegions,
            'domesticShippingAvailable' => is_bool($domestic) ? $domestic : null,
            'internationalShippingAvailable' => is_bool($international) ? $international : null,
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>|null
     */
    protected function webPageEntity(array $page, ?array $canonical, ?string $organizationId): ?array
    {
        $url = $this->nullableString($canonical['canonical_url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $url.'#webpage',
            'url' => $url,
            'name' => $page['title'] ?? null,
            'description' => $page['meta_description'] ?? null,
            'about' => $organizationId,
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>|null
     */
    protected function collectionPageEntity(array $page, ?array $canonical, ?string $organizationId): ?array
    {
        $pageType = strtolower((string) ($page['page_type'] ?? ''));
        if (! str_contains($pageType, 'wholesale')) {
            return null;
        }

        $url = $this->nullableString($canonical['canonical_url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $url.'#collection-page',
            'url' => $url,
            'name' => $page['title'] ?? null,
            'description' => $page['summary'] ?? null,
            'about' => $organizationId,
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>|null
     */
    protected function faqPageEntity(array $page, ?array $canonical): ?array
    {
        $faqItems = $this->faqItems($page['faq_items'] ?? []);
        if ($faqItems === []) {
            return null;
        }

        $url = $this->nullableString($canonical['canonical_url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $url.'#faq',
            'url' => $url,
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], $faqItems),
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed> $domains
     * @return array<string,mixed>|null
     */
    protected function breadcrumbEntity(array $page, ?array $canonical, array $domains): ?array
    {
        $url = $this->nullableString($canonical['canonical_url'] ?? null);
        if ($url === null) {
            return null;
        }

        $rootDomain = $this->nullableString($domains['primary_retail_domain'] ?? null)
            ?? $this->nullableString($domains['primary_wholesale_domain'] ?? null);
        if ($rootDomain === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            '@id' => $url.'#breadcrumbs',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
                    'item' => 'https://'.$rootDomain.'/',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => (string) ($page['title'] ?? 'Page'),
                    'item' => $url,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function memberProgramEntity(array $profile): ?array
    {
        $enabled = (bool) data_get($profile, 'merchant_signals.member_program_enabled', false);
        if (! $enabled) {
            return null;
        }

        $name = $this->nullableString(data_get($profile, 'merchant_signals.member_program_name'));
        if ($name === null) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'MemberProgram',
            'name' => $name,
            'description' => data_get($profile, 'merchant_signals.member_program_description'),
        ];
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

    /**
     * @return array<int,array{question:string,answer:string}>
     */
    protected function faqItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = $this->nullableString($row['question'] ?? null);
            $answer = $this->nullableString($row['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $items[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $items;
    }

    protected function websiteNameForRole(string $role, array $brand): string
    {
        return match ($role) {
            'wholesale_storefront' => (string) ($brand['wholesale_brand_label'] ?? $brand['primary_brand_name'] ?? 'Wholesale'),
            'retail_storefront' => (string) ($brand['retail_brand_label'] ?? $brand['primary_brand_name'] ?? 'Retail'),
            'brand_story_site' => (string) ($brand['primary_brand_name'] ?? 'Brand'),
            default => (string) ($brand['primary_brand_name'] ?? 'Website'),
        };
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
