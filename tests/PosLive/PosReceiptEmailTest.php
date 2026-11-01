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
    \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendPlatformEmail::class]);
    config(['pos_live.receipt_email_enabled' => true, 'ses_migration.platform_enabled' => true,
        'ses_migration.platform_key' => 'fixture-key', 'ses_migration.platform_secret' => 'fixture-secret',
        'ses_migration.platform_from' => 'no-reply@theeverbranch.com', 'ses_migration.platform_configuration_set' => 'everbranch-platform-fixture',
        'ses_migration.approved_attempt_cost_micros' => 1000, 'ses_migration.max_per_second' => 100,
        'ses_migration.platform_daily_attempts' => 100, 'ses_migration.account_daily_attempts' => 100]);
    $this->tenant = Tenant::create(['name' => 'Fictional POS shop', 'slug' => 'pos-fixture-'.Str::lower(Str::random(8)), 'status' => 'active']);
    // Outbox suppression is global to a recipient; avoid collisions with other tests' addresses.
    $this->receiptEmail = 'pos-receipt-'.$this->tenant->id.'@example.invalid';
    config(['ses_migration.platform_recipients' => [$this->receiptEmail]]);
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
    $this->profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => $this->receiptEmail]);
    $this->selection = ['customer_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function emailPosConfirm($test, array $rules = [], array $programOverrides = []): object
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

function emailPosContact($test, object $sale, string $email): void
{
    DB::table('commerce_stock_receipt_associations')->insert([
        'tenant_id' => $test->tenant->id, 'runtime_id' => $test->store->runtime(), 'sale_id' => $sale->id,
        'actor_id' => $test->actor->id, 'request_hash' => str_repeat('a', 64), 'input_hash' => str_repeat('b', 64),
        'contact' => $test->store->encrypt(['email' => $email]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('queues exactly one receipt to an explicitly supplied address and returns its durable status on replay', function (): void {
    $sale = emailPosConfirm($this);
    emailPosContact($this, $sale, strtoupper($this->receiptEmail));
    $url = $this->base.'/sales/'.$sale->id.'/receipt/email';
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'not_requested');
    expect(\App\Models\PlatformEmailMessage::where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $first = $this->postJson($url)->assertOk()->json();
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'queued');
    expect($first['state'])->toBe('queued');
    $oldKey = config('app.key');
    config(['app.key' => 'fixture-rotated-key', 'app.previous_keys' => [$oldKey]]);
    $again = $this->postJson($url)->assertOk()->json();
    config(['app.key' => $oldKey, 'app.previous_keys' => []]);
    expect($again['delivery_id'])->toBe($first['delivery_id'])
        ->and(\App\Models\PlatformEmailMessage::where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $delivery = \App\Models\PlatformEmailDelivery::whereKey($first['delivery_id'])->firstOrFail();
    $message = $delivery->message;
    expect($message->deliveries()->count())->toBe(1)
        ->and($delivery->recipient)->toBe($this->receiptEmail);
    expect($message->tenant_id)->toBe($this->tenant->id)
        ->and(quoted_printable_decode($message->mime))->toContain('Fictional merchandise', 'fixture_payment', 'Paid: $10.70')
        ->and(DB::table('platform_email_messages')->where('id', $message->id)->value('mime'))->not->toContain('fixture_payment');
    \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SendPlatformEmail::class, 1);
    // An uncertain provider result stays held on replay; later evidence may establish acceptance.
    DB::table('platform_email_deliveries')->where('id', $first['delivery_id'])->update(['state' => 'unknown']);
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'unknown');
    $this->postJson($url)->assertOk()->assertJsonPath('state', 'unknown');
    \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SendPlatformEmail::class, 1);
    DB::table('platform_email_deliveries')->where('id', $first['delivery_id'])->update(['state' => 'accepted', 'provider_message_id' => 'fixture-provider-id']);
    $this->postJson($url)->assertOk()->assertJsonPath('state', 'accepted');
    \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SendPlatformEmail::class, 1);
    $this->postJson($this->base.'/sales/'.str_repeat('f', 32).'/receipt/email')->assertNotFound();
    $this->withHeader('X-Pos-Device', 'wrong-device')->postJson($url)->assertNotFound();
});

it('rejects missing contact and unconfirmed provider evidence without queuing mail', function (): void {
    $sale = emailPosConfirm($this);
    $url = $this->base.'/sales/'.$sale->id.'/receipt/email';
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'contact_required');
    $this->postJson($url)->assertStatus(422)->assertJsonPath('error.code', 'receipt_email_contact_required');
    emailPosContact($this, $sale, $this->receiptEmail);
    $this->operations['payment-operation']['state'] = 'unknown';
    $this->postJson($url)->assertStatus(409)->assertJsonPath('error.code', 'paid_receipt_required');
    expect(\App\Models\PlatformEmailMessage::where('tenant_id', $this->tenant->id)->count())->toBe(0);
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SendPlatformEmail::class);
});

it('keeps receipt sending disabled by default and never queues from status reads', function (): void {
    $sale = emailPosConfirm($this);
    emailPosContact($this, $sale, $this->receiptEmail);
    $url = $this->base.'/sales/'.$sale->id.'/receipt/email';
    config(['pos_live.receipt_email_enabled' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'not_requested')->assertJsonPath('enabled', false);
    $this->postJson($url)->assertStatus(503)->assertJsonPath('error.code', 'receipt_email_unavailable');
    config(['pos_live.receipt_email_enabled' => true]);
    $readOnly = $this->actor->createToken('Read only', ['mobile:read', 'payments:read'], now()->addDay())->plainTextToken;
    $this->withToken($readOnly);
    auth()->forgetGuards();
    $this->getJson($url)->assertOk()->assertJsonPath('state', 'not_requested');
    auth()->forgetGuards();
    $this->postJson($url)->assertForbidden();
    expect(\App\Models\PlatformEmailMessage::where('tenant_id', $this->tenant->id)->count())->toBe(0);
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SendPlatformEmail::class);
});
