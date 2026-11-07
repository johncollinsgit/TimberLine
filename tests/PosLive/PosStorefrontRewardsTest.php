<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosRewardRedemptionSettings;
use App\Services\Pos\PosVerifiedCustomerAccount;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(fn () => $this->withoutVite());

function storefrontRewardsFixture(int $cap = 500, string $name = 'Forest Credit'): array
{
    config(['pos_live.runtime_id' => 'fixture_browser', 'pos_live.in_person_rewards_enabled' => true,
        'pos_live.durable_start_claims_enabled' => true, 'marketing.shopify.app_proxy_enabled' => true,
        'marketing.shopify.app_proxy_secret' => 'fictional-proxy-secret', 'pos_live.existing_wallet_review_enabled' => true]);
    $tenant = Tenant::create(['name' => 'Fictional merchant', 'slug' => 'browser-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $store = ShopifyStore::create(['tenant_id' => $tenant->id, 'store_key' => 'browser-'.$tenant->id,
        'shop_domain' => 'browser-'.$tenant->id.'.myshopify.com', 'access_token' => 'fictional']);
    foreach (['candle_cash_program_config' => ['label' => $name], 'candle_cash_policy_config' => ['earning_rules' => []],
        'candle_cash_access_state' => ['launch_state' => 'published', 'test_mode' => false]] as $key => $value) {
        TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => $key, 'value' => $value]);
    }
    $policy = ['enabled' => true, 'max_minor' => $cap, 'refund_policy' => 'restore_full_review_partial'];
    $version = app(PosRewardRedemptionSettings::class)->version($policy, app(PosCustomerRewards::class)->policy($tenant->id)['version']);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$policy, 'reviewed_version' => $version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional buyer']);
    $gid = 'gid://shopify/Customer/771';
    $meta = ['verification_method' => 'new_profile_authenticated_customer_account', 'shopify_store_key' => $store->store_key,
        'shopify_customer_gid' => $gid, 'verified_profile_id' => $profile->id, 'verified_at' => now()->toIso8601String(),
        'signature' => app(PosVerifiedCustomerAccount::class)->signature($tenant->id, $profile->id, $store->store_key, $gid)];
    foreach ([PosVerifiedCustomerAccount::SOURCE, 'shopify_customer'] as $source) {
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => $source,
            'source_id' => $store->store_key.':771', 'source_meta' => $meta, 'match_method' => 'customer_verified_account_link']);
    }
    CandleCashBalance::create(['marketing_profile_id' => $profile->id, 'balance' => 12]);

    return [$tenant, $store, $profile];
}

function storefrontRewardsUrl(ShopifyStore $store, string $customer = '771', ?int $timestamp = null): string
{
    $query = ['shop' => $store->shop_domain, 'logged_in_customer_id' => $customer, 'path_prefix' => '/apps/forestry', 'timestamp' => (string) ($timestamp ?? time())];
    ksort($query);
    $query['signature'] = hash_hmac('sha256', collect($query)->map(fn ($value, $key) => $key.'='.$value)->implode(''), 'fictional-proxy-secret');

    return '/shopify/marketing/v1/rewards/show-code?'.http_build_query($query);
}

it('shows tenant terms privately and issues one consented browser QR without putting a credential in a URL or snapshot', function (): void {
    [$tenant, $store] = storefrontRewardsFixture();
    $url = storefrontRewardsUrl($store);
    $page = $this->get($url)->assertOk()->assertSee('Forest Credit')->assertSee('$12.00')->assertSee('$5.00');
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    $body = ['challenge' => $page->viewData('challenge'), 'action' => 'code', 'confirmed' => true];
    $this->postJson($url, [...$body, 'confirmed' => false])->assertStatus(422);
    $this->postJson($url, $body)->assertOk()->assertJsonStructure(['svg', 'expires_at'])->assertJsonMissingPath('qr_payload');
    $this->postJson($url, $body)->assertConflict();
    expect(DB::table('pos_reward_authorizations')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('rejects stale signatures and another signed customer using the original consent, and preserves different tenant rules', function (): void {
    [, $store] = storefrontRewardsFixture();
    $url = storefrontRewardsUrl($store);
    $challenge = $this->get($url)->assertOk()->viewData('challenge');
    $this->get(storefrontRewardsUrl($store, '771', time() - 121))->assertUnauthorized();
    $this->postJson(storefrontRewardsUrl($store, '772'), ['challenge' => $challenge, 'action' => 'code', 'confirmed' => true])->assertStatus(422);
    [, $other] = storefrontRewardsFixture(300, 'Trail Tokens');
    $this->get(storefrontRewardsUrl($other))->assertOk()->assertSee('Trail Tokens')->assertSee('$3.00')->assertDontSee('Forest Credit');
    $this->get(storefrontRewardsUrl($store, ''))->assertOk()->assertSee('Sign in')->assertDontSee('$12.00');
});
