<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\ConfirmedCustomerPurchaseProjection;
use App\Services\Team\Studio\EventCommerceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function purchaseLookupRecord($tenant, $area, string $kind, array $data = []): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => $kind, 'title' => 'Fictional '.$kind, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => $data]);
}

function purchaseLookupScans(): array
{
    return array_values(array_filter(DB::getQueryLog(), function (array $query): bool {
        $sql = strtolower(str_replace(['"', '`'], '', $query['query']));

        return str_contains($sql, 'from team_operation_records') && in_array('event', $query['bindings'], true)
            && ! preg_match('/\bid\s*(?:in\s*\(|=)/', $sql);
    }));
}

beforeEach(function (): void {
    $this->freezeTime();
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'request_lookup_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    app(StockLedger::class)->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'manual',
        'external_id' => 'lookup-event', 'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'UTC', 'postal_code' => '29201']),
        'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
});

it('bounds encrypted event scans across a cold audience and refreshes late attribution in the next request', function (): void {
    $buyers = collect();
    $orders = [];
    for ($i = 0; $i < 24; $i++) {
        $buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Buyer '.$i, 'country' => 'US', 'postal_code' => '75201']);
        $buyers->push($buyer);
        for ($j = 0; $j < 2; $j++) {
            $id = 'lookup-order-'.$i.'-'.$j;
            $orders[] = $id;
            SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $id, 'state' => 'COMPLETED', 'closed_at' => now()->subHour(),
                'location_id' => 'lookup-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 80, 'currency' => 'USD']]]);
            SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id,
                'status' => 'COMPLETED', 'amount_money' => 1080, 'currency' => 'USD', 'location_id' => 'lookup-location', 'source_type' => 'CARD', 'raw_payload' => []]);
            MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $buyer->id, 'source_type' => 'square_order', 'source_id' => $id]);
        }
    }
    $occurrence = purchaseLookupRecord($this->tenant, $this->area, 'event', ['_teams_enrichment' => ['commerce_event' => ['id' => 123],
        'square_orders' => ['ids' => $orders, 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]]]);
    $series = purchaseLookupRecord($this->tenant, $this->area, 'series');
    $seriesLink = purchaseLookupRecord($this->tenant, $this->area, 'event_link', ['series_id' => $series->id, 'occurrence_id' => $occurrence->id]);
    for ($i = 0; $i < 40; $i++) {
        purchaseLookupRecord($this->tenant, $this->area, 'event', ['source_id' => 'unrelated-'.$i, 'notes' => str_repeat('Unrelated encrypted event. ', 20)]);
    }
    [$foreign] = teamWorkspace();
    $foreignArea = studioArea($foreign);
    purchaseLookupRecord($foreign, $foreignArea, 'event', ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'square_orders' => ['ids' => $orders]]]);

    $url = '/marketing/event-audiences/preview?tenant='.$this->tenant->slug;
    $input = ['kind' => 'previous_show_nearby', 'series_id' => $series->id, 'postal_code' => '29201', 'radius_miles' => 50];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $preview = $this->actingAs($this->owner)->postJson($url, [...$input, 'preview_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('matched', 24)->assertJsonPath('complete', true);
    $scans = purchaseLookupScans();
    $coldQueryCount = count(DB::getQueryLog());
    $coldHistoryReads = collect(DB::getQueryLog())->filter(function ($query) {
        $sql = str_replace(['"', '`'], '', $query['query']);

        return str_contains($sql, 'from square_orders') && str_contains($sql, 'order by closed_at desc, id desc');
    })->count();
    DB::disableQueryLog();
    // One shared lookup plus the independent original-sales allocation conflict check, rather than a scan per purchase/profile.
    expect($scans)->toHaveCount(2);
    foreach ($scans as $scan) {
        expect($scan['bindings'])->toContain($this->tenant->id)->not->toContain($foreign->id);
    }
    expect(collect($scans)->filter(fn ($scan) => str_contains(str_replace(['"', '`'], '', $scan['query']), 'select id, connection_id, data')))->toHaveCount(1);
    // Cohort source confirmation and rule evaluation share one original-history rebuild per canonical buyer.
    expect($coldHistoryReads)->toBe(24)->and($coldQueryCount)->toBeLessThan(1600);
    expect($preview->json('samples.0.reasons'))->toBe(['Previous buyer at '.$series->title]);
    $projection = app(ConfirmedCustomerPurchaseProjection::class)->read($buyers->first(), $this->tenant->id);
    expect($projection['confirmed_orders'])->toBe(2)->and($projection['purchased_event_ids'])->toBe(['123'])
        ->and($projection['purchased_series_ids'])->toBe([$series->id])->and($projection['currencies'][0]['net_minor'])->toBeNull()
        ->and($projection['currencies'][0]['refunds_known'])->toBeFalse()->and($projection['captured_event_ids'])->toBe([]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->postJson($url, [...$input, 'preview_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('matched', 24);
    $warmHistoryReads = collect(DB::getQueryLog())->filter(function ($query) {
        $sql = str_replace(['"', '`'], '', $query['query']);

        return str_contains($sql, 'from square_orders') && str_contains($sql, 'order by closed_at desc, id desc');
    })->count();
    $warmQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($warmHistoryReads)->toBe(0)->and($warmQueries)->toBeLessThan(700);

    // Explicit attribution can move to a newly confirmed canonical customer; another HTTP request must not reuse the old lookup/projection.
    $late = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Confirmed later', 'country' => 'US', 'postal_code' => '75201']);
    MarketingProfileLink::forTenantId($this->tenant->id)->where('marketing_profile_id', $buyers->first()->id)->update(['marketing_profile_id' => $late->id]);
    $replacement = purchaseLookupRecord($this->tenant, $this->area, 'event', $occurrence->data);
    $occurrence->update(['status' => 'withdrawn']);
    $seriesLink->update(['data' => ['series_id' => $series->id, 'occurrence_id' => $replacement->id]]);
    $this->travel(6)->seconds();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $fresh = $this->postJson($url, [...$input, 'preview_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('matched', 24)->assertJsonPath('complete', true);
    $scans = purchaseLookupScans();
    DB::disableQueryLog();
    expect($scans)->toHaveCount(2)->and(array_column($fresh->json('samples'), 'id'))->toContain($late->id)->not->toContain($buyers->first()->id);
    expect(app(ConfirmedCustomerPurchaseProjection::class)->read($buyers->first(), $this->tenant->id)['confirmed_orders'])->toBe(0);
    Http::assertNothingSent();
});

it('rebuilds for console callers and rechecks current candidate ownership instead of retaining models between jobs', function (): void {
    $service = app(EventCommerceReport::class);
    $event = purchaseLookupRecord($this->tenant, $this->area, 'event', ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'square_orders' => ['ids' => ['original-order']]]]);
    expect($service->candidate($this->tenant->id, 123)?->id)->toBe($event->id)
        ->and($service->squareOccurrences($this->tenant->id, 'original-order')->pluck('id')->all())->toBe([$event->id]);
    $event->update(['data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'square_orders' => ['ids' => ['new-order']]]]]);
    expect($service->squareOccurrences($this->tenant->id, 'original-order'))->toBeEmpty()
        ->and($service->squareOccurrences($this->tenant->id, 'new-order')->pluck('id')->all())->toBe([$event->id]);
    $event->update(['status' => 'withdrawn']);
    expect($service->candidate($this->tenant->id, 123))->toBeNull()->and($service->squareOccurrences($this->tenant->id, 'new-order'))->toBeEmpty();
    Http::assertNothingSent();
});
