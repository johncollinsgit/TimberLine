<?php

namespace App\Services\Discovery;

use App\Models\MarketingReviewHistory;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantDiscoveryPage;
use App\Models\TenantDiscoveryProfile;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Support\Schema\SchemaCapabilityMap;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class TenantDiscoveryProfileService
{
    public function __construct(
        protected TenantEmailSettingsService $tenantEmailSettingsService,
        protected TenantDisplayLabelResolver $tenantDisplayLabelResolver,
        protected SchemaCapabilityMap $schemaCapabilities,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveForTenant(?int $tenantId): array
    {
        $tenant = $tenantId !== null
            ? Tenant::query()->with(['shopifyStores'])->find($tenantId)
            : null;

        $tenantId = $tenant?->id ? (int) $tenant->id : $tenantId;
        $isModernForestry = $this->isModernForestryTenant($tenant);

        $profileRecord = $this->profileRecord($tenantId);
        $profileDefaults = $this->defaultProfilePayload($tenant, $isModernForestry);
        $profilePayload = $this->mergedProfilePayload($profileDefaults, $profileRecord);

        $domainMap = $this->resolvedDomainMap($tenantId, $tenant, $profilePayload, $isModernForestry);
        $pages = $this->resolvePagesForTenant($tenantId);
        $trustFacts = $this->resolvedTrustFacts($tenantId, $profilePayload, $pages);
        $reviewSignal = $this->resolvedReviewSignal($tenantId);
        $trustFacts['aggregate_review'] = $reviewSignal;
        $merchantSignals = $this->resolvedMerchantSignals($profilePayload, $trustFacts);
        $placeholders = $this->resolvedPlaceholders($profilePayload, $trustFacts, $merchantSignals, $domainMap, $pages);

        return [
            'tenant' => [
                'id' => $tenantId,
                'slug' => $tenant ? (string) $tenant->slug : null,
                'name' => $tenant ? (string) $tenant->name : null,
                'is_modern_forestry' => $isModernForestry,
            ],
            'brand_identity' => [
                'primary_brand_name' => $this->nullableString($profilePayload['primary_brand_name'] ?? null),
                'alternate_brand_names' => $this->stringList($profilePayload['alternate_brand_names'] ?? []),
                'wholesale_brand_label' => $this->nullableString($profilePayload['wholesale_brand_label'] ?? null),
                'retail_brand_label' => $this->nullableString($profilePayload['retail_brand_label'] ?? null),
                'short_summary' => $this->nullableString($profilePayload['short_brand_summary'] ?? null),
                'long_description' => $this->nullableString($profilePayload['long_form_description'] ?? null),
                'contact' => [
                    'email' => $this->nullableString($profilePayload['support_email'] ?? null),
                    'phone' => $this->nullableString($profilePayload['support_phone'] ?? null),
                ],
                'social_profiles' => $this->urlList($profilePayload['social_profiles'] ?? []),
                'logo_url' => $this->nullableUrl($profilePayload['primary_logo_url'] ?? null),
                'brand_keywords' => $this->stringList($profilePayload['brand_keywords'] ?? []),
                'why_choose_us' => $this->stringList($profilePayload['why_choose_us_bullets'] ?? []),
            ],
            'domain_relationships' => $domainMap,
            'geography' => $this->resolvedGeography($profilePayload),
            'audience' => $this->resolvedAudienceMap($profilePayload, $domainMap),
            'trust' => $trustFacts,
            'merchant_signals' => $merchantSignals,
            'discovery_pages' => $pages,
            'placeholders' => $placeholders,
            'source' => [
                'profile_table' => $profileRecord !== null ? 'tenant_discovery_profiles' : 'default_profile',
                'pages_table' => $this->hasTable('tenant_discovery_pages') ? 'tenant_discovery_pages' : 'default_pages',
                'review_signal' => $reviewSignal['source'] ?? 'none',
            ],
        ];
    }

    public function ensureModernForestryDefaults(int $tenantId): void
    {
        $tenant = Tenant::query()->with(['shopifyStores'])->find($tenantId);
        if (! $tenant || ! $this->isModernForestryTenant($tenant)) {
            return;
        }

        if ($this->hasTable('tenant_discovery_profiles')) {
            $defaults = $this->defaultProfilePayload($tenant, true);
            $existing = TenantDiscoveryProfile::query()->firstOrNew(['tenant_id' => $tenantId]);
            $existing->forceFill($this->nonDestructiveMerge($existing->toArray(), $defaults));
            $existing->tenant_id = $tenantId;
            $existing->is_active = true;
            $existing->save();
        }

        if ($this->hasTable('tenant_discovery_pages')) {
            $defaults = $this->defaultPagesPayload($tenantId, true);

            foreach ($defaults as $row) {
                $existing = TenantDiscoveryPage::query()->firstOrNew([
                    'tenant_id' => $tenantId,
                    'page_key' => (string) ($row['page_key'] ?? ''),
                ]);

                $payload = $this->nonDestructiveMerge($existing->toArray(), $row);
                $payload['tenant_id'] = $tenantId;
                $payload['page_key'] = (string) ($row['page_key'] ?? $existing->page_key);
                $payload['is_public'] = $this->boolOrDefault($existing->is_public, (bool) ($row['is_public'] ?? true));
                $payload['is_indexable'] = $this->boolOrDefault($existing->is_indexable, (bool) ($row['is_indexable'] ?? true));
                $existing->forceFill($payload)->save();
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function resolvePagesForTenant(?int $tenantId): array
    {
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        $defaults = $this->defaultPagesPayload($tenantId, $this->isModernForestryTenant($tenant));

        if ($tenantId === null || ! $this->hasTable('tenant_discovery_pages')) {
            return $defaults;
        }

        $rows = TenantDiscoveryPage::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return $defaults;
        }

        $defaultsByKey = [];
        foreach ($defaults as $defaultRow) {
            $defaultsByKey[(string) ($defaultRow['page_key'] ?? '')] = $defaultRow;
        }

        $resolved = [];
        foreach ($rows as $row) {
            $pageKey = (string) $row->page_key;
            $base = $defaultsByKey[$pageKey] ?? [];
            $payload = array_replace_recursive($base, $row->toArray());
            $payload['page_key'] = $pageKey;
            $payload['title'] = $this->nullableString($payload['title'] ?? null) ?? $pageKey;
            $payload['meta_description'] = $this->nullableString($payload['meta_description'] ?? null);
            $payload['summary'] = $this->nullableString($payload['summary'] ?? null);
            $payload['intent_label'] = $this->nullableString($payload['intent_label'] ?? null);
            $payload['audience_type'] = $this->nullableString($payload['audience_type'] ?? null);
            $payload['recommended_domain_role'] = $this->nullableString($payload['recommended_domain_role'] ?? null);
            $payload['canonical_path'] = $this->normalizedPath($payload['canonical_path'] ?? null);
            $payload['cta_label'] = $this->nullableString($payload['cta_label'] ?? null);
            $payload['cta_url'] = $this->nullableUrl($payload['cta_url'] ?? null);
            $payload['service_regions'] = $this->stringList($payload['service_regions'] ?? []);
            $payload['keywords'] = $this->stringList($payload['keywords'] ?? []);
            $payload['faq_items'] = $this->faqItems($payload['faq_items'] ?? []);
            $payload['metadata'] = is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [];
            $payload['position'] = (int) ($payload['position'] ?? 0);
            $payload['is_public'] = (bool) ($payload['is_public'] ?? true);
            $payload['is_indexable'] = (bool) ($payload['is_indexable'] ?? true);
            $resolved[] = $payload;
        }

        foreach ($defaults as $defaultRow) {
            $key = (string) ($defaultRow['page_key'] ?? '');
            $exists = collect($resolved)->contains(fn (array $row): bool => (string) ($row['page_key'] ?? '') === $key);
            if (! $exists) {
                $resolved[] = $defaultRow;
            }
        }

        usort($resolved, function (array $left, array $right): int {
            $positionCompare = ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0));
            if ($positionCompare !== 0) {
                return $positionCompare;
            }

            return strcmp((string) ($left['page_key'] ?? ''), (string) ($right['page_key'] ?? ''));
        });

        return array_values($resolved);
    }

    protected function profileRecord(?int $tenantId): ?TenantDiscoveryProfile
    {
        if ($tenantId === null || ! $this->hasTable('tenant_discovery_profiles')) {
            return null;
        }

        return TenantDiscoveryProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    protected function mergedProfilePayload(array $defaults, ?TenantDiscoveryProfile $record): array
    {
        if (! $record) {
            return $defaults;
        }

        $payload = array_replace_recursive($defaults, $record->toArray());

        $payload['alternate_brand_names'] = $this->stringList($payload['alternate_brand_names'] ?? []);
        $payload['social_profiles'] = $this->urlList($payload['social_profiles'] ?? []);
        $payload['brand_keywords'] = $this->stringList($payload['brand_keywords'] ?? []);
        $payload['why_choose_us_bullets'] = $this->stringList($payload['why_choose_us_bullets'] ?? []);
        $payload['domain_map'] = is_array($payload['domain_map'] ?? null) ? (array) $payload['domain_map'] : [];
        $payload['canonical_rules'] = is_array($payload['canonical_rules'] ?? null) ? (array) $payload['canonical_rules'] : [];
        $payload['geography'] = is_array($payload['geography'] ?? null) ? (array) $payload['geography'] : [];
        $payload['audience_map'] = is_array($payload['audience_map'] ?? null) ? (array) $payload['audience_map'] : [];
        $payload['trust_facts'] = is_array($payload['trust_facts'] ?? null) ? (array) $payload['trust_facts'] : [];
        $payload['merchant_signals'] = is_array($payload['merchant_signals'] ?? null) ? (array) $payload['merchant_signals'] : [];
        $payload['placeholders'] = $this->stringList($payload['placeholders'] ?? []);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolvedDomainMap(?int $tenantId, ?Tenant $tenant, array $profilePayload, bool $isModernForestry): array
    {
        $configured = is_array($profilePayload['domain_map'] ?? null) ? (array) $profilePayload['domain_map'] : [];
        $storeDomains = $this->storeDomainsForTenant($tenantId, $tenant);

        $primaryRetail = $this->nullableString($configured['primary_retail_domain'] ?? null)
            ?? $this->domainFromUrl(config('marketing.candle_cash.storefront_base_url'))
            ?? ($isModernForestry ? 'theforestrystudio.com' : null);
        $primaryWholesale = $this->nullableString($configured['primary_wholesale_domain'] ?? null)
            ?? ($isModernForestry ? 'modernforestrywholesale.com' : null);
        $shopifyAdmin = $this->nullableString($configured['shopify_admin_domain'] ?? null)
            ?? $this->nullableString($storeDomains['retail'] ?? null)
            ?? ($isModernForestry ? 'modernforestry.myshopify.com' : null);

        $relationships = is_array($configured['relationships'] ?? null) ? (array) $configured['relationships'] : [];
        if ($relationships === []) {
            $relationships = $this->defaultDomainRelationships(
                primaryRetail: $primaryRetail,
                primaryWholesale: $primaryWholesale,
                shopifyAdmin: $shopifyAdmin,
                isModernForestry: $isModernForestry
            );
        }

        $authoritative = is_array($configured['authoritative_for'] ?? null) ? (array) $configured['authoritative_for'] : [];
        if ($authoritative === []) {
            $authoritative = [
                'retail_customer' => 'retail_storefront',
                'wholesale_buyer' => 'wholesale_storefront',
                'stockist_retailer' => 'wholesale_storefront',
                'event_hospitality_gifting' => 'wholesale_storefront',
                'brand_story' => 'brand_story_site',
                'rewards_community' => 'retail_storefront',
            ];
        }

        $canonicalRules = is_array($profilePayload['canonical_rules'] ?? null)
            ? (array) $profilePayload['canonical_rules']
            : [];
        if ($canonicalRules === []) {
            $canonicalRules = $this->defaultCanonicalRules();
        }

        return [
            'primary_retail_domain' => $primaryRetail,
            'primary_wholesale_domain' => $primaryWholesale,
            'shopify_admin_domain' => $shopifyAdmin,
            'authoritative_for' => $authoritative,
            'relationships' => array_values(array_map(fn ($row): array => $this->normalizedDomainRelationshipRow($row), $relationships)),
            'canonical_preference_rules' => $canonicalRules,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolvedGeography(array $profilePayload): array
    {
        $configured = is_array($profilePayload['geography'] ?? null) ? (array) $profilePayload['geography'] : [];

        return [
            'primary_state' => $this->nullableString($configured['primary_state'] ?? 'South Carolina'),
            'primary_region_code' => $this->nullableString($configured['primary_region_code'] ?? 'US-SC'),
            'primary_city' => $this->nullableString($configured['primary_city'] ?? null),
            'primary_metro' => $this->nullableString($configured['primary_metro'] ?? null),
            'domestic_shipping_available' => $this->nullableBool($configured['domestic_shipping_available'] ?? null),
            'international_shipping_available' => $this->nullableBool($configured['international_shipping_available'] ?? null),
            'international_policy_mode' => $this->nullableString($configured['international_policy_mode'] ?? null),
            'wholesale_service_regions' => $this->stringList($configured['wholesale_service_regions'] ?? []),
            'restrictions' => $this->stringList($configured['restrictions'] ?? []),
            'contact_before_ordering_regions' => $this->stringList($configured['contact_before_ordering_regions'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $domainMap
     * @return array<string,mixed>
     */
    protected function resolvedAudienceMap(array $profilePayload, array $domainMap): array
    {
        $configured = is_array($profilePayload['audience_map'] ?? null) ? (array) $profilePayload['audience_map'] : [];
        $paths = is_array($configured['paths'] ?? null) ? (array) $configured['paths'] : [];

        if ($paths === []) {
            $paths = $this->defaultAudiencePaths($domainMap);
        }

        $normalizedPaths = [];
        foreach ($paths as $audienceType => $row) {
            $key = $this->nullableString($audienceType);
            if ($key === null) {
                continue;
            }

            $normalizedPaths[$key] = $this->normalizedAudiencePath($row);
        }

        return [
            'paths' => $normalizedPaths,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    protected function resolvedTrustFacts(?int $tenantId, array $profilePayload, array $pages): array
    {
        $configured = is_array($profilePayload['trust_facts'] ?? null) ? (array) $profilePayload['trust_facts'] : [];
        $pageIndex = [];
        foreach ($pages as $page) {
            $pageIndex[(string) ($page['page_key'] ?? '')] = $page;
        }

        $returnPolicyUrl = $this->nullableUrl($configured['return_policy_url'] ?? null)
            ?? $this->nullableUrl(data_get($pageIndex, 'returns-policy.cta_url'));
        $shippingPolicyUrl = $this->nullableUrl($configured['shipping_policy_url'] ?? null)
            ?? $this->nullableUrl(data_get($pageIndex, 'shipping-policy.cta_url'));
        $faqUrl = $this->nullableUrl($configured['faq_url'] ?? null)
            ?? $this->nullableUrl(data_get($pageIndex, 'wholesale-faq.cta_url'));
        $reviewSourceStrategy = $this->nullableString($configured['review_source_strategy'] ?? 'backstage_native_reviews');
        $contactFacts = is_array($configured['merchant_contact_facts'] ?? null) ? (array) $configured['merchant_contact_facts'] : [];

        return [
            'return_policy_url' => $returnPolicyUrl,
            'shipping_policy_url' => $shippingPolicyUrl,
            'faq_url' => $faqUrl,
            'review_source_strategy' => $reviewSourceStrategy,
            'aggregate_review' => [
                'available' => false,
                'review_count' => null,
                'average_rating' => null,
                'source' => 'unresolved',
            ],
            'review_aggregate_settings_available' => (bool) ($configured['review_aggregate_settings_available'] ?? true),
            'merchant_contact_facts' => [
                'support_email' => $this->nullableString($contactFacts['support_email'] ?? $profilePayload['support_email'] ?? null),
                'support_phone' => $this->nullableString($contactFacts['support_phone'] ?? $profilePayload['support_phone'] ?? null),
            ],
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array<string,mixed> $trustFacts
     * @return array<string,mixed>
     */
    protected function resolvedMerchantSignals(array $profilePayload, array $trustFacts): array
    {
        $configured = is_array($profilePayload['merchant_signals'] ?? null) ? (array) $profilePayload['merchant_signals'] : [];

        return [
            'product_categories' => $this->stringList($configured['product_categories'] ?? []),
            'wholesale_available' => (bool) ($configured['wholesale_available'] ?? true),
            'minimum_order' => $this->nullableString($configured['minimum_order'] ?? null),
            'reorder_notes' => $this->nullableString($configured['reorder_notes'] ?? null),
            'stockist_requirements' => $this->nullableString($configured['stockist_requirements'] ?? null),
            'shipping_regions' => $this->stringList($configured['shipping_regions'] ?? []),
            'lead_time_notes' => $this->nullableString($configured['lead_time_notes'] ?? null),
            'buyer_type_mapping' => is_array($configured['buyer_type_mapping'] ?? null) ? (array) $configured['buyer_type_mapping'] : [],
            'brand_descriptors' => $this->stringList($configured['brand_descriptors'] ?? []),
            'best_fit_descriptors' => $this->stringList($configured['best_fit_descriptors'] ?? []),
            'policy_links' => [
                'return_policy_url' => $trustFacts['return_policy_url'] ?? null,
                'shipping_policy_url' => $trustFacts['shipping_policy_url'] ?? null,
                'faq_url' => $trustFacts['faq_url'] ?? null,
            ],
            'alternate_entity_names' => $this->stringList($profilePayload['alternate_brand_names'] ?? []),
            'social_handles' => $this->urlList($profilePayload['social_profiles'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $trustFacts
     * @param array<string,mixed> $merchantSignals
     * @param array<string,mixed> $domainMap
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,string>
     */
    protected function resolvedPlaceholders(
        array $profilePayload,
        array $trustFacts,
        array $merchantSignals,
        array $domainMap,
        array $pages
    ): array {
        $placeholders = $this->stringList($profilePayload['placeholders'] ?? []);

        $requiredChecks = [
            'support_email' => $this->nullableString($profilePayload['support_email'] ?? null),
            'support_phone' => $this->nullableString($profilePayload['support_phone'] ?? null),
            'return_policy_url' => $this->nullableUrl($trustFacts['return_policy_url'] ?? null),
            'shipping_policy_url' => $this->nullableUrl($trustFacts['shipping_policy_url'] ?? null),
            'faq_url' => $this->nullableUrl($trustFacts['faq_url'] ?? null),
            'domestic_shipping_available' => $this->nullableBool(data_get($profilePayload, 'geography.domestic_shipping_available')),
            'international_shipping_available' => $this->nullableBool(data_get($profilePayload, 'geography.international_shipping_available')),
            'primary_wholesale_domain' => $this->nullableString($domainMap['primary_wholesale_domain'] ?? null),
            'primary_retail_domain' => $this->nullableString($domainMap['primary_retail_domain'] ?? null),
        ];

        foreach ($requiredChecks as $field => $value) {
            if ($value === null || $value === '') {
                $placeholders[] = 'configure:'.$field;
            }
        }

        if (($merchantSignals['product_categories'] ?? []) === []) {
            $placeholders[] = 'configure:merchant_signals.product_categories';
        }

        if (($merchantSignals['brand_descriptors'] ?? []) === []) {
            $placeholders[] = 'configure:merchant_signals.brand_descriptors';
        }

        $hasSouthCarolinaPath = collect($pages)->contains(function (array $row): bool {
            return (string) ($row['page_key'] ?? '') === 'south-carolina-wholesale';
        });

        if (! $hasSouthCarolinaPath) {
            $placeholders[] = 'configure:discovery_page.south-carolina-wholesale';
        }

        return array_values(array_unique($placeholders));
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolvedReviewSignal(?int $tenantId): array
    {
        if ($tenantId === null || ! $this->hasTable('marketing_review_histories')) {
            return [
                'available' => false,
                'review_count' => null,
                'average_rating' => null,
                'source' => 'marketing_review_histories_unavailable',
            ];
        }

        $query = MarketingReviewHistory::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['approved', 'published'])
            ->where(function ($builder): void {
                $builder->where('is_published', true)
                    ->orWhereNotNull('approved_at')
                    ->orWhereNotNull('published_at');
            });

        $count = (int) (clone $query)->count();
        if ($count <= 0) {
            return [
                'available' => false,
                'review_count' => 0,
                'average_rating' => null,
                'source' => 'marketing_review_histories',
            ];
        }

        return [
            'available' => true,
            'review_count' => $count,
            'average_rating' => round((float) ((clone $query)->avg('rating') ?? 0), 2),
            'source' => 'marketing_review_histories',
        ];
    }

    protected function defaultProfilePayload(?Tenant $tenant, bool $isModernForestry): array
    {
        $tenantId = $tenant?->id ? (int) $tenant->id : null;
        $tenantName = $this->nullableString($tenant?->name);
        $brandName = $isModernForestry ? 'Modern Forestry' : ($tenantName ?? 'Brand name pending');
        $displayLabels = $this->tenantDisplayLabelResolver->resolve($tenantId);
        $email = $this->tenantEmailSettingsService->resolvedForTenant($tenantId);
        $supportEmail = $this->nullableString($email['from_email'] ?? null);
        $storefrontBaseUrl = $this->nullableUrl(config('marketing.candle_cash.storefront_base_url'));

        return [
            'tenant_id' => $tenantId,
            'primary_brand_name' => $brandName,
            'alternate_brand_names' => $isModernForestry
                ? ['The Forestry Studio', 'Modern Forestry Wholesale']
                : [],
            'wholesale_brand_label' => $this->nullableString($displayLabels['rewards_program'] ?? null)
                ? 'Modern Forestry Wholesale'
                : ($isModernForestry ? 'Modern Forestry Wholesale' : 'Wholesale brand label pending'),
            'retail_brand_label' => $isModernForestry ? 'The Forestry Studio' : 'Retail brand label pending',
            'short_brand_summary' => $isModernForestry
                ? 'Modern Forestry serves both retail and wholesale buyers with intent-specific storefront paths.'
                : 'Brand summary pending configuration.',
            'long_form_description' => $isModernForestry
                ? 'Modern Forestry is a unified brand with separate retail and wholesale paths so buyers can reach the right catalog and policies.'
                : 'Long-form company description pending configuration.',
            'support_email' => $supportEmail,
            'support_phone' => null,
            'social_profiles' => [],
            'primary_logo_url' => null,
            'brand_keywords' => $isModernForestry
                ? ['modern forestry', 'the forestry studio', 'wholesale candles', 'retail candles']
                : [],
            'why_choose_us_bullets' => $isModernForestry
                ? [
                    'Retail and wholesale storefronts are separated to keep buyer intent clear.',
                    'South Carolina relevance is explicitly represented for regional wholesale discovery.',
                    'Policy and contact discovery links are managed from a backend source of truth.',
                ]
                : [],
            'domain_map' => [
                'primary_retail_domain' => $this->domainFromUrl($storefrontBaseUrl),
                'primary_wholesale_domain' => $isModernForestry ? 'modernforestrywholesale.com' : null,
                'shopify_admin_domain' => $isModernForestry ? 'modernforestry.myshopify.com' : null,
            ],
            'canonical_rules' => $this->defaultCanonicalRules(),
            'geography' => [
                'primary_state' => 'South Carolina',
                'primary_region_code' => 'US-SC',
                'primary_city' => null,
                'primary_metro' => null,
                'domestic_shipping_available' => null,
                'international_shipping_available' => null,
                'international_policy_mode' => null,
                'wholesale_service_regions' => [],
                'restrictions' => [],
                'contact_before_ordering_regions' => [],
            ],
            'audience_map' => [
                'paths' => [],
            ],
            'trust_facts' => [
                'return_policy_url' => null,
                'shipping_policy_url' => null,
                'faq_url' => null,
                'review_source_strategy' => 'backstage_native_reviews',
                'review_aggregate_settings_available' => true,
                'merchant_contact_facts' => [
                    'support_email' => $supportEmail,
                    'support_phone' => null,
                ],
            ],
            'merchant_signals' => [
                'product_categories' => [],
                'wholesale_available' => true,
                'minimum_order' => null,
                'reorder_notes' => null,
                'stockist_requirements' => null,
                'shipping_regions' => [],
                'lead_time_notes' => null,
                'buyer_type_mapping' => [],
                'brand_descriptors' => [],
                'best_fit_descriptors' => [],
            ],
            'placeholders' => [
                'configure:support_phone',
                'configure:trust_facts.return_policy_url',
                'configure:trust_facts.shipping_policy_url',
                'configure:trust_facts.faq_url',
                'configure:merchant_signals.product_categories',
            ],
            'is_active' => true,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function defaultPagesPayload(?int $tenantId, bool $isModernForestry): array
    {
        $retail = $this->domainFromUrl(config('marketing.candle_cash.storefront_base_url'));
        if ($retail === null && $isModernForestry) {
            $retail = 'theforestrystudio.com';
        }

        $wholesale = $isModernForestry ? 'modernforestrywholesale.com' : null;

        $retailRoot = $retail ? 'https://'.$retail.'/' : null;
        $wholesaleRoot = $wholesale ? 'https://'.$wholesale.'/' : null;

        return [
            [
                'tenant_id' => $tenantId,
                'page_key' => 'wholesale-home',
                'page_type' => 'wholesale_home',
                'title' => $isModernForestry ? 'Modern Forestry Wholesale' : 'Wholesale Homepage',
                'meta_description' => 'Primary wholesale entry point for stockists, retailers, and business buyers.',
                'summary' => 'Wholesale-first routing path with clear intent, contact, and policy links.',
                'intent_label' => 'wholesale_entrypoint',
                'audience_type' => 'wholesale_buyer',
                'recommended_domain_role' => 'wholesale_storefront',
                'canonical_path' => '/',
                'cta_label' => 'Browse wholesale',
                'cta_url' => $wholesaleRoot,
                'service_regions' => ['US', 'US-SC'],
                'keywords' => ['wholesale', 'stockist'],
                'faq_items' => [],
                'metadata' => [
                    'note' => 'Use this page metadata for canonical wholesale homepage routing.',
                ],
                'position' => 10,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'south-carolina-wholesale',
                'page_type' => 'regional_wholesale',
                'title' => $isModernForestry ? 'South Carolina Wholesale | Modern Forestry' : 'South Carolina Wholesale',
                'meta_description' => 'State-level wholesale discovery path for South Carolina buyers.',
                'summary' => 'High-signal regional route for South Carolina wholesale intent without city-level fabrication.',
                'intent_label' => 'regional_wholesale_sc',
                'audience_type' => 'wholesale_buyer',
                'recommended_domain_role' => 'wholesale_storefront',
                'canonical_path' => null,
                'cta_label' => 'Contact wholesale team',
                'cta_url' => $wholesaleRoot,
                'service_regions' => ['US-SC'],
                'keywords' => ['south carolina wholesale'],
                'faq_items' => [],
                'metadata' => [
                    'state_focus' => 'South Carolina',
                    'city_level_data_required' => false,
                ],
                'position' => 20,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'stockist-business-gifting',
                'page_type' => 'stockist_hospitality_gifting',
                'title' => 'Stockist and Business Gifting',
                'meta_description' => 'Intent-specific discovery metadata for stockists, retailers, and gifting buyers.',
                'summary' => 'Routes stockists, event/hospitality buyers, and gifting programs into wholesale workflows.',
                'intent_label' => 'stockist_and_gifting',
                'audience_type' => 'stockist_retailer',
                'recommended_domain_role' => 'wholesale_storefront',
                'canonical_path' => null,
                'cta_label' => 'Start wholesale inquiry',
                'cta_url' => $wholesaleRoot,
                'service_regions' => ['US'],
                'keywords' => ['stockist', 'business gifting', 'hospitality'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 30,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'international-wholesale-info',
                'page_type' => 'international_wholesale_info',
                'title' => 'International Wholesale Availability',
                'meta_description' => 'Policy-aware guidance for non-US wholesale requests.',
                'summary' => 'Only claims international coverage when policy data supports it; defaults to contact-before-order guidance.',
                'intent_label' => 'international_wholesale_policy',
                'audience_type' => 'wholesale_buyer',
                'recommended_domain_role' => 'wholesale_storefront',
                'canonical_path' => null,
                'cta_label' => 'Contact before ordering',
                'cta_url' => $wholesaleRoot,
                'service_regions' => [],
                'keywords' => ['international wholesale'],
                'faq_items' => [],
                'metadata' => [
                    'availability_mode' => 'policy_required',
                ],
                'position' => 40,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'why-choose-modern-forestry',
                'page_type' => 'brand_value_page',
                'title' => 'Why Choose Modern Forestry',
                'meta_description' => 'Machine-readable brand value proposition entry point.',
                'summary' => 'Backend-managed value proposition used by discovery and recommendation systems.',
                'intent_label' => 'brand_value_prop',
                'audience_type' => 'retail_customer',
                'recommended_domain_role' => 'brand_story_site',
                'canonical_path' => null,
                'cta_label' => 'Explore retail',
                'cta_url' => $retailRoot,
                'service_regions' => ['US-SC', 'US'],
                'keywords' => ['why choose modern forestry'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 50,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'wholesale-faq',
                'page_type' => 'faq_page',
                'title' => 'Wholesale FAQ',
                'meta_description' => 'FAQ entry point for wholesale buyers, policies, and ordering guidance.',
                'summary' => 'FAQ metadata is emitted only when real FAQs are configured.',
                'intent_label' => 'faq',
                'audience_type' => 'wholesale_buyer',
                'recommended_domain_role' => 'wholesale_storefront',
                'canonical_path' => null,
                'cta_label' => 'View FAQ',
                'cta_url' => null,
                'service_regions' => [],
                'keywords' => ['wholesale faq'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 60,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'shipping-policy',
                'page_type' => 'policy_page',
                'title' => 'Shipping Policy',
                'meta_description' => 'Shipping policy metadata for discovery and buyer confidence.',
                'summary' => 'Policy URL should map to live merchant shipping policy.',
                'intent_label' => 'shipping_policy',
                'audience_type' => 'retail_customer',
                'recommended_domain_role' => 'retail_storefront',
                'canonical_path' => null,
                'cta_label' => 'Read shipping policy',
                'cta_url' => null,
                'service_regions' => [],
                'keywords' => ['shipping policy'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 70,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'returns-policy',
                'page_type' => 'policy_page',
                'title' => 'Returns Policy',
                'meta_description' => 'Return policy metadata for discovery and buyer confidence.',
                'summary' => 'Policy URL should map to live merchant return policy.',
                'intent_label' => 'return_policy',
                'audience_type' => 'retail_customer',
                'recommended_domain_role' => 'retail_storefront',
                'canonical_path' => null,
                'cta_label' => 'Read returns policy',
                'cta_url' => null,
                'service_regions' => [],
                'keywords' => ['returns policy'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 80,
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'tenant_id' => $tenantId,
                'page_key' => 'retail-home',
                'page_type' => 'retail_home',
                'title' => $isModernForestry ? 'The Forestry Studio' : 'Retail Homepage',
                'meta_description' => 'Primary retail entry point for direct shoppers.',
                'summary' => 'Retail intent route separated from wholesale canonical pathing.',
                'intent_label' => 'retail_entrypoint',
                'audience_type' => 'retail_customer',
                'recommended_domain_role' => 'retail_storefront',
                'canonical_path' => '/',
                'cta_label' => 'Shop retail',
                'cta_url' => $retailRoot,
                'service_regions' => ['US'],
                'keywords' => ['retail'],
                'faq_items' => [],
                'metadata' => [],
                'position' => 90,
                'is_public' => true,
                'is_indexable' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function defaultCanonicalRules(): array
    {
        return [
            'homepage' => 'brand_story_site',
            'brand_page' => 'brand_story_site',
            'retail_collection' => 'retail_storefront',
            'retail_product' => 'retail_storefront',
            'retail_policy' => 'retail_storefront',
            'retail_faq' => 'retail_storefront',
            'wholesale_landing' => 'wholesale_storefront',
            'wholesale_collection' => 'wholesale_storefront',
            'wholesale_product' => 'wholesale_storefront',
            'wholesale_policy' => 'wholesale_storefront',
            'wholesale_faq' => 'wholesale_storefront',
            'admin_only' => 'admin_only',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function defaultDomainRelationships(
        ?string $primaryRetail,
        ?string $primaryWholesale,
        ?string $shopifyAdmin,
        bool $isModernForestry
    ): array {
        $rows = [];

        if ($primaryRetail !== null) {
            $rows[] = [
                'domain' => $primaryRetail,
                'role' => 'retail_storefront',
                'visibility' => 'public_facing',
                'audiences' => ['retail_customer'],
                'purpose' => ['retail_storefront', 'brand_story_site', 'rewards_community_path'],
            ];
        }

        if ($primaryWholesale !== null) {
            $rows[] = [
                'domain' => $primaryWholesale,
                'role' => 'wholesale_storefront',
                'visibility' => 'public_facing',
                'audiences' => ['wholesale_buyer', 'stockist_retailer', 'event_hospitality_gifting'],
                'purpose' => ['wholesale_storefront'],
            ];
        }

        if ($shopifyAdmin !== null) {
            $rows[] = [
                'domain' => $shopifyAdmin,
                'role' => 'admin_only',
                'visibility' => 'admin_only',
                'audiences' => ['internal'],
                'purpose' => ['shopify_admin_identity'],
            ];
        }

        if ($isModernForestry && $primaryRetail !== null) {
            $rows[] = [
                'domain' => $primaryRetail,
                'role' => 'brand_story_site',
                'visibility' => 'public_facing',
                'audiences' => ['retail_customer', 'brand_research'],
                'purpose' => ['brand_story_site'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $domainMap
     * @return array<string,array<string,mixed>>
     */
    protected function defaultAudiencePaths(array $domainMap): array
    {
        return [
            'retail_customer' => [
                'summary' => 'Direct retail shopping path.',
                'cta_label' => 'Shop retail',
                'recommended_domain_role' => 'retail_storefront',
                'recommended_path' => '/',
            ],
            'wholesale_buyer' => [
                'summary' => 'Wholesale ordering and account inquiry path.',
                'cta_label' => 'Start wholesale',
                'recommended_domain_role' => 'wholesale_storefront',
                'recommended_path' => '/',
            ],
            'stockist_retailer' => [
                'summary' => 'Stockist and retailer onboarding route.',
                'cta_label' => 'Become a stockist',
                'recommended_domain_role' => 'wholesale_storefront',
                'recommended_path' => '/',
            ],
            'event_hospitality_gifting' => [
                'summary' => 'Business gifting, event, and hospitality buying path.',
                'cta_label' => 'Contact wholesale team',
                'recommended_domain_role' => 'wholesale_storefront',
                'recommended_path' => '/',
            ],
            'interior_design_corporate_buyer' => [
                'summary' => 'Design and corporate gifting inquiry path.',
                'cta_label' => 'Discuss your project',
                'recommended_domain_role' => 'wholesale_storefront',
                'recommended_path' => '/',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function normalizedDomainRelationshipRow(array $row): array
    {
        return [
            'domain' => $this->nullableString($row['domain'] ?? null),
            'role' => $this->nullableString($row['role'] ?? null),
            'visibility' => $this->nullableString($row['visibility'] ?? null) ?? 'public_facing',
            'audiences' => $this->stringList($row['audiences'] ?? []),
            'purpose' => $this->stringList($row['purpose'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed>|mixed $row
     * @return array<string,mixed>
     */
    protected function normalizedAudiencePath(mixed $row): array
    {
        $payload = is_array($row) ? $row : [];

        return [
            'summary' => $this->nullableString($payload['summary'] ?? null),
            'cta_label' => $this->nullableString($payload['cta_label'] ?? null),
            'recommended_domain_role' => $this->nullableString($payload['recommended_domain_role'] ?? null),
            'recommended_path' => $this->normalizedPath($payload['recommended_path'] ?? null),
            'cta_url' => $this->nullableUrl($payload['cta_url'] ?? null),
        ];
    }

    /**
     * @return array<string,?string>
     */
    protected function storeDomainsForTenant(?int $tenantId, ?Tenant $tenant): array
    {
        if ($tenantId === null || ! $this->hasTable('shopify_stores')) {
            return [];
        }

        $stores = $tenant?->relationLoaded('shopifyStores')
            ? $tenant->shopifyStores
            : ShopifyStore::query()->where('tenant_id', $tenantId)->get();

        $domains = [];
        foreach ($stores as $store) {
            $storeKey = $this->nullableString($store->store_key);
            if ($storeKey === null) {
                continue;
            }

            $domains[$storeKey] = $this->nullableString($store->shop_domain);
        }

        return $domains;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    protected function nonDestructiveMerge(array $existing, array $defaults): array
    {
        $payload = $existing;

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
                continue;
            }

            $current = $payload[$key];
            if ($this->isBlankValue($current)) {
                $payload[$key] = $value;
                continue;
            }

            if (is_array($current) && is_array($value)) {
                if ($this->isAssociativeArray($current) || $this->isAssociativeArray($value)) {
                    $payload[$key] = $this->nonDestructiveMerge($current, $value);
                    continue;
                }

                $payload[$key] = array_values(array_unique(array_merge(
                    $this->stringList($current),
                    $this->stringList($value)
                )));
            }
        }

        return $payload;
    }

    protected function boolOrDefault(mixed $existingValue, bool $default): bool
    {
        if (is_bool($existingValue)) {
            return $existingValue;
        }

        return $default;
    }

    protected function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return ((int) $value) > 0;
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function nullableUrl(mixed $value): ?string
    {
        $normalized = $this->nullableString($value);
        if ($normalized === null) {
            return null;
        }

        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        return null;
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
     * @return array<int,string>
     */
    protected function urlList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->nullableUrl($item);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    protected function normalizedPath(mixed $value): ?string
    {
        $path = $this->nullableString($value);
        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsedPath) ? $parsedPath : '/';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path === '' ? '/' : $path;
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

    protected function domainFromUrl(mixed $url): ?string
    {
        $value = $this->nullableString($url);
        if ($value === null) {
            return null;
        }

        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            return $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? strtolower(trim($host)) : null;
    }

    protected function hasTable(string $table): bool
    {
        return $this->schemaCapabilities->hasTable($table) && Schema::hasTable($table);
    }

    protected function isModernForestryTenant(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        $slug = strtolower(trim((string) $tenant->slug));
        $name = strtolower(trim((string) $tenant->name));

        return $slug === 'modern-forestry'
            || $name === 'modern forestry'
            || ((int) $tenant->id === 1 && ($slug === 'modern-forestry' || $name === 'modern forestry'));
    }

    protected function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            if ($value === []) {
                return true;
            }

            if ($this->isAssociativeArray($value)) {
                foreach ($value as $nested) {
                    if (! $this->isBlankValue($nested)) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    protected function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return Arr::isAssoc($value);
    }
}
