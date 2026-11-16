<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\TenantCustomerLookup;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Team\Studio\EventSalesHistory;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'event_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->stock = app(StockLedger::class);
    $this->pool = $this->stock->createPool($this->tenant->id, 'fixture-pool', $this->owner->id, 'Fictional show', 'show', 'event:123')['pool_id'];
    $this->stock->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'fixture-event',
        'metadata' => json_encode(['name' => 'Fictional show', 'timezone' => 'America/New_York', 'start_on' => '2026-11-06', 'end_on' => '2026-11-08']), 'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->store = app(PosStore::class);
    $this->evidence = [];
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(function ($tenant, $id) {
        expect($tenant)->toBe($this->tenant->id);

        return $this->evidence[$id] ?? throw new DomainException('Original evidence unavailable');
    });
    app()->instance(PaymentJournal::class, $journal);
});

function eventReceipt($test, string $state = 'succeeded', string $environment = 'live', ?string $pool = null, ?int $customer = null): object
{
    $id = bin2hex(random_bytes(16));
    $register = str_repeat('a', 32);
    $binding = (new MerchantBinding($test->tenant->id, $register, 'square', 'fixture-merchant', 'fixture-location', $environment, 'event_fixture'))->snapshot();
    $lines = [['line_id' => 'line1', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 70]];
    $snapshot = ['binding' => $binding, 'register_name' => 'Original show register', 'location_name' => 'Original venue', 'sale' => (new SaleSnapshot($id, 'usd', $lines))->snapshot(),
        'terms' => ['stock_pool_id' => $pool ?? $test->pool, 'tax_minor' => 70, 'total_minor' => 1070, 'subtotal_minor' => 1000, 'display' => [['title' => 'Fictional candle', 'quantity' => 1]]],
        'configuration' => ['shopify' => ['tax_address' => ['city' => 'Original city']]], 'customer' => $customer ? ['id' => $customer] : null];
    $attempt = bin2hex(random_bytes(16));
    $request = (string) Str::uuid();
    $test->store->rows('sales', $test->tenant->id)->insert($test->store->values($test->tenant->id, [
        'id' => $id, 'register_id' => $register, 'actor_id' => $test->owner->id, 'location_id' => 'original-location', 'request_id' => Str::uuid(), 'payment_request_id' => $request,
        'sdk_attempt_id' => Str::uuid(), 'attempt_id' => $attempt, 'snapshot' => $test->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot),
        'amount_minor' => 1070, 'currency' => 'usd', 'commerce_source' => StockPosCommerce::VERSION, 'state' => $state, 'stock_state' => 'committed', 'created_at' => '2026-11-07 01:00:00', 'expires_at' => now()->addHour(),
    ]));
    $test->evidence[$attempt] = ['kind' => 'payment', 'state' => 'succeeded', 'sale_id' => $id, 'request_id' => $request, 'binding' => $binding, 'amount_minor' => 1070, 'currency' => 'usd', 'provider_id' => 'fixture-'.$id];

    return $test->store->rows('sales', $test->tenant->id)->where('id', $id)->firstOrFail();
}

function commerceOccurrence($test, array $data = []): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id, 'kind' => 'event', 'title' => 'Fictional show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => $data]);
}

test('event sales use original paid evidence, operator, pool, location and local date', function () {
    $sale = eventReceipt($this);
    eventReceipt($this, 'quoted');
    eventReceipt($this, 'succeeded', 'test');
    eventReceipt($this, 'succeeded', 'live', str_repeat('c', 32));
    $report = app(PosEventSales::class)->read($this->tenant->id, 123, 'America/New_York');
    expect($report['complete'])->toBeTrue()->and($report['sale_count'])->toBe(1)->and($report['net_sales_minor'])->toBe(1000)->and($report['sales_tax_minor'])->toBe(70)
        ->and($report['daily'][0]['date'])->toBe('2026-11-06')->and($report['locations'][0]['name'])->toBe('Original venue')->and($report['operators'][0]['user_id'])->toBe((string) $this->owner->id);
    expect($report['operators'][0]['net_sales_minor'])->toBe(1000)->and($report['operators'][0]['known_net_sales_minor'])->toBe(1000)
        ->and($report['operators'][0]['complete'])->toBeTrue()->and($report['daily'][0]['minute_sales']['20:00'])->toBe(1000);
    expect(app(PosEventSales::class)->read($this->tenant->id + 1, 123, 'UTC')['sale_count'])->toBe(0);
    // No mutable current register configuration is needed to retain this event identity.
    expect($this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->value('snapshot_hash'))->toBe($sale->snapshot_hash);
});

test('missing payment evidence and partial refund tax allocation stay unknown', function () {
    $sale = eventReceipt($this);
    $this->evidence[$sale->attempt_id]['amount_minor'] = 100;
    $read = fn () => app(PosEventSales::class)->read($this->tenant->id, 123, 'UTC');
    expect($read()['net_sales_minor'])->toBeNull();
    $this->evidence[$sale->attempt_id]['amount_minor'] = 1070;
    $this->store->rows('events', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['sale_id' => $sale->id, 'code' => 'square_refund_observed', 'details' => $this->store->encrypt(['payment_id' => 'fixture-'.$sale->id, 'refund_id' => 'fixture-refund', 'amount_minor' => 500]), 'created_at' => now()]));
    expect($read()['net_sales_minor'])->toBeNull()->and($read()['confirmed_refunds_minor'])->toBe(500)
        ->and($read()['operators'][0]['net_sales_minor'])->toBeNull()->and($read()['operators'][0]['complete'])->toBeFalse();
    $this->store->rows('events', $this->tenant->id)->update(['details' => $this->store->encrypt(['payment_id' => 'fixture-'.$sale->id, 'refund_id' => 'fixture-refund', 'amount_minor' => 1070])]);
    expect($read()['net_sales_minor'])->toBe(0)->and($read()['sales_tax_minor'])->toBe(0);
});

test('shared profitability keeps the saved policy when current company rates change', function () {
    eventReceipt($this);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $policy = studioPolicy();
    $inputs = studioInputs(['fees' => [['label' => 'Booth', 'total_minor' => 5000, 'deposit_minor' => 1000, 'deposit_credited' => true]], 'trips' => [['id' => 'trip', 'kind' => 'studio_event', 'distance_millimiles' => 100000, 'round_trip' => true, 'vehicles' => 1]]]);
    $budget = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event_budget', 'title' => 'Saved budget', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'frozen', 'version' => 1, 'data' => ['event_id' => $event->id, 'policy_id' => (string) Str::uuid(), 'policy_version' => 1, 'policy_snapshot' => $policy, 'inputs' => $inputs, 'preview' => app(\App\Services\Team\Studio\EventCostCalculator::class)->preview($inputs, $policy), 'frozen_at' => now()->toIso8601String()]]);
    $event->update(['data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'budget_id' => $budget->id]]]);
    $url = '/team/events/'.$event->id.'/profitability?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $first = $this->actingAs($this->owner)->getJson($url)->assertOk()->json();
    expect($first['profitability']['estimated_cost_minor'])->toBe(18400)->and($first['profitability']['estimated_profit_minor'])->toBe(-17400)->and($first['travel']['planned_millimiles'])->toBe(200000);
    $newPolicy = studioPolicy();
    foreach ($newPolicy['rules'] as &$rule) {
        if ($rule['category'] === 'mileage') {
            $rule['effect']['rate_minor'] = 90;
        }
    }
    unset($rule);
    studioDefinition($this->tenant, $this->owner, $this->area, ['definition' => $newPolicy]);
    expect($this->getJson($url)->assertOk()->json('profitability'))->toBe($first['profitability']);
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $this->actingAs($member)->getJson($url)->assertForbidden();
});

test('reviewed commerce associations replay once and refuse duplicate or foreign identities', function () {
    $event = commerceOccurrence($this);
    $url = '/team/studio/commerce?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $input = ['request_id' => (string) Str::uuid(), 'event_id' => $event->id, 'expected_version' => 1, 'commerce_event_id' => 123, 'reason' => 'Reviewed original event identity.'];
    $this->actingAs($this->owner)->postJson($url, $input)->assertOk()->assertJsonPath('version', 2);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('version', 2);
    $other = commerceOccurrence($this);
    $this->postJson($url, array_replace($input, ['request_id' => (string) Str::uuid(), 'event_id' => $other->id]))->assertConflict();
    $this->postJson($url, array_replace($input, ['request_id' => (string) Str::uuid(), 'event_id' => $other->id, 'commerce_event_id' => 999]))->assertUnprocessable();
    expect(app(EventCommerceReport::class)->candidate($this->tenant->id, 123)?->id)->toBe($event->id);
});

test('event customer lookup separates captured contacts from verified purchases and keeps tenant boundaries', function () {
    $captured = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'phone' => '(864) 555-0101', 'normalized_phone' => '+18645550101']);
    $buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Paid buyer']);
    $unpaid = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Unpaid buyer']);
    MarketingProfile::create(['tenant_id' => teamWorkspace()[0]->id, 'first_name' => 'Ada', 'normalized_phone' => '+18645550101']);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $captured->id, 'source_type' => 'pos_event_capture', 'source_id' => 'capture', 'source_meta' => ['event_id' => 123]]);
    foreach ([[$buyer, 'succeeded'], [$unpaid, 'quoted']] as [$profile, $state]) {
        $sale = eventReceipt($this, $state, 'live', null, $profile->id);
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_sale', 'source_id' => 'event_fixture:'.$sale->id]);
    }
    $cohort = app(EventCustomerCohort::class)->read($this->tenant->id, 123);
    expect($cohort['profile_ids'])->toContain($captured->id, $buyer->id)->not->toContain($unpaid->id)
        ->and($cohort['relations'][$captured->id])->toBe(['captured' => true])->and($cohort['relations'][$buyer->id])->toBe(['purchased' => true]);
    $lookup = app(TenantCustomerLookup::class);
    expect($lookup->search($this->tenant->id, '8645550101')['customers'])->toHaveCount(1)
        ->and($lookup->search($this->tenant->id, 'Ada Buyer', $cohort['profile_ids'])['customers'][0]['id'])->toBe($captured->id)
        ->and($lookup->search($this->tenant->id, '', [])['customers'])->toBeEmpty();
});

test('Teams customer history needs the current tenant area role and never starts a purchase', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer']);
    $url = '/team/customers?tenant='.$this->tenant->slug.'&area='.$this->area->id.'&query=Ada';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('customers.0.id', $profile->id)->assertHeader('Cache-Control');
    expect($this->getJson($url)->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->getJson('/team/customers/'.$profile->id.'?tenant='.$this->tenant->slug.'&area='.$this->area->id)->assertOk()->assertJsonPath('commerce.customer_id', $profile->id);
    [$other, $foreignOwner] = teamWorkspace();
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Ada']);
    $this->getJson('/team/customers/'.$foreign->id.'?tenant='.$this->tenant->slug.'&area='.$this->area->id)->assertNotFound();
    $viewer = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
    $this->actingAs($viewer)->getJson($url)->assertForbidden();
    $this->actingAs($foreignOwner)->getJson($url)->assertForbidden();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(0);
});

test('the shared event sales endpoint retains original receipt details and current financial tenant access', function () {
    $sale = eventReceipt($this);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $url = '/team/events/'.$event->id.'/confirmed-sales?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $response = $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('sales.0.id', $sale->id)->assertJsonPath('report.sales.sale_count', 1)->assertJsonPath('has_more', false);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $member = teamMember($this->tenant, 'member');
    $this->actingAs($member)->getJson($url)->assertForbidden();
    [$other, $owner] = teamWorkspace();
    $this->actingAs($owner)->getJson($url)->assertForbidden();
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(1);
});

test('the warm event index keeps late confirmation, new backdated sales and post-sale attribution current', function () {
    $pending = eventReceipt($this, 'quoted');
    eventReceipt($this, pool: str_repeat('b', 32));
    $read = fn () => app(PosEventSales::class)->read($this->tenant->id, 123, 'UTC');
    expect($read()['sale_count'])->toBe(0);
    $this->store->rows('sales', $this->tenant->id)->where('id', $pending->id)->update(['state' => 'succeeded']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($read()['sale_count'])->toBe(1);
    $reads = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => str_replace('`', '"', $sql))
        ->filter(fn ($sql) => str_contains($sql, 'from "pos_live_sales"') && ! str_contains($sql, 'count(*)'));
    expect($reads->isNotEmpty())->toBeTrue()->and($reads->every(fn ($sql) => str_contains($sql, '"id" in')))->toBeTrue();
    DB::disableQueryLog();
    $buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Late buyer']);
    $this->stock->rows('receipt_associations', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'runtime_id' => 'event_fixture', 'sale_id' => $pending->id,
        'customer_profile_id' => $buyer->id, 'actor_id' => $this->owner->id, 'request_hash' => str_repeat('a', 64), 'input_hash' => str_repeat('b', 64), 'contact' => $this->store->encrypt([])]);
    expect($read()['customer_counts']['attributed_orders'])->toBe(1);
    $old = eventReceipt($this);
    $this->store->rows('sales', $this->tenant->id)->where('id', $old->id)->update(['created_at' => '2025-01-01 00:00:00']);
    expect($read()['sale_count'])->toBe(2)->and($read()['collected_total_minor'])->toBe(2140);
    expect(app(PosEventSales::class)->read($this->tenant->id + 1, 123, 'UTC')['sale_count'])->toBe(0);
    Http::assertNothingSent();
});

test('an older event remains complete beyond another events ten thousand quote window', function () {
    $old = eventReceipt($this);
    $padding = eventReceipt($this, 'quoted', pool: str_repeat('b', 32));
    $snapshot = app(\App\Services\Pos\PosService::class)->snapshot($padding);
    $template = (array) $padding;
    $batch = [];
    for ($i = 0; $i < 10001; $i++) {
        $id = substr(hash('sha256', 'retained-other-event-'.$i), 0, 32);
        $snapshot['sale']['sale_id'] = $id;
        $batch[] = array_replace($template, ['id' => $id, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(),
            'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot), 'created_at' => '2026-11-08 01:00:00']);
        if (count($batch) === 100) {
            $this->store->rows('sales', $this->tenant->id)->insert($batch);
            $batch = [];
        }
    }
    if ($batch) {
        $this->store->rows('sales', $this->tenant->id)->insert($batch);
    }
    $report = app(PosEventSales::class)->read($this->tenant->id, 123, 'UTC', true);
    expect($report['complete'])->toBeTrue()->and($report['receipt_ids'])->toBe([$old->id])->and($report['net_sales_minor'])->toBe(1000);
});

test('canonical attribution counts collapse aliases while a broken association leaves verified sales visible', function () {
    $customer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Canonical buyer']);
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $customer->id]);
    eventReceipt($this, customer: $customer->id);
    eventReceipt($this, customer: $alias->id);
    $read = fn () => app(PosEventSales::class)->read($this->tenant->id, 123, 'UTC');
    expect($read()['customer_counts']['unique_buyers'])->toBe(1)->and($read()['customer_counts']['attributed_orders'])->toBe(2);
    [$other] = teamWorkspace();
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'email' => 'foreign-private@example.invalid']);
    $guest = eventReceipt($this);
    $this->stock->rows('receipt_associations', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'runtime_id' => 'event_fixture', 'sale_id' => $guest->id,
        'customer_profile_id' => $foreign->id, 'actor_id' => $this->owner->id, 'request_hash' => str_repeat('c', 64), 'input_hash' => str_repeat('d', 64), 'contact' => $this->store->encrypt([])]);
    $report = $read();
    expect($report['complete'])->toBeTrue()->and($report['net_sales_minor'])->toBe(3000)->and($report['customer_counts']['complete'])->toBeFalse()
        ->and($report['customer_counts']['unattributed_orders'])->toBe(1)->and($report['customer_review_items'])->not->toBeEmpty();
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $response = $this->actingAs($this->owner)->getJson('/team/events/'.$event->id.'/confirmed-sales?tenant='.$this->tenant->slug.'&area='.$this->area->id)
        ->assertOk()->assertDontSee('foreign-private@example.invalid');
    $row = collect($response->json('sales'))->firstWhere('id', $guest->id);
    expect($row['customer'])->toBeNull()->and($row['customer_review_required'])->toBeTrue();
});

function scopedCommerceMapping($test, TeamOperationRecord $event, bool $partial = false): array
{
    $binding = ['connection_id' => 'fixture-original-reader', 'connection_generation' => 1, 'merchant_id' => 'fixture-merchant', 'environment' => 'production', 'location_ids' => ['fixture-location']];
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('assertCurrent')->with($test->tenant->id, $binding)->andReturnNull();
    app()->instance(SquareConnectionContext::class, $context);
    $runs = [];
    foreach (['orders', 'payments'] as $kind) {
        $isPartial = $partial && $kind === 'payments';
        $runs[$kind] = MarketingImportRun::create(['tenant_id' => $test->tenant->id, 'type' => 'square_'.$kind.'_sync', 'status' => $isPartial ? 'partial' : 'completed',
            'started_at' => '2026-11-13', 'finished_at' => '2026-11-13', 'summary' => ['source_binding' => $binding, 'mode' => 'live-sync', 'errors' => 0,
                'since' => '2020-01-01T00:00:00Z', 'initial_cursor' => null, 'checkpoint' => ['cursor' => $isPartial ? 'remaining-page' : null, 'errors' => 0]]]);
    }
    $series = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id, 'kind' => 'series', 'title' => 'Fictional annual show',
        'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => []]);
    app(TeamsStudio::class)->reviewOccurrence($test->tenant, $test->owner, $test->area, [
        'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id,
        'financial_id' => null, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed original source location and exact annual selling dates.',
        'venue' => 'Fictional hall', 'timezone' => 'America/New_York', 'verified_selling_days' => 3, 'daily_hours' => [],
        'square_scopes' => [['location_id' => 'fixture-location', 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08',
            'orders_run_id' => $runs['orders']->id, 'payments_run_id' => $runs['payments']->id]],
    ]);

    return $binding;
}

function scopedCommerceOrder($test, array $binding, string $id, int $net): void
{
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $id, 'location_id' => 'fixture-location', 'state' => 'COMPLETED',
        'total_money_amount' => $net + 70, 'total_money_currency' => 'USD', 'closed_at' => '2026-11-07 16:00:00', 'synced_at' => now(),
        'raw_payload' => ['_source_binding' => $binding, 'total_tax_money' => ['amount' => 70, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id, 'location_id' => 'fixture-location',
        'amount_money' => $net + 70, 'currency' => 'USD', 'status' => 'COMPLETED', 'source_type' => 'CARD', 'created_at_source' => '2026-11-07 16:00:00', 'synced_at' => now(),
        'raw_payload' => ['_source_binding' => $binding, 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
}

test('reviewed live Square scopes feed Pay and history once alongside static selections and original Pay mirrors', function () {
    $sale = eventReceipt($this);
    $this->store->rows('sales', $this->tenant->id)->where('id', $sale->id)->update(['provider_order_id' => 'native-square-mirror']);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $binding = scopedCommerceMapping($this, $event);
    scopedCommerceOrder($this, $binding, 'external-square-order', 2000);
    scopedCommerceOrder($this, $binding, 'native-square-mirror', 1000);
    $data = $event->data;
    $data['_teams_enrichment']['square_orders'] = ['ids' => ['external-square-order', 'native-square-mirror'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()];
    $event->update(['data' => $data]);

    $report = app(PosEventSales::class)->read($this->tenant->id, 123, 'America/New_York', true);
    expect($report['sale_count'])->toBe(2)->and($report['net_sales_minor'])->toBe(3000)->and($report['paid_total_minor'])->toBe(3140)
        ->and($report['square_order_ids'])->toBe(['external-square-order'])
        ->and($report['source_freshness']['square_deduplicated_orders'])->toBeGreaterThanOrEqual(2);
    $history = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event)['current'];
    expect($history['sale_count'])->toBe(2)->and($history['known_net_sales_minor'])->toBe(3000)
        ->and($history['net_sales_minor'])->toBe(3000)->and(array_sum(array_column($history['daily'], 'sale_count')))->toBe(2)
        ->and(array_sum(array_column($history['daily'], 'net_sales_minor')))->toBe(3000);
    expect($this->store->rows('sales', $this->tenant->id)->count())->toBe(1)->and(SquareOrder::forTenantId($this->tenant->id)->count())->toBe(2);
    Http::assertNothingSent();
});

test('partially retrieved reviewed Square scopes show known Pay sales without claiming complete event coverage', function () {
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $binding = scopedCommerceMapping($this, $event, true);
    scopedCommerceOrder($this, $binding, 'partial-external-order', 2000);
    // Full original Square snapshots legitimately omit zero-refund fields.
    $order = SquareOrder::forTenantId($this->tenant->id)->where('square_order_id', 'partial-external-order')->firstOrFail();
    $order->update(['raw_payload' => [...$order->raw_payload, 'net_amounts' => ['total_money' => ['amount' => 2070, 'currency' => 'USD']]]]);
    $payment = SquarePayment::forTenantId($this->tenant->id)->where('square_order_id', $order->square_order_id)->firstOrFail();
    $payment->update(['raw_payload' => ['_source_binding' => $binding, 'id' => $payment->square_payment_id, 'status' => 'COMPLETED',
        'order_id' => $order->square_order_id, 'location_id' => 'fixture-location', 'amount_money' => ['amount' => 2070, 'currency' => 'USD'],
        'total_money' => ['amount' => 2070, 'currency' => 'USD']]]);
    $report = app(PosEventSales::class)->read($this->tenant->id, 123, 'America/New_York');
    expect($report['sale_count'])->toBe(1)->and($report['paid_total_minor'])->toBe(2070)->and($report['known_net_sales_minor'])->toBe(2000)
        ->and($report['source_freshness']['square_oldest_selected_sync'])->toBe($order->synced_at->toIso8601String())
        ->and($report['source_coverage_complete'])->toBeFalse()->and($report['net_sales_minor'])->toBeNull()
        ->and($report['review_items'])->toContain('Square source coverage is incomplete or its original connection changed.');
    $history = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event);
    expect($history['current']['sale_count'])->toBe(1)->and($history['current']['known_net_sales_minor'])->toBe(2000)
        ->and($history['current']['source_coverage_complete'])->toBeFalse()->and($history['current']['net_sales_minor'])->toBeNull()
        ->and($history['benchmarks']['average_event_net_sales_minor'])->toBeNull();
});

test('current reviewed scope sales fail closed after original source withdrawal or generation changes', function (string $change) {
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $binding = scopedCommerceMapping($this, $event);
    scopedCommerceOrder($this, $binding, 'source-sensitive-order', 2000);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'user_id' => $this->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'settings' => [], 'credentials' => [], 'sync_state' => []]);
    $event->update(['connection_id' => $connection->id, 'data' => [...$event->data, '_generation' => 1]]);
    $read = fn () => app(PosEventSales::class)->read($this->tenant->id, 123, 'America/New_York');
    expect($read()['known_net_sales_minor'])->toBe(2000);
    if ($change === 'generation') {
        $connection->update(['generation' => 2]);
    } elseif ($change === 'withdrawn') {
        $event->update(['status' => 'withdrawn']);
    } else {
        $context = Mockery::mock(SquareConnectionContext::class);
        $context->shouldReceive('assertCurrent')->andThrow(new Symfony\Component\HttpKernel\Exception\HttpException(409));
        app()->instance(SquareConnectionContext::class, $context);
    }
    $report = $read();
    expect($report['sale_count'])->toBe(0)->and($report['known_net_sales_minor'])->toBe(0)->and($report['source_coverage_complete'])->toBeFalse();
})->with(['generation', 'withdrawn', 'square_revoked']);

function nativeCommerceScope($test): array
{
    $register = str_repeat('a', 32);
    $connection = str_repeat('c', 32);
    $test->stock->bind($test->tenant->id, 'native-report-binding', $test->owner->id, 'pos', $test->store->runtime().':'.$register, $test->pool);
    $test->store->rows('connections', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['id' => $connection,
        'merchant_id' => 'fixture-merchant', 'provider_location_id' => 'fixture-location', 'environment' => 'sandbox', 'credentials' => $test->store->encrypt([])]));
    $test->store->rows('registers', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['id' => $register, 'name' => 'Current fictional show',
        'location_id' => 'fixture-location', 'location_name' => 'Fictional venue', 'connection_id' => $connection, 'catalog_source' => 'native',
        'configuration' => $test->store->encrypt(['commerce_adapter' => StockPosCommerce::VERSION, 'inventory_pool_id' => $test->pool]), 'active' => true]));
    $test->store->rows('assignments', $test->tenant->id)->insert($test->store->values($test->tenant->id, ['register_id' => $register,
        'user_id' => $test->owner->id, 'device_id' => 'fixture-owner-phone', 'role' => 'admin', 'active' => true]));

    return ['tenant_id' => $test->tenant->id, 'actor_id' => $test->owner->id, 'role' => 'owner',
        'register' => $test->store->rows('registers', $test->tenant->id)->where('id', $register)->firstOrFail()];
}

test('compact native Sales retains shared totals and source freshness without loading the full Teams context', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    eventReceipt($this);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $binding = scopedCommerceMapping($this, $event, true);
    scopedCommerceOrder($this, $binding, 'compact-external-square', 2000);
    $scope = nativeCommerceScope($this);
    $full = app(EventCommerceReport::class)->forEvent($this->tenant, $this->owner, $event);
    expect($full)->toHaveKeys(['profitability', 'travel', 'budget', 'annual_history', 'history']);
    $intelligence = Mockery::mock(\App\Services\Team\Studio\EventIntelligence::class);
    $intelligence->shouldNotReceive('detail');
    app()->instance(\App\Services\Team\Studio\EventIntelligence::class, $intelligence);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $compact = app(EventCommerceReport::class)->forRegister($scope, $this->owner);
    $bindings = collect(DB::getQueryLog())->flatMap(fn ($query) => $query['bindings']);
    DB::disableQueryLog();
    expect($compact['sales'])->toBe($full['sales'])->and($compact['sales']['sale_count'])->toBe(2)
        ->and($compact['sales']['known_net_sales_minor'])->toBe(3000)->and($compact['sales']['source_coverage_complete'])->toBeFalse()
        ->and($compact['event_name'])->toBe($event->title)->and($compact['event_id'])->toBe(123)
        ->and(array_intersect(array_keys($compact), ['profitability', 'travel', 'budget', 'annual_history', 'history']))->toBeEmpty()
        ->and($bindings)->not->toContain('event_budget', 'financial', 'staff');
    Http::assertNothingSent();
});

test('compact native Sales keeps current device role tenant and stock-binding fences', function (string $change, int $status) {
    config(['pos_live.enabled' => true, 'pos_live.event_stats_enabled' => true]);
    eventReceipt($this);
    commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $scope = nativeCommerceScope($this);
    $token = $this->owner->createToken('Fictional native Sales read', ['mobile:read', 'payments:read'], now()->addDay())->plainTextToken;
    $this->withToken($token)->withHeader('X-Pos-Device', 'fixture-owner-phone');
    $url = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$scope['register']->id.'/event-stats';
    $this->getJson($url)->assertOk()->assertJsonPath('sales.sale_count', 1)->assertJsonMissingPath('annual_history');
    if ($change === 'device') {
        $this->withHeader('X-Pos-Device', 'unassigned-phone');
    } elseif ($change === 'role') {
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'manager']);
    } elseif ($change === 'membership') {
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['membership_active' => false]);
    } elseif ($change === 'stock_binding') {
        $this->stock->rows('bindings', $this->tenant->id)->update(['active' => false]);
    } else {
        [$foreign] = teamWorkspace();
        $url = str_replace('/'.$this->tenant->slug.'/', '/'.$foreign->slug.'/', $url);
    }
    $this->getJson($url)->assertStatus($status)->assertJsonMissingPath('sales');
})->with([['device', 404], ['role', 403], ['membership', 404], ['stock_binding', 503], ['tenant', 404]]);

test('compact native Sales freshly verifies its source task on every read and keeps canonical occurrence and timezone admission', function () {
    eventReceipt($this);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $scope = nativeCommerceScope($this);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'user_id' => $this->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1,
        'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $event->update(['connection_id' => $connection->id, 'data' => [...$event->data, '_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    $task = ['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(['*tasks/300*' => Http::sequence()->push(['data' => $task])->pushStatus(403)->push(['data' => $task])->push(['data' => $task])]);
    $read = fn () => app(EventCommerceReport::class)->forRegister($scope, $this->owner);
    expect($read()['sales']['sale_count'])->toBe(1);
    try {
        $read();
        $this->fail('A successful prior read must not authorize a revoked task.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    $this->stock->rows('event_links', $this->tenant->id)->where('event_id', 123)->update(['metadata' => json_encode(['timezone' => 'Unreviewed/Invalid'])]);
    try {
        $read();
        $this->fail('The compact report must retain the reviewed timezone gate.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(422);
    }
    commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    expect($read)->toThrow(\App\Services\Pos\PosException::class, 'event_cost_mapping_required');
});

test('bulk commerce associations retain explicit precedence, reviewed unique Asana links and tenant isolation', function () {
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'user_id' => $this->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1]);
    $source = 'reviewed-asana-source';
    $this->stock->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 456, 'provider' => 'asana', 'external_id' => $source,
        'metadata' => '{}', 'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $explicit = commerceOccurrence($this, ['source_id' => $source, '_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $asana = commerceOccurrence($this, ['source_id' => $source]);
    $unreviewed = commerceOccurrence($this, ['source_id' => 'unreviewed', '_teams_enrichment' => ['commerce_event' => ['id' => 999]]]);
    $malformed = commerceOccurrence($this, ['source_id' => $source, '_teams_enrichment' => ['commerce_event' => ['id' => ['unreviewed']]]]);
    foreach ([$explicit, $asana, $unreviewed, $malformed] as $event) {
        $event->update(['connection_id' => $connection->id]);
    }
    $service = app(EventCommerceReport::class);
    DB::enableQueryLog();
    $ids = $service->commerceIds([$explicit, $asana, $unreviewed, $malformed]);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($ids)->toBe([$explicit->id => 123, $asana->id => 456, $unreviewed->id => null, $malformed->id => null])
        ->and($queries)->toHaveCount(2);
    $this->stock->rows('event_links', $this->tenant->id)->where('provider', 'asana')->where('external_id', $source)->delete();
    expect($service->commerceIds([$explicit, $asana]))->toBe([$explicit->id => 123, $asana->id => null]);
    [$foreign] = teamWorkspace();
    $foreignArea = studioArea($foreign);
    $foreignEvent = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $foreign->id, 'area_id' => $foreignArea->id,
        'kind' => 'event', 'status' => 'accepted', 'source_key' => hash('sha256', Str::uuid()), 'title' => 'Foreign event',
        'data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]]);
    expect($service->commerceIds([$explicit, $foreignEvent]))->toBe([$explicit->id => 123, $foreignEvent->id => null]);
    Http::assertNothingSent();
});

test('browser compact sales load only admitted summary and transactions expand separately without unused history or budget reads', function () {
    $sale = eventReceipt($this);
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    app()->instance(\App\Services\Team\Studio\EventIntelligence::class, Mockery::mock(\App\Services\Team\Studio\EventIntelligence::class)->shouldNotReceive('detail')->getMock());
    app()->instance(\App\Services\Team\Studio\EventProfitability::class, Mockery::mock(\App\Services\Team\Studio\EventProfitability::class)->shouldNotReceive('calculate')->getMock());
    $url = '/team/events/'.$event->id.'/confirmed-sales?tenant='.$this->tenant->slug;
    $summary = $this->actingAs($this->owner)->getJson($url.'&summary=1')->assertOk()->assertJsonPath('details_loaded', false)
        ->assertJsonCount(0, 'sales')->assertJsonPath('report.sales.sale_count', 1)->assertJsonPath('report.sales.net_sales_minor', 1000)
        ->assertJsonMissingPath('report.sales.receipt_ids')->assertJsonMissingPath('report.sales.square_order_ids')
        ->assertJsonMissingPath('report.history')->assertJsonMissingPath('report.annual_history')->assertJsonMissingPath('report.budget')->json();
    $expanded = $this->getJson($url.'&summary=0')->assertOk()->assertJsonPath('details_loaded', true)->assertJsonPath('sales.0.id', $sale->id)->json();
    expect(\Illuminate\Support\Arr::except($expanded['report']['sales'], ['as_of', 'source_freshness']))
        ->toBe(\Illuminate\Support\Arr::except($summary['report']['sales'], ['as_of', 'source_freshness']));
    $member = teamMember($this->tenant, 'member');
    $this->actingAs($member)->getJson($url.'&summary=1')->assertForbidden()->assertJsonMissingPath('report.sales');
    $this->actingAs($this->owner)->getJson($url.'&summary=unexpected')->assertUnprocessable();
});

test('browser summary retains known reviewed Square sales under partial coverage and fresh source task withdrawal', function () {
    $event = commerceOccurrence($this, ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]);
    $binding = scopedCommerceMapping($this, $event, true);
    scopedCommerceOrder($this, $binding, 'partial-summary-original', 2000);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'user_id' => $this->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1,
        'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $event->update(['connection_id' => $connection->id, 'data' => [...$event->data, '_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    $task = ['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(['*tasks/300*' => Http::sequence()->push(['data' => $task])->pushStatus(403)]);
    $url = '/team/events/'.$event->id.'/confirmed-sales?tenant='.$this->tenant->slug.'&summary=1';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('report.sales.sale_count', 1)
        ->assertJsonPath('report.sales.known_net_sales_minor', 2000)->assertJsonCount(0, 'sales')->assertJsonPath('details_loaded', false);
    $this->getJson($url)->assertForbidden()->assertJsonMissingPath('report.sales');
    Http::assertSentCount(2);
});
