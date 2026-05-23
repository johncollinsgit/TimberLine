<?php

beforeEach(function (): void {
    $this->withoutVite();
});

test('Everbranch brand assets are configured and available', function (): void {
    $assets = (array) config('everbranch.brand_assets');

    expect($assets['cache_tag'] ?? null)->toBe('eb1')
        ->and($assets['mark'] ?? null)->toBe('brand/everbranch-mark.svg')
        ->and($assets['lockup'] ?? null)->toBe('brand/everbranch-lockup.svg')
        ->and($assets['auth'] ?? null)->toBe('brand/everbranch-auth.svg')
        ->and($assets['favicon_svg'] ?? null)->toBe('brand/everbranch-favicon.svg');

    foreach ([
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
});

test('public and auth surfaces render Everbranch logo assets and refreshed metadata', function (): void {
    $this->get(route('platform.promo'))
        ->assertOk()
        ->assertSee('brand/everbranch-lockup.svg?v=eb1', false)
        ->assertSee('brand/everbranch-mark.svg?v=eb1', false)
        ->assertSee('brand/everbranch-favicon.svg?v=eb1', false)
        ->assertSee('og-image.png?v=eb1', false)
        ->assertDontSee('brand/forestry-backstage-lockup.svg?v=fb2', false);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('brand/everbranch-auth.svg?v=eb1', false)
        ->assertSee('brand/everbranch-favicon.svg?v=eb1', false)
        ->assertDontSee('brand/forestry-backstage-auth.svg?v=fb2', false);
});

test('shared logo components use config backed Everbranch assets', function (): void {
    $logo = trim((string) $this->blade('<x-app-logo />'));
    $icon = trim((string) $this->blade('<x-app-logo-icon class="size-8" />'));
    $reviewEmail = (string) file_get_contents(resource_path('views/emails/product-review-submitted.blade.php'));

    expect($logo)->toContain('brand/everbranch-mark.svg?v=eb1')
        ->and($logo)->toContain('Everbranch')
        ->and($icon)->toContain('brand/everbranch-mark.svg?v=eb1')
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
