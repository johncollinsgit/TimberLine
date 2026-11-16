<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\SquarePurchaseEvidence;
use App\Services\Team\Studio\EventCustomerDependencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-07T12:00:00Z'));
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'pos_live.runtime_id' => 'dependencies_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Avery', 'last_name' => 'Buyer']);
    $this->otherBuyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Bailey', 'last_name' => 'Buyer']);
    $this->binding = ['environment' => 'production', 'merchant_id' => 'dependency-merchant', 'location_ids' => ['dependency-location'],
        'connection_id' => 'dependency-reader', 'runtime_id' => 'dependencies_fixture', 'connection_generation' => 1, 'source_purpose' => 'customer_history'];
    app(StockLedger::class)->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id,
        'event_id' => 123, 'provider' => 'manual', 'external_id' => 'dependency-reviewed-event',
        'metadata' => json_encode(['name' => 'Fictional original fair', 'timezone' => 'UTC', 'start_on' => '2026-11-01', 'end_on' => '2026-11-02']),
        'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event', 'title' => 'Fictional original fair', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'data' => ['_teams_enrichment' => [
            'commerce_event' => ['id' => 123, 'reviewed_by' => (string) $this->owner->id, 'reviewed_at' => now()->toIso8601String()],
            'square_orders' => ['ids' => ['dependency-order'], 'reviewed_by' => $this->owner->id,
                'reviewed_at' => now()->toIso8601String(), 'source_binding' => $this->binding],
        ]]]);
    $this->order = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'dependency-order',
        'location_id' => 'dependency-location', 'state' => 'COMPLETED', 'total_money_amount' => 1100, 'total_money_currency' => 'USD',
        'closed_at' => '2026-11-01T16:00:00Z', 'raw_payload' => ['id' => 'dependency-order', '_source_binding' => $this->binding,
            'total_money' => ['amount' => 1100, 'currency' => 'USD'], 'total_tax_money' => ['amount' => 100, 'currency' => 'USD'],
            'total_tip_money' => ['amount' => 0, 'currency' => 'USD'],
            'net_amounts' => ['total_money' => ['amount' => 1100, 'currency' => 'USD'], 'tax_money' => ['amount' => 100, 'currency' => 'USD']]]]);
    $this->payment = dependencyPayment($this, 'dependency-payment', 1100);
    $this->orderLink = dependencySourceLink($this, $this->buyer, 'square_order', 'dependency-order', [
        'private_provenance' => ['evidence' => 'original A', 'retained_nested' => ['flag' => true]],
    ]);
    $this->dependencies = app(EventCustomerDependencies::class);
});

function dependencySourceLink($test, MarketingProfile $profile, string $type, string $id, array $meta = []): MarketingProfileLink
{
    return MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id,
        'source_type' => $type, 'source_id' => $id, 'source_meta' => $meta, 'match_method' => 'explicit_source_review', 'confidence' => '1.00']);
}

function dependencyPayment($test, string $id, int $amount, string $orderId = 'dependency-order'): SquarePayment
{
    return SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => $id, 'square_order_id' => $orderId,
        'location_id' => 'dependency-location', 'amount_money' => $amount, 'currency' => 'USD', 'status' => 'COMPLETED', 'source_type' => 'CARD',
        'created_at_source' => '2026-11-01T16:00:00Z', 'raw_payload' => ['id' => $id, 'order_id' => $orderId, 'location_id' => 'dependency-location',
            'status' => 'COMPLETED', '_source_binding' => $test->binding, 'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
            'total_money' => ['amount' => $amount, 'currency' => 'USD'], 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
}

function dependencyPurchase(): array
{
    return ['source' => 'square_order', 'source_id' => 'dependency-order', 'amount_minor' => 1100,
        'refunded_minor' => 0, 'currency' => 'USD', 'occurred_at' => '2026-11-01T16:00:00+00:00'];
}

function dependencyRejects(callable $read): void
{
    try {
        $read();
        test()->fail('Changed customer/source evidence must reject the fact.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
}

function dependencyMoney($test, int $amount, int $refund = 0): void
{
    $raw = $test->order->raw_payload;
    $raw['total_money']['amount'] = $amount;
    $raw['net_amounts']['total_money']['amount'] = $amount - $refund;
    $test->order->update(['total_money_amount' => $amount, 'raw_payload' => $raw]);
    $raw = $test->payment->raw_payload;
    $raw['amount_money']['amount'] = $raw['total_money']['amount'] = $amount;
    $raw['refunded_money']['amount'] = $refund;
    $test->payment->update(['amount_money' => $amount, 'raw_payload' => $raw]);
}

test('event dependency frames retain original purchase witnesses and ignore unrelated customer imports', function () {
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    expect($frame['claims']['square_order:dependency-order'])->toBe($this->buyer->id)
        ->and($frame['labels'][$this->buyer->id])->toBe('Avery Buyer')
        ->and($frame['witnesses']['square_order:dependency-order'][$this->buyer->id])->toMatchArray([
            'confirmed' => true, 'amount_minor' => 1100, 'refunded_minor' => 0, 'currency' => 'USD', 'occurred_at' => '2026-11-01T16:00:00+00:00',
        ]);
    $this->dependencies->assertPurchase($frame, $this->event->id, $this->buyer, dependencyPurchase());
    $unrelated = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Unrelated']);
    dependencySourceLink($this, $unrelated, 'square_order', 'unrelated-source', ['private' => ['not_in_this_event' => true]]);
    $again = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($again['signature'])->toBe($frame['signature'])
        ->and($again['witnesses'])->toBe($frame['witnesses']);
    Http::assertNothingSent();
});

test('fresh scoped identity dependencies detect every relevant identity or full metadata change', function (string $change) {
    $frame = $this->dependencies->capture($this->tenant, $this->area);
    match ($change) {
        'reassignment' => $this->orderLink->update(['marketing_profile_id' => $this->otherBuyer->id]),
        'delete_replace' => (function () {
            $data = $this->orderLink->only(['source_type', 'source_id', 'source_meta']);
            $this->orderLink->delete();
            dependencySourceLink($this, $this->buyer, $data['source_type'], $data['source_id'], $data['source_meta']);
        })(),
        'merge' => $this->buyer->update(['merged_into_profile_id' => $this->otherBuyer->id]),
        'archive' => $this->buyer->update(['archived_at' => now()]),
        'incoming_alias' => MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Incoming alias', 'merged_into_profile_id' => $this->buyer->id]),
        'full_metadata' => DB::table('marketing_profile_links')->where('id', $this->orderLink->id)->update(['source_meta' => json_encode([
            'private_provenance' => ['evidence' => 'original B', 'retained_nested' => ['flag' => true]],
        ])]),
    };
    $again = $this->dependencies->capture($this->tenant, $this->area, $frame['scope']);
    expect($again['signature'])->not->toBe($frame['signature']);
})->with(['reassignment', 'delete_replace', 'merge', 'archive', 'incoming_alias', 'full_metadata']);

test('a conflicting split tender rejects an event purchase despite one clear direct order owner', function () {
    $raw = $this->payment->raw_payload;
    $raw['amount_money']['amount'] = $raw['total_money']['amount'] = 600;
    $this->payment->update(['amount_money' => 600, 'raw_payload' => $raw]);
    $otherTender = dependencyPayment($this, 'dependency-second-payment', 500);
    dependencySourceLink($this, $this->buyer, 'square_payment', $this->payment->square_payment_id);
    dependencySourceLink($this, $this->otherBuyer, 'square_payment', $otherTender->square_payment_id);
    expect(app(SquarePurchaseEvidence::class)->confirmedOrder($this->order, SquarePayment::where('tenant_id', $this->tenant->id)->get()))->toBeTrue();

    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    expect($frame['claims']['square_order:dependency-order'])->toBeNull();
    dependencyRejects(fn () => $this->dependencies->assertPurchase($frame, $this->event->id, $this->buyer, dependencyPurchase()));
});

test('A to B to A source changes cannot admit intermediate customer or label facts', function () {
    $unselected = $this->order->replicate();
    $raw = $unselected->raw_payload;
    $raw['id'] = 'unselected-original';
    $unselected->fill(['square_order_id' => 'unselected-original', 'raw_payload' => $raw])->save();
    dependencyPayment($this, 'unselected-payment', 1100, 'unselected-original');
    dependencySourceLink($this, $this->buyer, 'square_order', 'unselected-original');
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    $this->orderLink->update(['marketing_profile_id' => $this->otherBuyer->id]);
    $this->orderLink->update(['marketing_profile_id' => $this->buyer->id]);
    $this->buyer->update(['first_name' => 'Intermediate']);
    $intermediateLabel = $this->buyer->fresh();
    $this->buyer->update(['first_name' => 'Avery']);
    $again = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($again['signature'])->toBe($frame['signature']);
    dependencyRejects(fn () => $this->dependencies->assertPurchase($again, $this->event->id, $this->otherBuyer, dependencyPurchase()));
    dependencyRejects(fn () => $this->dependencies->assertPurchase($again, $this->event->id, $intermediateLabel, dependencyPurchase()));
    dependencyRejects(fn () => $this->dependencies->assertPurchase($again, $this->event->id, $this->buyer, array_replace(dependencyPurchase(), ['source_id' => 'unselected-original'])));
    $this->dependencies->assertPurchase($again, $this->event->id, $this->buyer->fresh(), dependencyPurchase());
});

test('capture facts require an unambiguous current canonical relationship to the selected event', function () {
    $capture = dependencySourceLink($this, $this->buyer, 'pos_event_capture', 'dependencies_fixture:capture-one', [
        'event_id' => 123, 'captured_at' => '2026-11-01T12:00:00+00:00', 'capture_evidence_version' => 1, 'record_origin' => 'created',
    ]);
    $frame = $this->dependencies->capture($this->tenant, $this->area);
    $this->dependencies->assertCapture($frame, $this->event->id, $this->buyer);
    dependencyRejects(fn () => $this->dependencies->assertCapture($frame, $this->event->id, $this->otherBuyer));
    $capture->update(['marketing_profile_id' => $this->otherBuyer->id]);
    $changed = $this->dependencies->capture($this->tenant, $this->area, $frame['scope']);
    expect($changed['signature'])->not->toBe($frame['signature']);
    dependencyRejects(fn () => $this->dependencies->assertCapture($changed, $this->event->id, $this->buyer));
});

test('original monetary and selling-date revisions invalidate the scoped customer dependency frame', function (string $change) {
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    if ($change === 'date') {
        $this->order->update(['closed_at' => '2026-11-02T16:00:00Z']);
    } else {
        dependencyMoney($this, $change === 'amount' ? 1200 : 1100, $change === 'refund' ? 100 : 0);
    }
    $changed = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($changed['signature'])->not->toBe($frame['signature']);
    dependencyRejects(fn () => $this->dependencies->assertPurchase($changed, $this->event->id, $this->buyer, dependencyPurchase()));
})->with(['amount', 'refund', 'date']);

test('money A to B to A cannot admit an intermediate original-purchase amount', function () {
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    dependencyMoney($this, 1200);
    $intermediate = array_replace(dependencyPurchase(), ['amount_minor' => 1200]);
    dependencyMoney($this, 1100);
    $again = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($again['signature'])->toBe($frame['signature']);
    dependencyRejects(fn () => $this->dependencies->assertPurchase($again, $this->event->id, $this->buyer, $intermediate));
    $this->dependencies->assertPurchase($again, $this->event->id, $this->buyer, dependencyPurchase());
});

test('a different raw provider customer does not override the explicitly linked original order', function () {
    $this->order->update(['square_customer_id' => 'raw-provider-customer']);
    $this->payment->update(['square_customer_id' => 'raw-provider-customer']);
    dependencySourceLink($this, $this->otherBuyer, 'square_customer', 'raw-provider-customer');
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    expect($frame['claims']['square_order:dependency-order'])->toBe($this->buyer->id)
        ->and($frame['claims']['square_customer:raw-provider-customer'])->toBe($this->otherBuyer->id);
    $this->dependencies->assertPurchase($frame, $this->event->id, $this->buyer, dependencyPurchase());
});

test('reviewed Website event witnesses fence their original money and inherited customer ownership', function () {
    $site = TenantSite::create(['tenant_id' => $this->tenant->id, 'subdomain' => 'dependency-event']);
    $customer = WebsiteCustomer::create(['tenant_id' => $this->tenant->id]);
    $customerLink = dependencySourceLink($this, $this->buyer, 'website_customer', (string) $customer->id);
    $order = WebsiteOrder::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $site->id, 'website_customer_id' => $customer->id,
        'source' => 'website', 'number' => 'reviewed-website', 'lookup_token' => 'reviewed-website', 'paid_at' => '2026-11-01T17:00:00Z',
        'payment_status' => 'paid', 'total_cents' => 2400, 'refunded_cents' => 0, 'currency' => 'USD']);
    dependencySourceLink($this, $this->buyer, 'website_order', (string) $order->id, ['event_attribution' => [
        'event_id' => 123, 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Reviewed original event receipt',
    ]]);
    $purchase = ['source' => 'website_order', 'source_id' => (string) $order->id, 'amount_minor' => 2400,
        'refunded_minor' => 0, 'currency' => 'USD', 'occurred_at' => '2026-11-01T17:00:00+00:00'];
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    $this->dependencies->assertPurchase($frame, $this->event->id, $this->buyer, $purchase);
    $order->update(['payment_status' => 'partially_refunded', 'refunded_cents' => 200]);
    $changed = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($changed['signature'])->not->toBe($frame['signature']);
    dependencyRejects(fn () => $this->dependencies->assertPurchase($changed, $this->event->id, $this->buyer, $purchase));
    $this->dependencies->assertPurchase($changed, $this->event->id, $this->buyer, array_replace($purchase, ['refunded_minor' => 200]));
    $customerLink->update(['marketing_profile_id' => $this->otherBuyer->id]);
    $conflicted = $this->dependencies->capture($this->tenant, $this->area, $changed['scope'], true);
    dependencyRejects(fn () => $this->dependencies->assertPurchase($conflicted, $this->event->id, $this->buyer, array_replace($purchase, ['refunded_minor' => 200])));
});

test('a new competing allocation in another area invalidates the captured event selectors', function () {
    $frame = $this->dependencies->capture($this->tenant, $this->area);
    $otherArea = $this->area->replicate();
    $otherArea->id = (string) Str::uuid();
    $otherArea->slug = 'other-dependency-area';
    $otherArea->save();
    TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $otherArea->id,
        'kind' => 'event', 'title' => 'Competing original allocation', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['_teams_enrichment' => ['square_orders' => ['ids' => ['dependency-order'], 'reviewed_by' => $this->owner->id,
            'reviewed_at' => now()->toIso8601String(), 'source_binding' => $this->binding]]]]);
    try {
        $changed = $this->dependencies->capture($this->tenant, $this->area, $frame['scope']);
        expect($changed['signature'])->not->toBe($frame['signature']);
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
});

test('unrelated event notes versions and stock pool labels do not change the effective customer frame', function () {
    $unrelated = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event', 'title' => 'Unrelated planning record', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['original_notes' => 'Original unrelated planning notes']]);
    $stock = app(StockLedger::class);
    $pool = $stock->createPool($this->tenant->id, 'unrelated-dependency-pool', $this->owner->id, 'Unrelated pool', 'show', 'event:999');
    $frame = $this->dependencies->capture($this->tenant, $this->area);
    $unrelated->update(['version' => 2, 'data' => ['original_notes' => 'Changed unrelated planning notes']]);
    $stock->rows('pools', $this->tenant->id)->where('id', $pool['pool_id'])->update(['name' => 'Renamed unrelated pool', 'updated_at' => now()->addSecond()]);
    expect($this->dependencies->capture($this->tenant, $this->area, $frame['scope'])['signature'])->toBe($frame['signature']);
});

test('fresh witnesses reject a formerly valid purchase after its event review authority is revoked', function (string $change) {
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    $this->dependencies->assertPurchase($frame, $this->event->id, $this->buyer, dependencyPurchase());
    if ($change === 'allocation_reviewer') {
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['membership_active' => false]);
    } else {
        app(StockLedger::class)->rows('event_links', $this->tenant->id)->where('event_id', 123)->update([
            'metadata' => '{',
        ]);
    }
    dependencyRejects(function () {
        $changed = $this->dependencies->capture($this->tenant, $this->area, null, true);
        $this->dependencies->assertPurchase($changed, $this->event->id, $this->buyer, dependencyPurchase());
    });
})->with(['allocation_reviewer', 'commerce_metadata']);

test('fresh event frames stay bounded with current and doubled unrelated customer directories', function () {
    $baseline = $this->dependencies->capture($this->tenant, $this->area);
    $metrics = [];
    foreach (['current', 'double'] as $stage) {
        $firstProfile = (int) DB::table('marketing_profiles')->max('id') + 1;
        for ($offset = 0; $offset < 76000; $offset += 200) {
            $rows = [];
            for ($n = $offset; $n < min($offset + 200, 76000); $n++) {
                $rows[] = ['id' => $firstProfile + $n, 'tenant_id' => $this->tenant->id, 'first_name' => 'Unrelated imported '.$stage.' '.$n,
                    'source_channels' => '["square"]', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
            }
            DB::table('marketing_profiles')->insert($rows);
        }
        for ($offset = 0; $offset < 151000; $offset += 200) {
            $rows = [];
            for ($n = $offset; $n < min($offset + 200, 151000); $n++) {
                $sourceType = $n < 58000 ? 'square_payment' : ($n < 72000 ? 'square_order' : ($n % 2 ? 'square_customer' : 'shopify_customer'));
                $rows[] = ['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $firstProfile + intdiv($n, 2),
                    'source_type' => $sourceType, 'source_id' => 'unrelated-'.$stage.'-'.$n,
                    'source_meta' => json_encode(['source_system' => str_starts_with($sourceType, 'square_') ? 'square' : 'shopify', 'source_record_type' => substr($sourceType, strpos($sourceType, '_') + 1), 'import_reference' => $stage.'-'.$n]),
                    'match_method' => 'original_provider_id', 'confidence' => '1.00', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
            }
            DB::table('marketing_profile_links')->insert($rows);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $samples = [];
        foreach (range(1, 10) as $sample) {
            $started = hrtime(true);
            $frame = $this->dependencies->capture($this->tenant, $this->area, $baseline['scope']);
            $samples[] = (hrtime(true) - $started) / 1000000;
            expect($frame['signature'])->toBe($baseline['signature'])
                ->and(array_keys($frame['canonical']))->toBe([$this->buyer->id])
                ->and($frame['labels'])->toBe([$this->buyer->id => 'Avery Buyer']);
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $sql = str_replace(['"', '`'], '', strtolower($query['query']));
            if (! str_starts_with(ltrim($sql), 'select')) {
                continue;
            }
            if (str_contains($sql, 'from marketing_profiles')) {
                expect((bool) preg_match('/\b(?:marketing_profiles\.)?id\s*(?:=|in\s*\()|\bmerged_into_profile_id\s+in\s*\(/', $sql))->toBeTrue();
            }
            if (str_contains($sql, 'from marketing_profile_links')) {
                $eventAnchor = str_contains($sql, 'source_type') && (bool) preg_match('/json_extract\(source_meta,\s*\'\$\.(?:event_attribution\.)?event_id\'\)\s+in\s*\(/', $sql);
                expect(str_contains($sql, 'marketing_profile_id in (') || str_contains($sql, 'source_type =') && str_contains($sql, 'source_id in (') || $eventAnchor)->toBeTrue($sql);
            }
        }
        sort($samples);
        $p95 = $samples[(int) ceil(count($samples) * .95) - 1];
        expect($p95)->toBeLessThan(250.0);
        $metrics[$stage] = ['profiles' => DB::table('marketing_profiles')->count(), 'links' => DB::table('marketing_profile_links')->count(),
            'samples' => count($samples), 'p95_ms' => round($p95, 3), 'queries_per_frame' => count($queries) / count($samples)];
    }
    $path = sys_get_temp_dir().'/event-customer-dependency-workload-'.getmypid().'.json';
    file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDERR, 'Event customer frame workload: '.json_encode($metrics, JSON_THROW_ON_ERROR).' artifact='.$path.PHP_EOL);
});
