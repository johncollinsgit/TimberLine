<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'commerce-account-test');
    config()->set('marketing.shopify.allow_legacy_token', false);
});

function commerceAccountStore(string $key): array
{
    $tenant = Tenant::query()->create(['name' => 'Store '.$key, 'slug' => $key]);
    $shop = $key.'.myshopify.com';
    config()->set('services.shopify.stores.'.$key.'.shop', $shop);
    ShopifyStore::query()->create(['tenant_id' => $tenant->id, 'store_key' => $key, 'shop_domain' => $shop,
        'access_token' => 'test-only', 'installed_at' => now()]);
    $profile = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Private '.$key]);
    CustomerExternalProfile::query()->create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
        'provider' => 'shopify', 'integration' => 'native', 'store_key' => $key, 'external_customer_id' => '12345']);
    $order = Order::factory()->create(['tenant_id' => $tenant->id, 'order_number' => 'PRIVATE-'.$key]);
    MarketingProfileLink::query()->create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
        'source_type' => 'order', 'source_id' => (string) $order->id]);

    return compact('tenant', 'profile', 'shop');
}

function commerceAccountUrl(array $params): string
{
    $params['timestamp'] = (string) time();
    ksort($params);
    $base = collect($params)->map(fn ($value, $key) => $key.'='.$value)->implode('');
    $params['signature'] = hash_hmac('sha256', $base, 'commerce-account-test');

    return route('marketing.shopify.account', $params);
}

test('a second tenant has its own authenticated purchases and rewards account', function (): void {
    commerceAccountStore('first-account-store');
    $second = commerceAccountStore('second-account-store');
    $this->get(commerceAccountUrl(['shop' => $second['shop'], 'logged_in_customer_id' => '12345']))
        ->assertOk()->assertSeeText('Store second-account-store')
        ->assertSeeText('Purchases & Rewards')->assertSeeText('PRIVATE-second-account-store')
        ->assertDontSeeText('PRIVATE-first-account-store')->assertDontSeeText('Modern Forestry');
});

test('signed visitor customer hints and contact fields do not authenticate a shopper', function (): void {
    commerceAccountStore('unused-account-store');
    $store = commerceAccountStore('private-account-store');
    $this->get(commerceAccountUrl(['shop' => $store['shop'], 'logged_in_customer_id' => '',
        'customer_id' => '12345', 'shopify_customer_id' => '12345', 'email' => 'same@example.com']))
        ->assertOk()->assertSeeText('Sign in on the storefront')
        ->assertDontSeeText('PRIVATE-private-account-store');
});

test('a proxy store hint cannot switch the signed shop customer to another tenant', function (): void {
    commerceAccountStore('first-private-store');
    $second = commerceAccountStore('second-private-store');
    $this->get(commerceAccountUrl(['shop' => $second['shop'], 'store_key' => 'first-private-store', 'logged_in_customer_id' => '12345']))
        ->assertOk()->assertSeeText('PRIVATE-second-private-store')->assertDontSeeText('PRIVATE-first-private-store');
});

test('conflicting customer source associations fail closed instead of choosing the latest', function (): void {
    commerceAccountStore('unused-conflict-store');
    $store = commerceAccountStore('conflict-store');
    $other = MarketingProfile::query()->create(['tenant_id' => $store['tenant']->id, 'first_name' => 'Other']);
    MarketingProfileLink::query()->create(['tenant_id' => $store['tenant']->id, 'marketing_profile_id' => $other->id,
        'source_type' => 'shopify_customer', 'source_id' => 'conflict-store:12345']);
    $this->get(commerceAccountUrl(['shop' => $store['shop'], 'logged_in_customer_id' => '12345']))
        ->assertOk()->assertSeeText('Your account has not been connected yet')
        ->assertDontSeeText('PRIVATE-conflict-store');
});
