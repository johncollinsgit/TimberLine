<?php

namespace App\Services\Discovery;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DiscoveryDomainAuditService
{
    public function __construct(
        protected TenantDiscoveryProfileService $discoveryProfileService,
        protected DomainCanonicalResolver $domainCanonicalResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function auditForTenant(?int $tenantId, array $options = []): array
    {
        $timeout = max(2, (int) ($options['timeout'] ?? 8));
        $profile = $this->discoveryProfileService->resolveForTenant($tenantId);
        $domainMap = (array) ($profile['domain_relationships'] ?? []);
        $pages = is_array($profile['discovery_pages'] ?? null) ? (array) $profile['discovery_pages'] : [];
        $domains = $this->domainsFromRelationships($domainMap);
        $results = [];
        $issues = [];

        foreach ($domains as $domain => $role) {
            $homepageUrl = 'https://'.$domain.'/';
            $homepage = $this->fetchUrl($homepageUrl, $timeout);
            $robots = $this->fetchUrl('https://'.$domain.'/robots.txt', $timeout);
            $wellKnown = $this->fetchUrl('https://'.$domain.'/.well-known/brand-discovery.json', $timeout);

            $homepageAudit = $this->analyzeHtmlPage($domain, $homepage);
            $robotsAudit = $this->analyzeRobots($domain, $robots);
            $wellKnownAudit = [
                'url' => 'https://'.$domain.'/.well-known/brand-discovery.json',
                'reachable' => $wellKnown['ok'] && $wellKnown['status_code'] < 500,
                'status_code' => $wellKnown['status_code'],
            ];

            $pageAudits = [];
            foreach ($pages as $page) {
                if (! is_array($page) || ! (bool) ($page['is_public'] ?? true)) {
                    continue;
                }

                $pageKey = $this->nullableString($page['page_key'] ?? null);
                if ($pageKey === null) {
                    continue;
                }

                $canonical = $this->domainCanonicalResolver->resolveForDiscoveryPage($tenantId, $pageKey);
                if ($this->nullableString($canonical['target_domain'] ?? null) !== $domain) {
                    continue;
                }

                $canonicalUrl = $this->nullableString($canonical['canonical_url'] ?? null);
                if ($canonicalUrl === null) {
                    continue;
                }

                $response = $this->fetchUrl($canonicalUrl, $timeout);
                $analysis = $this->analyzeHtmlPage($domain, $response);
                $analysis['page_key'] = $pageKey;
                $analysis['expected_canonical'] = $canonicalUrl;
                $pageAudits[] = $analysis;
            }

            $domainIssues = $this->issuesForDomain(
                domain: $domain,
                role: $role,
                homepageAudit: $homepageAudit,
                robotsAudit: $robotsAudit,
                pageAudits: $pageAudits
            );
            $issues = array_merge($issues, $domainIssues);

            $results[$domain] = [
                'domain' => $domain,
                'expected_role' => $role,
                'homepage' => $homepageAudit,
                'robots' => $robotsAudit,
                'well_known' => $wellKnownAudit,
                'pages' => $pageAudits,
                'issues' => $domainIssues,
            ];
        }

        $status = $this->auditStatus($issues);

        return [
            'tenant_id' => $tenantId,
            'status' => $status,
            'issues' => $issues,
            'domains' => array_values($results),
            'summary' => [
                'domain_count' => count($results),
                'issue_count' => count($issues),
                'severe_issue_count' => count(array_filter($issues, fn (array $issue): bool => (string) ($issue['severity'] ?? '') === 'severe')),
                'warning_issue_count' => count(array_filter($issues, fn (array $issue): bool => (string) ($issue['severity'] ?? '') === 'warning')),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string,mixed> $domainMap
     * @return array<string,string>
     */
    protected function domainsFromRelationships(array $domainMap): array
    {
        $relationships = is_array($domainMap['relationships'] ?? null) ? (array) $domainMap['relationships'] : [];
        $rows = [];
        foreach ($relationships as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = $this->normalizeHost($row['domain'] ?? null);
            $role = $this->nullableString($row['role'] ?? null);
            if ($domain === null || $role === null) {
                continue;
            }

            $rows[$domain] = $role;
        }

        foreach ([
            'primary_retail_domain' => 'retail_storefront',
            'primary_wholesale_domain' => 'wholesale_storefront',
            'shopify_admin_domain' => 'admin_only',
        ] as $key => $defaultRole) {
            $domain = $this->normalizeHost($domainMap[$key] ?? null);
            if ($domain !== null && ! array_key_exists($domain, $rows)) {
                $rows[$domain] = $defaultRole;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $fetch
     * @return array<string,mixed>
     */
    protected function analyzeHtmlPage(string $domain, array $fetch): array
    {
        $body = (string) ($fetch['body'] ?? '');
        $canonical = $this->extractCanonicalUrl($body);
        $canonicalHost = $canonical !== null ? $this->normalizeHost(parse_url($canonical, PHP_URL_HOST)) : null;
        $title = $this->extractTagValue($body, 'title');
        $description = $this->extractMetaContent($body, 'description');
        $robotsMeta = $this->extractMetaContent($body, 'robots');
        $schemaCount = substr_count(strtolower($body), 'application/ld+json');
        $xRobotsTag = $this->nullableString($fetch['headers']['x-robots-tag'] ?? null);
        $isNoindex = $this->containsAny((string) $robotsMeta, ['noindex']) || $this->containsAny((string) $xRobotsTag, ['noindex']);
        $isNosnippet = $this->containsAny((string) $robotsMeta, ['nosnippet']) || $this->containsAny((string) $xRobotsTag, ['nosnippet']);

        return [
            'url' => $fetch['url'] ?? null,
            'reachable' => (bool) ($fetch['ok'] ?? false),
            'status_code' => (int) ($fetch['status_code'] ?? 0),
            'title_present' => $title !== null,
            'meta_description_present' => $description !== null,
            'canonical_url' => $canonical,
            'canonical_host' => $canonicalHost,
            'canonical_conflict' => $canonicalHost !== null && $canonicalHost !== $domain,
            'schema_script_count' => $schemaCount,
            'robots_meta' => $robotsMeta,
            'x_robots_tag' => $xRobotsTag,
            'noindex' => $isNoindex,
            'nosnippet' => $isNosnippet,
            'stale_theme_signal' => $this->staleThemeSignal($body),
            'contains_growave_runtime' => stripos($body, 'growave') !== false,
        ];
    }

    /**
     * @param array<string,mixed> $fetch
     * @return array<string,mixed>
     */
    protected function analyzeRobots(string $domain, array $fetch): array
    {
        $body = (string) ($fetch['body'] ?? '');
        $lower = strtolower($body);
        $disallowAll = str_contains($lower, "disallow: /");

        return [
            'url' => 'https://'.$domain.'/robots.txt',
            'reachable' => (bool) ($fetch['ok'] ?? false),
            'status_code' => (int) ($fetch['status_code'] ?? 0),
            'disallow_all' => $disallowAll,
            'body_excerpt' => mb_substr($body, 0, 400),
        ];
    }

    /**
     * @param array<string,mixed> $homepageAudit
     * @param array<string,mixed> $robotsAudit
     * @param array<int,array<string,mixed>> $pageAudits
     * @return array<int,array<string,mixed>>
     */
    protected function issuesForDomain(
        string $domain,
        string $role,
        array $homepageAudit,
        array $robotsAudit,
        array $pageAudits
    ): array {
        $issues = [];

        if (! (bool) ($homepageAudit['reachable'] ?? false)) {
            $issues[] = $this->issue($domain, 'severe', 'homepage_unreachable', 'Homepage is unreachable for domain audit.');
        }

        if ((bool) ($homepageAudit['noindex'] ?? false)) {
            $issues[] = $this->issue($domain, 'severe', 'homepage_noindex', 'Homepage appears to be noindex.');
        }

        if ((bool) ($robotsAudit['disallow_all'] ?? false)) {
            $issues[] = $this->issue($domain, 'severe', 'robots_disallow_all', 'robots.txt appears to block all crawlers.');
        }

        if (! (bool) ($homepageAudit['title_present'] ?? false)) {
            $issues[] = $this->issue($domain, 'warning', 'missing_title', 'Homepage title tag is missing.');
        }

        if (! (bool) ($homepageAudit['meta_description_present'] ?? false)) {
            $issues[] = $this->issue($domain, 'warning', 'missing_meta_description', 'Homepage meta description is missing.');
        }

        if (((int) ($homepageAudit['schema_script_count'] ?? 0)) <= 0) {
            $issues[] = $this->issue($domain, 'warning', 'missing_schema_scripts', 'Homepage has no JSON-LD scripts.');
        }

        if ((bool) ($homepageAudit['canonical_conflict'] ?? false)) {
            $issues[] = $this->issue($domain, 'warning', 'canonical_conflict', 'Homepage canonical host differs from audited domain.');
        }

        if ((bool) ($homepageAudit['nosnippet'] ?? false)) {
            $issues[] = $this->issue($domain, 'warning', 'homepage_nosnippet', 'Homepage appears to set nosnippet.');
        }

        if ($domain === 'theforestrystudio.com' && ((bool) ($homepageAudit['stale_theme_signal'] ?? false) || (bool) ($homepageAudit['contains_growave_runtime'] ?? false))) {
            $issues[] = $this->issue($domain, 'severe', 'stale_custom_domain_signal', 'Detected stale custom-domain output signals (legacy theme or Growave runtime markers).');
        }

        if ($domain === 'modernforestrywholesale.com') {
            $hasMetadata = (bool) ($homepageAudit['title_present'] ?? false)
                && (bool) ($homepageAudit['meta_description_present'] ?? false)
                && ((int) ($homepageAudit['schema_script_count'] ?? 0) > 0);
            if (! $hasMetadata) {
                $issues[] = $this->issue($domain, 'warning', 'wholesale_discovery_metadata_thin', 'Wholesale domain is missing metadata signals needed for recommendation/discovery confidence.');
            }
        }

        if ($role === 'admin_only' && ! str_ends_with($domain, '.myshopify.com')) {
            $issues[] = $this->issue($domain, 'warning', 'admin_role_domain_mismatch', 'Domain is marked admin_only but is not a myshopify host.');
        }

        foreach ($pageAudits as $pageAudit) {
            $pageKey = $this->nullableString($pageAudit['page_key'] ?? null) ?? 'unknown';
            if (! (bool) ($pageAudit['reachable'] ?? false)) {
                $issues[] = $this->issue($domain, 'warning', 'page_unreachable', "Discovery page [{$pageKey}] is unreachable.");
            }

            if ((bool) ($pageAudit['noindex'] ?? false)) {
                $issues[] = $this->issue($domain, 'warning', 'page_noindex', "Discovery page [{$pageKey}] appears noindex.");
            }

            if ((bool) ($pageAudit['canonical_conflict'] ?? false)) {
                $issues[] = $this->issue($domain, 'warning', 'page_canonical_conflict', "Discovery page [{$pageKey}] canonical host differs from audited domain.");
            }
        }

        return $issues;
    }

    /**
     * @return array{domain:string,severity:string,code:string,message:string}
     */
    protected function issue(string $domain, string $severity, string $code, string $message): array
    {
        return [
            'domain' => $domain,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function fetchUrl(string $url, int $timeout): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => 'ForestryBackstageDomainAudit/1.0',
                ])
                ->get($url);

            $headers = [];
            foreach ($response->headers() as $key => $value) {
                $headers[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }

            return [
                'url' => $url,
                'ok' => $response->successful(),
                'status_code' => $response->status(),
                'headers' => $headers,
                'body' => $response->body(),
            ];
        } catch (\Throwable $exception) {
            return [
                'url' => $url,
                'ok' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => $exception->getMessage(),
            ];
        }
    }

    protected function extractCanonicalUrl(string $html): ?string
    {
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $matches) === 1) {
            return $this->nullableString($matches[1] ?? null);
        }

        return null;
    }

    protected function extractTagValue(string $html, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $matches) === 1) {
            $value = trim(strip_tags((string) ($matches[1] ?? '')));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    protected function extractMetaContent(string $html, string $name): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches) === 1) {
            return $this->nullableString($matches[1] ?? null);
        }

        return null;
    }

    protected function staleThemeSignal(string $html): bool
    {
        $patterns = [
            'theme 136487764227',
            '136487764227',
            'prestige',
            'growave',
        ];

        $lower = strtolower($html);
        foreach ($patterns as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $needles
     */
    protected function containsAny(string $haystack, array $needles): bool
    {
        $normalized = strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($normalized, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     */
    protected function auditStatus(array $issues): string
    {
        if (collect($issues)->contains(fn (array $issue): bool => (string) ($issue['severity'] ?? '') === 'severe')) {
            return 'drift';
        }

        if ($issues !== []) {
            return 'warning';
        }

        return 'ok';
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

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
