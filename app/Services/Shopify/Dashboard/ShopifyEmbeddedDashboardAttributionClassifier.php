<?php

namespace App\Services\Shopify\Dashboard;

class ShopifyEmbeddedDashboardAttributionClassifier
{
    /**
     * @param  array<string,mixed>  $context
     * @return array{channel:string,confidence:string,matchedBy:?string,matchedValue:?string}
     */
    public function classify(array $context): array
    {
        $explicitChannel = $this->normalizeScalar($context['explicitChannel'] ?? null);
        if (in_array($explicitChannel, ['text', 'email'], true)) {
            return [
                'channel' => $explicitChannel,
                'confidence' => 'high',
                'matchedBy' => 'explicit_channel',
                'matchedValue' => $explicitChannel,
            ];
        }

        $utmSources = $this->normalizedStrings($this->signalValues($context, [
            'utm_source',
            'utmSource',
            'utm-source',
        ]));
        $utmMediums = $this->normalizedStrings($this->signalValues($context, [
            'utm_medium',
            'utmMedium',
            'utm-medium',
        ]));
        $utmCampaigns = $this->normalizedStrings($this->signalValues($context, [
            'utm_campaign',
            'utmCampaign',
            'utm-campaign',
        ]));
        $utmContents = $this->normalizedStrings($this->signalValues($context, [
            'utm_content',
            'utmContent',
            'utm-content',
        ]));
        $utmTerms = $this->normalizedStrings($this->signalValues($context, [
            'utm_term',
            'utmTerm',
            'utm-term',
        ]));
        $sourceTypes = $this->normalizedStrings($this->signalValues($context, [
            'source_type',
            'sourceType',
        ]));
        $sourceNames = $this->normalizedStrings($this->signalValues($context, [
            'source',
            'source_name',
            'sourceName',
            'source_channel',
            'sourceChannel',
            'source_surface',
            'sourceSurface',
            'provider',
            'campaign_type',
            'campaignType',
            'signup_source',
            'signupSource',
            'endpoint',
        ]));
        $referrerDomains = $this->normalizedDomains($this->signalValues($context, [
            'referrer',
            'referer',
            'referring_site',
            'referringSite',
            'referrer_url',
            'referrerUrl',
            'referring_url',
            'referringUrl',
            'landing_referrer',
            'landingReferrer',
        ]));
        $landingDomains = $this->normalizedDomains($this->signalValues($context, [
            'landing_site',
            'landingSite',
            'landing_page',
            'landingPage',
            'landing_url',
            'landingUrl',
            'source_url',
            'sourceUrl',
        ]));

        $searchPool = array_values(array_unique(array_merge(
            $utmSources,
            $utmMediums,
            $utmCampaigns,
            $utmContents,
            $utmTerms,
            $sourceTypes,
            $sourceNames,
            $referrerDomains,
            $landingDomains
        )));

        if ($match = $this->firstMatch($searchPool, [
            'sms',
            'text',
            'postscript',
            'attentive',
            'tapcart-sms',
            'klaviyo_sms',
        ])) {
            return $this->match('text', 'high', 'text_signal', $match);
        }

        if ($match = $this->firstMatch($searchPool, [
            'email',
            'e-mail',
            'klaviyo',
            'mailchimp',
            'campaign_monitor',
            'sendgrid',
            'customerio',
        ])) {
            return $this->match('email', 'high', 'email_signal', $match);
        }

        if ($match = $this->firstHostMatch($referrerDomains, [
            'instagram.com',
            'l.instagram.com',
        ])) {
            return $this->match('instagram', 'high', 'referrer_domain', $match);
        }

        if ($match = $this->firstKeywordMatch(array_merge($utmSources, $sourceNames, $utmCampaigns), [
            'instagram',
            'ig',
            'insta',
        ])) {
            return $this->match('instagram', 'medium', 'instagram_signal', $match);
        }

        if ($match = $this->firstHostMatch($referrerDomains, [
            'facebook.com',
            'm.facebook.com',
            'l.facebook.com',
            'fb.com',
        ])) {
            return $this->match('facebook', 'high', 'referrer_domain', $match);
        }

        if ($match = $this->firstKeywordMatch(array_merge($utmSources, $sourceNames, $utmCampaigns), [
            'facebook',
            'fb',
            'meta',
            'paid-social',
            'paid_social',
        ])) {
            return $this->match('facebook', 'medium', 'facebook_signal', $match);
        }

        if ($match = $this->firstHostMatch($referrerDomains, [
            'google.com',
            'www.google.com',
            'googleadservices.com',
            'g.doubleclick.net',
        ])) {
            return $this->match('google', 'high', 'referrer_domain', $match);
        }

        if (
            ($match = $this->firstKeywordMatch($utmSources, [
                'google',
                'google-shopping',
                'google_shopping',
                'adwords',
                'gads',
            ]))
            || ($match = $this->firstKeywordMatch($sourceNames, [
                'google',
                'google-shopping',
                'google_shopping',
                'adwords',
                'gads',
            ]))
            || ($this->containsAny($utmMediums, ['cpc', 'ppc', 'paidsearch', 'paid_search', 'shopping', 'search'])
                && $this->containsAny(array_merge($utmSources, $sourceNames, $referrerDomains), ['google', 'googleadservices', 'adwords', 'gads']))
        ) {
            return $this->match('google', 'medium', 'google_signal', is_string($match) ? $match : 'google');
        }

        if ($match = $this->firstMatch(array_merge($utmSources, $utmMediums, $sourceNames, $referrerDomains), [
            '(direct)',
            'direct',
            '(none)',
            'none',
            'no_referrer',
            'no-referrer',
        ])) {
            return $this->match('direct', 'high', 'direct_signal', $match);
        }

        if ($match = $this->firstMatch(array_merge($sourceTypes, $sourceNames, $utmSources, $utmCampaigns), [
            'referral',
            'birthday',
            'review',
            'growave',
            'event',
            'import',
            'widget',
            'shopify',
            'wholesale',
            'retail',
            'manual',
        ])) {
            return $this->match('other', 'medium', 'recognized_other_signal', $match);
        }

        if ($searchPool !== []) {
            return $this->match('other', 'low', 'unmapped_signal', $searchPool[0]);
        }

        return $this->match('unknown', 'low', null, null);
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  array<int,string>  $keys
     * @return array<int,mixed>
     */
    protected function signalValues(array $value, array $keys): array
    {
        $signals = [];

        foreach ($keys as $key) {
            $signal = data_get($value, $key);
            if ($signal !== null && $signal !== '') {
                $signals[] = $signal;
            }
        }

        foreach ((array) ($value['sourceMeta'] ?? []) as $metaKey => $metaValue) {
            if (in_array((string) $metaKey, $keys, true) && $metaValue !== null && $metaValue !== '') {
                $signals[] = $metaValue;
            }

            if (is_array($metaValue)) {
                $signals = array_merge($signals, $this->signalValues($metaValue, $keys));
            }
        }

        return $signals;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function normalizedStrings(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                $normalized = array_merge($normalized, $this->normalizedStrings(array_values($value)));
                continue;
            }

            $string = $this->normalizeScalar($value);
            if ($string !== null) {
                $normalized[] = $string;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function normalizedDomains(array $values): array
    {
        $domains = [];

        foreach ($values as $value) {
            foreach ($this->normalizedStrings([$value]) as $candidate) {
                $domains[] = $this->normalizeDomain($candidate);
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    protected function normalizeDomain(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        if (! str_contains($candidate, '://') && str_contains($candidate, '.')) {
            $candidate = 'https://' . ltrim($candidate, '/');
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return strtolower(trim($value, '/'));
    }

    protected function normalizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    protected function containsAny(array $haystack, array $needles): bool
    {
        return $this->firstMatch($haystack, $needles) !== null;
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    protected function firstMatch(array $haystack, array $needles): ?string
    {
        foreach ($haystack as $value) {
            foreach ($needles as $needle) {
                if ($value === $needle || str_contains($value, $needle)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    protected function firstKeywordMatch(array $haystack, array $needles): ?string
    {
        foreach ($haystack as $value) {
            foreach ($needles as $needle) {
                $pattern = '/(^|[\\s_:\\-\\/\\.])' . preg_quote($needle, '/') . '([\\s_:\\-\\/\\.]|$)/';
                if ($value === $needle || preg_match($pattern, $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $domains
     * @param  array<int,string>  $needles
     */
    protected function firstHostMatch(array $domains, array $needles): ?string
    {
        foreach ($domains as $domain) {
            foreach ($needles as $needle) {
                if ($domain === $needle || str_ends_with($domain, '.' . $needle)) {
                    return $domain;
                }
            }
        }

        return null;
    }

    /**
     * @return array{channel:string,confidence:string,matchedBy:?string,matchedValue:?string}
     */
    protected function match(string $channel, string $confidence, ?string $matchedBy, ?string $matchedValue): array
    {
        return [
            'channel' => $channel,
            'confidence' => $confidence,
            'matchedBy' => $matchedBy,
            'matchedValue' => $matchedValue,
        ];
    }
}
