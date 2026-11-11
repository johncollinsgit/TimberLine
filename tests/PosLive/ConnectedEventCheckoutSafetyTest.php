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

// Independent HTTP coverage for event safety; no test transaction wraps money admission.
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

function eventSafetyConfiguration($test, array $changes): void
{
    $register = $test->store->rows('registers', $test->tenant->id)->where('id', $test->register)->firstOrFail();
    $test->store->rows('registers', $test->tenant->id)->where('id', $test->register)
        ->update(['configuration' => $test->store->encrypt([...$test->store->decrypt($register->configuration), ...$changes])]);
}

function eventSafetyCountContext($test): array
{
    $catalog = $test->getJson($test->base.'/catalog')->assertOk()->json();

    return ['expected_inventory_pool_id' => $catalog['inventory_pool_id'],
        'expected_inventory_context_version' => $catalog['inventory_context_version']];
}

function eventSafetyRewards($test): array
{
    config(['pos_live.cash_enabled' => true, 'pos_live.staff_rewards_enabled' => true, 'pos_live.in_person_rewards_enabled' => true,
        'pos_live.durable_start_claims_enabled' => true, 'pos_live.square_environment' => 'production', 'pos_live.apple_team_id' => 'FICTIONALTEAM',
        'pos_live.distribution_verified' => true, 'pos_live.tap_entitlement_verified' => true, 'pos_live.square_signature_verified' => true,
        'pos_live.webhooks_verified' => true, 'cache.default' => 'database']);
    $test->withHeader('X-Pos-Build', 'fixture_build');
    $test->store->rows('connections', $test->tenant->id)->update(['environment' => 'production', 'acceptance_at' => now(), 'acceptance_build' => 'fixture_build']);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10],
        'candle_cash_policy_config' => ['earning_rules' => ['first_order_reward_amount' => 3.50, 'event_rewards_enabled' => true,
            'enable_milestone_rewards' => true, 'enable_order_based_earning' => true, 'rewardable_channels' => 'show_issued_online_redeemed']],
        'candle_cash_access_state' => ['launch_state' => 'published', 'test_mode' => false]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $settings = app(\App\Services\Pos\PosRewardRedemptionSettings::class);
    $program = app(\App\Services\Pos\PosCustomerRewards::class)->policy($test->tenant->id);
    $policy = ['enabled' => true, 'max_minor' => 500, 'member_max_minor' => 1000, 'refund_policy' => 'restore_full_review_partial'];
    $version = $settings->version($policy, $program['version']);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => $settings::SETTING_KEY,
        'value' => [...$policy, 'reviewed_version' => $version, 'reviewed_by' => $test->actor->id, 'reviewed_at' => now()->toIso8601String()]]);
    eventSafetyConfiguration($test, ['cash_enabled' => true, 'staff_rewards_enabled' => true,
        'merchant_review' => ['document_hash' => str_repeat('a', 64), 'markup_minor' => 0],
        'customer_rewards' => ['enabled' => true, 'threshold_mode' => 'highest_per_purchase', 'policy_version' => $program['version']],
        'pos_candle_cash_reviewed_version' => $version, 'pos_candle_cash_register_cap' => ['max_minor' => 500,
            'reviewed_by' => $test->actor->id, 'reviewed_at' => now()->toIso8601String(), 'policy_version' => $version]]);
    \App\Models\CandleCashBalance::create(['marketing_profile_id' => $test->profile->id, 'balance' => 10]);

    return ['customer_profile_id' => $test->profile->id, 'channel' => 'email', 'expected_contact' => $test->profile->email,
        'request_id' => (string) Str::uuid(), 'customer_confirmed' => true];
}

it('keeps batch physical counts atomic and replays only the exact original reviewed quantities', function (): void {
    $second = str_repeat('e', 32);
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $second, 'tenant_id' => $this->tenant->id,
        'sku' => 'SECOND', 'title' => 'Second scent', 'product_key' => 'second', 'unit_amount_minor' => 1200, 'revision' => str_repeat('e', 64)]);
    $this->stock->count($this->tenant->id, 'second-opening', $this->actor->id, $this->pool, [$second => 4], 'opening_count');
    $before = collect($this->getJson($this->base.'/catalog')->assertOk()->json('items'))->keyBy('item_id');
    $body = [...eventSafetyCountContext($this), 'request_id' => (string) Str::uuid(), 'physically_confirmed' => true, 'items' => [
        ['item_id' => $this->item, 'quantity' => 8, 'expected_version' => $before[$this->item]['balance_version']],
        ['item_id' => $second, 'quantity' => 9, 'expected_version' => $before[$second]['balance_version'] - 1]]];
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertConflict()->assertJsonPath('error.code', 'stock_count_changed_by_activity');
    $balances = collect($this->stock->listing($this->tenant->id, $this->pool))->keyBy('item_id');
    expect($balances[$this->item]['on_hand'])->toBe(2)->and($balances[$second]['on_hand'])->toBe(4);
    $body['items'][1]['expected_version'] = $before[$second]['balance_version'];
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertOk();
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertOk();
    $this->postJson($this->base.'/stock-counts/batch', [...$body, 'items' => [[...$body['items'][0], 'quantity' => 7], $body['items'][1]]])->assertConflict();
    $balances = collect($this->stock->listing($this->tenant->id, $this->pool))->keyBy('item_id');
    expect($balances[$this->item]['on_hand'])->toBe(8)->and($balances[$second]['on_hand'])->toBe(9)
        ->and($this->stock->rows('operations', $this->tenant->id)->where('request_hash', hash('sha256', 'mobile-batch:'.$body['request_id']))->count())->toBe(1);
});

it('rejects foreign stock and nonowner physical counts without changing either tenant', function (): void {
    $foreign = Tenant::create(['name' => 'Other stock owner', 'slug' => 'other-stock-owner-'.Str::lower(Str::random(8))]);
    $foreignItem = str_repeat('f', 32);
    $this->stock->rows('items', $foreign->id)->insert(['id' => $foreignItem, 'tenant_id' => $foreign->id,
        'sku' => 'FOREIGN', 'title' => 'Other tenant scent', 'product_key' => 'foreign', 'unit_amount_minor' => 1700, 'revision' => str_repeat('f', 64)]);
    $body = [...eventSafetyCountContext($this), 'request_id' => (string) Str::uuid(), 'physically_confirmed' => true,
        'items' => [['item_id' => $foreignItem, 'quantity' => 8, 'expected_version' => 0]]];
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertConflict()->assertJsonPath('error.code', 'count_product_unavailable');
    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
    $this->postJson($this->base.'/stock-counts/batch', [...$body, 'items' => [['item_id' => $this->item, 'quantity' => 8, 'expected_version' => 1]]])->assertForbidden();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->rows('balances', $foreign->id)->count())->toBe(0);
});

it('cannot apply a saved physical count to a replacement event pool with coincidentally matching balance versions', function (): void {
    $catalog = $this->getJson($this->base.'/catalog')->assertOk()->json();
    $body = ['request_id' => (string) Str::uuid(), 'physically_confirmed' => true,
        'expected_inventory_pool_id' => $catalog['inventory_pool_id'], 'expected_inventory_context_version' => $catalog['inventory_context_version'],
        'items' => [['item_id' => $this->item, 'quantity' => 9, 'expected_version' => $catalog['items'][0]['balance_version']]]];
    $otherPool = $this->stock->createPool($this->tenant->id, 'replacement-pool', $this->actor->id, 'Other event physical stock', 'shared')['pool_id'];
    $this->stock->count($this->tenant->id, 'replacement-opening', $this->actor->id, $otherPool, [$this->item => 5], 'opening_count');
    expect($this->stock->listing($this->tenant->id, $otherPool)[0]['balance_version'])->toBe($body['items'][0]['expected_version']);
    $this->stock->bind($this->tenant->id, 'rebind-register', $this->actor->id, 'pos', 'fixture_runtime:'.$this->register, $otherPool);
    eventSafetyConfiguration($this, ['inventory_pool_id' => $otherPool]);
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertConflict()->assertJsonPath('error.code', 'count_context_changed');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->listing($this->tenant->id, $otherPool)[0]['on_hand'])->toBe(5);
    $fresh = $this->getJson($this->base.'/catalog')->assertOk()->json();
    expect($fresh['inventory_pool_id'])->toBe($otherPool)->and($fresh['inventory_context_version'])->not->toBe($catalog['inventory_context_version']);
    $this->postJson($this->base.'/stock-counts/batch', [...$body, 'request_id' => (string) Str::uuid(),
        'expected_inventory_pool_id' => $fresh['inventory_pool_id'], 'expected_inventory_context_version' => $fresh['inventory_context_version']])->assertOk();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->listing($this->tenant->id, $otherPool)[0]['on_hand'])->toBe(9);
});

it('requires fresh review when a catalog identity changes while retaining legacy single-count retry evidence', function (): void {
    $context = eventSafetyCountContext($this);
    $version = $this->stock->listing($this->tenant->id, $this->pool)[0]['balance_version'];
    $body = [...$context, 'request_id' => (string) Str::uuid(), 'physically_confirmed' => true,
        'items' => [['item_id' => $this->item, 'quantity' => 9, 'expected_version' => $version]]];
    $this->stock->rows('items', $this->tenant->id)->where('id', $this->item)->update(['title' => 'Corrected product and scent identity', 'revision' => str_repeat('e', 64)]);
    $this->postJson($this->base.'/stock-counts/batch', $body)->assertConflict()->assertJsonPath('error.code', 'count_context_changed');
    $request = (string) Str::uuid();
    $this->stock->count($this->tenant->id, 'mobile:'.$request, $this->actor->id, $this->pool, [$this->item => 3], 'physical_count', [$this->item => $version]);
    $before = $this->stock->rows('movements', $this->tenant->id)->count();
    $this->postJson($this->base.'/stock-counts', ['request_id' => $request, 'physically_confirmed' => true,
        'item_id' => $this->item, 'quantity' => 3, 'expected_version' => $version])->assertOk();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(3)
        ->and($this->stock->rows('movements', $this->tenant->id)->count())->toBe($before);
});

it('rejects half of an optional single-count inventory context without changing stock', function (string $field): void {
    $context = eventSafetyCountContext($this);
    $this->postJson($this->base.'/stock-counts', ['request_id' => (string) Str::uuid(), 'physically_confirmed' => true,
        'item_id' => $this->item, 'quantity' => 9, 'expected_version' => 1, $field => $context[$field]])->assertUnprocessable();
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2);
})->with(['expected_inventory_pool_id', 'expected_inventory_context_version']);

it('cannot replay a legacy single-count request at a second register with another stock pool', function (): void {
    $otherPool = $this->stock->createPool($this->tenant->id, 'other-register-pool', $this->actor->id, 'Other register stock', 'shared')['pool_id'];
    $this->stock->count($this->tenant->id, 'other-register-opening', $this->actor->id, $otherPool, [$this->item => 4], 'opening_count');
    $register = $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->firstOrFail();
    $otherRegister = str_repeat('f', 32);
    $copy = (array) $register;
    $copy['id'] = $otherRegister;
    $copy['configuration'] = $this->store->encrypt([...$this->store->decrypt($register->configuration), 'inventory_pool_id' => $otherPool]);
    $this->store->rows('registers', $this->tenant->id)->insert($copy);
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $otherRegister, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true]));
    $this->stock->bind($this->tenant->id, 'other-register-binding', $this->actor->id, 'pos', 'fixture_runtime:'.$otherRegister, $otherPool);
    $body = ['request_id' => (string) Str::uuid(), 'physically_confirmed' => true,
        'item_id' => $this->item, 'quantity' => 9, 'expected_version' => 1];
    $this->postJson($this->base.'/stock-counts', $body)->assertOk();
    $otherBase = str_replace($this->register, $otherRegister, $this->base);
    $this->postJson($otherBase.'/stock-counts', $body)->assertConflict()->assertJsonPath('error.code', 'stock_request_conflict');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(9)
        ->and($this->stock->listing($this->tenant->id, $otherPool)[0]['on_hand'])->toBe(4);
});

it('treats photo interpretation as an untrusted editable draft and never changes inventory from an AI response', function (): void {
    $ask = Mockery::mock(\App\Services\AiBranch\AiBranchChat::class);
    app()->instance(\App\Services\AiBranch\AiBranchChat::class, $ask);
    $request = (string) Str::uuid();
    $conversation = new \App\Models\AiBranchConversation(['id' => 77]);
    $ask->shouldReceive('start')->once()->withArgs(fn ($tenant, $actor) => $tenant->id === $this->tenant->id && $actor->id === $this->actor->id)->andReturn($conversation);
    $ask->shouldReceive('ask')->twice()->withArgs(function ($tenant, $actor, $conversationId, $question, $id) use ($request): bool {
        expect($tenant->id)->toBe($this->tenant->id)->and($actor->id)->toBe($this->actor->id)->and($conversationId)->toBe(77)
            ->and($id)->toBe($request)->and($question)->toContain('CATALOG:', 'Fictional finished item', 'OCR:', 'FFI 12')
            ->not->toContain($this->profile->email, 'fixture_merchant', 'fixture-token');

        return true;
    })->andReturn(['text' => json_encode(['rows' => [
        ['label' => 'FFI', 'catalog_index' => 0, 'quantity' => 12, 'uncertain' => false],
        ['label' => 'Out of catalog', 'catalog_index' => 999, 'quantity' => 4, 'uncertain' => false],
        ['label' => 'Untrusted quantity', 'catalog_index' => 0, 'quantity' => '1000000', 'uncertain' => false],
    ]], JSON_THROW_ON_ERROR)]);
    $body = ['request_id' => $request, 'text' => 'FFI 12'];
    $this->postJson($this->base.'/stock-counts/draft', $body)->assertOk()->assertJsonPath('confirmation_required', true)
        ->assertJsonPath('rows.0.item_id', $this->item)->assertJsonPath('rows.0.quantity', 12)
        ->assertJsonPath('rows.1.item_id', null)->assertJsonPath('rows.1.uncertain', true)
        ->assertJsonPath('rows.2.quantity', null)->assertJsonPath('rows.2.uncertain', true);
    $this->postJson($this->base.'/stock-counts/draft', $body)->assertOk();
    $this->postJson($this->base.'/stock-counts/draft', [...$body, 'text' => 'FFI 99'])->assertConflict()->assertJsonPath('error.code', 'count_draft_changed');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(2)
        ->and($this->stock->rows('operations', $this->tenant->id)->where('kind', 'count')->count())->toBe(1);
    Http::assertNothingSent();
});

it('requires the saved contact and never turns a staff approval into a verified wallet account', function (): void {
    $input = eventSafetyRewards($this);
    $this->postJson($this->base.'/reward-codes/staff-confirmation', [...$input, 'expected_contact' => 'other@example.invalid'])->assertUnprocessable();
    $proof = $this->postJson($this->base.'/reward-codes/staff-confirmation', $input)->assertOk()->json('qr_payload');
    $authorization = app(\App\Services\Pos\PosRewardCustomerProof::class)->preview($this->tenant->id, $proof);
    expect($authorization->account_link_id)->toBeNull()->and($authorization->disclosure_version)->toBe(\App\Services\Pos\PosStaffRewardAuthorization::DISCLOSURE)
        ->and(\App\Models\MarketingProfileLink::forTenantId($this->tenant->id)->count())->toBe(0)
        ->and(\App\Models\CustomerExternalProfile::forTenantId($this->tenant->id)->count())->toBe(0);
    $this->travel(121)->seconds();
    $this->postJson($this->base.'/reward-codes/preview', ['proof' => $proof])->assertForbidden();
    $this->postJson($this->base.'/quotes', [...$this->selection, 'reward_redemption' => ['proof' => $proof, 'amount_minor' => 350]])->assertForbidden();
    expect(\App\Models\CandleCashBalance::findOrFail($this->profile->id)->balance)->toBe(10.0);
});

it('refuses a staff proof after the confirmed contact changes and preserves the unspent original hold', function (): void {
    $input = eventSafetyRewards($this);
    $proof = $this->postJson($this->base.'/reward-codes/staff-confirmation', $input)->assertOk()->json('qr_payload');
    $quote = $this->postJson($this->base.'/quotes', [...$this->selection, 'tender' => 'cash',
        'reward_redemption' => ['proof' => $proof, 'amount_minor' => 350]])->assertOk()->json();
    $this->profile->update(['email' => 'changed@example.invalid']);
    $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/cash', ['received_minor' => 1000])->assertConflict()->assertJsonPath('error.code', 'customer_identity_review_required');
    expect(DB::table('pos_candle_cash_reservations')->where('sale_id', $quote['sale_id'])->value('state'))->toBe('held')
        ->and($this->store->rows('events', $this->tenant->id)->where('code', 'cash_sale_completed')->count())->toBe(0)
        ->and((float) \App\Models\CandleCashBalance::findOrFail($this->profile->id)->balance)->toBe(6.5);
    Http::assertNothingSent();
});

it('applies a lowered register cap again at cash and zero tender admission without rewriting the original quote', function (bool $rewardOnly): void {
    $input = eventSafetyRewards($this);
    if ($rewardOnly) {
        $this->stock->rows('items', $this->tenant->id)->where('id', $this->item)->update(['unit_amount_minor' => 350]);
    }
    $proof = $this->postJson($this->base.'/reward-codes/staff-confirmation', $input)->assertOk()->json('qr_payload');
    $quote = $this->postJson($this->base.'/quotes', [...$this->selection, 'tender' => 'cash',
        'reward_redemption' => ['proof' => $proof, 'amount_minor' => 350]])->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    eventSafetyConfiguration($this, ['pos_candle_cash_register_cap' => ['max_minor' => 200, 'reviewed_by' => $this->actor->id,
        'reviewed_at' => now()->toIso8601String(), 'policy_version' => $snapshot['terms']['pos_candle_cash']['policy_version']]]);
    if ($rewardOnly) {
        $register = $this->store->rows('registers', $this->tenant->id)->firstOrFail();
        $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'register' => $register];
        expect(fn () => app(\App\Services\Pos\PosRewardOnlySale::class)->complete($scope, $sale, $snapshot,
            \App\Services\PaymentsCore\MerchantBinding::restore($snapshot['binding'])))
            ->toThrow(\App\Services\Pos\PosException::class, 'membership_redemption_limit_changed');
    } else {
        $this->postJson($this->base.'/sales/'.$quote['sale_id'].'/cash', ['received_minor' => 1000])->assertConflict()->assertJsonPath('error.code', 'membership_redemption_limit_changed');
    }
    expect($this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->value('snapshot_hash'))->toBe($sale->snapshot_hash)
        ->and(DB::table('pos_candle_cash_reservations')->where('sale_id', $sale->id)->value('state'))->toBe('held')
        ->and(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);

it('recovers paid cash after collection is disabled without permitting a replacement charge', function (): void {
    config(['pos_live.cash_enabled' => true]);
    eventSafetyConfiguration($this, ['cash_enabled' => true]);
    $quote = $this->postJson($this->base.'/quotes', [...$this->selection, 'tender' => 'cash'])->assertOk()->json();
    $path = $this->base.'/sales/'.$quote['sale_id'];
    $this->postJson($path.'/cash', ['received_minor' => 2000])->assertOk();
    config(['pos_live.cash_enabled' => false, 'pos_live.owned_inventory_enabled' => false, 'pos_live.native_client_enabled' => false]);
    $this->postJson($path.'/cash', ['received_minor' => 2000])->assertOk()->assertJsonPath('canonical_confirmation', true);
    $this->postJson($path.'/collect')->assertConflict()->assertJsonPath('error.code', 'cash_confirmation_required');
    expect($this->store->rows('events', $this->tenant->id)->where('code', 'cash_sale_completed')->count())->toBe(1)
        ->and(DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps independently reviewed tenant reward limits and customer balances isolated', function (): void {
    $input = eventSafetyRewards($this);
    $other = Tenant::create(['name' => 'Other rewards program', 'slug' => 'other-rewards-program-'.Str::lower(Str::random(8))]);
    $profile = \App\Models\MarketingProfile::create(['tenant_id' => $other->id, 'email' => 'other-program@example.invalid']);
    \App\Models\CandleCashBalance::create(['marketing_profile_id' => $profile->id, 'balance' => 42]);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 3],
        'candle_cash_policy_config' => ['earning_rules' => ['event_rewards_enabled' => true]],
        'candle_cash_access_state' => ['launch_state' => 'published', 'test_mode' => false]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $other->id, 'key' => $key, 'value' => $value]);
    }
    $settings = app(\App\Services\Pos\PosRewardRedemptionSettings::class);
    $raw = ['enabled' => true, 'max_minor' => 275, 'member_max_minor' => 450, 'refund_policy' => 'restore_full_review_partial'];
    $program = app(\App\Services\Pos\PosCustomerRewards::class)->policy($other->id);
    $version = $settings->version($raw, $program['version']);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $other->id, 'key' => $settings::SETTING_KEY,
        'value' => [...$raw, 'reviewed_version' => $version, 'reviewed_by' => $this->actor->id, 'reviewed_at' => now()->toIso8601String()]]);
    $scope = ['tenant_id' => $other->id, 'register' => (object) ['configuration' => $this->store->encrypt([
        'pos_candle_cash_reviewed_version' => $version, 'pos_candle_cash_register_cap' => ['max_minor' => 100,
            'reviewed_by' => $this->actor->id, 'reviewed_at' => now()->toIso8601String(), 'policy_version' => $version]])]];
    expect($settings->forRegisterProfile($scope, $profile)['max_minor'])->toBe(100)
        ->and($settings->forProfile($profile)['max_minor'])->toBe(275)
        ->and($settings->forProfile($this->profile)['max_minor'])->toBe(500);
    $this->postJson($this->base.'/reward-codes/staff-confirmation', [...$input, 'customer_profile_id' => $profile->id, 'expected_contact' => $profile->email])->assertNotFound();
    expect((float) \App\Models\CandleCashBalance::findOrFail($profile->id)->balance)->toBe(42.0)
        ->and((float) \App\Models\CandleCashBalance::findOrFail($this->profile->id)->balance)->toBe(10.0)
        ->and(DB::table('pos_reward_authorizations')->where('tenant_id', $other->id)->count())->toBe(0);
});

it('cannot use a higher authenticated member wallet allowance to bypass an event register cap', function (string $tender, bool $wouldBeRewardsOnly): void {
    eventSafetyRewards($this);
    $link = \Tests\Support\PosVerifiedAccountFixture::create($this->profile);
    $this->postJson($this->base.'/customers/'.$this->profile->id.'/membership-review', ['active' => true, 'confirmed' => true,
        'reference' => 'Fictional current membership independently reviewed'])->assertOk();
    $policy = app(\App\Services\Pos\PosRewardRedemptionSettings::class)->forProfile($this->profile);
    expect($policy['max_minor'])->toBe(1000);
    $proof = app(\App\Services\Pos\PosRewardCustomerProof::class)->issueForAuthenticatedProfile($this->profile,
        ['max_minor' => 1000, 'policy_version' => $policy['version'], 'disclosure_version' => \App\Services\Pos\PosRewardCustomerProof::DISCLOSURE_VERSION], $link);
    if ($wouldBeRewardsOnly) {
        $this->stock->rows('items', $this->tenant->id)->where('id', $this->item)->update(['unit_amount_minor' => 600]);
    }
    $this->postJson($this->base.'/quotes', [...$this->selection, 'tender' => $tender,
        'reward_redemption' => ['proof' => $proof['qr_payload'], 'amount_minor' => $wouldBeRewardsOnly ? 600 : 501]])
        ->assertConflict()->assertJsonPath('error.code', 'membership_redemption_limit_changed');
    expect(DB::table('pos_candle_cash_reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and((float) \App\Models\CandleCashBalance::findOrFail($this->profile->id)->balance)->toBe(10.0);
    $quote = $this->postJson($this->base.'/quotes', [...$this->selection, 'request_id' => (string) Str::uuid(), 'tender' => $tender,
        'reward_redemption' => ['proof' => $proof['qr_payload'], 'amount_minor' => 350]])->assertOk()->json();
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $quote['sale_id'])->firstOrFail();
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($sale);
    $rules = app(\App\Services\Pos\PosReceiptRewards::class)->forSale($sale, $snapshot)['rules'];
    expect(implode(' ', $rules))->toContain('Use up to $5.00')->not->toContain('$10.00');
    Http::assertNothingSent();
})->with([['card', false], ['cash', false], ['cash', true]]);

it('reverses the original cash earned ledger once and restores the fractional redeemed balance without restocking', function (): void {
    $input = eventSafetyRewards($this);
    $proof = $this->postJson($this->base.'/reward-codes/staff-confirmation', $input)->assertOk()->json('qr_payload');
    $quote = $this->postJson($this->base.'/quotes', [...$this->selection, 'tender' => 'cash',
        'reward_redemption' => ['proof' => $proof, 'amount_minor' => 350]])->assertOk()->json();
    $path = $this->base.'/sales/'.$quote['sale_id'];
    $this->postJson($path.'/cash', ['received_minor' => 1000])->assertOk();
    $earned = (float) \App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->sum('candle_cash_delta');
    expect($earned)->toBe(3.5);
    $request = ['request_id' => (string) Str::uuid(), 'amount_minor' => $quote['amount_minor'], 'reason' => 'requested_by_customer'];
    $this->postJson($path.'/refunds', $request)->assertOk();
    $this->postJson($path.'/refunds', $request)->assertOk();
    expect((float) \App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'everbranch_pay')->sum('candle_cash_delta'))->toBe(0.0)
        ->and((float) \App\Models\CandleCashBalance::findOrFail($this->profile->id)->balance)->toBe(10.0)
        ->and(\App\Models\CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'pos_reservation_refund')->count())->toBe(1)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['on_hand'])->toBe(1);
    Http::assertNothingSent();
});
