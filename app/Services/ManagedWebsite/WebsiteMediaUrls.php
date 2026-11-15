<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;

/** Translate only verified, site-owned upload references; never fetch a URL. */
class WebsiteMediaUrls
{
    public function forSite(TenantSite $site, ?string $url): string
    {
        $url = trim((string) $url);
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        if (! preg_match('#\A/(website-media|website-catalog-media)/([1-9][0-9]*)\z#', $path, $match)) {
            return str_starts_with($path, '/website-media/') || str_starts_with($path, '/website-catalog-media/') ? '' : $url;
        }
        if (array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts)) !== []) {
            return '';
        }
        if (isset($parts['host'])) {
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ! in_array($parts['host'], $this->hosts($site), true)) {
                return '';
            }
        } elseif (isset($parts['scheme']) || str_starts_with($url, '//')) {
            return '';
        }
        if (! TenantSiteMedia::query()->whereKey($match[2])->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->exists()) {
            return '';
        }

        return $path;
    }

    /** Exact canonical aliases for a product's stored upload, independent of request Host. */
    public function catalogReferences(TenantSite $site, TenantSiteMedia $media): array
    {
        return $this->references($site, $media, 'website-catalog-media');
    }

    public function publishedPageReferences(TenantSite $site, TenantSiteMedia $media): bool
    {
        $urls = $this->references($site, $media, 'website-media');
        if ($urls === []) {
            return false;
        }
        $versions = TenantSitePageVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)
            ->where('status', 'published')->where('blocks', 'like', '%website-media%')
            ->whereIn('id', TenantSitePage::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->select('published_version_id'))
            ->cursor();
        foreach ($versions as $version) {
            foreach ((array) $version->blocks as $block) {
                if (in_array($block['image_url'] ?? null, $urls, true)) {
                    return true;
                }
                foreach ((array) ($block['items'] ?? []) as $item) {
                    if (is_array($item) && in_array($item['image_url'] ?? null, $urls, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function references(TenantSite $site, TenantSiteMedia $media, string $prefix): array
    {
        if ((int) $media->tenant_id !== (int) $site->tenant_id || (int) $media->tenant_site_id !== (int) $site->id) {
            return [];
        }
        $path = '/'.$prefix.'/'.$media->id;
        $urls = [$path];
        foreach ($this->hosts($site) as $host) {
            foreach (['https', 'http'] as $scheme) {
                $urls[] = $scheme.'://'.$host.$path;
            }
        }

        return $urls;
    }

    private function hosts(TenantSite $site): array
    {
        return array_values(array_unique(array_filter([
            (string) config('tenancy.domains.canonical.landlord_host'),
            (string) config('tenancy.domains.canonical.public_host'),
            $site->subdomain.'.'.config('tenancy.domains.canonical.base_domain'),
            ...$site->domains()->where('status', 'active')->pluck('hostname')->all(),
        ])));
    }
}
