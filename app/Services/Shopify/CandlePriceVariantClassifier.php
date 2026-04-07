<?php

namespace App\Services\Shopify;

class CandlePriceVariantClassifier
{
    /**
     * @return array<string,string>
     */
    public function priceMap(): array
    {
        return [
            '4 oz candle' => '14.00',
            '8 oz candle' => '20.00',
            '16 oz candle' => '30.00',
            'wax melt' => '7.00',
            '8 oz wood wick candle' => '22.00',
            '16 oz wood wick candle' => '32.00',
        ];
    }

    /**
     * @param  array<string,mixed>  $product
     * @param  array<string,mixed>  $variant
     * @return array{
     *   detected_category:?string,
     *   new_price:?string,
     *   reason:string
     * }
     */
    public function classify(array $product, array $variant): array
    {
        $productTitle = $this->normalizeText($product['title'] ?? null);
        $productType = $this->normalizeText($product['productType'] ?? null);
        $handle = $this->normalizeText($product['handle'] ?? null);
        $variantTitle = $this->normalizeText($variant['title'] ?? null);
        $tags = $this->normalizeTags($product['tags'] ?? []);
        $selectedOptions = $this->normalizeSelectedOptions($variant['selectedOptions'] ?? []);

        $productClassifierText = trim(implode(' ', array_filter([
            $productTitle,
            $productType,
            implode(' ', $tags),
        ])));

        $variantText = trim(implode(' ', array_filter([
            $variantTitle,
            implode(' ', $selectedOptions),
        ])));

        $exclusionText = trim(implode(' ', array_filter([
            $productClassifierText,
            $handle,
            $variantText,
        ])));

        if ($this->containsAnyPhrase($exclusionText, [
            'subscription',
            'candle club',
            'mug-club',
            'monthly candle subscription',
        ])) {
            return $this->excluded('Candle Club subscription');
        }

        if ($this->containsAnyPhrase($exclusionText, ['wholesale'])) {
            return $this->excluded('wholesale item / non-target pricing context');
        }

        if ($this->containsAnyPhrase($exclusionText, [
            'room spray',
            'room sprays',
            'linen spray',
            'linen sprays',
        ])) {
            return $this->excluded('room spray');
        }

        if ($this->containsAnyPhrase($exclusionText, [
            'bundle',
            'bundles',
            'kit',
            'kits',
            'flight',
            'flights',
            'sampler',
            'gift set',
            'sample pack',
        ])) {
            return $this->excluded('bundle or multi-item set');
        }

        if ($this->isExplicitWaxMelt($variantText, $productTitle, $productType)) {
            return $this->matched('wax melt');
        }

        $candleConfidence = $this->containsWord($productType, 'candle')
            || $this->containsWord($productTitle, 'candle')
            || $this->containsAnyPhrase($productClassifierText, ['cotton wick', 'wood wick', 'cedar wick'])
            || $this->containsAnyPhrase($variantText, ['cotton wick', 'wood wick', 'cedar wick']);

        if (! $candleConfidence) {
            $possibleCandle = $this->containsWord($handle, 'candle')
                || $this->containsWord($productTitle, 'soy')
                || $this->containsWord($productType, 'soy')
                || $this->containsWord($variantText, 'wick');

            return $this->excluded($possibleCandle ? 'ambiguous candle identification' : 'non-candle item');
        }

        $size = $this->resolveSize($variantText, $productTitle);
        if ($size === null) {
            return $this->excluded('ambiguous size');
        }

        $woodWick = $this->containsAnyPhrase(trim($productTitle.' '.$variantText), [
            'wood wick',
            'cedar wick',
        ]);

        if ($woodWick) {
            return match ($size) {
                '8' => $this->matched('8 oz wood wick candle'),
                '16' => $this->matched('16 oz wood wick candle'),
                default => $this->excluded('wood wick size not mapped'),
            };
        }

        return match ($size) {
            '4' => $this->matched('4 oz candle'),
            '8' => $this->matched('8 oz candle'),
            '16' => $this->matched('16 oz candle'),
            default => $this->excluded('ambiguous size'),
        };
    }

    /**
     * @param  array<int,mixed>  $tags
     * @return array<int,string>
     */
    protected function normalizeTags(array $tags): array
    {
        return array_values(array_filter(array_map(
            fn ($tag): string => $this->normalizeText(is_scalar($tag) ? (string) $tag : null),
            $tags
        )));
    }

    /**
     * @param  array<int,mixed>  $selectedOptions
     * @return array<int,string>
     */
    protected function normalizeSelectedOptions(array $selectedOptions): array
    {
        $normalized = [];

        foreach ($selectedOptions as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = $this->normalizeText($option['name'] ?? null);
            $value = $this->normalizeText($option['value'] ?? null);
            $pair = trim(implode(' ', array_filter([$name, $value])));

            if ($pair !== '') {
                $normalized[] = $pair;
            }
        }

        return $normalized;
    }

    protected function normalizeText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = strtolower(trim((string) $value));
        $normalized = str_replace(['"', "'"], ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param  array<int,string>  $phrases
     */
    protected function containsAnyPhrase(string $haystack, array $phrases): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    protected function containsWord(string $haystack, string $word): bool
    {
        if ($haystack === '' || $word === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($word, '/').'\b/', $haystack) === 1;
    }

    protected function isExplicitWaxMelt(string $variantText, string $productTitle, string $productType): bool
    {
        if ($this->containsAnyPhrase($variantText, ['wax melt', 'wax melts'])) {
            return true;
        }

        if ($variantText === 'default title') {
            return $this->containsAnyPhrase($productTitle, ['wax melt', 'wax melts'])
                || $this->containsAnyPhrase($productType, ['wax melt', 'wax melts']);
        }

        return false;
    }

    protected function resolveSize(string $variantText, string $productTitle): ?string
    {
        $variantSizes = $this->detectSizes($variantText);
        if (count($variantSizes) === 1) {
            return $variantSizes[0];
        }

        if (count($variantSizes) > 1) {
            return null;
        }

        $productSizes = $this->detectSizes($productTitle);

        return count($productSizes) === 1
            ? $productSizes[0]
            : null;
    }

    /**
     * @return array<int,string>
     */
    protected function detectSizes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\b(4|8|16)\s*oz\b/', $text, $matches);

        $sizes = array_values(array_unique($matches[1] ?? []));
        sort($sizes);

        return $sizes;
    }

    /**
     * @return array{detected_category:?string,new_price:?string,reason:string}
     */
    protected function matched(string $category): array
    {
        return [
            'detected_category' => $category,
            'new_price' => $this->priceMap()[$category] ?? null,
            'reason' => '',
        ];
    }

    /**
     * @return array{detected_category:?string,new_price:?string,reason:string}
     */
    protected function excluded(string $reason): array
    {
        return [
            'detected_category' => null,
            'new_price' => null,
            'reason' => $reason,
        ];
    }
}
