<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashChannelPolicy;
use App\Services\Marketing\CandleCashDailyRedemptionLimit;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function dailyCashFixture(string $slug = 'modern-forestry'): array
{
    Http::preventStrayRequests();
    $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => 'Fictional daily rewards']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional customer']);
    $reward = CandleCashReward::create(['name' => 'Redeem $10 Reward Credit', 'candle_cash_cost' => 10,
        'reward_type' => 'coupon', 'reward_value' => '10USD', 'is_active' => true]);
    $service = app(CandleCashService::class);
    $service->addPoints($profile, 100, 'earn', 'test', 'fictional-seed');

    return [$tenant, $profile, $reward, $service];
}

afterEach(function () {
    $this->travelBack();
});

test('a redeemed website code blocks another same-day website app or staff redemption without another debit', function () {
    $this->travelTo(now()->setDate(2026, 11, 19)->setTime(15, 0));
    [, $profile, $reward, $service] = dailyCashFixture();
    $first = $service->requestStorefrontRedemption($profile, $reward);
    CandleCashRedemption::findOrFail($first['redemption_id'])->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    foreach (['shopify', 'modern_forestry_ios', 'public_lookup'] as $platform) {
        expect($service->requestStorefrontRedemption($profile, $reward, $platform)['state'])->toBe('daily_limit_reached');
    }
    expect($service->redeemReward($profile, $reward, 'square')['error'])->toBe('daily_limit_reached')
        ->and($service->currentBalance($profile))->toBe(90.0)
        ->and(CandleCashRedemption::where('marketing_profile_id', $profile->id)->count())->toBe(1)
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('type', 'redeem')->count())->toBe(1);
    expect(app(CandleCashAccessGate::class)->storefrontRedeemAccessPayload($profile))->toMatchArray([
        'redeem_enabled' => false, 'mode' => 'daily_limit_reached', 'reset_at' => '2026-11-20T05:00:00+00:00',
    ]);
});

test('a pending code remains reusable after uncertain provider sync without reserving a second allowance', function () {
    [, $profile, $reward, $service] = dailyCashFixture();
    $first = $service->requestStorefrontRedemption($profile, $reward);
    $redemption = CandleCashRedemption::findOrFail($first['redemption_id']);
    $service->recordShopifySyncFailure($redemption, new RuntimeException('Fictional lost response'));
    $retry = $service->requestStorefrontRedemption($profile, $reward);
    expect($retry['state'])->toBe('already_has_active_code')->and($retry['code'])->toBe($first['code'])
        ->and($service->currentBalance($profile))->toBe(90.0)
        ->and(app(CandleCashAccessGate::class)->storefrontRedeemAccessPayload($profile)['redeem_enabled'])->toBeTrue();
    Http::assertNothingSent();
});

test('a canceled reservation does not reset the daily allowance', function () {
    [, $profile, $reward, $service] = dailyCashFixture();
    $first = $service->requestStorefrontRedemption($profile, $reward);
    $redemption = CandleCashRedemption::findOrFail($first['redemption_id']);
    $service->cancelIssuedRedemptionAndRestoreBalance($redemption, 'Fictional confirmed cancellation', 'shopify_discount_deactivated');
    expect($service->requestStorefrontRedemption($profile, $reward)['state'])->toBe('daily_limit_reached')
        ->and($service->currentBalance($profile))->toBe(100.0);
});

test('the allowance resets at Eastern midnight rather than UTC midnight', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-19 23:58:00', 'America/New_York')->utc());
    [, $profile, $reward, $service] = dailyCashFixture();
    $first = $service->requestStorefrontRedemption($profile, $reward);
    CandleCashRedemption::findOrFail($first['redemption_id'])->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    $this->travel(1)->minutes();
    expect($service->requestStorefrontRedemption($profile, $reward)['state'])->toBe('daily_limit_reached');
    $this->travel(2)->minutes();
    expect($service->requestStorefrontRedemption($profile, $reward)['state'])->toBe('code_issued')
        ->and($service->currentBalance($profile))->toBe(80.0);
});

test('a code issued yesterday and used today consumes today allowance', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-18 22:00', 'America/New_York')->utc());
    [, $profile, $reward, $service] = dailyCashFixture();
    $first = $service->requestStorefrontRedemption($profile, $reward);
    $this->travel(12)->hours();
    CandleCashRedemption::findOrFail($first['redemption_id'])->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    expect($service->requestStorefrontRedemption($profile, $reward)['state'])->toBe('daily_limit_reached');
});

test('DST transitions still reset at local midnight', function (string $localNow, string $expectedReset) {
    $this->travelTo(\Carbon\CarbonImmutable::parse($localNow, 'America/New_York')->utc());
    [, $profile] = dailyCashFixture();
    expect(app(CandleCashDailyRedemptionLimit::class)->status($profile)['reset_at'])->toBe($expectedReset);
})->with([
    ['2026-03-08 00:01:00', '2026-03-09T04:00:00+00:00'],
    ['2026-11-01 00:01:00', '2026-11-02T05:00:00+00:00'],
]);

test('customer and tenant boundaries preserve independent allowances', function () {
    [, $profile, $reward, $service] = dailyCashFixture();
    $service->redeemReward($profile, $reward, 'shopify');
    $other = MarketingProfile::create(['tenant_id' => $profile->tenant_id, 'first_name' => 'Fictional second customer']);
    $service->addPoints($other, 30, 'earn', 'test', 'other');
    expect($service->redeemReward($other, $reward, 'shopify')['ok'])->toBeTrue();
    [$tenantB, $profileB, $rewardB, $serviceB] = dailyCashFixture('fictional-other-tenant');
    expect($serviceB->redeemReward($profileB, $rewardB, 'shopify')['ok'])->toBeTrue()
        ->and($serviceB->redeemReward($profileB, $rewardB, 'shopify')['ok'])->toBeTrue()
        ->and(app(CandleCashDailyRedemptionLimit::class)->rules($tenantB->id)['maximum'])->toBe(0);
});

test('in person consumption blocks a second purchase but permits replay of the original evidence', function () {
    [, $profile, $reward, $service] = dailyCashFixture();
    $code = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'square')['redemption_id']);
    $policy = app(CandleCashChannelPolicy::class);
    $policy->consumeInPerson($code, 'square_manual', 'fictional-1', 'manual_reconcile');
    expect($policy->consumeInPerson($code, 'square_order', 'fictional-1', 'square_sync')->id)->toBe($code->id);
    $older = $code->replicate();
    $older->fill(['redemption_code' => 'CC-FICTIONAL-OLDER', 'status' => 'issued', 'issued_at' => now()->subDays(2),
        'redeemed_at' => null, 'external_order_id' => null]);
    $older->save();
    expect(fn () => $policy->consumeInPerson($older, 'square_manual', 'fictional-2', 'manual_reconcile'))->toThrow(ValidationException::class);
});

test('published redemption rules include the daily limit and reset timezone', function () {
    [$tenant, , , $service] = dailyCashFixture();
    expect($service->redemptionRulesPayload($tenant->id))->toMatchArray([
        'max_redemptions_per_customer_per_day' => 1, 'redemption_timezone' => 'America/New_York',
    ])->and($service->redemptionRulesPayload($tenant->id)['channel_summary'])->toContain('once per customer per day', 'Eastern Time');
});

test('signed storefront status and redeem responses explain the limit without calling Shopify or debiting again', function () {
    [$tenant, $profile, $reward, $service] = dailyCashFixture();
    $reward = $service->storefrontReward($tenant->id);
    $profile->update(['email' => 'daily-fiction@example.com', 'normalized_email' => 'daily-fiction@example.com',
        'phone' => '5553339911', 'normalized_phone' => '+15553339911']);
    \App\Models\ShopifyStore::updateOrCreate(['store_key' => 'retail'], [
        'shop_domain' => 'modernforestry.myshopify.com', 'access_token' => 'fictional-token',
        'tenant_id' => $tenant->id, 'installed_at' => now(),
    ]);
    config()->set('marketing.shopify.signing_secret', 'fictional-daily-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'fictional-daily-client');
    $first = $service->requestStorefrontRedemption($profile, $reward);
    CandleCashRedemption::findOrFail($first['redemption_id'])->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    $sign = function (string $method, string $path, array $query, string $body): array {
        ksort($query);
        $timestamp = time();
        $signed = implode("\n", [$timestamp, $method, $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), hash('sha256', $body)]);

        return ['X-Marketing-Timestamp' => (string) $timestamp, 'X-Marketing-Signature' => hash_hmac('sha256', $signed, 'fictional-daily-secret')];
    };
    $identity = ['email' => $profile->email, 'phone' => $profile->phone, 'shop' => 'modernforestry.myshopify.com'];
    $this->withHeaders($sign('GET', '/shopify/marketing/candle-cash/status', $identity, ''))
        ->getJson(route('marketing.shopify.candle-cash.status', $identity))
        ->assertOk()->assertJsonPath('data.redemption_access.mode', 'daily_limit_reached')
        ->assertJsonPath('data.redemption_access.redeem_enabled', false)
        ->assertJsonPath('data.redemption_rules.max_redemptions_per_customer_per_day', 1);
    $payload = [...$identity, 'reward_id' => $reward->id];
    $this->withHeaders($sign('POST', '/shopify/marketing/rewards/redeem', [], json_encode($payload)))
        ->postJson(route('marketing.shopify.rewards.redeem'), $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'daily_limit_reached');
    expect($service->currentBalance($profile))->toBe(90.0)
        ->and(CandleCashRedemption::where('marketing_profile_id', $profile->id)->count())->toBe(1);
    Http::assertNothingSent();
});
