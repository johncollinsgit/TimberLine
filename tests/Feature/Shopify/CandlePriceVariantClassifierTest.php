<?php

use App\Services\Shopify\CandlePriceVariantClassifier;

it('classifies standard retail candle sizes from variant titles', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Coffeehouse',
            'handle' => 'coffeehouse',
            'productType' => 'Soy Candles',
            'tags' => ['Retail Priced'],
        ],
        [
            'title' => '8oz Cotton Wick',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBe('8 oz candle')
        ->and($result['new_price'])->toBe('20.00')
        ->and($result['reason'])->toBe('');
});

it('treats cedar wick variants as wood wick candles', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Lavender',
            'handle' => 'lavender',
            'productType' => 'Soy Candles',
            'tags' => ['Retail Priced'],
        ],
        [
            'title' => '16oz Cedar Wick',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBe('16 oz wood wick candle')
        ->and($result['new_price'])->toBe('32.00');
});

it('excludes candle club subscriptions before matching wax melts or sizes', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Monthly Candle Subscription',
            'handle' => 'monthly-candle-subscription',
            'productType' => 'Soy Candles',
            'tags' => ['candle subscription'],
        ],
        [
            'title' => 'Wax Melt / Scent of the Month',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBeNull()
        ->and($result['new_price'])->toBeNull()
        ->and($result['reason'])->toBe('Candle Club subscription');
});

it('excludes wholesale-context candles from the retail pricing pass', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Wholesale Citronella',
            'handle' => 'wholesale-citronella',
            'productType' => 'Wholesale',
            'tags' => ['wholesale'],
        ],
        [
            'title' => '16oz Cotton Wick',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBeNull()
        ->and($result['reason'])->toBe('wholesale item / non-target pricing context');
});

it('uses product title as a size fallback when the variant title is generic', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Holiday Glow 4oz Candle',
            'handle' => 'holiday-glow-4oz-candle',
            'productType' => '',
            'tags' => [],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBe('4 oz candle')
        ->and($result['new_price'])->toBe('14.00');
});

it('excludes bundle-style products from the single-candle pricing pass', function (): void {
    $classifier = app(CandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Winter Bundle (3 Candles)',
            'handle' => 'winter-bundle-3-candles',
            'productType' => 'Soy Candles',
            'tags' => ['Retail Priced'],
        ],
        [
            'title' => '16oz Cotton Wick',
            'selectedOptions' => [],
        ]
    );

    expect($result['detected_category'])->toBeNull()
        ->and($result['reason'])->toBe('bundle or multi-item set');
});
