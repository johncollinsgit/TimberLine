<?php

use App\Services\Shopify\ModernForestryVariantMediaClassifier;

test('modern forestry variant media classifier normalizes size titles', function (string $title, string $canonical): void {
    $classifier = new ModernForestryVariantMediaClassifier();

    expect($classifier->classify($title))->toBe($canonical);
})->with([
    ['4oz', '4oz'],
    ['4 oz', '4oz'],
    ['4-ounce candle', '4oz'],
    ['4 ounce candle', '4oz'],
    ['8oz', '8oz'],
    ['8 oz Candle', '8oz'],
    ['8-ounce candle', '8oz'],
    ['16oz jar', '16oz'],
    ['16 oz Candle', '16oz'],
    ['16-ounce candle', '16oz'],
    ['8oz Wood Wick', 'wood_wick_8oz'],
    ['8 oz cedar wick', 'wood_wick_8oz'],
    ['8-ounce wooden wick candle', 'wood_wick_8oz'],
    ['16oz Wood Wick', 'wood_wick_16oz'],
    ['16 oz cedar wick', 'wood_wick_16oz'],
    ['16-ounce wooden wick candle', 'wood_wick_16oz'],
]);

test('modern forestry variant media classifier normalizes wax melt aliases', function (string $title): void {
    $classifier = new ModernForestryVariantMediaClassifier();

    expect($classifier->classify($title))->toBe('wax_melt');
})->with([
    'wax melt',
    'wax melts',
    'melt',
    'melts',
    'wax tart',
    'wax tarts',
    'soy tart',
    'soy tarts',
]);

test('modern forestry variant media classifier handles ambiguity and unknowns', function (): void {
    $classifier = new ModernForestryVariantMediaClassifier();

    expect($classifier->classify('4 oz / 8 oz sampler'))->toBeNull()
        ->and($classifier->isAmbiguous('4 oz / 8 oz sampler'))->toBeTrue()
        ->and($classifier->classify('room spray'))->toBeNull()
        ->and($classifier->isAmbiguous('room spray'))->toBeFalse()
        ->and($classifier->classify('8 oz wax melt'))->toBe('wax_melt');
});
