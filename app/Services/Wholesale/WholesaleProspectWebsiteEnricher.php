<?php

namespace App\Services\Wholesale;

use App\Models\TenantDiscoveryProfile;
use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectEvidence;
use DOMDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class WholesaleProspectWebsiteEnricher
{
    /** @return array{enriched:bool,reason:?string} */
    public function enrich(WholesaleProspect $prospect): array
    {
        $url = $this->safePublicUrl((string) $prospect->website);
        if ($url === null) {
            return ['enriched' => false, 'reason' => 'Website URL was missing or not safe for controlled retrieval.'];
        }

        try {
            if (! $this->robotsAllows($url)) {
                return ['enriched' => false, 'reason' => 'Website robots directives do not allow enrichment.'];
            }

            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->retry(2, 250, throw: false)
                ->withHeaders(['User-Agent' => 'EverbranchProspectResearch/1.0 (+https://everbranch.com)'])
                ->get($url);
            if (! $response->successful()) {
                return ['enriched' => false, 'reason' => 'Website did not return a successful response.'];
            }

            $html = Str::limit($response->body(), 1_000_000, '');
            $profile = TenantDiscoveryProfile::query()
                ->where('tenant_id', (int) $prospect->tenant_id)
                ->where('is_active', true)
                ->first();
            $signals = $this->extractSignals($html, $url, $profile);
            $summary = $this->summary($signals);
            $fit = $this->updatedFit((array) $prospect->fit_explanation, $signals);
            $brandLabel = trim((string) ($profile?->wholesale_brand_label ?: $profile?->primary_brand_name));
            $positioningSubject = $brandLabel !== '' ? $brandLabel.' products' : 'the tenant’s wholesale products';

            $prospect->forceFill([
                'public_business_email' => $prospect->public_business_email ?: $signals['email'],
                'contact_form_url' => $prospect->contact_form_url ?: $signals['contact_url'],
                'instagram_handle' => $prospect->instagram_handle ?: $signals['instagram_handle'],
                'facebook_page' => $prospect->facebook_page ?: $signals['facebook_page'],
                'fit_score' => $fit['score'],
                'fit_confidence' => $fit['confidence'],
                'fit_explanation' => $fit,
                'suggested_product_positioning' => $signals['merchandise_signals'] !== []
                    ? 'Position '.$positioningSubject.' against the observed '.implode(', ', $signals['merchandise_signals']).' assortment; verify buyer priorities first.'
                    : null,
                'suggested_opening_message_topic' => $signals['vendor_inquiries_restricted']
                    ? 'Do not initiate vendor outreach; ask an authorized operator to review the restriction.'
                    : 'Reference the public merchandise assortment and ask whether the buyer is open to reviewing products aligned with it.',
                'source_snapshot' => array_merge((array) $prospect->source_snapshot, [
                    'website_enrichment' => [
                        'signals' => $signals['merchandise_signals'],
                        'online_shop' => $signals['online_shop'],
                        'multiple_locations' => $signals['multiple_locations'],
                        'vendor_inquiries_restricted' => $signals['vendor_inquiries_restricted'],
                        'observed_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();

            WholesaleProspectEvidence::query()->create([
                'tenant_id' => (int) $prospect->tenant_id,
                'wholesale_prospect_id' => (int) $prospect->id,
                'source_type' => 'public_website',
                'source_url' => $url,
                'signal_type' => 'website_merchandise_review',
                'summary' => $summary,
                'supports_fit' => $signals['merchandise_signals'] !== [],
                'observed_at' => now(),
                'source_reference' => ['url' => $url, 'retrieved_at' => now()->toIso8601String()],
            ]);

            return ['enriched' => true, 'reason' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['enriched' => false, 'reason' => 'Website enrichment failed without changing prospect status.'];
        }
    }

    protected function robotsAllows(string $url): bool
    {
        $parts = parse_url($url);
        $robotsUrl = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/robots.txt';
        $response = Http::timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'EverbranchProspectResearch/1.0 (+https://everbranch.com)'])
            ->get($robotsUrl);

        if (! $response->successful()) {
            return true;
        }

        $applies = false;
        foreach (preg_split('/\R/', Str::lower($response->body())) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (str_starts_with($line, 'user-agent:')) {
                $agent = trim(substr($line, 11));
                $applies = in_array($agent, ['*', 'everbranchprospectresearch'], true);
            } elseif ($applies && preg_match('/^disallow:\s*\/$/', $line) === 1) {
                return false;
            }
        }

        return true;
    }

    protected function safePublicUrl(string $value): ?string
    {
        $url = filter_var(trim($value), FILTER_VALIDATE_URL);
        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if (! $url || ! in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $addresses = gethostbynamel($host);
        if ($addresses === false || collect($addresses)->contains(fn (string $address): bool => ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }

        return $url;
    }

    /** @return array<string,mixed> */
    protected function extractSignals(string $html, string $baseUrl, ?TenantDiscoveryProfile $profile = null): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        $text = Str::lower(preg_replace('/\s+/', ' ', $document->textContent) ?: '');
        $links = collect($document->getElementsByTagName('a'))->map(function ($node): array {
            return ['href' => trim((string) $node->getAttribute('href')), 'text' => Str::lower(trim((string) $node->textContent))];
        });

        $signalTerms = [
            'retail assortment' => ['shop', 'store', 'retail'],
            'independent brands' => ['independent brand', 'small batch'],
            'locally made products' => ['locally made', 'local makers', 'made in'],
        ];
        $configuredSignals = array_merge(
            (array) $profile?->brand_keywords,
            (array) data_get($profile?->merchant_signals, 'product_categories', []),
            (array) data_get($profile?->merchant_signals, 'brand_descriptors', []),
            (array) data_get($profile?->merchant_signals, 'best_fit_descriptors', [])
        );
        foreach ($configuredSignals as $configuredSignal) {
            $label = Str::lower(trim((string) $configuredSignal));
            if ($label !== '') {
                $signalTerms[$label] = [$label];
            }
        }
        $signals = collect($signalTerms)->filter(fn (array $terms): bool => collect($terms)->contains(fn (string $term): bool => str_contains($text, $term)))->keys()->values()->all();
        $emailHref = (string) data_get($links->first(fn (array $link): bool => str_starts_with($link['href'], 'mailto:')), 'href', '');
        $contactHref = (string) data_get($links->first(fn (array $link): bool => str_contains($link['text'], 'contact') || str_contains($link['href'], '/contact')), 'href', '');
        $instagram = (string) data_get($links->first(fn (array $link): bool => str_contains($link['href'], 'instagram.com/')), 'href', '');
        $facebook = (string) data_get($links->first(fn (array $link): bool => str_contains($link['href'], 'facebook.com/')), 'href', '');

        return [
            'merchandise_signals' => $signals,
            'online_shop' => str_contains($text, 'shop online') || $links->contains(fn (array $link): bool => str_contains($link['href'], '/shop') || str_contains($link['href'], '/collections')),
            'multiple_locations' => str_contains($text, 'our locations') || str_contains($text, 'locations'),
            'vendor_inquiries_restricted' => str_contains($text, 'do not accept vendor') || str_contains($text, 'no vendor inquiries'),
            'email' => $emailHref !== '' ? Str::lower(rawurldecode(explode('?', substr($emailHref, 7), 2)[0])) : null,
            'contact_url' => $this->absoluteUrl($contactHref, $baseUrl),
            'instagram_handle' => $instagram !== '' ? trim((string) parse_url($instagram, PHP_URL_PATH), '/') : null,
            'facebook_page' => $facebook !== '' ? $facebook : null,
        ];
    }

    protected function absoluteUrl(string $href, string $baseUrl): ?string
    {
        if ($href === '') {
            return null;
        }
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        $parts = parse_url($baseUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/'.ltrim($href, '/');
    }

    protected function summary(array $signals): string
    {
        $parts = $signals['merchandise_signals'] === []
            ? ['No target merchandise terms were confirmed on the retrieved page.']
            : ['Public website references '.implode(', ', $signals['merchandise_signals']).'.'];
        if ($signals['online_shop']) {
            $parts[] = 'An online shop link was observed.';
        }
        if ($signals['multiple_locations']) {
            $parts[] = 'The site references multiple locations.';
        }
        if ($signals['vendor_inquiries_restricted']) {
            $parts[] = 'The site states that vendor inquiries are restricted.';
        }

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> */
    protected function updatedFit(array $fit, array $signals): array
    {
        $positive = array_values((array) ($fit['positive_signals'] ?? []));
        $negative = array_values((array) ($fit['negative_signals'] ?? []));
        $score = (int) ($fit['score'] ?? 0);
        if ($signals['merchandise_signals'] !== []) {
            $score += min(20, count($signals['merchandise_signals']) * 4);
            $positive[] = 'Public website merchandise evidence includes '.implode(', ', $signals['merchandise_signals']).'.';
        }
        if ($signals['online_shop']) {
            $score += 5;
            $positive[] = 'The public website includes an online shop.';
        }
        if ($signals['multiple_locations']) {
            $score += 5;
            $positive[] = 'The public website references multiple locations.';
        }
        if ($signals['vendor_inquiries_restricted']) {
            $score -= 25;
            $negative[] = 'The public website states that vendor inquiries are restricted.';
        }

        return array_merge($fit, [
            'score' => max(0, min(100, $score)),
            'confidence' => min(100, (int) ($fit['confidence'] ?? 0) + 10),
            'positive_signals' => array_values(array_unique($positive)),
            'negative_signals' => array_values(array_unique($negative)),
            'evaluated_at' => now()->toIso8601String(),
        ]);
    }
}
