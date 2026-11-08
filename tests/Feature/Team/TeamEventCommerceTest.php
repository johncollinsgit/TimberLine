<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\TenantCustomerLookup;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use App\Services\Team\Studio\EventCommerceReport;
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
    expect($read()['net_sales_minor'])->toBeNull()->and($read()['confirmed_refunds_minor'])->toBe(500);
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
