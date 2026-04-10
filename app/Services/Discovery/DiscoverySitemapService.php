<?php

namespace App\Services\Discovery;

class DiscoverySitemapService
{
    public function __construct(
        protected TenantDiscoveryProfileService $discoveryProfileService,
        protected DomainCanonicalResolver $domainCanonicalResolver,
    ) {
    }

    public function buildSitemapXmlForTenant(?int $tenantId): string
    {
        $profile = $this->discoveryProfileService->resolveForTenant($tenantId);
        $tenant = (array) ($profile['tenant'] ?? []);
        $pages = is_array($profile['discovery_pages'] ?? null) ? (array) $profile['discovery_pages'] : [];
        $domainMap = (array) ($profile['domain_relationships'] ?? []);
        $baseDomain = $this->nullableString($domainMap['primary_retail_domain'] ?? null)
            ?? $this->nullableString($domainMap['primary_wholesale_domain'] ?? null);

        $urls = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (! (bool) ($page['is_public'] ?? true) || ! (bool) ($page['is_indexable'] ?? true)) {
                continue;
            }

            $pageKey = $this->nullableString($page['page_key'] ?? null);
            if ($pageKey === null) {
                continue;
            }

            $canonical = $this->domainCanonicalResolver->resolveForDiscoveryPage($tenantId, $pageKey);
            $url = $this->nullableString($canonical['canonical_url'] ?? null);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        if ($baseDomain !== null) {
            $urls[] = 'https://'.$baseDomain.'/.well-known/brand-discovery.json';
            $urls[] = 'https://'.$baseDomain.'/api/public/discovery/structured';

            $tenantSlug = $this->nullableString($tenant['slug'] ?? null);
            if ($tenantSlug !== null) {
                $urls[] = 'https://'.$baseDomain.'/api/public/discovery/brand/'.$tenantSlug;
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        $lastMod = now()->toAtomString();
        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($url, ENT_XML1).'</loc>';
            $lines[] = '    <lastmod>'.$lastMod.'</lastmod>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
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
