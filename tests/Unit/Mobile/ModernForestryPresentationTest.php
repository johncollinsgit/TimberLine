<?php

use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use App\Services\Mobile\ModernForestryStorefrontPresentationService;

it('reads the default website banner and collection order without using thumbnails', function (): void {
    $html = '<div class="Slideshow__Slide" id="Slidefall"><img src="//theforestrystudio.com/fall_1x1.jpg?v=2" data-src="//theforestrystudio.com/fall_x800.jpg?v=2"><h2>Shop our Fall Collection</h2><a href="/collections/autumn-collection">Click to Shop</a></div><a class="CollectionItem" href="/collections/autumn-collection"></a><a class="CollectionItem" href="/collections/winter-collection"></a><a class="CollectionItem" href="/collections/holiday-collection"></a>';
    $payload = (new ModernForestryStorefrontPresentationService)->parse($html);
    expect($payload['slides'][0]['title'])->toBe('Shop our Fall Collection')
        ->and($payload['slides'][0]['imageUrl'])->toBe('https://theforestrystudio.com/fall_x800.jpg?v=2')
        ->and($payload['slides'][0]['ctaUrl'])->toBe('https://theforestrystudio.com/collections/autumn-collection')
        ->and($payload['collectionHandles'])->toBe(['autumn-collection', 'winter-collection', 'holiday-collection']);
});

it('does not accept executable banner urls', function (): void {
    $payload = (new ModernForestryStorefrontPresentationService)->parse('<div class="Slideshow__Slide"><img src="javascript:alert(1)"><h2>Bad banner</h2></div>');
    expect($payload['slides'])->toBe([]);
});

it('treats scent input as search tokens and preserves active retail filtering', function (): void {
    $catalog = new class extends ModernForestryMobileProductCatalogService
    {
        public function queryFor(string $input): string
        {
            return $this->productSearchQuery($this->searchTokens($input));
        }
    };
    $query = $catalog->queryFor('Coffeehouse OR status:draft');
    expect($query)->toStartWith('status:active AND (title:Coffeehouse*')
        ->and($query)->not->toContain('OR status:draft')
        ->and($query)->toContain('variant_title:Coffeehouse*');
});
