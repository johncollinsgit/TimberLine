<?php

use App\Services\Shopify\WholesaleCandlePriceVariantClassifier;

it('matches wholesale wax melts and cedar wick variants from explicit variant titles', function (): void {
    $classifier = app(WholesaleCandlePriceVariantClassifier::class);

    $waxMelt = $classifier->classify(
        [
            'title' => 'Wholesale Coffeehouse',
            'handle' => 'wholesale-coffeehouse',
            'productType' => 'Wholesale',
            'tags' => ['wholesale'],
        ],
        [
            'title' => 'Wax Melt',
            'selectedOptions' => [],
        ]
    );

    $woodWick = $classifier->classify(
        [
            'title' => 'Wholesale Coffeehouse',
            'handle' => 'wholesale-coffeehouse',
            'productType' => 'Wholesale',
            'tags' => ['wholesale'],
        ],
        [
            'title' => '8oz Cedar Wick',
            'selectedOptions' => [],
        ]
    );

    expect($waxMelt['detected_category'])->toBe('wax melt')
        ->and($waxMelt['new_price'])->toBe('3.50')
        ->and($woodWick['detected_category'])->toBe('8 oz wood wick candle')
        ->and($woodWick['new_price'])->toBe('11.00');
});

it('matches explicit option values even when the product type is blank', function (): void {
    $classifier = app(WholesaleCandlePriceVariantClassifier::class);

    $result = $classifier->classify(
        [
            'title' => 'Wholesale Vanilla',
            'handle' => 'wholesale-vanilla',
            'productType' => '',
            'tags' => ['Classic Collection', 'wholesale'],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [
                ['name' => 'Size', 'value' => '16oz Cotton Wick'],
            ],
        ]
    );

    expect($result['detected_category'])->toBe('16 oz candle')
        ->and($result['new_price'])->toBe('15.00');
});

it('excludes wholesale room sprays flights one-click orders and add-ons', function (): void {
    $classifier = app(WholesaleCandlePriceVariantClassifier::class);

    $roomSpray = $classifier->classify(
        [
            'title' => 'Wholesale Room Sprays',
            'handle' => 'new-wholesale-room-spray',
            'productType' => 'Wholesale',
            'tags' => ['wholesale', 'Wholesale Room Sprays'],
        ],
        [
            'title' => 'Appalachian Maple Bourbon',
            'selectedOptions' => [],
        ]
    );

    $flight = $classifier->classify(
        [
            'title' => 'Wholesale Autumn Flight',
            'handle' => 'wholesale-autumn-flight',
            'productType' => 'Wholesale',
            'tags' => [],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [],
        ]
    );

    $oneClick = $classifier->classify(
        [
            'title' => 'Wholesale One-Click Order (8oz & 16oz)',
            'handle' => 'copy-of-wholesale-one-click-order-8oz-16oz',
            'productType' => 'Wholesale',
            'tags' => ['SingleQuantity'],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [],
        ]
    );

    $oneClickWithFlightHandle = $classifier->classify(
        [
            'title' => 'Wholesale One-Click Order (4oz & 8oz)',
            'handle' => 'copy-of-wholesale-classic-flight',
            'productType' => 'Wholesale',
            'tags' => ['SingleQuantity'],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [],
        ]
    );

    $service = $classifier->classify(
        [
            'title' => 'Use My Custom Label',
            'handle' => 'use-my-custom-label',
            'productType' => 'Wholesale',
            'tags' => ['SingleQuantity'],
        ],
        [
            'title' => 'Default Title',
            'selectedOptions' => [],
        ]
    );

    expect($roomSpray['reason'])->toBe('room spray')
        ->and($flight['reason'])->toBe('audit separately: wholesale flight product')
        ->and($oneClick['reason'])->toBe('audit separately: wholesale one-click order SKU')
        ->and($oneClickWithFlightHandle['reason'])->toBe('audit separately: wholesale one-click order SKU')
        ->and($service['reason'])->toBe('non-candle service/add-on');
});
