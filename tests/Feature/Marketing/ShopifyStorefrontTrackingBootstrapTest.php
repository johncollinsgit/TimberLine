<?php

test('shopify storefront tracking bootstrap files exist with expected proxy configuration', function () {
    $appToml = base_path('shopify.app.toml');
    $embedManifest = base_path('extensions/forestry-marketing-embed/shopify.extension.toml');
    $embedBlock = base_path('extensions/forestry-marketing-embed/blocks/marketing-app-embed.liquid');
    $embedAsset = base_path('extensions/forestry-marketing-embed/assets/marketing-storefront-tracker.js');
    $pixelManifest = base_path('extensions/forestry-marketing-pixel/shopify.extension.toml');
    $pixelSource = base_path('extensions/forestry-marketing-pixel/src/index.js');

    expect(is_file($appToml))->toBeTrue()
        ->and(is_file($embedManifest))->toBeTrue()
        ->and(is_file($embedBlock))->toBeTrue()
        ->and(is_file($embedAsset))->toBeTrue()
        ->and(is_file($pixelManifest))->toBeTrue()
        ->and(is_file($pixelSource))->toBeTrue();

    $appConfig = file_get_contents($appToml);
    $embedConfig = file_get_contents($embedManifest);
    $embedLiquid = file_get_contents($embedBlock);
    $pixelConfig = file_get_contents($pixelManifest);
    $pixelCode = file_get_contents($pixelSource);

    expect($appConfig)->toContain('embedded = true')
        ->toContain('application_url = "https://app.grovebud.com/shopify/app"')
        ->toContain('read_discounts')
        ->toContain('write_discounts')
        ->toContain('read_webhooks')
        ->toContain('write_webhooks')
        ->toContain('read_customer_events')
        ->toContain('subpath = "forestry"')
        ->toContain('prefix = "apps"')
        ->toContain('url = "https://app.grovebud.com/shopify/marketing/v1"');

    expect($embedConfig)->toContain('type = "theme"')
        ->and($embedLiquid)->toContain('/apps/forestry/funnel/event')
        ->and($embedLiquid)->toContain('"name": "Forestry tracking"')
        ->and($pixelConfig)->toContain('type = "web_pixel_extension"')
        ->and($pixelCode)->toContain("analytics.subscribe('product_viewed'")
        ->and($pixelCode)->toContain("analytics.subscribe('checkout_started'");
});
