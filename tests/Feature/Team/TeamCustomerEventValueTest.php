<?php

use App\Http\Controllers\CustomerEventAttributionReviewController;
use App\Http\Controllers\TeamCustomerEventValueController;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerEventAttributionReview;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';
require_once __DIR__.'/../../Support/CustomerProfileJson.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07T12:00:00Z'));
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'value_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->stock = app(StockLedger::class);
    $this->pool = valueEvent($this, 123);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional buyer']);
    $this->store = app(PosStore::class);
    $this->paymentEvidence = [];
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(fn ($tenant, $id) => $tenant === $this->tenant->id ? ($this->paymentEvidence[$id] ?? throw new DomainException('Missing original evidence')) : throw new DomainException('Foreign evidence'));
    app()->instance(PaymentJournal::class, $journal);
    Route::middleware(['web', 'auth', 'tenant.access'])->get('/fixture/value/events/{event}', TeamCustomerEventValueController::class);
    Route::middleware(['web', 'auth', 'tenant.access'])->post('/fixture/value/customers/{profile}', CustomerEventAttributionReviewController::class);
});

function valueEvent($test, int $id, string $provider = 'manual', ?string $external = null): string
{
    $pool = $test->stock->createPool($test->tenant->id, 'value-pool-'.$id, $test->owner->id, 'Same display name', 'show', 'event:'.$id)['pool_id'];
    $test->stock->rows('event_links', $test->tenant->id)->insert(['tenant_id' => $test->tenant->id, 'event_id' => $id, 'provider' => $provider, 'external_id' => $external ?? 'source-'.$id,
        'metadata' => json_encode(['name' => 'Same display name', 'timezone' => 'UTC', 'start_on' => '2026-01-01', 'end_on' => '2026-01-03']), 'reviewed_by' => $test->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    return $pool;
}

function valueCapture($test, MarketingProfile $profile, int $event = 123, string $at = '2026-01-01T12:00:00+00:00', array $extra = []): MarketingProfileLink
{
    return MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_event_capture',
        'source_id' => 'value_fixture:'.Str::uuid(), 'match_method' => 'register_customer_created', 'source_meta' => ['event_id' => $event, 'event_name' => 'Untrusted display name', 'captured_at' => $at,
            'capture_evidence_version' => 1, 'record_origin' => 'created', ...$extra]]);
}

function valueOrder($test, MarketingProfile $profile, string $at, int $amount = 1000, ?int $refund = 0, string $currency = 'USD', bool $confirmed = true): MarketingProfileLink
{
    $id = random_int(100000, 9999999);
    $order = Order::factory()->create(['tenant_id' => $test->tenant->id, 'ordered_at' => $at, 'shopify_order_id' => $id, 'shopify_store_key' => 'value',
        'attribution_meta' => ['customer_commerce' => ['tenant_id' => $test->tenant->id, 'shopify_order_id' => (string) $id, 'store_key' => 'value', 'financial_status' => $confirmed ? 'paid' : 'pending',
            'source_name' => 'web', 'total_price' => number_format($amount / 100, 2, '.', ''), 'refund_total' => $refund === null ? null : number_format($refund / 100, 2, '.', ''), 'currency' => $currency]]]);

    return MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);
}

function valueReviewInput(MarketingProfileLink $link, array $extra = []): array
{
    return ['request_id' => (string) Str::uuid(), 'source_link_id' => $link->id, 'expected_revision' => 0, 'kind' => 'acquisition', 'classification' => 'new',
        'prehistory_reviewed' => true, 'reason' => 'Owner reviewed the original historical customer records.', 'evidence_reference' => 'Reviewed source archive reference 123', ...$extra];
}

function valueReceipt($test, MarketingProfile $profile): object
{
    $id = bin2hex(random_bytes(16));
    $binding = (new MerchantBinding($test->tenant->id, str_repeat('a', 32), 'square', 'fixture-merchant', 'fixture-location', 'live', 'value_fixture'))->snapshot();
    $lines = [['line_id' => 'line1', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 70]];
    $snapshot = ['binding' => $binding, 'register_name' => 'Original register', 'location_name' => 'Original venue', 'sale' => (new SaleSnapshot($id, 'usd', $lines))->snapshot(),
        'terms' => ['stock_pool_id' => $test->pool, 'tax_minor' => 70, 'total_minor' => 1070, 'subtotal_minor' => 1000, 'display' => [['title' => 'Fictional item', 'quantity' => 1]]],
        'configuration' => [], 'customer' => ['id' => $profile->id, 'shopify_store_key' => 'value']];
    $attempt = bin2hex(random_bytes(16));
    $request = (string) Str::uuid();
    $test->store->rows('sales', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['id' => $id, 'register_id' => str_repeat('a', 32), 'actor_id' => $test->owner->id,
        'location_id' => 'original', 'request_id' => Str::uuid(), 'payment_request_id' => $request, 'sdk_attempt_id' => Str::uuid(), 'attempt_id' => $attempt,
        'snapshot' => $test->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot), 'amount_minor' => 1070, 'currency' => 'usd', 'commerce_source' => StockPosCommerce::VERSION,
        'provider_order_id' => 'square-'.$id, 'source_reference' => $test->store->encrypt(['order_id' => '12345']), 'state' => 'succeeded', 'stock_state' => 'committed', 'created_at' => '2026-01-02 12:00:00', 'expires_at' => now()->addHour()]));
    $test->paymentEvidence[$attempt] = ['kind' => 'payment', 'state' => 'succeeded', 'sale_id' => $id, 'request_id' => $request, 'binding' => $binding, 'amount_minor' => 1070, 'currency' => 'usd', 'provider_id' => 'payment-'.$id];
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_sale', 'source_id' => 'value_fixture:'.$id]);

    return $test->store->rows('sales', $test->tenant->id)->where('id', $id)->firstOrFail();
}

function valueMembership($test, string $joined): \App\Models\SubscriptionRechargeResource
{
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $test->tenant->id, 'store_key' => 'join-'.$test->tenant->id, 'shop_domain' => 'join-'.$test->tenant->id.'.myshopify.com', 'access_token' => 'fictional']);
    \App\Models\SubscriptionPilotMember::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->profile->id, 'shopify_store_id' => $shop->id, 'recharge_customer_id' => 'original-customer', 'shopify_customer_id' => '123',
        'billing_owner' => 'everbranch', 'status' => 'active', 'target_date' => now()->toDateString(), 'currency' => 'USD', 'details' => [], 'prepared_by' => $test->owner->id]);
    $generation = (string) Str::uuid();
    $connection = \App\Models\SubscriptionRechargeConnection::create(['tenant_id' => $test->tenant->id, 'shopify_store_id' => $shop->id, 'provider_store_id' => 'fictional', 'platform' => 'shopify',
        'identifier' => 'fictional.myshopify.com', 'status' => 'verified', 'last_complete_sync_at' => now(), 'sync_state' => ['last_complete' => ['generation_id' => $generation]]]);
    $credential = \App\Models\SubscriptionRechargeCredentialRevision::create(['tenant_id' => $test->tenant->id, 'connection_id' => $connection->id, 'revision' => 1, 'credentials' => [], 'token_id' => 'fixture', 'credential_fingerprint' => str_repeat('c', 64), 'digest_key_id' => str_repeat('d', 16)]);
    $operation = \App\Models\SubscriptionRechargeOperation::create(['tenant_id' => $test->tenant->id, 'connection_id' => $connection->id, 'credential_revision_id' => $credential->id, 'request_uuid' => (string) Str::uuid(),
        'kind' => 'shadow_sync', 'request' => [], 'request_fingerprint' => str_repeat('e', 64), 'digest_key_id' => str_repeat('d', 16)]);

    return \App\Models\SubscriptionRechargeResource::create(['tenant_id' => $test->tenant->id, 'connection_id' => $connection->id, 'sync_operation_id' => $operation->id, 'generation_id' => $generation,
        'resource_type' => 'subscription', 'provider_id' => 'original-subscription', 'customer_provider_id' => 'original-customer', 'completeness' => 'complete', 'canonical_digest' => str_repeat('f', 64),
        'digest_key_id' => str_repeat('d', 16), 'observed_at' => now(), 'snapshot' => ['id' => 'original-subscription', 'created_at' => $joined]]);
}

test('customer event value resolves aliases and chronological evidence without title or date guesses', function () {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id]);
    valueEvent($this, 456, 'asana', 'asana-original-task');
    valueCapture($this, $this->profile, 456, '2026-02-01T12:00:00+00:00');
    $first = valueCapture($this, $alias);
    valueOrder($this, $alias, '2026-01-02T12:00:00+00:00');
    $service = app(CustomerEventValueService::class);
    $value = $service->forProfile($alias, $this->tenant->id);
    expect($value['customer_id'])->toBe($this->profile->id)->and($value['first_recorded_capture']['source_link_id'])->toBe($first->id)
        ->and($value['acquisition']['event']['id'])->toBe(123)->and($value['acquisition']['event']['name'])->toBe('Same display name')
        ->and($value['purchase_journey'][0]['purchase_event'])->toBeNull()->and($value['customer_classification'])->toBe('unknown')
        ->and($value['confirmed_buyer'])->toBeTrue()->and($value['acquisition']['first_customer_acquisition_confirmed'])->toBeFalse();
    $stale = $this->profile;
    $survivor = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfile::forTenantId($this->tenant->id)->whereKey($stale->id)->update(['merged_into_profile_id' => $survivor->id]);
    expect($service->forProfile($stale, $this->tenant->id)['customer_id'])->toBe($survivor->id);
    [$foreign] = teamWorkspace();
    expect(fn () => $service->forProfile($this->profile, $foreign->id))->toThrow(ModelNotFoundException::class);
    $alias->update(['merged_into_profile_id' => MarketingProfile::create(['tenant_id' => $foreign->id])->id]);
    expect(fn () => $service->forProfile($alias->fresh(), $this->tenant->id))->toThrow(ModelNotFoundException::class);
});

test('invalid or simultaneous ambiguous capture evidence cannot select an acquisition event', function () {
    valueEvent($this, 456);
    $link = valueCapture($this, $this->profile, 123, '2026-01-01');
    expect(app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id)['first_recorded_capture'])->toBeNull();
    $link->update(['source_meta' => ['event_id' => 123, 'captured_at' => '2026-02-30T12:00:00+00:00']]);
    expect(app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id)['first_recorded_capture'])->toBeNull();
    $link->update(['source_meta' => ['event_id' => 123, 'captured_at' => '2026-01-01T12:00:00+00:00']]);
    valueCapture($this, $this->profile, 456);
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    expect($value['first_recorded_capture'])->toBeNull()->and($value['issues'])->toContain('first_capture_ambiguous');
});

test('new captures record created versus selected provenance and legacy labels cannot inflate new record reporting', function () {
    $metadata = ['name' => 'Reviewed current event', 'timezone' => 'UTC', 'start_on' => now()->toDateString(), 'end_on' => now()->toDateString()];
    $this->stock->rows('event_links', $this->tenant->id)->where('event_id', 123)->update(['metadata' => json_encode($metadata)]);
    $register = (object) ['id' => str_repeat('f', 32), 'configuration' => $this->store->encrypt(['inventory_pool_id' => $this->pool]), 'timezone' => 'UTC'];
    $this->stock->bind($this->tenant->id, 'capture-provenance-binding', $this->owner->id, 'pos', 'value_fixture:'.$register->id, $this->pool);
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->owner->id, 'register' => $register];
    $created = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Created at register']);
    $attribution = app(\App\Services\Pos\PosEventAttribution::class);
    $attribution->capture($scope, $created, 'explicit-created-command', true);
    $attribution->capture($scope, $this->profile, 'existing-selected-command');
    $attribution->capture($scope, $created, 'retry-cannot-change-origin');
    $legacy = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Legacy origin unknown']);
    valueCapture($this, $legacy, 123, now()->toIso8601String(), ['capture_evidence_version' => null, 'record_origin' => null]);
    $value = app(CustomerEventValueService::class);
    expect($value->forProfile($created, $this->tenant->id)['contact_classification'])->toBe('new_customer_record')
        ->and($value->forProfile($this->profile, $this->tenant->id)['contact_classification'])->toBe('captured_contact')
        ->and($value->forProfile($legacy, $this->tenant->id)['contact_classification'])->toBe('captured_contact')
        ->and($value->forProfile($legacy, $this->tenant->id)['customer_classification'])->toBe('unknown');
    $links = MarketingProfileLink::forTenantId($this->tenant->id)->where('source_type', 'pos_event_capture')->get();
    expect($links)->toHaveCount(3)->and($links->firstWhere('marketing_profile_id', $created->id)->source_meta['record_origin'])->toBe('created')
        ->and($links->firstWhere('marketing_profile_id', $this->profile->id)->match_method)->toBe('register_customer_selected');
    $event = $attribution->report(['tenant_id' => $this->tenant->id, 'admin' => true], now()->toDateString(), now()->toDateString())['events'][0];
    expect($event['new_customer_records'])->toBe(1)->and($event['selected_existing_customer_records'])->toBe(1)
        ->and($event['record_origin_unknown'])->toBe(1)->and($event['captured_customer_records'])->toBe(3);
});

test('capture stays a contact until original paid evidence and owner reviewed prehistory establish distinct facts', function () {
    $link = valueCapture($this, $this->profile);
    $service = app(CustomerEventValueService::class);
    $value = $service->forProfile($this->profile, $this->tenant->id);
    expect($value['contact_classification'])->toBe('new_customer_record')->and($value['confirmed_buyer'])->toBeFalse()->and($value['customer_classification'])->toBe('unknown');
    $input = valueReviewInput($link);
    $reviewer = app(CustomerEventAttributionReview::class);
    $result = $reviewer->review($this->owner, $this->tenant->id, $this->profile, $input);
    expect(customerProfileJsonValue($reviewer->review($this->owner, $this->tenant->id, $this->profile, $input)))->toBe(customerProfileJsonValue($result))
        ->and(MarketingIdentityReview::where('source_type', 'customer_event_attribution_review')->count())->toBe(1);
    $value = $service->forProfile($this->profile, $this->tenant->id);
    expect($value['customer_classification'])->toBe('new')->and($value['acquisition']['first_customer_acquisition_confirmed'])->toBeTrue()
        ->and($value['confirmed_buyer'])->toBeFalse()->and($value['review_options']['links'][0]['source_link_revision'])->toBe(1);
    expect(fn () => $reviewer->review($this->owner, $this->tenant->id, $this->profile, [...$input, 'classification' => 'returning']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $reviewer->review($this->owner, $this->tenant->id, $this->profile, [...$input, 'request_id' => (string) Str::uuid()]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    valueEvent($this, 456);
    $meta = $link->fresh()->source_meta;
    $link->update(['source_meta' => [...$meta, 'event_id' => 456]]);
    expect($service->forProfile($this->profile, $this->tenant->id)['customer_classification'])->toBe('unknown');
    $reviewer->review($this->owner, $this->tenant->id, $this->profile, valueReviewInput($link, ['expected_revision' => 1, 'classification' => 'unknown']));
    expect($service->forProfile($this->profile, $this->tenant->id)['customer_classification'])->toBe('unknown');
});

test('an original earlier purchase proves returning status and rejects a contradictory new classification', function () {
    $capture = valueCapture($this, $this->profile);
    valueOrder($this, $this->profile, '2025-12-31T23:59:59+00:00', 1000, 1000);
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    expect($value['customer_classification'])->toBe('returning');
    expect(fn () => app(CustomerEventAttributionReview::class)->review($this->owner, $this->tenant->id, $this->profile, valueReviewInput($capture)))->toThrow(ValidationException::class);
});

test('30 90 and 365 day values use strict elapsed boundaries refunds separate currencies and maturity', function () {
    valueCapture($this, $this->profile);
    foreach ([['2026-01-01T12:00:00+00:00', 1000, 0, 'USD'], ['2026-01-02T12:00:00+00:00', 1000, 250, 'USD'], ['2026-01-31T12:00:00+00:00', 2000, 2000, 'USD'],
        ['2026-04-01T12:00:00+00:00', 3000, 0, 'USD'], ['2026-01-03T12:00:00+00:00', 4000, 0, 'CAD']] as $row) {
        valueOrder($this, $this->profile, ...$row);
    }
    valueOrder($this, $this->profile, '2026-01-02T13:00:00+00:00', 9000, 0, 'USD', false);
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    [$thirty, $ninety, $year] = $value['windows'];
    $usd = collect($thirty['currencies'])->firstWhere('currency', 'USD');
    expect($usd['confirmed_paid_purchase_count'])->toBe(2)->and($usd['net_paid_minor'])->toBe(1750)->and($usd['repeat_net_paid_minor'])->toBe(750)
        ->and($usd['observed_repeat_purchase_count'])->toBe(1)->and($thirty['currencies'])->toHaveCount(2)
        ->and(collect($ninety['currencies'])->firstWhere('currency', 'USD')['confirmed_paid_purchase_count'])->toBe(3)
        ->and(collect($year['currencies'])->firstWhere('currency', 'USD')['net_paid_minor'])->toBe(4750)
        ->and($thirty['time_matured'])->toBeTrue()->and($year['time_matured'])->toBeFalse()->and($thirty['coverage_complete'])->toBeFalse()
        ->and($value['contribution_margin_minor'])->toBeNull();
});

test('unknown refund amounts withhold net value and reward only purchases do not become paid buyers', function () {
    valueCapture($this, $this->profile);
    valueOrder($this, $this->profile, '2026-01-02T12:00:00+00:00', 1000, null);
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    expect($value['windows'][0]['currencies'][0]['net_paid_minor'])->toBeNull()->and($value['windows'][0]['currencies'][0]['unknown_refund_purchase_count'])->toBe(1);
    $snapshot = Mockery::mock(CustomerCommerceSnapshotService::class);
    $snapshot->shouldReceive('forProfile')->andReturn(['has_more' => false, 'issues' => [], 'purchases' => [['id' => 'pos_sale:reward-only', 'source' => 'pos_sale', 'source_id' => 'reward-only',
        'confirmed' => true, 'channel' => 'in_person', 'currency' => 'USD', 'amount_minor' => 0, 'refunded_minor' => 0, 'occurred_at' => '2026-01-02T12:00:00+00:00']]]);
    app()->instance(CustomerCommerceSnapshotService::class, $snapshot);
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    expect($value['confirmed_buyer'])->toBeFalse()->and($value['windows'][0]['zero_paid_confirmed_purchase_count'])->toBe(1)->and($value['windows'][0]['currencies'])->toBe([]);
});

test('Pay Shopify and Square representations count one original purchase with its immutable pool event', function () {
    valueCapture($this, $this->profile);
    $sale = valueReceipt($this, $this->profile);
    $orderLink = valueOrder($this, $this->profile, '2026-01-02T12:00:00+00:00', 1070);
    Order::forTenantId($this->tenant->id)->findOrFail($orderLink->source_id)->update(['shopify_order_id' => 12345]);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $sale->provider_order_id, 'state' => 'COMPLETED', 'closed_at' => '2026-01-02T12:00:00+00:00', 'total_money_amount' => 1070, 'total_money_currency' => 'USD']);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$sale->id, 'square_order_id' => $sale->provider_order_id, 'status' => 'COMPLETED', 'amount_money' => 1070, 'currency' => 'USD',
        'created_at_source' => '2026-01-02T12:00:00+00:00', 'raw_payload' => ['refunded_money' => ['amount' => 0]]]);
    foreach (['square_order' => $sale->provider_order_id, 'square_payment' => 'payment-'.$sale->id] as $type => $id) {
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => $type, 'source_id' => $id]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $value = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id);
    expect($value['purchase_journey'])->toHaveCount(1)->and($value['windows'][0]['currencies'][0]['gross_paid_minor'])->toBe(1070)
        ->and($value['purchase_journey'][0]['purchase_event']['event']['id'])->toBe(123)->and($value['purchase_journey'][0]['purchase_event']['basis'])->toBe('original_pay_receipt_stock_pool')
        ->and(collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toHaveCount(0);
    DB::disableQueryLog();
    Http::assertNothingSent();
});

test('reviewed purchase event links cannot manufacture purchases or cross tenant and conflicting identities', function () {
    $link = valueOrder($this, $this->profile, '2026-01-02T12:00:00+00:00');
    $review = app(CustomerEventAttributionReview::class);
    $review->review($this->owner, $this->tenant->id, $this->profile, valueReviewInput($link, ['kind' => 'purchase_event', 'event_id' => 123]));
    expect(app(EventCustomerCohort::class)->read($this->tenant->id, 123)['profile_ids'])->toBe([$this->profile->id]);
    $unpaid = valueOrder($this, $this->profile, '2026-01-02T12:00:00+00:00', 1000, 0, 'USD', false);
    expect(fn () => $review->review($this->owner, $this->tenant->id, $this->profile, valueReviewInput($unpaid, ['kind' => 'purchase_event', 'event_id' => 123])))->toThrow(ValidationException::class);
    [$foreign] = teamWorkspace();
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => MarketingProfile::create(['tenant_id' => $foreign->id])->id]);
    $link->update(['marketing_profile_id' => $other->id]);
    expect(app(EventCustomerCohort::class)->read($this->tenant->id, 123)['profile_ids'])->toBe([]);
    expect(fn () => $review->review($this->owner, $foreign->id, $this->profile, valueReviewInput($link)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('contradictory Pay and provider customer identities suppress every representation rather than double counting', function () {
    valueCapture($this, $this->profile);
    $sale = valueReceipt($this, $this->profile);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $sale->provider_order_id, 'state' => 'COMPLETED',
        'closed_at' => '2026-01-02T12:00:00+00:00', 'total_money_amount' => 1070, 'total_money_currency' => 'USD']);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_order', 'source_id' => $sale->provider_order_id]);
    $service = app(CustomerEventValueService::class);
    expect($service->forProfile($this->profile, $this->tenant->id)['confirmed_buyer'])->toBeFalse()
        ->and($service->forProfile($other, $this->tenant->id)['confirmed_buyer'])->toBeFalse()
        ->and($service->forProfile($this->profile, $this->tenant->id)['issues'])->toContain('customer_identity_review_required');
});

test('the review HTTP command accepts explicit form attestations and refuses absent or foreign evidence', function () {
    $capture = valueCapture($this, $this->profile);
    $input = valueReviewInput($capture);
    $url = '/fixture/value/customers/'.$this->profile->id.'?tenant='.$this->tenant->slug;
    $this->actingAs($this->owner)->postJson($url, [...$input, 'prehistory_reviewed' => false])->assertUnprocessable();
    $form = [...$input, 'source_link_id' => (string) $capture->id, 'expected_revision' => '0', 'prehistory_reviewed' => '1'];
    $this->post($url, $form)->assertRedirect(route('customers.profile', ['tenant' => $this->tenant->slug, 'profile' => $this->profile->id]));
    expect(app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id)['customer_classification'])->toBe('new');
    [$foreign] = teamWorkspace();
    $foreignProfile = MarketingProfile::create(['tenant_id' => $foreign->id]);
    $this->postJson('/fixture/value/customers/'.$foreignProfile->id.'?tenant='.$this->tenant->slug, $input)->assertNotFound();
});

test('membership attribution uses original joins and a separate review rather than current active status', function () {
    $capture = valueCapture($this, $this->profile);
    $source = valueMembership($this, '2026-01-02T12:00:00+00:00');
    $service = app(CustomerEventValueService::class);
    $membership = $service->forProfile($this->profile, $this->tenant->id)['membership_conversion'];
    expect($membership['first_join_after_capture'])->toBeTrue()->and($membership['attributed_event_id'])->toBeNull()->and($membership['causal_credit'])->toBeNull();
    app(CustomerEventAttributionReview::class)->review($this->owner, $this->tenant->id, $this->profile, valueReviewInput($capture, ['kind' => 'membership_conversion', 'event_id' => 123, 'joined_at' => $membership['observed_join_after_capture_at']]));
    expect($service->forProfile($this->profile, $this->tenant->id)['membership_conversion']['attributed_event_id'])->toBe(123);
    $source->update(['stale_reason' => 'source_changed']);
    $value = $service->forProfile($this->profile, $this->tenant->id);
    $membership = $value['membership_conversion'];
    expect($membership['coverage_complete'])->toBeFalse()->and($membership['first_join_after_capture'])->toBeNull()->and($membership['attributed_event_id'])->toBeNull();
    expect($value['review_options']['links'][0]['allowed_clear_kinds'])->toContain('membership_conversion');
});

test('an old subscription remains preexisting even when billing was activated after capture', function () {
    valueCapture($this, $this->profile);
    valueMembership($this, '2025-01-01T00:00:00+00:00');
    $membership = app(CustomerEventValueService::class)->forProfile($this->profile, $this->tenant->id)['membership_conversion'];
    expect($membership['existing_before_capture'])->toBeTrue()->and($membership['first_join_after_capture'])->toBeFalse()->and($membership['observed_join_after_capture_at'])->toBeNull();
});

test('event summaries expose page denominators financial tenant access and reviewed identity boundaries', function () {
    valueCapture($this, $this->profile);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event', 'title' => 'Other title',
        'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]]);
    $url = '/fixture/value/events/'.$event->id.'?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $json = $this->actingAs($this->owner)->getJson($url)->assertOk()->json();
    expect($json['denominators']['captured_contacts_on_page'])->toBe(1)->and($json['denominators']['confirmed_event_buyers_on_page'])->toBe(0)
        ->and($json['windows'][0]['summary_scope'])->toBe('this_page_only')->and($json['windows'][0]['denominators']['classification_unknown_customers_on_page'])->toBe(1)
        ->and($json['page'])->toBe(1)->and($json['has_more'])->toBeFalse();
    $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(0, 'customers');
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $this->actingAs($member)->getJson($url)->assertForbidden();
    $capture = MarketingProfileLink::where('source_type', 'pos_event_capture')->firstOrFail();
    $this->postJson('/fixture/value/customers/'.$this->profile->id.'?tenant='.$this->tenant->slug, valueReviewInput($capture))->assertForbidden();
    [$foreign, $foreignOwner] = teamWorkspace();
    $this->actingAs($foreignOwner)->getJson($url)->assertForbidden();
    $this->actingAs($this->owner)->getJson('/fixture/value/events/'.$event->id.'?tenant='.$foreign->slug.'&area='.$this->area->id)->assertForbidden();
});
