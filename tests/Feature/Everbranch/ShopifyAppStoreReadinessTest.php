<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');
    config()->set('services.shopify.stores.wholesale.shop', 'modernforestry-wholesale.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'shopify-wholesale-client-id');
    config()->set('services.shopify.stores.wholesale.client_secret', 'shopify-wholesale-client-secret');
    config()->set('services.shopify.scopes', 'read_orders,read_products,read_customers,write_customers,read_pixels,write_pixels,read_customer_events');
});

function shopifyReadinessToml(): string
{
    return (string) file_get_contents(base_path('shopify.app.toml'));
}

function shopifyReadinessDoc(): string
{
    return (string) file_get_contents(base_path('docs/operations/everbranch-shopify-readiness-audit.md'));
}

test('shopify cli configuration uses canonical Everbranch URLs for app callback and proxy surfaces', function (): void {
    $toml = shopifyReadinessToml();

    expect($toml)->toContain('application_url = "https://app.theeverbranch.com/shopify/app"')
        ->and($toml)->toContain('"https://app.theeverbranch.com/shopify/callback/retail"')
        ->and($toml)->toContain('"https://app.theeverbranch.com/shopify/callback/wholesale"')
        ->and($toml)->toContain('url = "https://app.theeverbranch.com/shopify/marketing/v1"')
        ->and($toml)->toContain('prefix = "apps"')
        ->and($toml)->toContain('subpath = "forestry"')
        ->and($toml)->toContain('embedded = true')
        ->and($toml)->not->toContain('app.grovebud.com')
        ->and($toml)->not->toContain('app.forestrybackstage.com');
});

test('runtime shopify oauth and required webhook callbacks resolve to canonical landlord host', function (): void {
    $response = $this->get('http://app.theeverbranch.com/shopify/auth/retail');
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $redirectUri = (string) ($query['redirect_uri'] ?? '');

    expect(parse_url($redirectUri, PHP_URL_HOST))->toBe('app.theeverbranch.com')
        ->and($redirectUri)->toContain('/shopify/callback/retail');

    $callbacks = app(ShopifyWebhookSubscriptionService::class)->requiredTopicsWithCallbacks();

    expect($callbacks)->not->toBeEmpty();

    foreach ($callbacks as $topic => $callback) {
        expect((string) $topic)->not->toBe('')
            ->and(parse_url((string) $callback, PHP_URL_HOST))->toBe('app.theeverbranch.com')
            ->and(parse_url((string) $callback, PHP_URL_SCHEME))->toBe('https');
    }
});

test('shopify embedded app store renders evidence safe copy without checkout or purchase controls', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Shopify Evidence Tenant',
        'slug' => 'shopify-evidence-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Module Catalog')
        ->assertSeeText('Checkout not active here')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertSeeText('Access:')
        ->assertDontSeeText('Purchase module')
        ->assertDontSeeText('Start checkout')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now');
});

test('shopify billing remains inactive and separate from stripe readiness flags', function (): void {
    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and(shopifyReadinessToml())->not->toContain('billing');
});

test('privacy webhook endpoints are configured while partner dashboard evidence remains manual', function (): void {
    $topics = [
        'customers/data_request',
        'customers/redact',
        'shop/redact',
    ];

    foreach ($topics as $topic) {
        expect(config('shopify_webhooks.required_topics'))->not->toHaveKey($topic)
            ->and(config('shopify_webhooks.privacy_topics'))->toHaveKey($topic)
            ->and(shopifyReadinessDoc())->toContain($topic);
    }

    expect(Route::has('shopify.webhooks.customers.data-request'))->toBeTrue()
        ->and(Route::has('shopify.webhooks.customers.redact'))->toBeTrue()
        ->and(Route::has('shopify.webhooks.shop.redact'))->toBeTrue()
        ->and(shopifyReadinessDoc())->toContain('manual review required')
        ->and(shopifyReadinessDoc())->toContain('Partner Dashboard');
});

test('modern forestry mobile catalog remains outside generic shopify app store readiness', function (): void {
    expect(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse();
});
