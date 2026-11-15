<?php

// Render the real Blade control for loopback browser tests, never a runtime DB.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    throw new RuntimeException('Disposable browser rendering only.');
}
if ($argc !== 4 || ! preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $argv[1]) || ! in_array($argv[2], ['native', 'sawyer', 'gated', 'preview', 'shop-preview', 'layout-starter', 'layout-native', 'layout-serif', 'layout-sawyer'], true) || ! ctype_digit($argv[3]) || (int) $argv[3] < 1 || (int) $argv[3] > 100) {
    throw new RuntimeException('Fixed fictional browser input required.');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
if (is_file(dirname(__DIR__, 3).'/bootstrap/cache/config.php') || (is_string(getenv('APP_CONFIG_CACHE')) && getenv('APP_CONFIG_CACHE') !== '')) {
    throw new RuntimeException('Cached runtime configuration is not a browser fixture.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Disposable browser configuration required.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
config()->set('managed_website.commerce_enabled', false);
Illuminate\Support\Facades\URL::forceRootUrl($argv[1]);
Illuminate\Support\Facades\URL::forceScheme('http');
if (str_starts_with($argv[2], 'layout-')) {
    $settings = ['theme_key' => match ($argv[2]) {
        'layout-starter' => 'starter-apparel', 'layout-sawyer' => 'sawyer-naturals', default => 'hvac-service'
    },
        'theme_name' => 'Fictional preview', 'typography' => $argv[2] === 'layout-serif' ? 'serif' : 'sans', 'corners' => 'rounded'];
    $tenant = new App\Models\Tenant(['id' => 1, 'name' => 'Fictional preview', 'slug' => 'fictional-preview']);
    $tenant->setRelation('brandProfile', null);
    $site = new App\Models\TenantSite(['id' => 1, 'tenant_id' => 1, 'settings' => $settings]);
    $site->setRelation('tenant', $tenant);
    $theme = new App\Models\TenantSiteVersion(['settings' => $settings, 'navigation' => [], 'seo' => []]);
    $page = new App\Models\TenantSitePage(['id' => 1, 'slug' => '/', 'title' => 'Fictional preview']);
    $version = new App\Models\TenantSitePageVersion(['title' => 'Fictional preview', 'seo' => [], 'blocks' => [['type' => 'hero', 'heading' => 'Fictional preview', 'body' => 'Actual native Website layout.']]]);
    echo view('managed-website.public', compact('tenant', 'site', 'theme', 'page', 'version') + ['isDraftPreview' => true, 'previewMode' => 'site', 'editorUrl' => $argv[1].'/editor', 'previewLinks' => ['/']])->render();
    exit;
}
if ($argv[2] === 'shop-preview') {
    $tenant = new App\Models\Tenant(['id' => 1, 'name' => 'Fictional retailer', 'slug' => 'fictional-retail']);
    $tenant->setRelation('brandProfile', null);
    $storeSettings = ['theme_key' => 'starter-apparel', 'theme_name' => 'Fictional Linen Studio', 'typography' => 'sans', 'theme_palette' => ['ink' => '#183f35', 'brand' => '#246a55', 'surface' => '#fffefa', 'soft' => '#f0f4ee']];
    $site = new App\Models\TenantSite(['id' => 1, 'tenant_id' => 1, 'settings' => $storeSettings]);
    $site->setRelation('tenant', $tenant);
    $product = new App\Models\WebsiteProduct(['id' => 20, 'tenant_id' => 1, 'tenant_site_id' => 1, 'title' => 'Fictional linen shirt', 'handle' => 'fictional-linen-shirt', 'description' => 'Fictional product for a private browser fixture.', 'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => true, 'media' => ['/fixture-product.png']]);
    $product->setRelation('variants', collect([new App\Models\WebsiteProductVariant(['id' => 2, 'tenant_id' => 1, 'website_product_id' => 20, 'price_cents' => 3950, 'is_available' => true, 'inventory_quantity' => 2])]));
    $products = new Illuminate\Pagination\LengthAwarePaginator([$product], 1, 24);
    $collections = collect([new App\Models\WebsiteCollection(['id' => 3, 'title' => 'Fictional linen', 'handle' => 'linen'])]);
    $shopUrl = $argv[1].'/website/preview/shop?tenant=fictional-retail';
    $productUrls = [20 => $argv[1].'/website/preview/products/20?tenant=fictional-retail'];
    $retailCards = ['collections' => [['id' => 3, 'title' => 'Fictional linen', 'status' => 'draft', 'image' => '/fixture-product.png', 'alt' => 'Fictional linen collection', 'url' => $shopUrl.'&collection=linen']], 'products' => []];
    $storefront = ['is_retail_starter' => true, 'can_account' => false, 'can_cart' => false];
    echo view('managed-website.shop', compact('tenant', 'site', 'storeSettings', 'products', 'collections', 'shopUrl', 'productUrls', 'retailCards', 'storefront') + ['storePreview' => true, 'homeUrl' => '/editor', 'category' => 'all', 'search' => '', 'collection' => '', 'selectedCollection' => null])->render();
    exit;
}
$product = new App\Models\WebsiteProduct(['id' => 20, 'tenant_id' => 1, 'tenant_site_id' => 1, 'title' => 'Fictional linen shirt', 'handle' => 'fictional-linen-shirt']);
$preview = $argv[2] === 'preview';
$tenant = new App\Models\Tenant(['id' => 1, 'name' => 'Fictional retailer', 'slug' => 'fictional-retail']);
$site = new App\Models\TenantSite(['id' => 1, 'tenant_id' => 1]);
$site->setRelation('tenant', $tenant);
$variantChoices = collect(range(1, 100))->map(static function (int $id) use ($preview): array {
    $available = $id !== 1 && $id !== 3;

    return ['id' => $id, 'title' => match ($id) {
        1 => 'Natural / Small', 2 => 'Natural / Medium', 3 => 'Navy / Large', default => 'Fictional option '.$id
    },
        'price' => $id === 2 ? '$39.50' : '$'.number_format($id, 2), 'compare_price' => $id === 2 ? '$49.50' : '',
        'available' => $available, 'availability' => $available ? 'In stock' : 'Currently unavailable', 'maximum' => $available ? ($id === 2 ? 2 : 20) : 0,
        'action' => $preview ? '' : route('managed-website.store.cart.items.store', ['variant' => $id])];
});
$markup = view('managed-website.variant-picker', ['product' => $product, 'variantChoices' => $variantChoices, 'selectedVariant' => $variantChoices->firstWhere('id', (int) $argv[3]),
    'design' => $argv[2] === 'sawyer' ? 'sawyer' : 'native', 'site' => $site, 'storePreview' => $preview, 'productUrl' => $preview ? $argv[1].'/website/preview/products/20?tenant=fictional-retail' : null, 'cartEnabled' => ! in_array($argv[2], ['gated', 'preview'], true), 'checkoutReady' => false, 'contactUrl' => '/contact'])->render();
echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fictional retail controls</title><style>body{font:16px system-ui;margin:0;padding:16px;color:#183f35}main{max-width:600px;margin:auto}button{min-height:44px}h1{font-size:26px;overflow-wrap:anywhere}</style></head><body><main><h1>Fictional linen shirt</h1>'.$markup.'</main></body></html>';
