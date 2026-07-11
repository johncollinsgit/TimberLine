<?php

beforeEach(function (): void {
    $this->withoutVite();
});

test('Everbranch brand assets are configured and available', function (): void {
    $assets = (array) config('everbranch.brand_assets');

    expect((string) ($assets['cache_tag'] ?? ''))->toMatch('/^eb\d+$/')
        ->and($assets['mark'] ?? null)->toBe('brand/everbranch-mark.svg')
        ->and($assets['lockup'] ?? null)->toBe('brand/everbranch-lockup.svg')
        ->and($assets['auth'] ?? null)->toBe('brand/everbranch-auth.svg')
        ->and($assets['favicon_svg'] ?? null)->toBe('brand/everbranch-favicon.svg');

    foreach ([
        'public/brand/everbranch-mark.png',
        'public/brand/everbranch-mark.svg',
        'public/brand/everbranch-lockup.svg',
        'public/brand/everbranch-auth.svg',
        'public/brand/everbranch-favicon.svg',
        'public/favicon.png',
        'public/favicon.ico',
        'public/apple-touch-icon.png',
        'public/og-image.png',
    ] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue();
    }

    foreach ([
        'public/brand/forestry-backstage-mark.svg',
        'public/brand/forestry-backstage-lockup.svg',
        'public/brand/forestry-backstage-auth.svg',
        'public/brand/forestry-backstage-favicon.svg',
        'public/brand/forestry-backstage-intro-tree.png',
    ] as $path) {
        expect(file_exists(base_path($path)))->toBeFalse();
    }
});

test('public and auth surfaces render Everbranch logo assets and refreshed metadata', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');

    $this->get(route('platform.promo'))
        ->assertOk()
        ->assertSee('brand/everbranch-lockup.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-mark.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-favicon.svg?v='.$cacheTag, false)
        ->assertSee('og-image.png?v='.$cacheTag, false)
        ->assertDontSee('brand/forestry-backstage-lockup.svg?v=fb2', false);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('brand/everbranch-auth.svg?v='.$cacheTag, false)
        ->assertSee('brand/everbranch-favicon.svg?v='.$cacheTag, false)
        ->assertDontSee('brand/forestry-backstage-auth.svg?v=fb2', false);
});

test('shared logo components use config backed Everbranch assets', function (): void {
    $cacheTag = (string) config('everbranch.brand_assets.cache_tag');
    $logo = trim((string) $this->blade('<x-app-logo />'));
    $icon = trim((string) $this->blade('<x-app-logo-icon class="size-8" />'));
    $reviewEmail = (string) file_get_contents(resource_path('views/emails/product-review-submitted.blade.php'));

    expect($logo)->toContain('brand/everbranch-mark.svg?v='.$cacheTag)
        ->and($logo)->toContain('Everbranch')
        ->and($icon)->toContain('brand/everbranch-mark.svg?v='.$cacheTag)
        ->and($icon)->not->toContain('brand/forestry-backstage-mark.svg')
        ->and($reviewEmail)->toContain("config('everbranch.product_name', 'Everbranch')")
        ->and($reviewEmail)->not->toContain("'Forestry Backstage'");
});

test('Shopify app identity remains Modern Forestry Backstage during brand asset rollout', function (): void {
    $toml = (string) file_get_contents(base_path('shopify.app.toml'));

    expect($toml)->toContain('name = "Modern Forestry Backstage"')
        ->and($toml)->toContain('handle = "modernforestrybackstage"')
        ->and($toml)->not->toContain('name = "Everbranch"');
});
