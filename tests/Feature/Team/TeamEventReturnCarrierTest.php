<?php

use App\Models\MarketingImportRun;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Reporting\ReviewedSquareEventPurchases;
use App\Services\Team\Studio\EventSalesHistory;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function returnCarrierRecord($test, string $kind, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id, 'kind' => $kind,
        'title' => 'Fictional annual market', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02', 'data' => []], $extra));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-15T18:00:00Z'));
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->binding = ['connection_id' => 'fixture-original', 'connection_generation' => 1, 'runtime_id' => 'carrier-fixture', 'source_purpose' => 'discovery:carrier-fixture',
        'merchant_id' => 'fixture-merchant', 'environment' => 'production', 'location_ids' => ['retired-location', 'other-reviewed-location']];
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('assertCurrent')->with($this->tenant->id, $this->binding)->andReturnNull();
    app()->instance(SquareConnectionContext::class, $context);
    $this->series = returnCarrierRecord($this, 'series');
    $this->event = returnCarrierRecord($this, 'event');
    $this->money = fn (int $amount): array => ['amount' => $amount, 'currency' => 'USD'];
    $returned = ['total_money' => ($this->money)(648), 'tax_money' => ($this->money)(48), 'tip_money' => ($this->money)(0),
        'discount_money' => ($this->money)(0), 'service_charge_money' => ($this->money)(0), 'card_surcharge_money' => ($this->money)(0)];
    // Insert the carrier first so reconciliation cannot depend on row order.
    $this->carrier = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'return-carrier', 'location_id' => 'retired-location', 'state' => 'COMPLETED',
        'total_money_amount' => 0, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-01 16:05:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['id' => 'return-carrier', 'state' => 'COMPLETED', 'location_id' => 'retired-location', '_source_binding' => $this->binding,
            'total_money' => ($this->money)(0), 'total_tax_money' => ($this->money)(0), 'total_tip_money' => ($this->money)(0),
            'return_amounts' => $returned, 'net_amounts' => [...$returned, 'total_money' => ($this->money)(-648), 'tax_money' => ($this->money)(-48)],
            'returns' => [['source_order_id' => 'original-order', 'return_amounts' => $returned]],
            'refunds' => [['id' => 'legacy-refund', 'status' => 'APPROVED', 'amount_money' => ($this->money)(648)]]]]);
    $this->original = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'original-order', 'location_id' => 'retired-location', 'state' => 'COMPLETED',
        'total_money_amount' => 648, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-01 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['id' => 'original-order', 'state' => 'COMPLETED', 'location_id' => 'retired-location', '_source_binding' => $this->binding,
            'total_money' => ($this->money)(648), 'total_tax_money' => ($this->money)(48), 'total_tip_money' => ($this->money)(0),
            // Legacy orders retain the original pre-refund net amounts.
            'net_amounts' => ['total_money' => ($this->money)(648), 'tax_money' => ($this->money)(48), 'tip_money' => ($this->money)(0)]]]);
    $this->payment = SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'original-payment', 'square_order_id' => 'original-order', 'location_id' => 'retired-location',
        'amount_money' => 648, 'currency' => 'USD', 'status' => 'COMPLETED', 'source_type' => 'CARD', 'created_at_source' => '2025-11-01 16:00:00',
        'raw_payload' => ['id' => 'original-payment', 'order_id' => 'original-order', 'status' => 'COMPLETED', 'location_id' => 'retired-location', '_source_binding' => $this->binding,
            'amount_money' => ($this->money)(648), 'total_money' => ($this->money)(648), 'refunded_money' => ($this->money)(648), 'refund_ids' => ['original-payment_legacy-refund']]]);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'paid-order', 'location_id' => 'retired-location', 'state' => 'COMPLETED',
        'total_money_amount' => 1070, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-02 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['_source_binding' => $this->binding, 'total_tax_money' => ($this->money)(70)]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'paid-payment', 'square_order_id' => 'paid-order', 'location_id' => 'retired-location',
        'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED', 'raw_payload' => ['_source_binding' => $this->binding, 'refunded_money' => ($this->money)(0)]]);
    $this->allocation = ['ids' => ['return-carrier', 'original-order', 'paid-order'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String(), 'source_binding' => $this->binding];
    $this->readReturns = fn () => app(ReviewedSquareEventPurchases::class)->forOccurrence($this->tenant->id, $this->event, null, $this->allocation);
});

test('an exactly linked fully refunded return carrier never adds a sale or subtracts the refund twice', function (string $idForm): void {
    if ($idForm === 'direct') {
        $this->payment->update(['raw_payload' => [...$this->payment->raw_payload, 'refund_ids' => ['legacy-refund']]]);
    } elseif ($idForm === 'reordered_public_binding') {
        foreach ([$this->carrier, $this->original, $this->payment] as $record) {
            $binding = array_reverse($this->binding, true);
            $binding['location_ids'] = array_reverse($binding['location_ids']);
            $record->update(['raw_payload' => [...$record->raw_payload, '_source_binding' => $binding]]);
        }
    }
    $before = DB::table('square_orders')->where('tenant_id', $this->tenant->id)->get()->toArray();
    $report = ($this->readReturns)();
    expect($report['purchases'])->toHaveCount(2)
        ->and(array_column($report['purchases'], 'source_id'))->not->toContain('return-carrier')
        ->and(array_sum(array_column($report['purchases'], 'refunded_minor')))->toBe(648)
        ->and(array_sum(array_column($report['purchases'], 'net_sales_minor')))->toBe(1000)
        ->and($report['represented_return_order_ids'])->toBe(['return-carrier'])
        ->and($report['review_items'])->toBeEmpty();
    expect(DB::table('square_orders')->where('tenant_id', $this->tenant->id)->get()->toArray())->toEqual($before);
})->with(['direct', 'legacy_pair', 'reordered_public_binding']);

test('only a fully corroborated return carrier can stop blocking verified historical benchmarks', function (): void {
    $runs = [];
    foreach (['orders', 'payments'] as $kind) {
        $runs[$kind] = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_'.$kind.'_sync', 'status' => 'completed',
            'started_at' => '2026-01-05', 'finished_at' => '2026-01-05', 'summary' => ['source_binding' => $this->binding, 'mode' => 'live-sync', 'errors' => 0,
                'since' => '2020-01-01T00:00:00Z', 'initial_cursor' => null, 'checkpoint' => ['cursor' => null, 'errors' => 0]]]);
    }
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $this->series->id,
        'occurrence_id' => $this->event->id, 'financial_id' => null, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed original return and refund conservation.', 'venue' => 'Fictional hall',
        'timezone' => 'America/New_York', 'verified_selling_days' => 2, 'daily_hours' => [],
        'square_scopes' => [['location_id' => 'retired-location', 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02', 'orders_run_id' => $runs['orders']->id, 'payments_run_id' => $runs['payments']->id]]];
    $mapping = app(TeamsStudio::class)->reviewOccurrence($this->tenant, $this->owner, $this->area, $input);
    $current = returnCarrierRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    app(TeamsStudio::class)->reviewOccurrence($this->tenant, $this->owner, $this->area, [...$input, 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'occurrence_id' => $current->id, 'square_scopes' => []]);
    $history = app(EventSalesHistory::class);
    $report = $history->read($this->tenant, $this->owner, $current);
    expect($report['history'][0]['sale_count'])->toBe(2)->and($report['history'][0]['net_sales_minor'])->toBe(1000)
        ->and($report['history'][0]['complete'])->toBeTrue()->and($report['benchmarks']['occurrence_count'])->toBe(1)
        ->and($report['benchmarks']['average_event_net_sales_minor'])->toBe(1000)->and($report['benchmarks']['average_day_net_sales_minor'])->toBe(500);
    expect($history->scopePurchases($this->tenant, $this->event, TeamOperationRecord::findOrFail($mapping['id']))['purchases'])->toHaveCount(2);
    $this->carrier->update(['raw_payload' => [...$this->carrier->raw_payload, 'refunds' => []]]);
    $report = $history->read($this->tenant, $this->owner, $current);
    expect($report['history'][0]['complete'])->toBeFalse()->and($report['history'][0]['net_sales_minor'])->toBeNull()
        ->and($report['benchmarks']['occurrence_count'])->toBe(0)->and($report['benchmarks']['average_event_net_sales_minor'])->toBeNull();
});

test('uncertain return source money and refund identity remain unresolved', function (string $target, string $path, mixed $value): void {
    $model = $this->{$target};
    $raw = $model->raw_payload;
    data_set($raw, $path, $value);
    $model->update(['raw_payload' => $raw]);
    $report = ($this->readReturns)();
    expect($report['represented_return_order_ids'])->toBeEmpty()->and($report['review_items'])->not->toBeEmpty()
        ->and(array_column($report['purchases'], 'source_id'))->not->toContain('return-carrier');
})->with([
    'missing original link' => ['carrier', 'returns.0.source_order_id', 'missing-original'],
    'multiple return sources' => ['carrier', 'returns.1', ['source_order_id' => 'paid-order']],
    'foreign merchant' => ['carrier', '_source_binding.merchant_id', 'foreign-merchant'],
    'changed generation' => ['carrier', '_source_binding.connection_generation', 2],
    'changed carrier runtime' => ['carrier', '_source_binding.runtime_id', 'stale-runtime'],
    'changed original runtime' => ['original', '_source_binding.runtime_id', 'stale-runtime'],
    'changed payment runtime' => ['payment', '_source_binding.runtime_id', 'stale-runtime'],
    'changed carrier purpose' => ['carrier', '_source_binding.source_purpose', 'unreviewed-source'],
    'changed original purpose' => ['original', '_source_binding.source_purpose', 'unreviewed-source'],
    'changed payment purpose' => ['payment', '_source_binding.source_purpose', 'unreviewed-source'],
    'narrowed carrier locations' => ['carrier', '_source_binding.location_ids', ['retired-location']],
    'narrowed original locations' => ['original', '_source_binding.location_ids', ['retired-location']],
    'narrowed payment locations' => ['payment', '_source_binding.location_ids', ['retired-location']],
    'missing legacy runtime' => ['carrier', '_source_binding.runtime_id', null],
    'missing legacy purpose' => ['carrier', '_source_binding.source_purpose', null],
    'truncated carrier identity' => ['carrier', 'id', null],
    'new tender' => ['carrier', 'tenders', [['id' => 'additional-tender']]],
    'new merchandise' => ['carrier', 'line_items', [['quantity' => '1']]],
    'missing refund' => ['carrier', 'refunds', []],
    'duplicate refunds' => ['carrier', 'refunds.1', ['id' => 'second-refund']],
    'failed refund' => ['carrier', 'refunds.0.status', 'FAILED'],
    'foreign refund payment' => ['carrier', 'refunds.0.payment_id', 'foreign-payment'],
    'foreign refund location' => ['carrier', 'refunds.0.location_id', 'foreign-location'],
    'refund amount mismatch' => ['carrier', 'refunds.0.amount_money.amount', 647],
    'return total mismatch' => ['carrier', 'return_amounts.total_money.amount', 647],
    'return tax mismatch' => ['carrier', 'returns.0.return_amounts.tax_money.amount', 47],
    'foreign return currency' => ['carrier', 'return_amounts.total_money.currency', 'EUR'],
    'net return mismatch' => ['carrier', 'net_amounts.total_money.amount', -647],
    'net tax mismatch' => ['carrier', 'net_amounts.tax_money.amount', -47],
    'unknown return surcharge' => ['carrier', 'return_amounts.card_surcharge_money', null],
    'extra nested return charge' => ['carrier', 'returns.0.return_amounts.service_charge_money.amount', 1],
    'source tax unavailable' => ['original', 'total_tax_money', null],
    'source tip unsupported' => ['original', 'total_tip_money.amount', 1],
    'truncated original identity' => ['original', 'id', null],
    'source refund missing' => ['payment', 'refunded_money', null],
    'source partial refund' => ['payment', 'refunded_money.amount', 647],
    'unlinked refund id' => ['payment', 'refund_ids', ['unrelated-refund']],
    'foreign legacy prefix' => ['payment', 'refund_ids', ['foreign-payment_legacy-refund']],
    'ambiguous source refunds' => ['payment', 'refund_ids', ['legacy-refund', 'other-refund']],
]);

test('foreign unselected duplicate and charged carriers cannot be treated as represented', function (string $change): void {
    if ($change === 'foreign_tenant') {
        [$foreign] = teamWorkspace();
        $this->original->update(['tenant_id' => $foreign->id]);
    } elseif ($change === 'unselected_original') {
        $this->allocation['ids'] = ['return-carrier', 'paid-order'];
    } elseif ($change === 'foreign_location') {
        $this->carrier->update(['location_id' => 'foreign-location', 'raw_payload' => [...$this->carrier->raw_payload, 'location_id' => 'foreign-location']]);
    } elseif ($change === 'new_charge') {
        $this->carrier->update(['total_money_amount' => 1, 'raw_payload' => [...$this->carrier->raw_payload, 'total_money' => ($this->money)(1)]]);
    } elseif ($change === 'payment_record') {
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'carrier-failed-payment', 'square_order_id' => 'return-carrier', 'status' => 'FAILED']);
    } elseif (in_array($change, ['duplicate_carrier', 'duplicate_refund_alias'], true)) {
        $copy = $this->carrier->replicate();
        $copy->square_order_id = 'duplicate-carrier';
        $copy->raw_payload = [...$copy->raw_payload, 'id' => 'duplicate-carrier'];
        if ($change === 'duplicate_refund_alias') {
            $raw = $copy->raw_payload;
            $raw['refunds'][0]['id'] = 'original-payment_legacy-refund';
            $copy->raw_payload = $raw;
        }
        $copy->save();
        $this->allocation['ids'][] = 'duplicate-carrier';
    } elseif ($change === 'missing_binding') {
        unset($this->allocation['source_binding']);
    } else {
        $context = Mockery::mock(SquareConnectionContext::class);
        $context->shouldReceive('assertCurrent')->andThrow(new RuntimeException('Original source revoked.'));
        app()->instance(SquareConnectionContext::class, $context);
    }
    $report = ($this->readReturns)();
    expect($report['represented_return_order_ids'])->toBeEmpty()->and($report['review_items'])->not->toBeEmpty()
        ->and(array_column($report['purchases'], 'source_id'))->not->toContain('return-carrier');
})->with(['foreign_tenant', 'unselected_original', 'foreign_location', 'new_charge', 'payment_record', 'duplicate_carrier', 'duplicate_refund_alias', 'missing_binding', 'revoked_source']);
