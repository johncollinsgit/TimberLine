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

function customerPosConfirm($test, array $rules = [], array $programOverrides = []): object
{
    config(['pos_live.square_environment' => 'production']);
    $test->store->rows('connections', $test->tenant->id)->update(['environment' => 'production']);
    $program = array_replace(['second_order_reward_amount' => 5, 'redeem_increment_dollars' => 10], $programOverrides);
    $policy = ['earning_rules' => array_replace(['first_order_reward_amount' => 5,
        'event_rewards_enabled' => true, 'enable_milestone_rewards' => true, 'enable_order_based_earning' => true,
        'rewardable_channels' => 'show_issued_online_redeemed'], $rules)];
    foreach (['candle_cash_program_config' => $program, 'candle_cash_policy_config' => $policy] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $settings = $test->store->decrypt($test->store->rows('registers', $test->tenant->id)->first()->configuration);
    $settings['customer_required'] = true;
    $settings['customer_rewards'] = ['enabled' => true, 'threshold_mode' => 'highest_per_purchase',
        'policy_version' => app(\App\Services\Pos\PosCustomerRewards::class)->policy($test->tenant->id)['version']];
    $test->store->rows('registers', $test->tenant->id)->update(['configuration' => $test->store->encrypt($settings)]);
    $quote = $test->postJson($test->base.'/quotes', $test->selection)->assertOk()->json();
    $test->store->rows('sales', $test->tenant->id)->where('id', $quote['sale_id'])->update([
        'state' => 'succeeded', 'stock_state' => 'committed', 'attempt_id' => 'payment-operation']);
    $sale = $test->store->rows('sales', $test->tenant->id)->where('id', $quote['sale_id'])->first();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $test->operations = ['payment-operation' => ['id' => 'payment-operation', 'kind' => 'payment', 'state' => 'succeeded',
        'sale_id' => $sale->id, 'request_id' => $sale->payment_request_id, 'binding' => $snapshot['binding'], 'amount_minor' => 1070,
        'currency' => 'usd', 'provider_id' => 'fixture_payment', 'original_id' => null, 'original_provider_id' => null]];
    $journal = Mockery::mock(\App\Services\PaymentsCore\PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(function ($tenant, $id) use ($test) {
        expect($tenant)->toBe($test->tenant->id);

        return $test->operations[$id] ?? throw new DomainException('Unknown original operation');
    });
    app()->instance(\App\Services\PaymentsCore\PaymentJournal::class, $journal);

    return $sale;
}

function customerPosRefund($test, object $sale, string $id, int $amount): array
{
    return $test->operations[$id] = ['id' => $id, 'kind' => 'refund', 'state' => 'succeeded',
        'amount_minor' => $amount, 'original_id' => $sale->attempt_id, 'original_provider_id' => 'fixture_payment'];
}

it('requires an explicit pilot customer and rejects another tenant identity', function (): void {
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['customer_required'] = true;
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $without = $this->selection;
    unset($without['customer_profile_id']);
    $without['customer'] = ['name' => 'Ada Buyer', 'email' => 'ada@example.invalid'];
    $this->postJson($this->base.'/quotes', $without)->assertUnprocessable()->assertJsonPath('error.code', 'customer_selection_required');
    $other = Tenant::create(['name' => 'Another vendor', 'slug' => 'other-'.Str::random(8), 'status' => 'active']);
    $foreign = \App\Models\MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Private foreign customer']);
    $this->selection['customer_profile_id'] = $foreign->id;
    $this->postJson($this->base.'/quotes', $this->selection)->assertNotFound();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

it('searches tenant customers literally without a contact-based merge', function (): void {
    $this->getJson($this->base.'/customers?query=Ada')->assertOk()->assertJsonPath('customers.0.id', $this->profile->id);
    $this->getJson($this->base.'/customers?query=%25%25')->assertOk()->assertJsonCount(0, 'customers');
    $this->getJson($this->base.'/customers?query=a')->assertUnprocessable();
    $this->withHeader('X-Pos-Device', 'not-assigned')->getJson($this->base.'/customers?query=Ada')->assertNotFound();
});

it('finds a formatted phone by normalized digits without choosing or merging the customer', function (): void {
    $this->profile->update(['phone' => '(864) 555-0101', 'normalized_phone' => '+18645550101']);
    foreach (['8645550101', '(864) 555-0101', '+1 864 555 0101'] as $query) {
        $response = $this->getJson($this->base.'/customers?'.http_build_query(['query' => $query]))->assertOk()->json();
        expect(array_column($response['customers'], 'id'))->toContain($this->profile->id);
    }
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

it('creates one customer across retries and recovers it without granting consent', function (): void {
    $input = ['request_id' => (string) Str::uuid(), 'first_name' => 'New', 'last_name' => 'Buyer', 'email' => 'new@example.invalid'];
    $id = $this->postJson($this->base.'/customers', $input)->assertOk()->json('customer.id');
    $this->postJson($this->base.'/customers', $input)->assertOk()->assertJsonPath('customer.id', $id);
    $this->getJson($this->base.'/customer-requests/'.$input['request_id'])->assertOk()->assertJsonPath('customer.id', $id);
    $profile = \App\Models\MarketingProfile::findOrFail($id);
    expect($profile->accepts_email_marketing)->toBeFalse()->and($profile->accepts_sms_marketing)->toBeFalse();
    $input['first_name'] = 'Changed';
    $this->postJson($this->base.'/customers', $input)->assertConflict();
    $input['request_id'] = (string) Str::uuid();
    $this->postJson($this->base.'/customers', $input)->assertConflict()->assertJsonPath('error.code', 'select_existing_customer');
    $input['accepts_email_marketing'] = true;
    $this->postJson($this->base.'/customers', $input)->assertUnprocessable();
    expect(\App\Models\MarketingProfile::forTenantId($this->tenant->id)->count())->toBe(2);
});

it('freezes canonical identity and rejects a replay selecting someone else', function (): void {
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->first();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    expect($snapshot['customer']['id'])->toBe($this->profile->id);
    expect(\App\Models\MarketingProfileLink::forTenantId($this->tenant->id)->where('source_type', 'pos_sale')->first()->marketing_profile_id)->toBe($this->profile->id);
    $this->selection['customer_profile_id'] = 999999;
    $this->postJson($this->base.'/quotes', $this->selection)->assertConflict();
});

it('shows an explicitly selected customer’s connected purchase summary and balance only within the assigned tenant', function (): void {
    \Illuminate\Support\Facades\Route::get('/api/mobile/v1/workspaces/{tenant}/pos/registers/{register}/fixture-customer-commerce/{profile}',
        fn (string $tenant, string $register, int $profile) => response()->json(app(\App\Services\Pos\PosCustomerDirectory::class)->commerce($register, $profile)))
        ->middleware(['auth:sanctum', 'mobile.tenant', 'abilities:mobile:read,payments:read']);
    $sale = customerPosConfirm($this, ['first_order_reward_amount' => 5.25]);
    app(\App\Services\Pos\PosCustomerRewards::class)->apply($sale);
    $order = \App\Models\Order::create(['tenant_id' => $this->tenant->id, 'source' => 'shopify',
        'shopify_order_id' => '9001', 'shopify_store_key' => 'fixture_online', 'ordered_at' => now()->subDay(),
        'total_price' => '25.00', 'currency_code' => 'USD', 'attribution_meta' => ['customer_commerce' => [
            'financial_status' => 'paid', 'tenant_id' => $this->tenant->id, 'shopify_order_id' => '9001',
            'store_key' => 'fixture_online', 'source_name' => 'web', 'total_price' => '25.00', 'currency' => 'USD',
        ]]]);
    \App\Models\MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'source_type' => 'order', 'source_id' => (string) $order->id]);
    $transactions = \App\Models\CandleCashTransaction::count();
    $links = \App\Models\MarketingProfileLink::count();
    $url = $this->base.'/fixture-customer-commerce/'.$this->profile->id;
    $view = $this->getJson($url)->assertOk()->json();
    expect($view['customer']['id'])->toBe($this->profile->id)
        ->and($view['commerce']['customer_id'])->toBe($this->profile->id)
        ->and($view['commerce']['purchases'])->toHaveCount(2)
        ->and(collect($view['commerce']['purchases'])->pluck('channel')->sort()->values()->all())->toBe(['in_person', 'online'])
        ->and($view['commerce']['rewards']['balance'])->toBe('5.25')
        ->and(\App\Models\CandleCashTransaction::count())->toBe($transactions)
        ->and(\App\Models\MarketingProfileLink::count())->toBe($links);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $foreign = \App\Models\MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Other person']);
    $this->getJson($this->base.'/fixture-customer-commerce/'.$foreign->id)->assertNotFound();
    $this->withHeader('X-Pos-Device', 'wrong-device')->getJson($url)->assertNotFound();
});

it('posts decimal rewards once and reverses only the original sale earning', function (): void {
    $sale = customerPosConfirm($this, ['first_order_reward_amount' => 5.25]);
    $service = app(\App\Services\Pos\PosCustomerRewards::class);
    $service->apply($sale);
    $service->apply($sale);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(5.25);
    $partial = customerPosRefund($this, $sale, 'refund-one', 500);
    expect(fn () => $service->apply($sale, $partial))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_refund_allocation_review');
    expect(fn () => $service->apply($sale, $partial))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_refund_allocation_review');
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(5.25)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(1)
        ->and($this->store->rows('events', $this->tenant->id)->where('sale_id', $sale->id)->where('code', 'customer_refund_allocation_review')->count())->toBe(1)
        ->and(app(\App\Services\Pos\PosCustomerPurchases::class)->confirmedRefunds($sale))->toBe(500);
    $full = customerPosRefund($this, $sale, 'refund-two', 570);
    $service->apply($sale, $full);
    $service->apply($sale, $partial);
    $service->apply($sale);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0);
    expect(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(2);
    $view = app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect(\Illuminate\Support\Facades\Blade::render('<x-customer-commerce :snapshot="$snapshot" />', ['snapshot' => $view]))->toContain('Everbranch Pay', 'Refunded');
    expect($view['purchases'])->toHaveCount(1)->and($view['purchases'][0])->toMatchArray(['channel' => 'in_person', 'status' => 'refunded', 'refunded_minor' => 1070]);
});

it('holds the approved spend-tier earning for review after an amount-only partial refund', function (): void {
    $sale = customerPosConfirm($this, ['first_order_reward_amount' => null,
        'spend_threshold_rewards' => [['spend_dollars' => 5, 'reward_dollars' => 1], ['spend_dollars' => 10, 'reward_dollars' => 3]]]);
    $service = app(\App\Services\Pos\PosCustomerRewards::class);
    $service->apply($sale);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.0);
    $partial = customerPosRefund($this, $sale, 'partial-refund', 300);
    expect(fn () => $service->apply($sale, $partial))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_refund_allocation_review');
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.0)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(1)
        ->and(app(\App\Services\Pos\PosCustomerPurchases::class)->confirmedRefunds($sale))->toBe(300);
    $service->apply($sale, customerPosRefund($this, $sale, 'remaining-refund', 770));
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(2);
});

it('retains confirmed refund evidence when spent rewards need review', function (): void {
    $sale = customerPosConfirm($this);
    $service = app(\App\Services\Pos\PosCustomerRewards::class);
    $service->apply($sale);
    \App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->update(['balance' => 0]);
    $refund = customerPosRefund($this, $sale, 'spent-refund', 1070);
    expect(fn () => $service->apply($sale, $refund))->toThrow(\App\Services\Pos\PosException::class, 'customer_rewards_shortfall_review');
    expect(app(\App\Services\Pos\PosCustomerPurchases::class)->confirmedRefunds($sale))->toBe(1070);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0);
    expect($this->store->rows('events', $this->tenant->id)->where('code', 'customer_rewards_shortfall')->exists())->toBeTrue();
});

it('suppresses proven Square and Shopify mirrors of the native purchase', function (): void {
    $sale = customerPosConfirm($this);
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $snapshot['customer']['shopify_store_key'] = 'fixture-store-'.$this->tenant->id;
    $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->update([
        'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot),
        'source_reference' => $this->store->encrypt(['order_id' => 'gid://shopify/Order/4'])]);
    $order = \App\Models\Order::create(['tenant_id' => $this->tenant->id, 'source' => 'shopify', 'shopify_order_id' => 4,
        'shopify_store_key' => 'fixture-store-'.$this->tenant->id, 'ordered_at' => now(), 'total_price' => '10.70']);
    \App\Models\MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'source_type' => 'order', 'source_id' => (string) $order->id]);
    $view = app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forProfile($this->profile, $this->tenant->id);
    expect($view['purchases'])->toHaveCount(1)->and($view['confirmed_purchase_count'])->toBe(1);
});

it('allows separately gated owner onboarding while collection remains disabled', function (): void {
    config(['pos_live.onboarding_enabled' => true, 'pos_live.collection_enabled' => false]);
    $this->postJson($this->base.'/authorize-device', [])->assertOk()->assertJsonPath('can_link_apple_account', true);
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
    $quote = $this->postJson($this->base.'/quotes', $this->selection)->assertOk()->assertJsonPath('collection_ready', false)->json();
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/collect', [])->assertStatus(503)->assertJsonMissingPath('collection');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('does not give a cashier the owner onboarding exception', function (): void {
    config(['pos_live.onboarding_enabled' => true, 'pos_live.collection_enabled' => false]);
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
    $this->store->rows('assignments', $this->tenant->id)->update(['role' => 'cashier']);
    $this->postJson($this->base.'/authorize-device', [])->assertStatus(503);
    $this->postJson($this->base.'/customers', ['request_id' => (string) Str::uuid(), 'first_name' => 'New'])->assertForbidden();
});

it('observes a completed Square Dashboard refund without sending a refund request', function (): void {
    $sale = customerPosConfirm($this);
    app(\App\Services\Pos\PosCustomerRewards::class)->apply($sale);
    $connection = $this->store->rows('connections', $this->tenant->id)->first();
    Http::fake(['*/v2/refunds/dashboard-refund' => Http::response(['refund' => ['id' => 'dashboard-refund',
        'payment_id' => 'fixture_payment', 'location_id' => 'fixture_location', 'status' => 'COMPLETED',
        'amount_money' => ['amount' => 1070, 'currency' => 'USD']]])]);
    $observer = app(\App\Services\Pos\PosSquareRefundObserver::class);
    $observer->observe($sale, $connection, ['id' => 'fixture_payment', 'refund_ids' => ['dashboard-refund']]);
    $observer->observe($sale, $connection, ['id' => 'fixture_payment', 'refund_ids' => ['dashboard-refund']]);
    app(\App\Services\Pos\PosCustomerRewards::class)->apply($sale);
    expect(app(\App\Services\Pos\PosCustomerPurchases::class)->confirmedRefunds($sale))->toBe(1070);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('never uses legacy global program defaults as a tenant approval', function (): void {
    $settings = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->first()->configuration);
    $settings['customer_rewards'] = ['enabled' => true, 'policy_version' => str_repeat('e', 64)];
    $this->store->rows('registers', $this->tenant->id)->update(['configuration' => $this->store->encrypt($settings)]);
    $this->postJson($this->base.'/quotes', $this->selection)->assertStatus(503)->assertJsonPath('error.code', 'customer_rewards_policy_unreviewed');
});

it('honors the approved wallet cap once even after the customer spends the credit', function (): void {
    $sale = customerPosConfirm($this, [], ['max_wallet_balance_dollars' => 3]);
    $service = app(\App\Services\Pos\PosCustomerRewards::class);
    $service->apply($sale);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(3.0);
    \App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->update(['balance' => 0]);
    $service->apply($sale);
    expect(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->count())->toBe(1);
    expect(\App\Models\CandleCashBalance::where('marketing_profile_id', $this->profile->id)->first()->balance)->toBe(0.0);
});
