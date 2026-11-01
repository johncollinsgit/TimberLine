<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pos\PosCommerceRuleStore;
use App\Services\Pos\PosException;
use App\Services\Pos\PosShopifyOnlineDiscount;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    (require database_path('migrations/2026_11_01_140000_create_pos_shopify_discount_publications.php'))->up();
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'online_discount_test', 'pos_live.shopify_online_discounts_enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Online fixture', 'slug' => 'online-fixture-'.Str::random(8), 'status' => 'active']);
    $this->actor = User::factory()->create();
    $this->scope = ['tenant_id' => (int) $this->tenant->id, 'actor_id' => (int) $this->actor->id, 'admin' => true];
    $this->pos = app(PosStore::class);
    $this->register = str_repeat('a', 32);
    $connection = str_repeat('b', 32);
    $shopKey = 'fixture-'.Str::lower(Str::random(8));
    $this->shop = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => $shopKey, 'store_role' => 'retail',
        'shop_domain' => $shopKey.'.myshopify.com', 'access_token' => 'fictional-token']);
    $this->pos->rows('connections', $this->tenant->id)->insert($this->pos->values($this->tenant->id, ['id' => $connection,
        'merchant_id' => 'fictional', 'provider_location_id' => 'fictional', 'environment' => 'sandbox', 'credentials' => $this->pos->encrypt([]), 'active' => true]));
    $this->pos->rows('registers', $this->tenant->id)->insert($this->pos->values($this->tenant->id, ['id' => $this->register,
        'name' => 'Fixture', 'location_id' => 'fixture', 'location_name' => 'Fixture', 'connection_id' => $connection,
        'catalog_source' => 'shopify', 'configuration' => $this->pos->encrypt(['shopify' => ['store_id' => $this->shop->id]]), 'active' => true]));
    $rules = app(PosCommerceRuleStore::class);
    $this->rule = ['id' => 'five', 'name' => 'Five off', 'kind' => 'fixed', 'value' => 500,
        'scopes' => ['sale'], 'channels' => ['online'], 'active' => true];
    $saved = $rules->save($this->scope, $this->rule, $rules->get($this->scope)['version']);
    $this->version = $saved['version'];
    $rules->publish($this->scope, $this->register, $this->version, null, true);
});

it('prepares only explicitly public exclusive whole-sale fixed and percentage codes', function (): void {
    $service = app(PosShopifyOnlineDiscount::class);
    $input = $service->prepare($this->rule, 'SAVE5', '2026-11-01T00:00:00Z', true);
    expect($input['customerGets']['value']['discountAmount'])->toBe(['amount' => '5.00', 'appliesOnEachItem' => false])
        ->and($input['customerGets']['appliesOnSubscription'])->toBeFalse()
        ->and($input['combinesWith'])->toBe(['productDiscounts' => false, 'orderDiscounts' => false, 'shippingDiscounts' => false]);
    $percent = [...$this->rule, 'kind' => 'percent', 'value' => 1000];
    expect($service->prepare($percent, 'TENOFF', '2026-11-01T00:00:00Z', true)['customerGets']['value']['percentage'])->toBe(0.1);
    foreach ([['scopes' => ['item', 'sale']], ['kind' => 'addon'], ['channels' => ['in_person']]] as $unsupported) {
        expect(fn () => $service->prepare([...$this->rule, ...$unsupported], 'SAVE5', '2026-11-01T00:00:00Z', true))->toThrow(PosException::class, 'shopify_online_discount_unsupported');
    }
    expect(fn () => $service->prepare($this->rule, 'SAVE5', '2026-11-01T00:00:00Z', false))->toThrow(PosException::class, 'shopify_online_discount_unsupported');
});

it('stays off by default and journals one confirmed publication without repeating the provider request', function (): void {
    $service = app(PosShopifyOnlineDiscount::class);
    $request = (string) Str::uuid();
    $call = fn () => $service->publish($this->scope, $this->register, $this->version, 'five', 'SAVE5', '2026-11-01T00:00:00Z', $request, true);
    expect($call)->toThrow(PosException::class, 'shopify_online_discount_unavailable');
    config(['pos_live.shopify_online_discounts_enabled' => true]);
    Http::fake(['*.myshopify.com/*' => Http::sequence()
        ->push(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'currencyCode' => 'USD']]])
        ->push(['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/123'], 'userErrors' => []]]])]);
    expect($call())->toBe(['id' => 'gid://shopify/DiscountCodeNode/123', 'status' => 'published'])->and($call()['status'])->toBe('published');
    expect(fn () => $service->publish($this->scope, $this->register, $this->version, 'five', 'OTHER5', '2026-11-01T00:00:00Z', (string) Str::uuid(), true))
        ->toThrow(PosException::class, 'shopify_online_discount_review_required');
    expect(fn () => $service->publish([...$this->scope, 'admin' => false], $this->register, $this->version, 'five', 'SAVE5', '2026-11-01T00:00:00Z', (string) Str::uuid(), true))
        ->toThrow(PosException::class, 'organization_admin_required');
    Http::assertSentCount(2); // Currency check and one create; retries never create another discount.
});

it('allows only an assigned administrator to review an online code and keeps publishing off', function (): void {
    config(['pos_live.enabled' => true]);
    $this->actor->update(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->pos->rows('assignments', $this->tenant->id)->insert($this->pos->values($this->tenant->id, ['register_id' => $this->register,
        'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true]));
    $token = $this->actor->createToken('fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register.'/checkout-rules/online';
    $input = ['version' => $this->version, 'rule_id' => 'five', 'code' => 'SAVE5', 'starts_at' => '2026-11-01T00:00:00Z', 'public_eligibility_confirmed' => true];
    $this->postJson($base.'/review', $input)->assertOk()->assertJsonPath('input.code', 'SAVE5')->assertJsonPath('enabled', false);
    $this->postJson($base.'/publish', [...$input, 'request_id' => (string) Str::uuid(), 'confirmed' => true])->assertStatus(503)->assertJsonPath('error.code', 'shopify_online_discount_unavailable');
    Http::assertNothingSent();
    config(['pos_live.shopify_online_discounts_enabled' => true]);
    Http::fake(['*.myshopify.com/*' => function ($request) {
        if (str_contains($request['query'], 'DiscountStoreCurrency')) {
            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'currencyCode' => 'USD']]]);
        }
        if (str_contains($request['query'], 'DiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => null]]);
        }

        return Http::response([], 503);
    }]);
    $this->postJson($base.'/publish', [...$input, 'request_id' => (string) Str::uuid(), 'confirmed' => true])->assertStatus(503)->assertJsonPath('error.code', 'shopify_online_discount_review_required');
    $this->postJson($base.'/reconcile', ['rule_id' => 'five'])->assertOk()->assertJsonPath('status', 'review')->assertJsonPath('lookup_state', 'not_found_unverified');
    Http::assertSentCount(3);
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
    $this->postJson($base.'/review', $input)->assertForbidden();
});

it('holds uncertain provider results for review and never retries the create', function (): void {
    config(['pos_live.shopify_online_discounts_enabled' => true]);
    Http::fake(['*.myshopify.com/*' => function ($request) {
        if (str_contains($request['query'], 'DiscountStoreCurrency')) {
            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'currencyCode' => 'USD']]]);
        }
        if (str_contains($request['query'], 'DiscountByCode')) {
            return Http::response(['data' => ['codeDiscountNodeByCode' => ['id' => 'gid://shopify/DiscountCodeNode/777', 'codeDiscount' => ['__typename' => 'DiscountCodeBasic', 'title' => 'Five off', 'shortSummary' => '$5 off']]]]);
        }

        return Http::response([], 503);
    }]);
    $request = (string) Str::uuid();
    $service = app(PosShopifyOnlineDiscount::class);
    $call = fn () => $service->publish($this->scope, $this->register, $this->version, 'five', 'SAVE5', '2026-11-01T00:00:00Z', $request, true);
    expect($call)->toThrow(PosException::class, 'shopify_online_discount_review_required');
    expect($call)->toThrow(PosException::class, 'shopify_online_discount_review_required');
    expect(DB::table('pos_shopify_discount_publications')->where('request_id', $request)->value('status'))->toBe('review');
    Http::assertSentCount(2);
    expect($service->reconcile($this->scope, $this->register, 'five'))->toBe(['status' => 'review', 'lookup_state' => 'found_unverified', 'lookup_discount_id' => 'gid://shopify/DiscountCodeNode/777']);
    expect(DB::table('pos_shopify_discount_publications')->where('request_id', $request)->value('status'))->toBe('review')
        ->and(DB::table('pos_shopify_discount_publications')->where('request_id', $request)->value('lookup_state'))->toBe('found_unverified');
    expect($call)->toThrow(PosException::class, 'shopify_online_discount_review_required');
});

it('never creates a USD-cent rule on an unverified or non-USD Shopify store', function (): void {
    config(['pos_live.shopify_online_discounts_enabled' => true]);
    Http::fake(['*.myshopify.com/*' => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'currencyCode' => 'EUR']]])]);
    $request = (string) Str::uuid();
    expect(fn () => app(PosShopifyOnlineDiscount::class)->publish($this->scope, $this->register, $this->version, 'five', 'SAVE5', '2026-11-01T00:00:00Z', $request, true))
        ->toThrow(PosException::class, 'shopify_online_currency_unverified');
    expect(DB::table('pos_shopify_discount_publications')->where('request_id', $request)->exists())->toBeFalse();
    Http::assertSentCount(1);
});

it('recovers a missing publication uniqueness index without dropping evidence', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    Schema::table('pos_shopify_discount_publications', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropUnique('pos_shopify_discount_rule_uq'));
    (require database_path('migrations/2026_11_01_140000_create_pos_shopify_discount_publications.php'))->up();
    $index = collect(Schema::getIndexes('pos_shopify_discount_publications'))->firstWhere('name', 'pos_shopify_discount_rule_uq');
    expect($index['unique'])->toBeTrue()->and($index['columns'])->toBe(['tenant_id', 'shopify_store_id', 'rule_id']);
});
