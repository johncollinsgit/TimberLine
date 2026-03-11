<?php

namespace App\Services\Marketing;

class GrowaveCustomerMetafieldParser
{
    /**
     * @param  array<int,array{namespace:string,key:string,value:string,type:?string}>  $metafields
     * @return array{
     *   raw_metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>,
     *   points_balance:?int,
     *   vip_tier:?string,
     *   referral_link:?string
     * }
     */
    public function parse(array $metafields): array
    {
        $detected = [];

        foreach ($metafields as $metafield) {
            $normalized = $this->normalize($metafield);
            if ($normalized === null) {
                continue;
            }

            if (! $this->isGrowaveMetafield($normalized['namespace'], $normalized['key'])) {
                continue;
            }

            $detected[] = $normalized;
        }

        return [
            'raw_metafields' => $detected,
            'points_balance' => $this->extractPointsBalance($detected),
            'vip_tier' => $this->extractVipTier($detected),
            'referral_link' => $this->extractReferralLink($detected),
        ];
    }

    protected function normalize(array $metafield): ?array
    {
        $namespace = trim((string) ($metafield['namespace'] ?? ''));
        $key = trim((string) ($metafield['key'] ?? ''));
        if ($namespace === '' || $key === '') {
            return null;
        }

        return [
            'namespace' => $namespace,
            'key' => $key,
            'value' => (string) ($metafield['value'] ?? ''),
            'type' => $this->nullableString($metafield['type'] ?? null),
        ];
    }

    protected function isGrowaveMetafield(string $namespace, string $key): bool
    {
        $namespace = strtolower($namespace);
        $key = strtolower($key);

        $providerTokens = ['growave', 'ssw', 'socialshopwave'];
        foreach ($providerTokens as $token) {
            if (str_contains($namespace, $token) || str_contains($key, $token)) {
                return true;
            }
        }

        return $this->looksLikePointsKey($key)
            || $this->looksLikeVipKey($key)
            || $this->looksLikeReferralKey($key);
    }

    /**
     * @param  array<int,array{namespace:string,key:string,value:string,type:?string}>  $metafields
     */
    protected function extractPointsBalance(array $metafields): ?int
    {
        foreach ($metafields as $metafield) {
            if (! $this->looksLikePointsKey(strtolower($metafield['key']))) {
                continue;
            }

            $parsed = $this->parseNumericValue($metafield['value']);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{namespace:string,key:string,value:string,type:?string}>  $metafields
     */
    protected function extractVipTier(array $metafields): ?string
    {
        foreach ($metafields as $metafield) {
            if (! $this->looksLikeVipKey(strtolower($metafield['key']))) {
                continue;
            }

            $tier = $this->nullableString($metafield['value']);
            if ($tier !== null) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{namespace:string,key:string,value:string,type:?string}>  $metafields
     */
    protected function extractReferralLink(array $metafields): ?string
    {
        foreach ($metafields as $metafield) {
            if (! $this->looksLikeReferralKey(strtolower($metafield['key']))) {
                continue;
            }

            $value = $this->nullableString($metafield['value']);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function looksLikePointsKey(string $key): bool
    {
        return preg_match('/(loyalty.*points|points.*balance|points|point_balance)/', $key) === 1;
    }

    protected function looksLikeVipKey(string $key): bool
    {
        return preg_match('/(vip.*(tier|level)|tier.*vip)/', $key) === 1;
    }

    protected function looksLikeReferralKey(string $key): bool
    {
        return preg_match('/(referral.*(link|url|code)|invite.*link)/', $key) === 1;
    }

    protected function parseNumericValue(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach (['points', 'points_balance', 'balance', 'loyalty_points'] as $candidate) {
                $candidateValue = $decoded[$candidate] ?? null;
                if (is_numeric($candidateValue)) {
                    return (int) round((float) $candidateValue);
                }
            }
        }

        if (preg_match('/-?\d+(\.\d+)?/', $value, $matches) === 1) {
            return (int) round((float) $matches[0]);
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
