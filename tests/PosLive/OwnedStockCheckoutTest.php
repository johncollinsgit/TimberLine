<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Real provider boundaries must be exercised without a test-owned outer transaction.
uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional POS shop', 'slug' => 'pos-fixture-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['pos_live.enabled' => true, 'pos_live.collection_enabled' => true, 'pos_live.servicing_enabled' => true,
        'pos_live.native_client_enabled' => true, 'pos_live.runtime_id' => 'fixture_runtime',
        'pos_live.square_application_id' => 'sandbox-synthetic-app', 'pos_live.square_environment' => 'sandbox',
        'pos_live.tenant_ids' => [$this->tenant->id]]);
    $this->store = app(PosStore::class);
    $this->connection = str_repeat('c', 32);
    $this->register = str_repeat('a', 32);
    $this->item = str_repeat('b', 32);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->connection, 'merchant_id' => 'fixture_merchant', 'provider_location_id' => 'fixture_location',
        'environment' => 'sandbox', 'credentials' => $this->store->encrypt(['access_token' => 'fixture-token', 'expires_at' => now()->addDay()->toIso8601String()]),
        'active' => true, 'verified_at' => now(),
    ]));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->register, 'name' => 'Fictional counter', 'location_id' => 'fixture_counter', 'location_name' => 'Fictional store',
        'connection_id' => $this->connection, 'catalog_source' => 'native', 'active' => true, 'collection_enabled' => true,
        'servicing_enabled' => true, 'configuration' => $this->store->encrypt(['catalog_reviewed' => true, 'tax_reviewed' => true,
            'tax_valid_until' => now()->addDay()->toIso8601String()]),
    ]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true,
    ]));
    $this->store->rows('items', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->item, 'location_id' => 'fixture_counter', 'sku' => 'FICTIONAL-1', 'title' => 'Fictional merchandise',
        'kind' => 'merchandise', 'unit_amount_minor' => 1000, 'tax_bps' => 700, 'tax_basis' => 'Fictional test rate, not merchant tax advice',
        'tax_reviewed_at' => now(), 'reviewed_by' => $this->actor->id, 'revision' => str_repeat('d', 64), 'tracked' => true, 'quantity' => 1, 'active' => true,
    ]));
    $journal = DB::connection()->getDriverName() === 'mysql'
        ? new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), app('encrypter'))
        : new SqlitePaymentJournal(new PDO('sqlite::memory:'));
    if ($journal instanceof SqlitePaymentJournal) {
        $journal->installForTesting();
    }
    app()->instance(PaymentJournal::class, $journal);
    $this->journal = $journal;
    $this->token = $this->actor->createToken('POS fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($this->token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register;
    Http::fake([
        '*/v2/locations/fixture_location' => Http::response(['location' => ['id' => 'fixture_location', 'merchant_id' => 'fixture_merchant',
            'country' => 'US', 'currency' => 'USD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING']]]),
        '*/v2/merchants/fixture_merchant' => Http::response(['merchant' => ['id' => 'fixture_merchant', 'status' => 'ACTIVE']]),
    ]);
    $this->profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'ada@example.invalid']);
    $this->selection = ['customer_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function configureCheckoutOffers($test): array
{
    $rules = [
        ['id' => 'addon', 'name' => 'Add-on', 'kind' => 'addon', 'value' => 800, 'scopes' => ['item'], 'channels' => ['in_person'], 'active' => true, 'eligible_item_ids' => [$test->item], 'requires_other_item' => true],
        ['id' => 'fixed', 'name' => 'Item discount', 'kind' => 'fixed', 'value' => 100, 'scopes' => ['item'], 'channels' => ['in_person'], 'active' => true],
        ['id' => 'percent', 'name' => 'Sale discount', 'kind' => 'percent', 'value' => 1000, 'scopes' => ['sale'], 'channels' => ['in_person'], 'active' => true],
    ];
    $version = app(\App\Services\Pos\PosCommerceRules::class)->validate($rules)['version'];
    DB::table('tenant_commerce_rule_sets')->insert(['tenant_id' => $test->tenant->id, 'rules' => json_encode($rules),
        'version' => $version, 'updated_by' => $test->actor->id, 'created_at' => now(), 'updated_at' => now()]);
    $register = $test->store->rows('registers', $test->tenant->id)->first();
    $settings = $test->store->decrypt($register->configuration);
    $settings['checkout_rules_reviewed_version'] = $version;
    $test->store->rows('registers', $test->tenant->id)->update(['configuration' => $test->store->encrypt($settings)]);
    config(['pos_live.checkout_rules_enabled' => true]);

    return ['checkout_rules_version' => $version, 'addons' => [['rule_id' => 'addon', 'item_id' => $test->item, 'quantity' => 1]],
        'discounts' => [['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $test->item], ['rule_id' => 'percent', 'scope' => 'sale']]];
}

it('freezes server rules, taxes the discounted price and reserves both addon and ordinary units', function (): void {
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, ...$offers];
    $input['items'][0]['quantity'] = 2;
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 1637)->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    expect($snapshot['terms']['discount_minor'])->toBe(470)->and($snapshot['terms']['tax_minor'])->toBe(107)
        ->and($snapshot['terms']['rewardable_subtotal_minor'])->toBe(1530)
        ->and($snapshot['terms']['stock'][$this->item])->toBe(2)
        ->and($snapshot['terms']['checkout_rules_version'])->toBe($offers['checkout_rules_version']);
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
});

it('blocks a new collection if reviewed offers are disabled or changed after the quote', function (): void {
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, ...$offers];
    $input['items'][0]['quantity'] = 2;
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $id = $quote['sale_id'];
    $before = $this->store->rows('sales', $this->tenant->id)->where('id', $id)->first()->snapshot_hash;
    config(['pos_live.checkout_rules_enabled' => false]);
    $this->postJson($this->base.'/sales/'.$id.'/collect', [])->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_unavailable');
    config(['pos_live.checkout_rules_enabled' => true]);
    $row = DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->first();
    $rules = json_decode($row->rules, true);
    $rules[1]['value'] = 200;
    $version = app(\App\Services\Pos\PosCommerceRules::class)->validate($rules)['version'];
    DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->update(['rules' => json_encode($rules), 'version' => $version]);
    $this->postJson($this->base.'/sales/'.$id.'/collect', [])->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_review_required');
    $register = $this->store->rows('registers', $this->tenant->id)->first();
    $settings = $this->store->decrypt($register->configuration);
    $settings['checkout_rules_reviewed_version'] = $version;
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->postJson($this->base.'/sales/'.$id.'/collect', [])->assertStatus(409)->assertJsonPath('error.code', 'checkout_rules_changed');
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $id)->first();
    expect($sale->attempt_id)->toBeNull()->and($sale->descriptor_issued_at)->toBeNull()->and($sale->snapshot_hash)->toBe($before);
});

it('serializes rule changes and offer collection with the database tenant lock', function (): void {
    config(['cache.default' => 'database']);
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, ...$offers];
    $input['items'][0]['quantity'] = 2;
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $rules = app(\App\Services\Pos\PosCommerceRuleStore::class);
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'admin' => true];
    $existing = $rules->get($scope);
    $changed = $existing['rules'][1];
    $changed['value'] = 200;
    $held = \Illuminate\Support\Facades\Cache::lock($rules->lockKey($this->tenant->id), 30);
    expect($held->get())->toBeTrue();
    try {
        expect(fn () => $rules->save($scope, $changed, $existing['version']))
            ->toThrow(\App\Services\Pos\PosException::class, 'checkout_rules_busy');
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])
            ->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_busy');
        $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first();
        expect($sale->attempt_id)->toBeNull()->and($sale->descriptor_issued_at)->toBeNull()
            ->and($rules->get($scope)['version'])->toBe($existing['version']);
    } finally {
        $held->release();
    }
    expect($rules->save($scope, $changed, $existing['version'])['version'])->not->toBe($existing['version']);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])
        ->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_review_required');
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first();
    expect($sale->attempt_id)->toBeNull()->and($sale->descriptor_issued_at)->toBeNull();
});

it('does not activate draft offers or allow a standalone addon', function (): void {
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, ...$offers];
    $this->postJson($this->base.'/quotes', $input)->assertStatus(422)->assertJsonPath('error.code', 'checkout_addon_requires_purchase');
    $input['items'][0]['quantity'] = 2;
    config(['pos_live.checkout_rules_enabled' => false]);
    $this->postJson($this->base.'/quotes', $input)->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_unavailable');
    config(['pos_live.checkout_rules_enabled' => true]);
    $input['checkout_rules_version'] = str_repeat('0', 64);
    $this->postJson($this->base.'/quotes', $input)->assertStatus(409)->assertJsonPath('error.code', 'checkout_rules_changed');
    $input['checkout_rules_version'] = $offers['checkout_rules_version'];
    $register = $this->store->rows('registers', $this->tenant->id)->first();
    $settings = $this->store->decrypt($register->configuration);
    unset($settings['checkout_rules_reviewed_version']);
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->postJson($this->base.'/quotes', $input)->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_review_required');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(2);
});

it('limits required add-ons across selections to one per regular product unit', function (): void {
    configureCheckoutOffers($this);
    $draft = DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->firstOrFail();
    $rules = json_decode($draft->rules, true, 512, JSON_THROW_ON_ERROR);
    $secondRule = $rules[0];
    $secondRule['id'] = 'other_addon';
    $secondRule['name'] = 'Other add-on';
    $rules[] = $secondRule;
    $version = app(\App\Services\Pos\PosCommerceRules::class)->validate($rules)['version'];
    DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->update(['rules' => json_encode($rules), 'version' => $version]);
    $register = $this->store->rows('registers', $this->tenant->id)->firstOrFail();
    $settings = $this->store->decrypt($register->configuration);
    $settings['checkout_rules_reviewed_version'] = $version;
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $input = [...$this->selection, 'checkout_rules_version' => $version,
        'addons' => [
            ['rule_id' => 'addon', 'item_id' => $this->item, 'quantity' => 1],
            ['rule_id' => 'other_addon', 'item_id' => $this->item, 'quantity' => 1],
        ]];
    $input['items'][0]['quantity'] = 3; // One ordinary item cannot qualify two add-ons.
    $this->stock->count($this->tenant->id, 'more-units', $this->actor->id, $this->pool, [$this->item => 4], 'physical_count');
    $this->postJson($this->base.'/quotes', $input)->assertStatus(422)->assertJsonPath('error.code', 'checkout_addon_requires_purchase');
    $input['request_id'] = (string) Str::uuid();
    $input['items'][0]['quantity'] = 4;
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 3852);
});

it('does not count a custom amount or a bundle as a qualifying product for an add-on', function (): void {
    $offers = configureCheckoutOffers($this);
    $bundle = str_repeat('e', 32);
    $scope = ['tenant_id' => $this->tenant->id, 'register' => $this->store->rows('registers', $this->tenant->id)->firstOrFail()];
    $input = ['checkout_rules_version' => $offers['checkout_rules_version'], 'items' => [
        ['item_id' => $this->item, 'quantity' => 1], ['item_id' => $bundle, 'quantity' => 1],
    ], 'custom_charge' => ['description' => 'Other', 'amount_minor' => 500],
        'addons' => [['rule_id' => 'addon', 'item_id' => $this->item, 'quantity' => 1]]];
    $catalog = [$this->item => ['kind' => 'merchandise', 'unit_amount_minor' => 1000], $bundle => ['kind' => 'bundle', 'unit_amount_minor' => 2000]];
    expect(fn () => app(\App\Services\Pos\PosCheckoutOffers::class)->apply($scope, $input, $catalog, [$this->item => 1000, $bundle => 2000, 'custom' => 500]))
        ->toThrow(\App\Services\Pos\PosException::class, 'checkout_addon_requires_purchase');
});

it('applies repeated percentage discounts in their selected order', function (): void {
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, 'checkout_rules_version' => $offers['checkout_rules_version'],
        'discounts' => [['rule_id' => 'percent', 'scope' => 'sale'], ['rule_id' => 'percent', 'scope' => 'sale']]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 867)->json();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect(array_column($snapshot['terms']['applied_offers'], 'amount_minor'))->toBe([100, 90])
        ->and($snapshot['terms']['tax_minor'])->toBe(57);
});

it('targets selected units in order, including a mixed regular and addon variant', function (): void {
    $offers = configureCheckoutOffers($this);
    $input = [...$this->selection, ...$offers];
    $input['items'][0]['quantity'] = 2;
    $input['discounts'] = [
        ['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $this->item, 'quantity' => 1],
        ['rule_id' => 'percent', 'scope' => 'sale'],
        ['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $this->item, 'quantity' => 1],
    ];
    $invalid = $input;
    $invalid['discounts'][0]['quantity'] = 3;
    $this->postJson($this->base.'/quotes', $invalid)->assertStatus(422)->assertJsonPath('error.code', 'checkout_discount_invalid');
    $invalid = $input;
    $invalid['discounts'][1]['quantity'] = 1;
    $this->postJson($this->base.'/quotes', $invalid)->assertStatus(422)->assertJsonPath('error.code', 'checkout_discount_invalid');
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 1530)->json();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect(array_column($snapshot['terms']['applied_offers'], 'amount_minor'))->toBe([200, 100, 170, 100])
        ->and($snapshot['terms']['tax_minor'])->toBe(100)->and($snapshot['terms']['rewardable_subtotal_minor'])->toBe(1430)
        ->and($snapshot['terms']['applied_offers'][1]['quantity'])->toBe(1)
        ->and($snapshot['terms']['display'][0]['unit_net_minor'])->toBe([710, 720]);
});

it('sends exact selected-unit discounts to Shopify tax calculation', function (): void {
    $offers = configureCheckoutOffers($this);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'unit-test-'.$this->tenant->id,
        'shop_domain' => 'unit-test-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fixture-token']);
    $variant = 'gid://shopify/ProductVariant/101';
    $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $shop->id,
        'item_id' => $this->item, 'variant_id' => $variant, 'location_id' => 'gid://shopify/Location/301',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['shopify'] = ['store_id' => $shop->id, 'location_id' => 'gid://shopify/Location/301', 'tax_address' => ['countryCode' => 'US', 'zip' => '00000']];
    $this->store->rows('registers', $this->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $this->store->encrypt($settings)]);
    $received = [];
    Http::fake(['https://unit-test-*.myshopify.com/*' => function ($request) use (&$received, $variant) {
        $received = $request['variables']['input'];
        $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];

        return Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
            'taxesIncluded' => false, 'totalPriceSet' => $money('20.33'), 'totalTaxSet' => $money('1.33'),
            'lineItems' => [
                ['variant' => ['id' => $variant], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00'), 'discountedTotalSet' => $money('9.00')],
                ['variant' => ['id' => $variant], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00'), 'discountedTotalSet' => $money('10.00')],
            ],
        ]]]]);
    }]);
    $input = [...$this->selection, 'checkout_rules_version' => $offers['checkout_rules_version'],
        'discounts' => [['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $this->item, 'quantity' => 1]]];
    $input['items'][0]['quantity'] = 2;
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 2033);
    expect($received['lineItems'])->toHaveCount(2)->and($received['lineItems'][0]['quantity'])->toBe(1)
        ->and($received['lineItems'][0]['appliedDiscount']['value'])->toBe(1)
        ->and($received['lineItems'][1]['quantity'])->toBe(1)->and($received['lineItems'][1])->not->toHaveKey('appliedDiscount');
});

it('only exposes reviewed in-person rules to assigned cashiers', function (): void {
    $offers = configureCheckoutOffers($this);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->store->rows('assignments', $this->tenant->id)->update(['role' => 'cashier']);
    $available = $this->getJson($this->base.'/checkout-rules/available')->assertOk()->assertJsonPath('version', $offers['checkout_rules_version'])->json('rules');
    expect(array_column($available, 'id'))->toBe(['addon', 'fixed', 'percent']);
    $this->getJson($this->base.'/checkout-rules')->assertStatus(403);
    config(['pos_live.checkout_rules_enabled' => false]);
    $this->getJson($this->base.'/checkout-rules/available')->assertOk()->assertJsonPath('rules', [])->assertJsonPath('version', null);
});

it('discounts a typed amount before calculating tax without treating it as rewardable merchandise', function (): void {
    $offers = configureCheckoutOffers($this);
    $register = $this->store->rows('registers', $this->tenant->id)->first();
    $settings = $this->store->decrypt($register->configuration);
    $settings += ['custom_amount_enabled' => true, 'custom_taxable' => true, 'custom_taxable_reviewed' => true];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $input = ['request_id' => (string) Str::uuid(), 'checkout_rules_version' => $offers['checkout_rules_version'],
        'custom_charge' => ['description' => 'Custom amount', 'amount_minor' => 1000],
        'discounts' => [['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => 'custom'], ['rule_id' => 'percent', 'scope' => 'sale']]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 867)->json();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect($snapshot['terms']['discount_minor'])->toBe(190)->and($snapshot['terms']['tax_minor'])->toBe(57)
        ->and($snapshot['terms']['rewardable_subtotal_minor'])->toBe(0)->and($snapshot['terms']['stock'])->toBe([]);
});

it('sends per-unit allocated offers to Shopify and verifies discounted tax totals', function (): void {
    $offers = configureCheckoutOffers($this);
    $row = DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->first();
    $rules = json_decode($row->rules, true);
    $rules[1]['value'] = 101;
    $version = app(\App\Services\Pos\PosCommerceRules::class)->validate($rules)['version'];
    DB::table('tenant_commerce_rule_sets')->where('tenant_id', $this->tenant->id)->update(['rules' => json_encode($rules), 'version' => $version]);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'offer-test-'.$this->tenant->id,
        'shop_domain' => 'offer-test-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fixture-token']);
    $variant = 'gid://shopify/ProductVariant/101';
    $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $shop->id,
        'item_id' => $this->item, 'variant_id' => $variant, 'location_id' => 'gid://shopify/Location/301',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['checkout_rules_reviewed_version'] = $version;
    $settings['shopify'] = ['store_id' => $shop->id, 'location_id' => 'gid://shopify/Location/301', 'tax_address' => ['countryCode' => 'US', 'zip' => '00000']];
    $this->store->rows('registers', $this->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $this->store->encrypt($settings)]);
    $received = [];
    $wrong = true;
    Http::fake(['https://offer-test-*.myshopify.com/*' => function ($request) use (&$received, &$wrong, $variant) {
        $received = $request['variables']['input'];
        $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];

        return Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
            'taxesIncluded' => false, 'totalPriceSet' => $money('16.36'), 'totalTaxSet' => $money('1.07'),
            'lineItems' => [
                ['variant' => ['id' => $variant], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00'), 'discountedTotalSet' => $money('7.64')],
                ['variant' => ['id' => $variant], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00'), 'discountedTotalSet' => $money($wrong ? '7.66' : '7.65')],
            ],
        ]]]]);
    }]);
    $input = [...$this->selection, ...$offers, 'checkout_rules_version' => $version];
    $input['items'][0]['quantity'] = 2;
    $this->postJson($this->base.'/quotes', $input)->assertStatus(409)->assertJsonPath('error.code', 'total_changed');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(2);
    $wrong = false;
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 1636)->json();
    expect($received['acceptAutomaticDiscounts'])->toBeFalse()->and($received['lineItems'])->toHaveCount(2)
        ->and($received['lineItems'][0]['appliedDiscount']['value'])->toBe(2.35)
        ->and($received['lineItems'][1]['appliedDiscount']['value'])->toBe(2.36);
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect($snapshot['terms']['discount_minor'])->toBe(471)->and($snapshot['terms']['tax_minor'])->toBe(107);
});

beforeEach(function (): void {
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\StockSyncTenant::class]);
    config(['pos_live.owned_inventory_enabled' => true, 'pos_live.inventory_publication_enabled' => true]);
    $this->stock = app(\App\Services\Inventory\StockLedger::class);
    $this->pool = $this->stock->createPool($this->tenant->id, 'fixture-pool', $this->actor->id, 'Fictional shared stock', 'shared')['pool_id'];
    $this->item = bin2hex(random_bytes(16));
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $this->item, 'tenant_id' => $this->tenant->id,
        'sku' => 'STOCK-FIXTURE', 'title' => 'Fictional finished item', 'product_key' => 'stock-fixture',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $this->stock->count($this->tenant->id, 'opening', $this->actor->id, $this->pool, [$this->item => 2], 'opening_count');
    $this->stock->bind($this->tenant->id, 'register-pool', $this->actor->id, 'pos', 'fixture_runtime:'.$this->register, $this->pool);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings += ['commerce_adapter' => \App\Services\Pos\StockPosCommerce::VERSION, 'inventory_pool_id' => $this->pool, 'native_tax_reviewed' => true, 'native_tax_bps' => 700];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->selection = ['request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function ownedStockOnlineBinding($test): object
{
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $test->tenant->id, 'store_key' => 'stock-fixture-'.$test->tenant->id, 'shop_domain' => 'stock-'.$test->tenant->id.'.myshopify.com',
        'access_token' => 'fixture-shopify-token', 'scopes' => 'read_inventory,write_inventory,read_products']);
    $test->stock->rows('provider_items', $test->tenant->id)->insert(['tenant_id' => $test->tenant->id, 'store_id' => $shop->id,
        'item_id' => $test->item, 'variant_id' => 'gid://shopify/ProductVariant/101', 'inventory_item_id' => 'gid://shopify/InventoryItem/201',
        'inventory_policy' => 'DENY', 'location_id' => 'gid://shopify/Location/301', 'acknowledged_quantity' => 2, 'acknowledged_version' => 1]);
    $test->stock->bind($test->tenant->id, 'online-pool', $test->actor->id, 'shopify', (string) $shop->id, $test->pool,
        ['opening_reviewed_at' => now()->toIso8601String(), 'cutover_at' => now()->subHour()->toIso8601String(), 'location_id' => 'gid://shopify/Location/301'], true);

    $test->shopKey = $shop->store_key;

    return $shop;
}

function ownedStockReviewedEventTax($test): array
{
    config(['pos_live.tax_location_management_enabled' => true]);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $test->tenant->id, 'store_key' => 'tax-event-'.$test->tenant->id,
        'shop_domain' => 'tax-event-'.$test->tenant->id.'.myshopify.com', 'access_token' => 'fixture-token']);
    $variant = 'gid://shopify/ProductVariant/101';
    $test->stock->rows('provider_items', $test->tenant->id)->insert(['tenant_id' => $test->tenant->id, 'store_id' => $shop->id,
        'item_id' => $test->item, 'variant_id' => $variant, 'location_id' => 'gid://shopify/Location/301',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $test->stock->rows('pools', $test->tenant->id)->where('id', $test->pool)->update(['kind' => 'show', 'event_key' => 'event:123']);
    $metadata = ['name' => 'Fictional show', 'address' => '123 Example St', 'city' => 'Columbus', 'state' => 'GA',
        'postal_code' => '31901', 'end_on' => now()->addDays(30)->format('Y-m-d')];
    $link = $test->stock->rows('event_links', $test->tenant->id)->insertGetId(['tenant_id' => $test->tenant->id,
        'event_id' => 123, 'provider' => 'manual', 'external_id' => 'tax-fixture', 'metadata' => json_encode($metadata),
        'reviewed_by' => $test->actor->id, 'created_at' => now(), 'updated_at' => now()]);
    $settings = $test->store->decrypt($test->store->rows('registers', $test->tenant->id)->first()->configuration);
    $settings['shopify'] = ['store_id' => $shop->id, 'shop_id' => 'gid://shopify/Shop/123',
        'location_id' => 'gid://shopify/Location/301', 'tax_address' => ['countryCode' => 'US', 'zip' => '00000']];
    $test->store->rows('registers', $test->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $test->store->encrypt($settings)]);
    Http::fake([
        'https://tax-event-*.myshopify.com/*' => function ($request) use ($variant) {
            $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];

            return Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
                'taxesIncluded' => false, 'totalPriceSet' => $money('10.70'), 'totalTaxSet' => $money('0.70'),
                'lineItems' => [['variant' => ['id' => $variant], 'quantity' => 1,
                    'originalUnitPriceSet' => $money('10.00'), 'discountedTotalSet' => $money('10.00')]],
            ]]]]);
        },
        '*/v2/locations/fixture_location' => Http::response(['location' => ['id' => 'fixture_location', 'merchant_id' => 'fixture_merchant',
            'country' => 'US', 'currency' => 'USD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING']]]),
        '*/v2/merchants/fixture_merchant' => Http::response(['merchant' => ['id' => 'fixture_merchant', 'status' => 'ACTIVE']]),
    ]);
    $locations = $test->getJson($test->base.'/tax-locations')->assertOk()->json();
    $candidate = $locations['locations'][0];
    $test->postJson($test->base.'/tax-locations/publish', ['key' => $candidate['key'], 'version' => $candidate['version'],
        'previous_version' => $locations['current_version'], 'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
        'confirmed' => true])->assertOk();

    return [$link, $metadata];
}

it('rejects a changed reviewed event address before quote without creating a sale or calling the tax provider', function (): void {
    [$link, $metadata] = ownedStockReviewedEventTax($this);
    $providerCalls = Http::recorded()->count();
    $this->stock->rows('event_links', $this->tenant->id)->where('id', $link)->update(['metadata' => json_encode([...$metadata, 'address' => '456 Other St'])]);
    $this->postJson($this->base.'/quotes', $this->selection)->assertStatus(503)->assertJsonPath('error.code', 'tax_location_changed');
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(2)
        ->and(Http::recorded()->count())->toBe($providerCalls);
});

it('preserves the original recoverable sale when a reviewed event address changes before collection', function (): void {
    [$link, $metadata] = ownedStockReviewedEventTax($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('amount_minor', 1070)->json();
    $saleId = $quote['sale_id'];
    $before = $this->store->rows('sales', $this->tenant->id)->where('id', $saleId)->firstOrFail();
    $providerCalls = Http::recorded()->count();
    $this->stock->rows('event_links', $this->tenant->id)->where('id', $link)->update(['metadata' => json_encode([...$metadata, 'address' => '456 Other St'])]);
    $this->postJson($this->base.'/sales/'.$saleId.'/collect', [])->assertStatus(503)->assertJsonPath('error.code', 'tax_location_changed');
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $saleId)->firstOrFail();
    expect($sale->state)->toBe('quoted')->and($sale->attempt_id)->toBeNull()->and($sale->descriptor_issued_at)->toBeNull()
        ->and($sale->snapshot_hash)->toBe($before->snapshot_hash)->and(Http::recorded()->count())->toBe($providerCalls)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(1);
    $this->getJson($this->base.'/sales/'.$saleId)->assertOk()->assertJsonPath('state', 'quoted');
    $this->stock->rows('event_links', $this->tenant->id)->where('id', $link)->update(['metadata' => json_encode($metadata)]);
    $this->postJson($this->base.'/sales/'.$saleId.'/collect', [])->assertOk()->assertJsonPath('state', 'unknown')
        ->assertJsonPath('collection.amount_minor', 1070);
});

it('quotes guests, reserves event stock once and hands off without creating a Shopify order', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('customer', null)->assertJsonPath('amount_minor', 1070)->assertJsonPath('collection_ready', true)->json();
    $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(1);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonPath('state', 'unknown')->assertJsonPath('collection.amount_minor', 1070);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
    expect(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->stock->rows('reservations', $this->tenant->id)->whereNotNull('collection_locked_at')->count())->toBe(1);
});

it('shows and sells only the reviewed event selection without changing another register catalog', function (): void {
    $hidden = bin2hex(random_bytes(16));
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $hidden, 'tenant_id' => $this->tenant->id,
        'sku' => 'NOT-AT-THIS-SHOW', 'title' => 'Fictional mug', 'product_key' => $hidden, 'unit_amount_minor' => 1500,
        'tracked' => false, 'revision' => str_repeat('e', 64)]);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['catalog_item_ids'] = [$this->item];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->getJson($this->base.'/catalog')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.item_id', $this->item);
    $this->postJson($this->base.'/quotes', ['request_id' => (string) Str::uuid(), 'items' => [
        ['item_id' => $hidden, 'quantity' => 1, 'revision' => str_repeat('e', 64)]]])->assertConflict()->assertJsonPath('error.code', 'catalog_changed');
    expect($this->stock->item($this->tenant->id, $hidden)->active)->toBe(1);
    $this->postJson($this->base.'/quotes', $this->selection)->assertOk();
});

it('requires shared stock publication acknowledgment before collection without a network call in handoff', function (): void {
    ownedStockOnlineBinding($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('collection_ready', false)->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertConflict()->assertJsonPath('error.code', 'stock_availability_update_pending');
    expect($this->store->rows('sales', $this->tenant->id)->first()->attempt_id)->toBeNull();
    Http::fake(['https://*.myshopify.com/*' => Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['referenceDocumentUri' => 'fixture'], 'userErrors' => []]]])]);
    app(\App\Services\Inventory\StockShopifyPublisher::class)->drain($this->tenant->id);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()->assertJsonPath('collection_ready', true);
    $before = Http::recorded()->count();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonPath('state', 'unknown');
    expect(Http::recorded()->count())->toBe($before);
    Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities') && $r['variables']['input']['quantities'][0]['quantity'] === 1 && $r['variables']['input']['quantities'][0]['changeFromQuantity'] === 2);
});

it('lets an accepted competing online order cancel an unissued quote but never reuses that quote for charging', function (): void {
    config(['pos_live.durable_start_claims_enabled' => true]);
    ownedStockOnlineBinding($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $payload = ['id' => 901, 'created_at' => now()->subMinute()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2, 'current_quantity' => 2]], 'fulfillments' => [], 'refunds' => []];
    app(\App\Services\Inventory\StockOnlineOrders::class)->ingest($this->tenant->id, $this->shopKey, $payload);
    app(\App\Services\Inventory\StockOnlineOrders::class)->ingest($this->tenant->id, $this->shopKey, $payload);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('stock_state', 'released');
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertConflict();
    expect($this->store->rows('sales', $this->tenant->id)->first()->attempt_id)->toBeNull()
        ->and(DB::table('pos_payment_start_claims')->where('tenant_id', $this->tenant->id)->where('sale_id', $quote['sale_id'])->value('state'))->toBe('cancelled')
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(0)
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(1);
});

it('does not preempt the stock hold after a durable payment start claim', function (): void {
    config(['pos_live.durable_start_claims_enabled' => true]);
    ownedStockOnlineBinding($this);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    app(\App\Services\Pos\PosPaymentStartClaim::class)->beginCollection($this->tenant->id, $quote['sale_id']);
    $payload = ['id' => 901, 'created_at' => now()->subMinute()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2, 'current_quantity' => 2]], 'fulfillments' => [], 'refunds' => []];
    expect(fn () => app(\App\Services\Inventory\StockOnlineOrders::class)->ingest($this->tenant->id, $this->shopKey, $payload))
        ->toThrow(\App\Services\Inventory\StockException::class, 'stock_balance_conflict');
    expect(DB::table('pos_payment_start_claims')->where('tenant_id', $this->tenant->id)->where('sale_id', $quote['sale_id'])->value('state'))->toBe('starting')
        ->and($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->value('state'))->toBe('quoted')
        ->and((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $this->item)->value('reserved'))->toBe(1);
});

it('releases guest quote stock on explicit discard without a Shopify order or implicit restock on refund', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard', [])->assertOk()->assertJsonPath('discarded', true);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(2)
        ->and(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

function ownedStockComplete($test, ?array $existing = null, string $paymentId = 'fixture_payment'): array
{
    $quote = $existing ?? $test->postJson($test->base.'/quotes', $test->selection)->assertOk()->json();
    if ($existing === null) {
        $test->postJson($test->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk();
    }
    $sale = $test->store->rows('sales', $test->tenant->id)->where('id', $quote['sale_id'])->first();
    Http::fake(['*/v2/payments/'.$paymentId => Http::response(['payment' => ['id' => $paymentId, 'status' => 'COMPLETED',
        'reference_id' => $sale->payment_request_id, 'location_id' => 'fixture_location', 'source_type' => 'CARD',
        'amount_money' => ['amount' => 1070, 'currency' => 'USD'], 'total_money' => ['amount' => 1070, 'currency' => 'USD'],
        'card_details' => ['card' => ['card_brand' => 'VISA', 'last_4' => '1111', 'cardholder_name' => 'Card Receipt Name', 'fingerprint' => 'MUST_NOT_SAVE'],
            'entry_method' => 'CONTACTLESS', 'application_name' => 'VISA CREDIT', 'application_identifier' => 'A0000000031010']]])]);
    $test->postJson($test->base.'/sales/'.$sale->id.'/reconcile', ['payment_id' => $paymentId])->assertOk()->assertJsonPath('canonical_confirmation', true)->assertJsonPath('receipt_available', true);

    return $quote;
}

it('recovers canonical fake Square payment evidence after the native atomic handoff', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    config(['pos_live.durable_start_claims_enabled' => true]);
    $quote = ownedStockComplete($this);
    expect(DB::table('pos_payment_start_claims')->where('sale_id', $quote['sale_id'])->value('state'))->toBe('handed_off')
        ->and($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->value('state'))->toBe('succeeded')
        ->and(DB::table('payment_core_operations')->where('sale_id', $quote['sale_id'])->where('kind', 'payment')->count())->toBe(1);
});

it('confirms from fresh Square evidence, issues its own receipt and never creates an in-person Shopify order', function (): void {
    $quote = ownedStockComplete($this);
    $receipt = $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertOk()->assertJsonPath('payment_reference', 'fixture_payment')
        ->assertJsonPath('payment_details.last_four', '1111')->assertJsonPath('payment_details.application_name', 'VISA CREDIT')->json();
    expect($receipt['customer'])->toBeNull()->and($receipt['payment_details'])->not->toHaveKey('fingerprint')
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(1)
        ->and($this->stock->rows('movements', $this->tenant->id)->where('reason', 'sale')->count())->toBe(1)
        ->and(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
});

it('keeps receipt contact separate and associates a paid guest once without changing the original payment snapshot', function (): void {
    $quote = ownedStockComplete($this);
    $url = $this->base.'/sales/'.$quote['sale_id'].'/receipt/customer';
    $original = $this->store->rows('sales', $this->tenant->id)->first()->snapshot;
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'name' => 'Receipt recipient', 'phone' => '(864) 555-0101'])->assertOk()->assertJsonPath('associated', false);
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('associated', true)->assertJsonPath('receipt.customer.id', $this->profile->id)->assertJsonPath('receipt.contact.phone', '(864) 555-0101');
    $this->postJson($url, $input)->assertOk();
    expect($this->store->rows('sales', $this->tenant->id)->first()->snapshot)->toBe($original)
        ->and($this->stock->rows('receipt_associations', $this->tenant->id)->count())->toBe(1);
    $other = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Different', 'email' => 'different@example.invalid']);
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $other->id])->assertConflict()->assertJsonPath('error.code', 'receipt_customer_already_associated');
});

it('lets an owner count remaining units from the phone and rejects stale counts after other activity', function (): void {
    $item = $this->getJson($this->base.'/catalog')->assertOk()->json('items.0');
    $body = ['request_id' => (string) Str::uuid(), 'item_id' => $this->item, 'quantity' => 3,
        'expected_version' => $item['balance_version'], 'physically_confirmed' => true];
    $this->postJson($this->base.'/stock-counts', $body)->assertOk()->assertJsonPath('items.0.available_quantity', 3);
    $this->postJson($this->base.'/stock-counts', $body)->assertOk()->assertJsonPath('items.0.available_quantity', 3);
    $body['request_id'] = (string) Str::uuid();
    $body['quantity'] = 99;
    $this->postJson($this->base.'/stock-counts', $body)->assertConflict()->assertJsonPath('error.code', 'stock_count_changed_by_activity');
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
    $body['expected_version'] = $this->stock->listing($this->tenant->id, $this->pool)[0]['balance_version'];
    $this->postJson($this->base.'/stock-counts', $body)->assertForbidden();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(3);
});

it('settles the original physical hold even if its product is archived after collection starts', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk();
    $this->stock->rows('items', $this->tenant->id)->where('id', $this->item)->update(['active' => false]);
    ownedStockComplete($this, $quote);
    expect((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $this->item)->value('on_hand'))->toBe(1)
        ->and($this->store->rows('sales', $this->tenant->id)->first()->stock_state)->toBe('committed');
});

it('prices a reviewed bundle once and reserves its exact chosen components without overselling overlapping basket lines', function (): void {
    $parent = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    foreach ([$parent => ['Bundle', 2500], $other => ['Other candle', 1000]] as $id => [$title, $price]) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'title' => $title,
            'sku' => $id, 'product_key' => $id, 'unit_amount_minor' => $price, 'revision' => str_repeat('e', 64)]);
    }
    $this->stock->count($this->tenant->id, 'other-count', $this->actor->id, $this->pool, [$other => 1], 'opening_count');
    $bundles = app(\App\Services\Inventory\StockBundles::class);
    $bundles->review($this->tenant->id, 'bundle-review', $this->actor->id, $parent, 3, [$this->item, $other], str_repeat('e', 64));
    $catalog = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    $choice = ['item_id' => $parent, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'], 'components' => [
        ['item_id' => $this->item, 'quantity' => 2, 'revision' => $catalog[$this->item]['revision']],
        ['item_id' => $other, 'quantity' => 1, 'revision' => $catalog[$other]['revision']]]];
    $bad = ['request_id' => (string) Str::uuid(), 'items' => [$choice, $this->selection['items'][0]]];
    $this->postJson($this->base.'/quotes', $bad)->assertConflict()->assertJsonPath('error.code', 'stock_unavailable');
    $input = ['request_id' => (string) Str::uuid(), 'items' => [$choice]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 2675)->json();
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
    expect((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $this->item)->value('reserved'))->toBe(2)
        ->and((int) $this->stock->rows('balances', $this->tenant->id)->where('item_id', $other)->value('reserved'))->toBe(1)
        ->and($this->stock->rows('reservations', $this->tenant->id)->where('item_id', $parent)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('allocates online stock once, ignores reordered events and restocks only explicit merchandise returns', function (): void {
    ownedStockOnlineBinding($this);
    $service = app(\App\Services\Inventory\StockOnlineOrders::class);
    $time = now();
    $order = ['id' => 901, 'created_at' => $time->copy()->subMinute()->toIso8601String(), 'updated_at' => $time->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2]], 'fulfillments' => [], 'refunds' => []];
    $service->ingest($this->tenant->id, $this->shopKey, $order);
    $sent = $order;
    $sent['updated_at'] = $time->copy()->addSecond()->toIso8601String();
    $sent['fulfillments'] = [['id' => 9011, 'created_at' => $sent['updated_at'], 'status' => 'success', 'location_id' => 301, 'line_items' => [['id' => 1001, 'quantity' => 2]]]];
    $service->ingest($this->tenant->id, $this->shopKey, $sent);
    $financial = $sent;
    $financial['updated_at'] = $time->copy()->addSeconds(2)->toIso8601String();
    $financial['refunds'] = [['id' => 9021, 'created_at' => $financial['updated_at'], 'refund_line_items' => [['line_item_id' => 1001, 'quantity' => 2, 'restock_type' => 'no_restock']]]];
    $service->ingest($this->tenant->id, $this->shopKey, $financial);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(0);
    $returned = $financial;
    $returned['updated_at'] = $time->copy()->addSeconds(3)->toIso8601String();
    $returned['refunds'][] = ['id' => 9022, 'created_at' => $returned['updated_at'], 'refund_line_items' => [['line_item_id' => 1001, 'quantity' => 1, 'restock_type' => 'return', 'location_id' => 301]]];
    $service->ingest($this->tenant->id, $this->shopKey, $returned);
    $service->ingest($this->tenant->id, $this->shopKey, $returned);
    $service->ingest($this->tenant->id, $this->shopKey, $order);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(1)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['reserved_quantity'])->toBe(0);
    $cancel = $order;
    $cancel['id'] = 902;
    $cancel['cancelled_at'] = $time->toIso8601String();
    expect(fn () => $service->ingest($this->tenant->id, $this->shopKey, $cancel))->toThrow(\App\Services\Inventory\StockException::class, 'stock_cancellation_restock_evidence_required');
});

it('sells reviewed bundles with explicitly untracked components while retaining limits on counted components', function (): void {
    $parent = bin2hex(random_bytes(16));
    $unknown = bin2hex(random_bytes(16));
    foreach ([$parent => ['Show trio', 1800], $unknown => ['Uncounted show scent', 700]] as $id => [$title, $price]) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id,
            'title' => $title, 'sku' => $id, 'product_key' => $id, 'unit_amount_minor' => $price, 'tracked' => false, 'revision' => str_repeat('e', 64)]);
    }
    app(\App\Services\Inventory\StockBundles::class)->review($this->tenant->id, 'untracked-bundle', $this->actor->id,
        $parent, 3, [$this->item, $unknown], str_repeat('e', 64));
    $catalog = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    expect($catalog[$parent]['available_quantity'])->toBeNull()->and($catalog[$parent]['availability'])->toBe('available');
    $choice = ['item_id' => $parent, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'], 'components' => [
        ['item_id' => $this->item, 'quantity' => 3, 'revision' => $catalog[$this->item]['revision']]]];
    $this->postJson($this->base.'/quotes', ['request_id' => (string) Str::uuid(), 'items' => [$choice]])
        ->assertConflict()->assertJsonPath('error.code', 'stock_bundle_choices_changed');
    $choice['components'] = [['item_id' => $unknown, 'quantity' => 3, 'revision' => $catalog[$unknown]['revision']]];
    $quote = $this->postJson($this->base.'/quotes', ['request_id' => (string) Str::uuid(), 'items' => [$choice]])
        ->assertOk()->assertJsonPath('amount_minor', 1926)->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk()->assertJsonPath('collection.amount_minor', 1926);
    expect($this->stock->rows('balances', $this->tenant->id)->where('item_id', $unknown)->exists())->toBeFalse()
        ->and($this->stock->rows('reservations', $this->tenant->id)->where('item_id', $unknown)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('previews opening stock without writes and seeds old commitments without deducting past shipments twice', function (): void {
    $shop = ownedStockOnlineBinding($this);
    $shop->update(['scopes' => $shop->scopes.',read_orders']);
    $this->stock->rows('bindings', $this->tenant->id)->where('channel', 'shopify')->update(['settings' => '{}', 'publish_enabled' => false]);
    $order = ['id' => 901, 'created_at' => now()->subDays(2)->toIso8601String(), 'updated_at' => now()->subDay()->toIso8601String(),
        'line_items' => [['id' => 1001, 'variant_id' => 101, 'quantity' => 2]],
        'fulfillments' => [['id' => 991, 'created_at' => now()->subDay()->toIso8601String(), 'status' => 'success', 'location_id' => 301, 'line_items' => [['id' => 1001, 'quantity' => 1]]]], 'refunds' => []];
    $variant = ['id' => 'gid://shopify/ProductVariant/101', 'inventoryPolicy' => 'DENY', 'requiresComponents' => false,
        'product' => ['status' => 'ACTIVE', 'isGiftCard' => false], 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/201', 'tracked' => true,
            'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 1], ['name' => 'committed', 'quantity' => 1]]]]];
    Http::fake(['*/orders/901.json*' => Http::response(['order' => $order]), '*/graphql.json' => Http::response(['data' => [
        'shop' => ['id' => 'gid://shopify/Shop/401', 'currencyCode' => 'USD'], 'productVariants' => ['nodes' => [$variant], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);
    $input = ['request_id' => (string) Str::uuid(), 'store_id' => $shop->id, 'shop_id' => 'gid://shopify/Shop/401', 'location_id' => 'gid://shopify/Location/301',
        'pool_id' => $this->pool, 'physical_counts_reviewed' => true, 'expected_versions' => [$this->item => 1], 'opening_order_ids' => ['901']];
    $opening = app(\App\Services\Inventory\StockOnlineOpening::class);
    expect($opening->review($this->tenant->id, $this->actor->id, $input, false)['applied'])->toBeFalse()
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(0);
    $opening->review($this->tenant->id, $this->actor->id, $input, true);
    $opening->review($this->tenant->id, $this->actor->id, $input, true);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['reserved_quantity'])->toBe(1)
        ->and($this->stock->rows('online_orders', $this->tenant->id)->count())->toBe(1);
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities'));
});

it('never restores missing online commitments and retains the original inventory key after an uncertain response', function (): void {
    $shop = ownedStockOnlineBinding($this);
    $this->stock->count($this->tenant->id, 'more-physical-stock', $this->actor->id, $this->pool, [$this->item => 3], 'physical_count');
    $keys = [];
    Http::fake(['*/graphql.json' => function ($r) use (&$keys) {
        if (str_contains($r['query'], 'query StockLevel')) {
            return Http::response(['data' => ['inventoryItem' => ['id' => 'gid://shopify/InventoryItem/201', 'tracked' => true,
                'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 2], ['name' => 'committed', 'quantity' => 1]]]]]]);
        }
        $keys[] = $r['variables']['key'];
        if (count($keys) === 1) {
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost response');
        }

        return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['referenceDocumentUri' => 'fixture'], 'userErrors' => []]]]);
    }]);
    $publisher = app(\App\Services\Inventory\StockShopifyPublisher::class);
    $publisher->drain($this->tenant->id);
    expect($this->stock->rows('publications', $this->tenant->id)->value('error_code'))->toBe('stock_online_commitments_unreconciled');
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'inventorySetQuantities'));
    // A separate reviewed decrease can safely retry the SAME mutation after its first response is lost.
    $this->stock->rows('publications', $this->tenant->id)->update(['state' => 'superseded']);
    $this->stock->count($this->tenant->id, 'count-one', $this->actor->id, $this->pool, [$this->item => 1], 'physical_count');
    $publisher->drain($this->tenant->id);
    expect($this->stock->rows('publications', $this->tenant->id)->where('state', 'dispatching')->count())->toBe(1);
    $publisher->drain($this->tenant->id);
    $publisher->drain($this->tenant->id);
    expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1])
        ->and($this->stock->rows('publications', $this->tenant->id)->where('state', 'completed')->count())->toBe(1);
});

// Fictional live-mode fixtures exercise financial gates without provider traffic.
function ownedStockRewardsProgram($test, bool $multipliers = false): void
{
    config(['pos_live.square_environment' => 'production', 'pos_live.apple_team_id' => 'FICTIONALTEAM',
        'pos_live.distribution_verified' => true, 'pos_live.tap_entitlement_verified' => true,
        'pos_live.square_signature_verified' => true, 'pos_live.webhooks_verified' => true, 'cache.default' => 'database']);
    $test->withHeader('X-Pos-Build', 'fixture_build');
    $test->store->rows('connections', $test->tenant->id)->update(['environment' => 'production', 'acceptance_at' => now(), 'acceptance_build' => 'fixture_build']);
    $rules = ['first_order_reward_amount' => 3.50, 'event_rewards_enabled' => true, 'enable_milestone_rewards' => true,
        'enable_order_based_earning' => true, 'rewardable_channels' => 'show_issued_online_redeemed',
        'candle_club_multiplier_enabled' => $multipliers, 'candle_club_multiplier_value' => 2];
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10, 'second_order_reward_amount' => 1.25], 'candle_cash_policy_config' => ['earning_rules' => $rules]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $settings = $test->store->decrypt($test->store->rows('registers', $test->tenant->id)->first()->configuration);
    $settings['merchant_review'] = ['document_hash' => str_repeat('a', 64), 'markup_minor' => 0];
    $settings['customer_rewards'] = ['enabled' => true, 'threshold_mode' => 'highest_per_purchase',
        'policy_version' => app(\App\Services\Pos\PosCustomerRewards::class)->policy($test->tenant->id)['version']];
    $test->store->rows('registers', $test->tenant->id)->update(['configuration' => $test->store->encrypt($settings)]);
}

function ownedStockRewardCredential($test): array
{
    ownedStockRewardsProgram($test);
    config(['pos_live.in_person_rewards_enabled' => true, 'pos_live.durable_start_claims_enabled' => true]);
    $policy = ['enabled' => true, 'max_minor' => 500, 'refund_policy' => 'restore_full_review_partial'];
    $program = app(\App\Services\Pos\PosCustomerRewards::class)->policy($test->tenant->id);
    $version = app(\App\Services\Pos\PosRewardRedemptionSettings::class)->version($policy, $program['version']);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => \App\Services\Pos\PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$policy, 'reviewed_version' => $version, 'reviewed_by' => $test->actor->id, 'reviewed_at' => now()->toIso8601String()]]);
    $register = $test->store->rows('registers', $test->tenant->id)->first();
    $settings = $test->store->decrypt($register->configuration);
    $settings['pos_candle_cash_reviewed_version'] = $version;
    $test->store->rows('registers', $test->tenant->id)->update(['configuration' => $test->store->encrypt($settings)]);
    \App\Models\CandleCashBalance::create(['marketing_profile_id' => $test->profile->id, 'balance' => 10]);
    $link = \Tests\Support\PosVerifiedAccountFixture::create($test->profile);
    $accepted = ['max_minor' => 500, 'policy_version' => $version, 'disclosure_version' => \App\Services\Pos\PosRewardCustomerProof::DISCLOSURE_VERSION];

    return app(\App\Services\Pos\PosRewardCustomerProof::class)->issueForAuthenticatedProfile($test->profile, $accepted, $link);
}

it('atomically binds one capped reward to the original quote and taxes the discounted subtotal', function (): void {
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 535)->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    expect($snapshot['terms']['discount_minor'])->toBe(500)->and($snapshot['terms']['tax_minor'])->toBe(35)
        ->and($snapshot['terms']['pos_candle_cash']['profile_id'])->toBe($this->profile->id)
        ->and(json_encode($snapshot))->not->toContain($credential['qr_payload'])
        ->and(\App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(5.0)
        ->and(DB::table('pos_candle_cash_reservations')->where('sale_id', $sale->id)->count())->toBe(1);
    $this->travel(3)->minutes();
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $sale->id);
    expect(DB::table('pos_candle_cash_reservations')->where('sale_id', $sale->id)->count())->toBe(1);
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertOk();
    expect(\App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0);
});

it('rolls back the quote and proof when the wallet cannot cover its authorized amount', function (): void {
    $credential = ownedStockRewardCredential($this);
    \App\Models\CandleCashBalance::find($this->profile->id)->update(['balance' => 1]);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $this->postJson($this->base.'/quotes', $input)->assertStatus(422)->assertJsonPath('error.code', 'reward_amount_unavailable');
    expect($this->store->rows('sales', $this->tenant->id)->where('request_id', $input['request_id'])->count())->toBe(0)
        ->and(DB::table('pos_candle_cash_reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(app(\App\Services\Pos\PosRewardCustomerProof::class)->preview($this->tenant->id, $credential['qr_payload'])->sale_id)->toBeNull();
});

it('keeps a post-quote identity conflict from redirecting the reward hold or starting collection', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Production payment bindings require the durable MySQL journal.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $originalHold = DB::table('pos_candle_cash_reservations')->where('sale_id', $quote['sale_id'])->first();
    $proof = DB::table('pos_reward_authorizations')->where('id', $originalHold->authorization_reference)->first();
    $link = \App\Models\MarketingProfileLink::findOrFail($proof->account_link_id);
    $gid = $link->source_meta['shopify_customer_gid'];
    $other = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    \App\Models\CandleCashBalance::create(['marketing_profile_id' => $other->id, 'balance' => 12]);
    \App\Models\CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id,
        'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail',
        'external_customer_id' => basename($gid), 'external_customer_gid' => $gid]);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertStatus(409)
        ->assertJsonPath('error.code', 'customer_identity_review_required')->assertJsonMissingPath('collection');
    expect((int) DB::table('pos_candle_cash_reservations')->where('id', $originalHold->id)->value('marketing_profile_id'))->toBe($this->profile->id)
        ->and((float) \App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(5.0)
        ->and((float) \App\Models\CandleCashBalance::find($other->id)->balance)->toBe(12.0)
        ->and(DB::table('pos_payment_start_claims')->where('sale_id', $quote['sale_id'])->count())->toBe(0);
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertOk();
    expect((float) \App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0)
        ->and((float) \App\Models\CandleCashBalance::find($other->id)->balance)->toBe(12.0);
});

it('starts the original reward-discounted payment only once when the verified account remains current', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Production payment bindings require the durable MySQL journal.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonPath('collection.amount_minor', 535);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertOk()->assertJsonMissingPath('collection');
    expect(DB::table('pos_candle_cash_reservations')->where('sale_id', $quote['sale_id'])->value('state'))->toBe('held')
        ->and(DB::table('payment_core_operations')->where('sale_id', $quote['sale_id'])->where('kind', 'payment')->count())->toBe(1);
});

it('safely cancels a native preparation that stopped before the journal handoff', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $claim = app(\App\Services\Pos\PosPaymentStartClaim::class)->beginNativePreparation($this->tenant->id, $sale->id, $snapshot['terms']['pos_candle_cash']);
    expect($claim->state)->toBe('preparing_native_v1');
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertOk();
    expect(DB::table('pos_payment_start_claims')->where('id', $claim->id)->value('state'))->toBe('cancelled')
        ->and((float) \App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0);
    $binding = \App\Services\PaymentsCore\MerchantBinding::restore($snapshot['binding']);
    $frozen = new \App\Services\PaymentsCore\SaleSnapshot($sale->id, 'usd', $snapshot['sale']['lines']);
    expect(fn () => $this->journal->handoffNative($binding, $frozen, $sale->payment_request_id, $claim->id))
        ->toThrow(DomainException::class, 'Native handoff is no longer available');
    expect(DB::table('payment_core_operations')->where('sale_id', $sale->id)->count())->toBe(0);
});

it('does not upgrade or cancel an earlier legacy starting claim', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    config(['pos_live.durable_start_claims_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $claims = app(\App\Services\Pos\PosPaymentStartClaim::class);
    $legacy = $claims->beginCollection($this->tenant->id, $quote['sale_id']);
    expect($legacy->state)->toBe('starting');
    expect(fn () => $claims->cancel($this->tenant->id, $quote['sale_id']))
        ->toThrow(\App\Services\Pos\PosException::class, 'payment_claim_conflict');
    expect(fn () => $claims->beginNativePreparation($this->tenant->id, $quote['sale_id']))
        ->toThrow(\App\Services\Pos\PosException::class, 'payment_claim_conflict');
    expect(DB::table('pos_payment_start_claims')->where('id', $legacy->id)->value('state'))->toBe('starting');
});

it('automatically expires an interrupted native preparation without releasing an issued payment', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $claim = app(\App\Services\Pos\PosPaymentStartClaim::class)->beginNativePreparation($this->tenant->id, $sale->id, $snapshot['terms']['pos_candle_cash']);
    $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->update(['expires_at' => now()->subMinute()]);
    app(\App\Services\Pos\PosService::class)->expireUnstarted($sale);
    expect(DB::table('pos_payment_start_claims')->where('id', $claim->id)->value('state'))->toBe('cancelled')
        ->and($this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->value('state'))->toBe('cancelled')
        ->and((float) \App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0)
        ->and(DB::table('payment_core_operations')->where('sale_id', $sale->id)->count())->toBe(0);
});

it('keeps a lost native handoff response tied to its one original payment', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    config(['pos_live.durable_start_claims_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $claim = app(\App\Services\Pos\PosPaymentStartClaim::class)->beginNativePreparation($this->tenant->id, $sale->id);
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id,
        'register' => $this->store->rows('registers', $this->tenant->id)->firstOrFail()];
    $binding = \App\Services\PaymentsCore\MerchantBinding::restore($snapshot['binding']);
    $frozen = new \App\Services\PaymentsCore\SaleSnapshot($sale->id, 'usd', $snapshot['sale']['lines']);
    expect(fn () => $this->journal->handoffNative($binding, $frozen, $sale->payment_request_id, $claim->id))
        ->toThrow(DomainException::class, 'Native inventory reservation requires review');
    expect(DB::table('payment_core_operations')->where('sale_id', $sale->id)->count())->toBe(0);
    app(\App\Services\Pos\StockPosCommerce::class)->lockCollection($scope, $sale, $snapshot);
    $original = $this->journal->handoffNative($binding, $frozen, $sale->payment_request_id, $claim->id);
    // The response is considered lost: a retry may inspect but never return a replacement descriptor.
    $this->postJson($this->base.'/sales/'.$sale->id.'/collect', [])->assertOk()->assertJsonMissingPath('collection');
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertConflict();
    expect($original['state'])->toBe('unknown')
        ->and(DB::table('pos_payment_start_claims')->where('id', $claim->id)->value('state'))->toBe('handed_off')
        ->and(DB::table('payment_core_operations')->where('sale_id', $sale->id)->count())->toBe(1)
        ->and($this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->value('attempt_id'))->toBe($original['id']);
});

it('rolls back the entire native handoff when persistence fails before commit', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Atomic native handoff requires the durable MySQL journal.');
    }
    config(['pos_live.durable_start_claims_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $claim = app(\App\Services\Pos\PosPaymentStartClaim::class)->beginNativePreparation($this->tenant->id, $sale->id);
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id,
        'register' => $this->store->rows('registers', $this->tenant->id)->firstOrFail()];
    app(\App\Services\Pos\StockPosCommerce::class)->lockCollection($scope, $sale, $snapshot);
    $realCrypt = app('encrypter');
    $crypt = Mockery::mock(\Illuminate\Contracts\Encryption\Encrypter::class);
    $crypt->shouldReceive('decrypt')->andReturnUsing(fn (...$args) => $realCrypt->decrypt(...$args));
    $crypt->shouldReceive('encrypt')->andReturnUsing(function ($value, $serialize = true) use ($realCrypt) {
        if ($value === '[]') {
            throw new RuntimeException('fictional late journal failure');
        }

        return $realCrypt->encrypt($value, $serialize);
    });
    $failing = new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), $crypt);
    $binding = \App\Services\PaymentsCore\MerchantBinding::restore($snapshot['binding']);
    $frozen = new \App\Services\PaymentsCore\SaleSnapshot($sale->id, 'usd', $snapshot['sale']['lines']);
    expect(fn () => $failing->handoffNative($binding, $frozen, $sale->payment_request_id, $claim->id))
        ->toThrow(RuntimeException::class, 'fictional late journal failure');
    $current = $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->firstOrFail();
    expect($current->attempt_id)->toBeNull()->and($current->descriptor_issued_at)->toBeNull()
        ->and(DB::table('payment_core_operations')->where('sale_id', $sale->id)->count())->toBe(0)
        ->and(DB::table('pos_payment_start_claims')->where('id', $claim->id)->value('state'))->toBe('preparing_native_v1');
    $this->postJson($this->base.'/requests/'.$quote['request_id'].'/discard')->assertOk();
});

it('serializes native handoff against cancellation without issuing a replacement charge', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe native handoff race.');
    }
    config(['pos_live.durable_start_claims_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $claim = app(\App\Services\Pos\PosPaymentStartClaim::class)->beginNativePreparation($this->tenant->id, $sale->id);
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id,
        'register' => $this->store->rows('registers', $this->tenant->id)->firstOrFail()];
    app(\App\Services\Pos\StockPosCommerce::class)->lockCollection($scope, $sale, $snapshot);
    $binding = \App\Services\PaymentsCore\MerchantBinding::restore($snapshot['binding']);
    $frozen = new \App\Services\PaymentsCore\SaleSnapshot($sale->id, 'usd', $snapshot['sale']['lines']);
    $directory = sys_get_temp_dir().'/pos-handoff-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach (['handoff', 'cancel'] as $i => $action) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork native handoff race.');
            }
            if ($pid === 0) {
                try {
                    while (! file_exists($directory.'/go')) {
                        usleep(1000);
                        clearstatcache();
                    }
                    try {
                        if ($action === 'handoff') {
                            $journal = new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), app('encrypter'));
                            $journal->handoffNative($binding, $frozen, $sale->payment_request_id, $claim->id);
                        } else {
                            $claims = new \App\Services\Pos\PosPaymentStartClaim(new PosStore(DB::connection(), app('encrypter')));
                            $claims->cancel($this->tenant->id, $sale->id);
                        }
                        $result = 'won';
                    } catch (DomainException $error) {
                        $result = 'refused';
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        touch($directory.'/go');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [file_get_contents($directory.'/0'), file_get_contents($directory.'/1')];
        expect($results)->toBeIn([['won', 'refused'], ['refused', 'won']]);
        $state = DB::table('pos_payment_start_claims')->where('id', $claim->id)->value('state');
        $operationCount = DB::table('payment_core_operations')->where('sale_id', $sale->id)->count();
        expect($state)->toBe($results[0] === 'won' ? 'handed_off' : 'cancelled')
            ->and($operationCount)->toBe($results[0] === 'won' ? 1 : 0);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('does not collect a previously quoted reward after the global gate is turned off', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Production payment bindings require the durable MySQL journal.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->json();
    config(['pos_live.in_person_rewards_enabled' => false]);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertStatus(503)
        ->assertJsonPath('error.code', 'reward_payment_fence_not_ready')->assertJsonMissingPath('collection');
    expect(DB::table('pos_payment_start_claims')->where('sale_id', $quote['sale_id'])->count())->toBe(0)
        ->and(DB::table('pos_candle_cash_reservations')->where('sale_id', $quote['sale_id'])->value('state'))->toBe('held');
});

it('sends the capped reward as a discounted price before Shopify calculates tax', function (): void {
    $credential = ownedStockRewardCredential($this);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail',
        'shop_domain' => 'reward-fixture.myshopify.com', 'access_token' => 'fixture-token']);
    $variant = 'gid://shopify/ProductVariant/101';
    $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $shop->id,
        'item_id' => $this->item, 'variant_id' => $variant, 'location_id' => 'gid://shopify/Location/301',
        'unit_amount_minor' => 1000, 'revision' => str_repeat('d', 64)]);
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['shopify'] = ['store_id' => $shop->id, 'shop_domain' => $shop->shop_domain,
        'location_id' => 'gid://shopify/Location/301', 'tax_address' => ['countryCode' => 'US', 'zip' => '00000']];
    $this->store->rows('registers', $this->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $this->store->encrypt($settings)]);
    $received = [];
    Http::fake(['https://reward-fixture.myshopify.com/*' => function ($request) use (&$received, $variant) {
        $received = $request['variables']['input'];
        $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];

        return Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
            'taxesIncluded' => false, 'totalPriceSet' => $money('5.35'), 'totalTaxSet' => $money('0.35'),
            'lineItems' => [['variant' => ['id' => $variant], 'quantity' => 1, 'originalUnitPriceSet' => $money('10.00'),
                'discountedTotalSet' => $money('5.00')]],
        ]]]]);
    }]);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 535);
    expect($received['lineItems'][0]['appliedDiscount']['value'])->toEqual(5)
        ->and($received['acceptAutomaticDiscounts'])->toBeFalse();
});

it('binds one scanned reward to only one of two concurrent quote requests on MySQL', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe reward quote race.');
    }
    $credential = ownedStockRewardCredential($this);
    $input = [...$this->selection, 'reward_redemption' => ['proof' => $credential['qr_payload'], 'amount_minor' => 500]];
    $directory = sys_get_temp_dir().'/pos-reward-quote-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach (range(0, 1) as $i) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork reward quote race.');
            }
            if ($pid === 0) {
                try {
                    while (! file_exists($directory.'/go')) {
                        usleep(1000);
                        clearstatcache();
                    }
                    $request = [...$input, 'request_id' => (string) Str::uuid()];
                    $response = $this->postJson($this->base.'/quotes', $request);
                    file_put_contents($directory.'/'.$i, (string) $response->getStatusCode());
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        touch($directory.'/go');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $statuses = [file_get_contents($directory.'/0'), file_get_contents($directory.'/1')];
        sort($statuses);
        expect($statuses)->toBe(['200', '403'])
            ->and($this->store->rows('sales', $this->tenant->id)->count())->toBe(1)
            ->and(DB::table('pos_candle_cash_reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
            ->and((float) \App\Models\CandleCashBalance::find($this->profile->id)->balance)->toBe(5.0);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('reviews amount-only partial refunds once and reverses rewards after the full refund', function (string $scenario): void {
    config(['pos_live.partial_refund_reward_review_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $snapshot['binding']['environment'] = 'live';
    $snapshot['customer'] = ['id' => $this->profile->id, 'name' => 'Ada Buyer', 'shopify_customer_id' => null];
    $snapshot['customer_rewards'] = ['enabled' => true, 'version' => str_repeat('a', 64), 'order_number' => 1,
        'member' => false, 'program' => [], 'rules' => ['enable_order_based_earning' => true,
            'enable_milestone_rewards' => true, 'first_order_reward_amount' => 3.50]];
    $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->update([
        'state' => 'succeeded', 'attempt_id' => 'fixture-payment-operation', 'snapshot' => $this->store->encrypt($snapshot),
        'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot),
    ]);
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->firstOrFail();
    $payment = ['id' => $sale->attempt_id, 'kind' => 'payment', 'state' => 'succeeded', 'sale_id' => $sale->id,
        'provider_id' => 'fixture-provider-payment', 'request_id' => $sale->payment_request_id,
        'amount_minor' => (int) $sale->amount_minor, 'binding' => $snapshot['binding']];
    $partial = ['id' => 'fixture-refund-one', 'kind' => 'refund', 'state' => 'succeeded', 'original_id' => $sale->attempt_id,
        'original_provider_id' => $payment['provider_id'], 'provider_id' => 'fixture-provider-refund-one', 'amount_minor' => 500];
    $remainder = [...$partial, 'id' => 'fixture-refund-two', 'provider_id' => 'fixture-provider-refund-two', 'amount_minor' => 570];
    $journal = \Mockery::mock(\App\Services\PaymentsCore\PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(fn ($tenant, $id) => match ($id) {
        $payment['id'] => $payment, $partial['id'] => $partial, $remainder['id'] => $remainder,
        default => throw new \RuntimeException('Unexpected fixture operation'),
    });
    app()->instance(\App\Services\PaymentsCore\PaymentJournal::class, $journal);
    $rewards = app(\App\Services\Pos\PosCustomerRewards::class);
    if ($scenario !== 'unawarded') {
        $rewards->apply($sale);
    }
    expect((float) (\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance') ?? 0))
        ->toBe($scenario === 'unawarded' ? 0.0 : 3.50);

    app(\App\Services\Pos\PosService::class)->enqueue($this->tenant->id, $sale->id, 'customer_refund_fixture', ['operation_id' => $partial['id']]);
    $scope = ['tenant_id' => $this->tenant->id, 'register' => $this->store->rows('registers', $this->tenant->id)->firstOrFail()];
    app(\App\Services\Pos\PosOutbox::class)->drainSale($scope, $sale->id);
    $effect = $this->store->rows('outbox', $this->tenant->id)->where('sale_id', $sale->id)->where('effect_key', 'customer_refund_fixture')->firstOrFail();
    expect($effect->state)->toBe('review')->and($effect->error_code)->toBe('customer_rewards_refund_allocation_review')
        ->and((float) (\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance') ?? 0))
        ->toBe($scenario === 'unawarded' ? 0.0 : 3.50);
    expect(fn () => $rewards->apply($sale, $partial))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_refund_allocation_review');
    expect($this->store->rows('events', $this->tenant->id)->where('sale_id', $sale->id)->where('code', 'customer_refund_allocation_review')->count())->toBe(1);
    config(['pos_live.partial_refund_reward_review_enabled' => false]);
    app(\App\Services\Pos\PosOutbox::class)->drainSale($scope, $sale->id);
    expect($this->store->rows('outbox', $this->tenant->id)->where('id', $effect->id)->value('state'))->toBe('review')
        ->and((float) (\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance') ?? 0))
        ->toBe($scenario === 'unawarded' ? 0.0 : 3.50);
    config(['pos_live.partial_refund_reward_review_enabled' => true]);

    if ($scenario === 'spent') {
        \App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->update(['balance' => 0]);
        expect(fn () => $rewards->apply($sale, $remainder))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_shortfall_review');
        expect($this->store->rows('outbox', $this->tenant->id)->where('id', $effect->id)->value('state'))->toBe('review')
            ->and($this->store->rows('events', $this->tenant->id)->where('sale_id', $sale->id)->where('code', 'customer_rewards_shortfall')->count())->toBe(1);
        \App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->update(['balance' => 3.50]);
    }
    $rewards->apply($sale, $remainder);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0)
        ->and($this->store->rows('outbox', $this->tenant->id)->where('id', $effect->id)->value('state'))->toBe('completed')
        ->and($this->store->rows('events', $this->tenant->id)->where('sale_id', $sale->id)->where('code', 'customer_refund_review_superseded')->count())->toBe(1)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())
        ->toBe($scenario === 'unawarded' ? 0 : 2);
})->with(['available', 'spent', 'unawarded']);

it('confirms partial and full Square refunds without returning merchandise to stock', function (string $association): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Durable refund journal requires disposable MySQL.');
    }
    if ($association !== 'unassociated') {
        ownedStockRewardsProgram($this);
    }
    $quote = ownedStockComplete($this);
    $associationUrl = $this->base.'/sales/'.$quote['sale_id'].'/receipt/customer';
    $customer = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    if (in_array($association, ['before', 'spent'], true)) {
        $this->postJson($associationUrl, $customer)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
        expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.50);
        if ($association === 'spent') {
            \App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->update(['balance' => 0]);
        }
    }
    $refunds = [];
    Http::fake(['*/v2/refunds*' => function ($r) use (&$refunds) {
        if ($r->method() === 'POST') {
            $id = 'fixture_refund_'.count($refunds);
            $refunds[$id] = ['id' => $id, 'payment_id' => 'fixture_payment', 'location_id' => 'fixture_location',
                'reason' => $r['reason'], 'amount_money' => $r['amount_money'], 'status' => 'COMPLETED'];

            return Http::response(['refund' => ['id' => $id]]);
        }
        $id = basename(parse_url($r->url(), PHP_URL_PATH));

        return Http::response(isset($refunds[$id]) ? ['refund' => $refunds[$id]] : ['refunds' => array_values($refunds)]);
    }]);
    foreach ([500, 570] as $amount) {
        $input = ['request_id' => (string) Str::uuid(), 'amount_minor' => $amount, 'reason' => 'requested_by_customer'];
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/refunds', $input)->assertOk()->assertJsonPath('state', 'succeeded');
    }
    $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertOk()->assertJsonPath('refunded_minor', 1070);
    expect($refunds)->toHaveCount(2)->and($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(1)
        ->and($this->stock->rows('movements', $this->tenant->id)->where('reason', 'sale')->count())->toBe(1);
    if ($association === 'after') {
        $this->postJson($associationUrl, $customer)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    }
    if ($association !== 'unassociated') {
        expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0);
        $expected = ['before' => 2, 'after' => 0, 'spent' => 1][$association];
        expect(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe($expected);
    }
    if ($association === 'spent') {
        $this->getJson($this->base.'/sales/'.$quote['sale_id'].'/receipt')->assertOk()->assertJsonPath('customer_rewards_status', 'review_required');
    }
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'myshopify.com'));
})->with(['unassociated', 'before', 'after', 'spent']);

it('awards a guest purchase once after association and lets an owner resolve original-time eligibility review', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The reference SQLite journal deliberately rejects live-mode collection; this integration runs in the MySQL gate.');
    }
    ownedStockRewardsProgram($this, true);
    $quote = ownedStockComplete($this);
    $url = $this->base.'/sales/'.$quote['sale_id'].'/receipt/customer';
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'review_required');
    expect(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(0);
    $review = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id,
        'reward_evidence' => ['member_at_purchase' => false, 'prior_confirmed_orders' => 0, 'reason' => 'Fictional original purchase history reviewed']];
    $this->postJson($url, $review)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    $this->postJson($url, $review)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.50)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(1);
    $view = app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect(collect($view['purchases'])->where('channel', 'in_person'))->toHaveCount(1);
});

it('verifies prior in-person history before associating the next guest purchase and earns the second milestone once', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This regression exercises committed MySQL journal boundaries.');
    }
    ownedStockRewardsProgram($this);
    $first = ownedStockComplete($this);
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $this->profile->id];
    $this->postJson($this->base.'/sales/'.$first['sale_id'].'/receipt/customer', $input)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    $this->travel(2)->seconds();
    $this->selection['request_id'] = (string) Str::uuid();
    $second = ownedStockComplete($this, null, 'fixture_second_payment');
    $input['request_id'] = (string) Str::uuid();
    $this->postJson($this->base.'/sales/'.$second['sale_id'].'/receipt/customer', $input)->assertOk()->assertJsonPath('receipt.customer_rewards_status', 'processed');
    $this->postJson($this->base.'/sales/'.$second['sale_id'].'/receipt/customer', $input)->assertOk();
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(4.75)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(2);
});

it('parks an explicitly ended no-card interaction without financial cancellation and admits only a different sale', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect')->assertOk();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first();
    Http::fake(['*/v2/payments?*' => Http::response(['payments' => []])]);
    $body = ['sdk_attempt_id' => $sale->sdk_attempt_id, 'sdk_flow_ended' => true, 'no_card_presented' => true];
    foreach (['sdk_flow_ended', 'no_card_presented'] as $key) {
        $this->postJson($this->base.'/sales/'.$sale->id.'/leave-for-review', [...$body, $key => false])->assertConflict();
    }
    $this->postJson($this->base.'/sales/'.$sale->id.'/leave-for-review', [...$body, 'sdk_attempt_id' => (string) Str::uuid()])->assertConflict();
    $this->withHeader('X-Pos-Device', 'other-device');
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    $this->postJson($this->base.'/sales/'.$sale->id.'/leave-for-review', $body)->assertNotFound();
    $this->withHeader('X-Pos-Device', 'fixture_device_1234');
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    for ($i = 0; $i < 2; $i++) {
        $this->postJson($this->base.'/sales/'.$sale->id.'/leave-for-review', $body)->assertOk()
            ->assertJsonPath('state', 'unknown')->assertJsonPath('parked_for_review', true)
            ->assertJsonPath('receipt_available', false)->assertJsonPath('stock_state', 'held');
    }
    expect($this->journal->get($this->tenant->id, $sale->attempt_id)['parked_for_review'])->toBeTrue()
        ->and($this->store->rows('events', $this->tenant->id)->where('code', 'payment_parked_for_review')->count())->toBe(1);
    $this->postJson($this->base.'/sales/'.$sale->id.'/collect')->assertOk()->assertJsonMissingPath('collection');
    $this->selection['request_id'] = (string) Str::uuid();
    $next = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $this->postJson($this->base.'/sales/'.$next['sale_id'].'/collect')->assertOk()->assertJsonPath('state', 'unknown');
    ownedStockComplete($this, $quote);
    $this->postJson($this->base.'/sales/'.$sale->id.'/leave-for-review', $body)->assertOk()->assertJsonPath('state', 'succeeded')->assertJsonPath('parked_for_review', false);
    expect(DB::table('orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('keeps two mixes of one bundle distinct while pricing, discounting and reserving shared stock', function (): void {
    $parent = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    foreach ([$parent => ['Bundle', 2500], $other => ['Other candle', 1000]] as $id => [$title, $price]) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'title' => $title,
            'sku' => $id, 'product_key' => $id, 'unit_amount_minor' => $price, 'revision' => str_repeat('e', 64)]);
    }
    $this->stock->count($this->tenant->id, 'multiple-mix-count', $this->actor->id, $this->pool,
        [$this->item => 4, $other => 4], 'opening_count');
    app(\App\Services\Inventory\StockBundles::class)->review($this->tenant->id, 'multiple-mix-review', $this->actor->id,
        $parent, 3, [$this->item, $other], str_repeat('e', 64));
    $catalog = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    $firstId = bin2hex(random_bytes(16));
    $secondId = bin2hex(random_bytes(16));
    $first = ['item_id' => $parent, 'line_id' => $firstId, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'],
        'components' => [['item_id' => $this->item, 'quantity' => 3, 'revision' => $catalog[$this->item]['revision']]]];
    $second = ['item_id' => $parent, 'line_id' => $secondId, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'],
        'components' => [['item_id' => $other, 'quantity' => 3, 'revision' => $catalog[$other]['revision']]]];
    $offers = configureCheckoutOffers($this);
    $input = ['request_id' => (string) Str::uuid(), 'items' => [$first, $second, $this->selection['items'][0]],
        'checkout_rules_version' => $offers['checkout_rules_version'],
        'discounts' => [['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $firstId, 'quantity' => 1]]];
    $ambiguous = $input;
    $ambiguous['request_id'] = (string) Str::uuid();
    $ambiguous['discounts'][0]['item_id'] = $parent;
    $this->postJson($this->base.'/quotes', $ambiguous)->assertStatus(422)->assertJsonPath('error.code', 'checkout_discount_line_ambiguous');
    $duplicate = $input;
    $duplicate['request_id'] = (string) Str::uuid();
    $duplicate['items'][1]['line_id'] = $firstId;
    $this->postJson($this->base.'/quotes', $duplicate)->assertStatus(422);
    $stale = $input;
    $stale['request_id'] = (string) Str::uuid();
    $stale['items'][1]['revision'] = str_repeat('0', 64);
    $this->postJson($this->base.'/quotes', $stale)->assertConflict()->assertJsonPath('error.code', 'catalog_changed');
    $insufficient = $input;
    $insufficient['request_id'] = (string) Str::uuid();
    $insufficient['items'][2]['quantity'] = 2;
    $this->postJson($this->base.'/quotes', $insufficient)->assertConflict()->assertJsonPath('error.code', 'stock_unavailable');
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 6313)->json();
    $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('sale_id', $quote['sale_id']);
    $this->getJson($this->base.'/sales/'.$quote['sale_id'])->assertOk()
        ->assertJsonPath('lines.0.line_id', $firstId)->assertJsonPath('lines.1.line_id', $secondId)
        ->assertJsonPath('lines.0.components.0.quantity', 3)->assertJsonPath('lines.1.components.0.quantity', 3);
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect(array_column($snapshot['terms']['display'], 'line_id'))->toBe([$firstId, $secondId, $this->item])
        ->and(array_column($snapshot['terms']['display'], 'discount_minor'))->toBe([100, 0, 0])
        ->and($snapshot['terms']['stock'])->toBe([$this->item => 4, $other => 3]);
    $edited = $input;
    $edited['items'][0]['components'] = [
        ['item_id' => $this->item, 'quantity' => 2, 'revision' => $catalog[$this->item]['revision']],
        ['item_id' => $other, 'quantity' => 1, 'revision' => $catalog[$other]['revision']],
    ];
    $this->postJson($this->base.'/quotes', $edited)->assertConflict()->assertJsonPath('error.code', 'request_conflict');
    $this->postJson($this->base.'/requests/'.$input['request_id'].'/discard', [])->assertOk()->assertJsonPath('discarded', true);
    $edited['request_id'] = (string) Str::uuid();
    $next = $this->postJson($this->base.'/quotes', $edited)->assertOk()->assertJsonPath('amount_minor', 6313)->json();
    expect($next['sale_id'])->not->toBe($quote['sale_id']);
    $this->getJson($this->base.'/sales/'.$next['sale_id'])->assertOk()
        ->assertJsonPath('lines.0.line_id', $firstId)->assertJsonPath('lines.1.line_id', $secondId)
        ->assertJsonPath('lines.0.components.0.quantity', 2)->assertJsonPath('lines.1.components.0.quantity', 3);
    $saved = $this->store->rows('sales', $this->tenant->id)->where('id', $next['sale_id'])->first();
    expect($saved->attempt_id)->toBeNull()->and($saved->descriptor_issued_at)->toBeNull();
});

it('checks each Shopify-priced bundle line and rejects wrong aggregate tax totals', function (): void {
    $parent = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    foreach ([$parent => ['Bundle', 2500], $other => ['Other candle', 1000]] as $id => [$title, $price]) {
        $this->stock->rows('items', $this->tenant->id)->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'title' => $title,
            'sku' => $id, 'product_key' => $id, 'unit_amount_minor' => $price, 'revision' => str_repeat('e', 64)]);
    }
    $this->stock->count($this->tenant->id, 'shopify-mixes-count', $this->actor->id, $this->pool,
        [$this->item => 3, $other => 3], 'opening_count');
    app(\App\Services\Inventory\StockBundles::class)->review($this->tenant->id, 'shopify-mixes-review', $this->actor->id,
        $parent, 3, [$this->item, $other], str_repeat('e', 64));
    $offers = configureCheckoutOffers($this);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'mix-test-'.$this->tenant->id,
        'shop_domain' => 'mix-test-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fixture-token']);
    $parentVariant = 'gid://shopify/ProductVariant/301';
    foreach ([$parent => [$parentVariant, 2500], $this->item => ['gid://shopify/ProductVariant/302', 1000], $other => ['gid://shopify/ProductVariant/303', 1000]] as $id => [$variant, $price]) {
        $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $shop->id,
            'item_id' => $id, 'variant_id' => $variant, 'location_id' => 'gid://shopify/Location/301', 'unit_amount_minor' => $price, 'revision' => str_repeat('e', 64)]);
    }
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['shopify'] = ['store_id' => $shop->id, 'location_id' => 'gid://shopify/Location/301', 'tax_address' => ['countryCode' => 'US', 'zip' => '00000']];
    $this->store->rows('registers', $this->tenant->id)->update(['catalog_source' => 'shopify', 'configuration' => $this->store->encrypt($settings)]);
    $catalog = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    $firstId = bin2hex(random_bytes(16));
    $secondId = bin2hex(random_bytes(16));
    $input = ['request_id' => (string) Str::uuid(), 'items' => [
        ['item_id' => $parent, 'line_id' => $firstId, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'],
            'components' => [['item_id' => $this->item, 'quantity' => 3, 'revision' => $catalog[$this->item]['revision']]]],
        ['item_id' => $parent, 'line_id' => $secondId, 'quantity' => 1, 'revision' => $catalog[$parent]['revision'],
            'components' => [['item_id' => $other, 'quantity' => 3, 'revision' => $catalog[$other]['revision']]]],
    ], 'checkout_rules_version' => $offers['checkout_rules_version'],
        'discounts' => [['rule_id' => 'fixed', 'scope' => 'item', 'item_id' => $firstId, 'quantity' => 1]]];
    $received = [];
    $wrong = true;
    Http::fake(['https://mix-test-*.myshopify.com/*' => function ($request) use (&$received, &$wrong, $parentVariant) {
        $received = $request['variables']['input'];
        $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];

        return Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
            'taxesIncluded' => false, 'totalPriceSet' => $money('52.43'), 'totalTaxSet' => $money('3.43'),
            'lineItems' => [
                ['variant' => ['id' => $parentVariant], 'quantity' => 1, 'originalUnitPriceSet' => $money('25.00'), 'discountedTotalSet' => $money($wrong ? '23.00' : '24.00')],
                ['variant' => ['id' => $parentVariant], 'quantity' => 1, 'originalUnitPriceSet' => $money('25.00'), 'discountedTotalSet' => $money($wrong ? '26.00' : '25.00')],
            ],
        ]]]]);
    }]);
    $this->postJson($this->base.'/quotes', $input)->assertStatus(409)->assertJsonPath('error.code', 'total_changed');
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
    $wrong = false;
    $quote = $this->postJson($this->base.'/quotes', $input)->assertOk()->assertJsonPath('amount_minor', 5243)->json();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail());
    expect($received['lineItems'])->toHaveCount(2)->and($received['lineItems'][0]['appliedDiscount']['value'])->toBe(1)
        ->and($received['lineItems'][1])->not->toHaveKey('appliedDiscount')
        ->and(array_column($snapshot['terms']['display'], 'line_id'))->toBe([$firstId, $secondId])
        ->and($snapshot['terms']['stock'])->toBe([$this->item => 3, $other => 3]);
});
