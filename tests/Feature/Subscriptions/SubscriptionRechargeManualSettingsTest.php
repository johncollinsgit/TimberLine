<?php

use App\Models\IntegrationConnection;
use App\Models\SubscriptionRechargeConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionRechargeManualSettingsService;
use App\Services\Subscriptions\SubscriptionRechargeReadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/SubscriptionRechargeFixtures.php';

function rechargeManualDocument(array $changes = []): array
{
    return array_replace(['schema_version' => 1, 'expected_identity' => rechargeExpected(),
        'expected_merchant_origin' => 'https://fictional-merchant.admin.rechargeapps.com', 'category' => 'order_processing',
        'source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/order-processing',
        'observed_at' => '2026-10-16T10:25:00-04:00', 'expected_previous_version_id' => null,
        'fields' => ['recalculate_next_charge_on_frequency_change' => true, 'automation_enabled' => false,
            'retry_delays' => [1, 3, 5], 'payment_processors' => ['shopify', 'braintree', 'paypal'], 'date_policy' => null],
        'required_fields' => ['recalculate_next_charge_on_frequency_change', 'automation_enabled', 'uncaptured_rule']], $changes);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Manual settings merchant', 'slug' => 'manual-settings-merchant']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->source = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'access_token' => 'FICTIONAL_SOURCE_TOKEN']);
    $this->connection = SubscriptionRechargeConnection::query()->create(rechargeExpected() + ['tenant_id' => $this->tenant->id,
        'source_integration_connection_id' => $this->source->id, 'api_version' => '2021-11', 'status' => 'verified', 'verified_at' => now(),
        'evidence' => ['store' => ['id' => '9001', 'identifier' => 'reviewed-merchant'], 'retained_other_evidence' => ['example' => true]],
        'sync_state' => ['untouched_progress' => ['cursor' => 'fictional']]]);
    $this->manual = app(SubscriptionRechargeManualSettingsService::class);
    $this->record = fn (array $changes = [], ?string $uuid = null) => $this->manual->record($this->tenant->id, $this->actor, $this->connection->id, $uuid ?? (string) Str::uuid(), rechargeManualDocument($changes));
});

test('manual source fields and exact local draft retain provenance null false arrays and encrypted history without HTTP', function () {
    config()->set('subscriptions.recharge_reads_enabled', false);
    $first = ($this->record)();
    expect($first['state'])->toBe('unverified')->and($first['missing_fields'])->toBe(['uncaptured_rule'])->and($first['null_fields'])->toBe(['date_policy'])
        ->and($first['live_ready'])->toBeFalse()->and($first['cutover_enabled'])->toBeFalse();
    $connection = $this->connection->fresh();
    $version = $connection->evidence['manual_settings']['versions'][0];
    expect($version['source']['fields'])->toBe(rechargeManualDocument()['fields'])->and($version['draft']['fields'])->toBe($version['source']['fields'])
        ->and($version['draft']['source_version_id'])->toBe($first['version_id'])->and($version['draft']['source_digest'])->toBe($version['source_digest'])
        ->and($version['actor_user_id'])->toBe($this->actor->id)->and($version['source']['observed_at'])->toBe('2026-10-16T10:25:00-04:00')
        ->and($connection->evidence['retained_other_evidence'])->toBe(['example' => true])->and($connection->sync_state)->toBe(['untouched_progress' => ['cursor' => 'fictional']])
        ->and(DB::table('subscription_recharge_connections')->where('id', $connection->id)->value('evidence'))->not->toContain('recalculate_next_charge')
        ->and($connection->toArray())->not->toHaveKey('evidence');
    $review = $this->manual->latestForReview($this->tenant->id, $this->actor, $connection->id, 'order_processing');
    expect($review['source_fields'])->toBe($review['draft_fields'])->and($review['field_values_preserved'])->toBeTrue()->and($review['runtime_wired'])->toBeFalse()
        ->and($this->manual->latestForReview($this->tenant->id, $this->actor, $connection->id, 'notifications'))->toBeNull();
    $summary = $this->manual->summary($this->tenant->id, $this->actor, $connection->id);
    expect($summary['categories']['order_processing']['version_count'])->toBe(1)->and($summary['categories']['notifications']['field_count'])->toBeNull()
        ->and(json_encode($summary))->not->toContain('retry_delays')->and($summary['one_to_one_verified'])->toBeFalse();
    Http::assertNothingSent();
});

test('request replay is actor bound and canonical while stale append and altered replay cannot change immutable observations', function () {
    $uuid = (string) Str::uuid();
    $first = ($this->record)([], $uuid);
    $original = $this->connection->fresh()->evidence['manual_settings']['versions'][0];
    $replay = ($this->record)([], $uuid);
    expect($replay['version_id'])->toBe($first['version_id'])->and($replay['replayed'])->toBeTrue();
    expect(fn () => ($this->record)(['fields' => ['recalculate_next_charge_on_frequency_change' => false]], $uuid))->toThrow(HttpException::class);
    expect(fn () => ($this->record)())->toThrow(HttpException::class);
    $second = ($this->record)(['expected_previous_version_id' => $first['version_id'], 'fields' => ['recalculate_next_charge_on_frequency_change' => false]]);
    expect($second['version_id'])->not->toBe($first['version_id'])->and($this->connection->fresh()->evidence['manual_settings']['versions'][0])->toBe($original);
    $otherActor = User::factory()->create(['is_active' => true]);
    $otherActor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    expect(fn () => $this->manual->record($this->tenant->id, $otherActor, $this->connection->id, $uuid, rechargeManualDocument()))->toThrow(HttpException::class);
    expect($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount(2);
    Http::assertNothingSent();
});

test('merchant origin is pinned independently of opaque identifier and changed expected source is rejected', function () {
    $first = ($this->record)();
    expect(fn () => ($this->record)(['expected_previous_version_id' => $first['version_id'],
        'expected_merchant_origin' => 'https://another.admin.rechargeapps.com', 'source_url' => 'https://another.admin.rechargeapps.com/merchant/settings/order-processing']))->toThrow(HttpException::class);
    expect(fn () => ($this->record)(['expected_identity' => array_replace(rechargeExpected(), ['provider_store_id' => 'other'])]))->toThrow(HttpException::class);
    expect($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount(1);
});

test('explicit merchant business contact settings are retained privately while arbitrary contact fields are rejected', function () {
    $result = ($this->record)(['category' => 'notifications', 'source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/notifications',
        'fields' => ['sender_email' => 'sender@example.test', 'reply_to_email' => 'reply@example.test', 'support_email' => 'support@example.test', 'merchant_email' => 'owner@example.test'],
        'required_fields' => ['sender_email', 'reply_to_email', 'support_email', 'merchant_email']]);
    $review = $this->manual->latestForReview($this->tenant->id, $this->actor, $this->connection->id, 'notifications');
    expect($review['source_fields'])->toBe($review['draft_fields'])->and($review['source_fields']['sender_email'])->toBe('sender@example.test')
        ->and(json_encode($this->manual->summary($this->tenant->id, $this->actor, $this->connection->id)))->not->toContain('sender@example.test');
    expect(fn () => ($this->record)(['category' => 'notifications', 'source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/notifications',
        'expected_previous_version_id' => $result['version_id'], 'fields' => ['sender_email' => "sender@example.test\r\nBcc: other@example.test"]]))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('manual settings remain local and reject unverified connection foreign tenant inactive members and paywalls', function (string $condition) {
    if ($condition === 'unverified') {
        $this->connection->forceFill(['status' => 'pending_verification', 'verified_at' => null])->save();
    } elseif ($condition === 'member') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    } elseif ($condition === 'inactive') {
        $this->actor->forceFill(['is_active' => false])->save();
    } elseif ($condition === 'paywall') {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['billing_status' => 'pending']);
    } else {
        $foreign = Tenant::query()->create(['name' => 'Foreign merchant', 'slug' => 'foreign-manual']);
        $this->connection = SubscriptionRechargeConnection::query()->create(['tenant_id' => $foreign->id, 'provider_store_id' => 'other', 'platform' => 'shopify', 'identifier' => 'other', 'status' => 'verified', 'verified_at' => now(), 'api_version' => '2021-11']);
    }
    $exception = $condition === 'foreign' ? ModelNotFoundException::class : HttpException::class;
    expect(fn () => ($this->record)())->toThrow($exception);
    if ($condition !== 'unverified') {
        expect(fn () => $this->manual->summary($this->tenant->id, $this->actor, $this->connection->id))->toThrow($exception);
        expect(fn () => $this->manual->latestForReview($this->tenant->id, $this->actor, $this->connection->id, 'order_processing'))->toThrow($exception);
    }
    expect(data_get($this->connection->fresh()->evidence, 'manual_settings'))->toBeNull();
    Http::assertNothingSent();
})->with(['unverified', 'member', 'inactive', 'paywall', 'foreign']);

test('unsafe settings keys values and shapes fail before any source evidence or draft is retained', function (array $fields) {
    expect(fn () => ($this->record)(['fields' => $fields]))->toThrow(HttpException::class);
    expect(data_get($this->connection->fresh()->evidence, 'manual_settings'))->toBeNull();
    Http::assertNothingSent();
})->with([
    'token key' => [['gateway_token' => 'SECRET_CANARY']], 'nested password' => [['safe' => [['password' => 'SECRET_CANARY']]]],
    'gateway key' => [['gateway_key' => 'SECRET_CANARY']], 'card field' => [['card_last_digits' => '4242']],
    'customer contact key' => [['customer_email' => 'fictional@example.test']], 'contact value' => [['note' => 'fictional@example.test']],
    'PAN value' => [['note' => '4111111111111111']], 'PAN integer' => [['retries' => 4111111111111111]],
    'secret value' => [['note' => 'Bearer SECRET_CANARY']], 'float' => [['rate' => 1.5]],
    'oversized string' => [['note' => str_repeat('a', 4097)]], 'deep array' => [['nest' => [[[[[['too_deep']]]]]]]],
]);

test('source pages require exact known settings path on the pinned HTTPS merchant host and explicit source time', function (array $changes) {
    expect(fn () => ($this->record)($changes))->toThrow(HttpException::class);
    expect(data_get($this->connection->fresh()->evidence, 'manual_settings'))->toBeNull();
})->with([
    'foreign host' => [['source_url' => 'https://attacker.test/merchant/settings/order-processing']],
    'query secret' => [['source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/order-processing?token=canary']],
    'wrong category page' => [['source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/payment']],
    'HTTP origin' => [['expected_merchant_origin' => 'http://fictional-merchant.admin.rechargeapps.com']],
    'credential URL' => [['expected_merchant_origin' => 'https://user:password@fictional-merchant.admin.rechargeapps.com']],
    'time no offset' => [['observed_at' => '2026-10-16T12:00:00']], 'invalid calendar' => [['observed_at' => '2026-02-31T12:00:00Z']],
]);

test('history retention rejects the twenty first version without eviction and categories append independently', function () {
    $previous = null;
    for ($i = 0; $i < SubscriptionRechargeManualSettingsService::MAX_CATEGORY_VERSIONS; $i++) {
        $result = ($this->record)(['expected_previous_version_id' => $previous]);
        $previous = $result['version_id'];
    }
    $original = $this->connection->fresh()->evidence;
    expect(fn () => ($this->record)(['expected_previous_version_id' => $previous]))->toThrow(HttpException::class);
    expect($this->connection->fresh()->evidence)->toBe($original);
    $payment = ($this->record)(['category' => 'payment_vault', 'source_url' => 'https://fictional-merchant.admin.rechargeapps.com/merchant/settings/payment',
        'fields' => ['payment_processor' => ['shopify', 'braintree', 'paypal']], 'required_fields' => ['payment_processor']]);
    expect($payment['state'])->toBe('captured_fields_prepared')->and($payment['one_to_one_verified'])->toBeFalse()
        ->and($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount(21);
});

test('global encrypted envelope size fails closed without evicting or partially appending settings versions', function () {
    $fields = [];
    foreach (range(1, 15) as $number) {
        $fields['rule_'.$number] = str_repeat('x', 4000);
    }
    $previous = null;
    $accepted = 0;
    for ($i = 0; $i < 12; $i++) {
        $before = $this->connection->fresh()->evidence;
        try {
            $result = ($this->record)(['expected_previous_version_id' => $previous, 'fields' => $fields]);
            $previous = $result['version_id'];
            $accepted++;
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(422)->and($this->connection->fresh()->evidence)->toBe($before);
            break;
        }
    }
    expect($accepted)->toBeGreaterThan(0)->toBeLessThan(12)->and($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount($accepted);
});

test('APP_KEY rotation retains canonical request replay and original source digest provenance', function () {
    $uuid = (string) Str::uuid();
    $first = ($this->record)([], $uuid);
    $before = $this->connection->fresh()->evidence['manual_settings'];
    $oldKey = config('app.key');
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', [$oldKey]);
    app()->forgetInstance('encrypter');
    \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
    $result = ($this->record)([], $uuid);
    expect($result['version_id'])->toBe($first['version_id'])->and($result['replayed'])->toBeTrue()->and($this->connection->fresh()->evidence['manual_settings'])->toBe($before);
});

test('successful provider reverify and token rotation preserve fresh manual history and unrelated evidence', function () {
    $first = ($this->record)();
    $manual = $this->connection->fresh()->evidence['manual_settings'];
    config()->set('subscriptions.recharge_reads_enabled', true);
    config()->set('subscriptions.recharge_read_tenant_ids', [(int) $this->tenant->id]);
    rechargeFake(null, function ($path) use ($first) {
        static $appended = false;
        if ($path === 'token_information' && ! $appended) {
            $appended = true;
            ($this->record)(['expected_previous_version_id' => $first['version_id'], 'fields' => ['recalculate_next_charge_on_frequency_change' => false]]);
        }

        return null;
    });
    $result = app(SubscriptionRechargeReadService::class)->verifyConnection($this->tenant->id, $this->actor, $this->source->id, rechargeExpected(), (string) Str::uuid());
    expect($result['status'])->toBe('completed')->and($this->connection->fresh()->evidence['manual_settings']['versions'][0])->toBe($manual['versions'][0])
        ->and($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount(2)->and($this->connection->fresh()->evidence['retained_other_evidence'])->toBe(['example' => true]);
    $newSource = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'external_account_id' => (string) Str::uuid(), 'status' => 'pending', 'access_token' => 'FICTIONAL_NEW_TOKEN']);
    rechargeFake();
    $saved = $this->connection->fresh()->evidence['manual_settings'];
    app(SubscriptionRechargeReadService::class)->verifyConnection($this->tenant->id, $this->actor, $newSource->id, rechargeExpected(), (string) Str::uuid());
    expect($this->connection->fresh()->evidence['manual_settings'])->toBe($saved);
});

test('operator settings import requires a private file and explicit IDs then returns safe offline JSON', function () {
    $path = tempnam(sys_get_temp_dir(), 'recharge-settings-');
    $document = rechargeManualDocument(['fields' => ['payment_processor' => 'FictionalPrivateLabel']]);
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    $options = ['action' => 'import', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--connection' => (string) $this->connection->id,
        '--request' => (string) Str::uuid(), '--file' => $path];
    try {
        $this->artisan('subscriptions:recharge-settings', $options)->expectsOutputToContain('"status": "completed"')->doesntExpectOutputToContain('FictionalPrivateLabel')->assertSuccessful();
        $this->artisan('subscriptions:recharge-settings', ['action' => 'summary', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--connection' => (string) $this->connection->id])->expectsOutputToContain('"live_ready": false')->assertSuccessful();
        chmod($path, 0644);
        $options['--request'] = (string) Str::uuid();
        $this->artisan('subscriptions:recharge-settings', $options)->expectsOutputToContain('Recharge settings evidence unavailable.')->assertFailed();
        $this->artisan('subscriptions:recharge-settings', ['action' => 'summary', '--actor' => $this->actor->id, '--connection' => $this->connection->id])->assertFailed();
        expect($this->connection->fresh()->evidence['manual_settings']['versions'])->toHaveCount(1);
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
});
