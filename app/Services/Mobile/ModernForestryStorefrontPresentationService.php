<?php

namespace App\Services\Mobile;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Read only presentation from Modern Forestry's published retail homepage. */
class ModernForestryStorefrontPresentationService
{
    public function snapshot(): array
    {
        if (! config('mobile_catalog.website_sync_enabled', true)) {
            return [];
        }

        // A quarter boundary never reuses the previous season's short-lived snapshot.
        $season = now('America/New_York')->format('Y').'-'.now('America/New_York')->quarter;

        return Cache::remember('modern_forestry:storefront_presentation:v1:'.$season, 120, function (): array {
            try {
                $response = Http::timeout(4)->get(ModernForestryMobileProductCatalogService::STOREFRONT_BASE_URL.'/');
                if ($response->successful() && strlen($response->body()) <= 2_000_000) {
                    $snapshot = $this->parse($response->body());
                    if ($snapshot['slides'] !== [] && $snapshot['collectionHandles'] !== []) {
                        Cache::put('modern_forestry:storefront_presentation:last_good', $snapshot, 86400);

                        return $snapshot;
                    }
                }
            } catch (Throwable) {
                // Preserve usable presentation during a brief storefront outage.
            }

            return Cache::get('modern_forestry:storefront_presentation:last_good', []);
        });
    }

    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $slides = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " Slideshow__Slide ")]') as $slide) {
            $heading = $xpath->query('.//h2', $slide)->item(0);
            $images = $xpath->query('.//img', $slide);
            $image = null;
            foreach ($images as $candidate) {
                if (! $candidate instanceof DOMElement) {
                    continue;
                }
                $image = $this->url($candidate->getAttribute('data-src') ?: $candidate->getAttribute('src'));
                if ($image !== null) {
                    break;
                }
            }
            $title = trim($heading?->textContent ?? '');
            if ($title === '' || $image === null) {
                continue;
            }
            $links = $xpath->query('.//a[@href]', $slide);
            $first = $links->item(0);
            $second = $links->item(1);
            $subtitle = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " SectionHeader__SubHeading ")]', $slide)->item(0);
            $slides[] = [
                'id' => 'website-'.($slide->getAttribute('id') ?: count($slides)),
                'title' => $title,
                'subtitle' => trim($subtitle?->textContent ?? '') ?: null,
                'imageUrl' => $image,
                'mobileImageUrl' => $image,
                'ctaTitle' => trim($first?->textContent ?? '') ?: null,
                'ctaUrl' => $first instanceof DOMElement ? $this->url($first->getAttribute('href')) : null,
                'secondaryCtaTitle' => trim($second?->textContent ?? '') ?: null,
                'secondaryCtaUrl' => $second instanceof DOMElement ? $this->url($second->getAttribute('href')) : null,
            ];
        }
        $handles = [];
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " CollectionItem ")]') as $link) {
            if (preg_match('~/collections/([^/?#]+)~', $link->getAttribute('href'), $matches)) {
                $handles[] = rawurldecode($matches[1]);
            }
        }

        return ['slides' => $slides, 'collectionHandles' => array_values(array_unique($handles))];
    }

    private function url(string $value): ?string
    {
        $value = str_replace('{width}', '1200', trim($value));
        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        }
        if (str_starts_with($value, '/')) {
            $value = ModernForestryMobileProductCatalogService::STOREFRONT_BASE_URL.$value;
        }
        $parts = parse_url($value);

        return ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']) ? $value : null;
    }
}
