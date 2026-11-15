<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSiteVersion;
use Illuminate\Validation\ValidationException;

/** Translate only verified, site-owned upload references; never fetch a URL. */
class WebsiteMediaUrls
{
    /** Validate selected images without fetching or adopting a different site's upload. */
    public function validatedImage(TenantSite $site, string $url, string $field): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $safe = $this->forSite($site, $url);
        $upload = preg_match('#\A/(website-media|website-catalog-media)/#', $path) === 1;
        $remote = is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false && ! isset($parts['user']) && ! isset($parts['pass']);
        $local = is_array($parts) && ! isset($parts['host']) && ! isset($parts['scheme'])
            && preg_match('#\A/images/[a-zA-Z0-9_./~%-]+\z#', $url) === 1
            && ! str_contains(rawurldecode($url), '..') && is_file(public_path(ltrim($url, '/')));
        if ($safe === '' || ! ($upload || $remote || $local)) {
            throw ValidationException::withMessages([$field => 'Choose a photo from this website or upload an image.']);
        }

        return $upload ? $safe : $url;
    }

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
        $siteVersion = TenantSiteVersion::query()->whereKey($site->published_site_version_id)
            ->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'published')->first();
        if ($siteVersion && (in_array(data_get($siteVersion->settings, 'logo_url'), $urls, true)
            || in_array(data_get($siteVersion->seo, 'social_image'), $urls, true))) {
            return true;
        }
        $versions = TenantSitePageVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)
            ->where('status', 'published')->where(fn ($query) => $query->where('blocks', 'like', '%website-media%')->orWhere('seo', 'like', '%website-media%'))
            ->whereIn('id', TenantSitePage::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->select('published_version_id'))
            ->cursor();
        foreach ($versions as $version) {
            if (in_array(data_get($version->seo, 'social_image'), $urls, true)) {
                return true;
            }
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
