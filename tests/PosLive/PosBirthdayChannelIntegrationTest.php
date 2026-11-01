<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayRewardActivationService;
use App\Services\Marketing\BirthdayRewardRedemptionReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

class FakeBirthdayActivationService extends BirthdayRewardActivationService
{
    protected function resolveStoreConfig(BirthdayRewardIssuance $issuance, array $options = []): ?array
    {
        return ['key' => $issuance->shopify_store_key, 'shop' => 'fictional.myshopify.com', 'token' => 'fake-token'];
    }
}

afterEach(function (): void {
    if (isset($this->ownedStoreId)) {
        ShopifyStore::whereKey($this->ownedStoreId)->delete();
    }
});

function birthdayChannelFixture($test): array
{
    $tenant = Tenant::create(['name' => 'Fictional birthday shop', 'slug' => 'channel-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $storeKey = 'fixture-'.Str::lower(Str::random(10));
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Test']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => 4, 'birth_day' => 30]);
    $issuance = BirthdayRewardIssuance::create(['customer_birthday_profile_id' => $birthday->id, 'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026, 'reward_type' => 'discount_code', 'reward_name' => 'Birthday', 'status' => 'issued',
        'reward_value' => '10.00', 'reward_code' => 'BDAY-FICTIONAL123', 'expires_at' => now()->addDay(), 'shopify_store_key' => $storeKey]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_reward_config', 'value' => ['discount_code_prefix' => 'BDAY']]);
    $store = ShopifyStore::create(['tenant_id' => $tenant->id, 'store_key' => $storeKey, 'shop_domain' => 'fictional.myshopify.com',
        'access_token' => 'fake-token', 'installed_at' => now()]);
    $test->ownedStoreId = $store->id;
    DB::table('birthday_cycle_coverage')->insert(['tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id, 'cycle_year' => 2026,
        'issuance_id' => $issuance->id, 'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now()]);

    return [$tenant, $profile, $issuance];
}

it('claims the online activation before fake provider calls and records later online use once', function (): void {
    Http::preventStrayRequests();
    config(['pos_live.birthday_channel_fence_enabled' => true]);
    [$tenant, $profile, $issuance] = birthdayChannelFixture($this);
    Http::fake(function ($request) use ($tenant) {
        expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('online_in_flight');
        $query = (string) ($request->data()['query'] ?? '');
        if (str_contains($query, 'BirthdayDiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => null]]);
        }

        return Http::response(['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => [
            'id' => 'gid://shopify/DiscountCodeNode/111', 'codeDiscount' => ['__typename' => 'DiscountCodeBasic',
                'title' => 'Fictional', 'startsAt' => now()->subMinute()->toIso8601String(), 'endsAt' => now()->addDay()->toIso8601String()],
        ], 'userErrors' => []]]]);
    });
    $result = app(FakeBirthdayActivationService::class)->activate($issuance);
    expect($result['ok'])->toBeTrue()->and(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('idle');
    $reconciler = app(BirthdayRewardRedemptionReconciliationService::class);
    $input = ['codes' => [$issuance->reward_code], 'externalOrderSource' => 'shopify', 'externalOrderId' => 'fake-order-1',
        'profileIds' => [$profile->id], 'context' => ['tenant_id' => $tenant->id, 'order_id' => 1]];
    $reconciler->reconcileCodes(...$input);
    expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('online_consumed');
    $reconciler->reconcileCodes(...$input);
    expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('online_consumed');
    $input['externalOrderId'] = 'fake-order-2';
    $input['context']['order_id'] = 2;
    $reconciler->reconcileCodes(...$input);
    expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('review')
        ->and(MarketingStorefrontEvent::where('event_type', 'birthday_cross_channel_review_required')->where('marketing_profile_id', $profile->id)->exists())->toBeTrue();
});

it('deactivates once, retrieves the exact node and keeps even an inactive code in review', function (): void {
    Http::preventStrayRequests();
    config(['pos_live.birthday_channel_fence_enabled' => true, 'pos_live.birthday_shopify_deactivation_enabled' => true]);
    [$tenant, , $issuance] = birthdayChannelFixture($this);
    $node = 'gid://shopify/DiscountCodeNode/111';
    $issuance->update(['shopify_discount_node_id' => $node]);
    Http::fake(function ($request) use ($node) {
        $query = (string) ($request->data()['query'] ?? '');
        if (str_contains($query, 'BirthdayCodeDeactivate')) {
            return Http::response(['data' => ['discountCodeDeactivate' => ['codeDiscountNode' => ['id' => $node], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['node' => ['id' => $node, 'codeDiscount' => ['status' => 'EXPIRED', 'asyncUsageCount' => 0]]]]);
    });
    $result = app(\App\Services\Pos\PosBirthdayShopifyDeactivation::class)->deactivateForReview($tenant->id, $issuance->id);
    expect($result)->toBe(['state' => 'inactive_review_required', 'redeemable' => false])
        ->and(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('review');
    Http::assertSentCount(2);
});

it('does not retry an ambiguous deactivation or let a second tenant request it', function (): void {
    Http::preventStrayRequests();
    config(['pos_live.birthday_channel_fence_enabled' => true, 'pos_live.birthday_shopify_deactivation_enabled' => true]);
    [$tenant, , $issuance] = birthdayChannelFixture($this);
    $issuance->update(['shopify_discount_node_id' => 'gid://shopify/DiscountCodeNode/111']);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $service = app(\App\Services\Pos\PosBirthdayShopifyDeactivation::class);
    expect(fn () => $service->deactivateForReview($other->id, $issuance->id))->toThrow(\App\Services\Pos\PosException::class, 'birthday_deactivation_binding_invalid');
    Http::fake(['*' => Http::response([], 503)]);
    $result = $service->deactivateForReview($tenant->id, $issuance->id);
    expect($result)->toBe(['state' => 'provider_review_required', 'redeemable' => false])
        ->and(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('review');
    Http::assertSentCount(1);
    expect(fn () => $service->deactivateForReview($tenant->id, $issuance->id))->toThrow(\App\Services\Pos\PosException::class, 'birthday_fence_review_required');
    Http::assertSentCount(1);
});

it('keeps an uncertain provider activation in review and does not retry the network', function (): void {
    Http::preventStrayRequests();
    config(['pos_live.birthday_channel_fence_enabled' => true]);
    [$tenant, , $issuance] = birthdayChannelFixture($this);
    Http::fake(['*' => Http::response([], 503)]);
    $service = app(FakeBirthdayActivationService::class);
    $first = $service->activate($issuance);
    $firstCalls = Http::recorded()->count();
    $second = $service->activate($issuance->fresh());
    expect($first['ok'])->toBeFalse()->and($second['error'])->toBe('birthday_fence_review_required')
        ->and(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('review');
    expect($firstCalls)->toBeGreaterThan(0);
    Http::assertSentCount($firstCalls);
});
