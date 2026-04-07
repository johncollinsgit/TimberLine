<?php

namespace App\Services\Shopify;

class WholesaleCandlePriceVariantClassifier extends CandlePriceVariantClassifier
{
    /**
     * @return array<string,string>
     */
    public function priceMap(): array
    {
        return [
            '4 oz candle' => '7.00',
            '8 oz candle' => '10.00',
            '16 oz candle' => '15.00',
            'wax melt' => '3.50',
            '8 oz wood wick candle' => '11.00',
            '16 oz wood wick candle' => '16.00',
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
        $handle = $this->normalizeText($product['handle'] ?? null);
        $productType = $this->normalizeText($product['productType'] ?? null);
        $tags = $this->normalizeTags($product['tags'] ?? []);
        $variantTitle = $this->normalizeText($variant['title'] ?? null);
        $optionValues = $this->normalizeOptionValues($variant['selectedOptions'] ?? []);

        $productText = trim(implode(' ', array_filter([
            $productTitle,
            $handle,
            $productType,
            implode(' ', $tags),
        ])));

        if ($this->containsAnyPhrase($productText, [
            'room spray',
            'room sprays',
        ])) {
            return $this->excluded('room spray');
        }

        if ($this->containsAnyPhrase($productText, [
            'one click',
            'oneclick',
        ])) {
            return $this->excluded('audit separately: wholesale one-click order SKU');
        }

        if ($this->containsAnyPhrase($productText, [
            'flight',
            'flights',
        ])) {
            return $this->excluded('audit separately: wholesale flight product');
        }

        if ($this->containsAnyPhrase($productText, [
            'custom label',
            'use my custom label',
            'barcodes',
            'barcode',
            'label set up',
            'label setup',
        ])) {
            return $this->excluded('non-candle service/add-on');
        }

        $normalizedCandidates = array_values(array_filter(array_unique(array_merge(
            [$variantTitle],
            $optionValues,
        ))));

        foreach ($normalizedCandidates as $candidate) {
            $category = $this->explicitCategoryForVariant($candidate);
            if ($category !== null) {
                return $this->matched($category);
            }
        }

        return $this->excluded('did not match explicit wholesale candle rule');
    }

    /**
     * @param  array<int,mixed>  $selectedOptions
     * @return array<int,string>
     */
    protected function normalizeOptionValues(array $selectedOptions): array
    {
        $values = [];

        foreach ($selectedOptions as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = $this->normalizeText($option['value'] ?? null);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    protected function explicitCategoryForVariant(string $normalizedVariant): ?string
    {
        return match ($normalizedVariant) {
            'wax melt' => 'wax melt',
            '4oz cotton wick', '4 oz cotton wick' => '4 oz candle',
            '8oz cotton wick', '8 oz cotton wick' => '8 oz candle',
            '16oz cotton wick', '16 oz cotton wick' => '16 oz candle',
            '8oz cedar wick', '8 oz cedar wick' => '8 oz wood wick candle',
            '16oz cedar wick', '16 oz cedar wick' => '16 oz wood wick candle',
            default => null,
        };
    }
}
