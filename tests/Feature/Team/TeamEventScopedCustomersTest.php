<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Team\Studio\EventPerformance;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'scoped_customer_fixture']);
    $this->travelTo(now()->setDate(2026, 11, 15));
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->binding = ['connection_id' => 'fictional-original-reader', 'connection_generation' => 1, 'merchant_id' => 'fictional-merchant', 'environment' => 'production', 'location_ids' => ['retired-location']];
    $this->connectionActive = true;
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('available')->with($this->tenant->id)->andReturnUsing(fn () => [['id' => 'fictional-original-reader', 'connection_generation' => 1, 'merchant_id' => 'fictional-merchant', 'environment' => 'production', 'location_ids' => ['retired-location'], 'status' => $this->connectionActive ? 'active' : 'disconnected']]);
    $context->shouldReceive('assertCurrent')->with($this->tenant->id, $this->binding)->andReturnUsing(function () {
        abort_unless($this->connectionActive, 409, 'Original source revoked.');
    });
    app()->instance(SquareConnectionContext::class, $context);
    $this->runs = [];
    foreach (['orders', 'payments'] as $kind) {
        $this->runs[$kind] = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'created_by' => $this->owner->id, 'type' => 'square_'.$kind.'_sync', 'status' => 'completed', 'started_at' => '2026-01-05', 'finished_at' => '2026-01-05',
            'summary' => ['source_binding' => $this->binding, 'mode' => 'live-sync', 'errors' => 0, 'since' => '2020-01-01T00:00:00Z', 'initial_cursor' => null, 'checkpoint' => ['cursor' => null, 'errors' => 0]]]);
    }
});

function scopedCustomerOccurrence($test, string $kind): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id, 'kind' => $kind,
        'title' => 'Historical fair', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'visibility' => 'admin',
        'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'data' => ['person_name' => 'Former operator', 'net_sales_cents' => 3500]]);
}

function scopedCustomerOrder($test, string $source, string $date, int $net, ?MarketingProfile $customer): void
{
    $total = $net + 70;
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $source, 'location_id' => 'retired-location', 'state' => 'COMPLETED', 'total_money_amount' => $total, 'total_money_currency' => 'USD', 'closed_at' => $date.' 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['_source_binding' => $test->binding, 'total_tax_money' => ['amount' => 70, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'payment-'.$source, 'square_order_id' => $source, 'location_id' => 'retired-location', 'amount_money' => $total, 'currency' => 'USD', 'status' => 'COMPLETED', 'source_type' => 'CARD', 'created_at_source' => $date.' 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['_source_binding' => $test->binding, 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    if ($customer) {
        MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $customer->id, 'source_type' => 'square_order', 'source_id' => $source]);
    }
}

test('real historical scoped originals feed canonical customer reporting without a Pay event or copied customer master', function () {
    $series = scopedCustomerOccurrence($this, 'series');
    $historic = scopedCustomerOccurrence($this, 'financial');
    $customer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Customer']);
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Prior record', 'merged_into_profile_id' => $customer->id]);
    scopedCustomerOrder($this, 'original-one', '2025-11-07', 1000, $alias);
    scopedCustomerOrder($this, 'original-two', '2025-11-08', 2000, $customer);
    scopedCustomerOrder($this, 'unattributed-original', '2025-11-09', 500, null);
    app(TeamsStudio::class)->reviewOccurrence($this->tenant, $this->owner, $this->area, [
        'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $historic->id,
        'financial_id' => $historic->id, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed historical period and original reader.', 'venue' => 'Historic venue', 'timezone' => 'America/New_York',
        'verified_selling_days' => 3, 'daily_hours' => [], 'square_scopes' => [['location_id' => 'retired-location', 'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'orders_run_id' => $this->runs['orders']->id, 'payments_run_id' => $this->runs['payments']->id]],
    ]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $url = '/team/event-performance?tenant='.$this->tenant->slug.'&area='.$this->area->id.'&from=2025-11-01&to=2025-11-30';
    $response = $this->actingAs($this->owner)->getJson($url.'&group_by=customer')->assertOk()->json();
    expect($response['rows'])->toHaveCount(1)->and($response['rows'][0]['id'])->toBe((string) $customer->id)
        ->and($response['rows'][0]['label'])->toBe('Ada Customer')->and($response['rows'][0]['paid_total_minor'])->toBe(3140)
        ->and($response['rows'][0]['sale_count'])->toBe(2)->and($response['rows'][0]['event_ids'])->toBe([$historic->id])
        ->and($response['summary']['customer_count'])->toBe(1)->and($response['summary']['paid_total_minor'])->toBe(3140)
        ->and($response['summary']['net_sales_minor'])->toBe(3500);
    $this->getJson($url.'&customer_id='.$customer->id)->assertOk()->assertJsonPath('total_rows', 1)->assertJsonPath('rows.0.id', $historic->id);
    expect(MarketingProfile::forTenantId($this->tenant->id)->count())->toBe(2);
    $this->connectionActive = false;
    $this->getJson($url.'&group_by=customer')->assertConflict();
});

test('existing original Square customer journeys use their nested reviewed event contract in central reporting', function (bool $alsoScoped) {
    $event = scopedCustomerOccurrence($this, 'event');
    $customer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Current Buyer']);
    scopedCustomerOrder($this, 'legacy-original-one', '2025-11-07', 1000, $customer);
    app(StockLedger::class)->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'original-event',
        'metadata' => json_encode(['name' => 'Historical fair', 'timezone' => 'America/New_York', 'start_on' => '2025-11-07', 'end_on' => '2025-11-09']), 'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $event->update(['data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'square_orders' => ['ids' => ['legacy-original-one'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]]]]);
    if ($alsoScoped) {
        $series = scopedCustomerOccurrence($this, 'series');
        app(TeamsStudio::class)->reviewOccurrence($this->tenant, $this->owner, $this->area, [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id,
            'financial_id' => null, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed same original source and period.', 'venue' => 'Historic venue', 'timezone' => 'America/New_York',
            'verified_selling_days' => 3, 'daily_hours' => [], 'square_scopes' => [['location_id' => 'retired-location', 'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'orders_run_id' => $this->runs['orders']->id, 'payments_run_id' => $this->runs['payments']->id]],
        ]);
    }
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $url = '/team/event-performance?tenant='.$this->tenant->slug.'&area='.$this->area->id.'&from=2025-11-01&to=2025-11-30&group_by=customer';
    $response = $this->actingAs($this->owner)->getJson($url)->assertOk()->json();
    expect($response['rows'])->toHaveCount(1)->and($response['rows'][0]['paid_total_minor'])->toBe(1070)
        ->and($response['rows'][0]['id'])->toBe((string) $customer->id)->and($response['summary']['customer_purchase_count'])->toBe(1);
    $this->connectionActive = false;
    $this->getJson($url)->assertConflict();
})->with(['legacy original only' => false, 'same original also in reviewed scope' => true]);
