<?php

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Reporting\ReviewedSquareEventPurchases;
use App\Services\Team\Studio\EventSquareAllocationFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
});

function allocationFactEvent($test, array $data = [], array $attributes = []): TeamOperationRecord
{
    return TeamOperationRecord::create([...['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id,
        'kind' => 'event', 'title' => 'Fictional allocation', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => $data], ...$attributes]);
}

function allocationFactData(array $ids, int $reviewer): array
{
    return ['_teams_enrichment' => ['square_orders' => ['ids' => $ids, 'reviewed_by' => $reviewer, 'reviewed_at' => '2025-01-01T12:00:00Z']]];
}

test('scoped allocation facts detect same-version edits insertions withdrawals deletion and other-area claims', function () {
    $own = allocationFactEvent($this);
    $otherArea = $this->area->replicate();
    $otherArea->id = (string) Str::uuid();
    $otherArea->slug = 'other-shows';
    $otherArea->save();
    $other = allocationFactEvent($this, allocationFactData(['first'], $this->owner->id), ['area_id' => $otherArea->id]);
    $facts = app(EventSquareAllocationFacts::class);
    expect($facts->conflictsFor($this->tenant->id, ['first', 'second'], $own->id))->toBe(['first']);
    expect($facts->occurrenceIdsFor($this->tenant->id, ['first', 'missing']))->toBe(['first' => [$other->id]]);
    $other->update(['data' => allocationFactData(['second'], $this->owner->id)]);
    expect($other->version)->toBe(1)->and($facts->conflictsFor($this->tenant->id, ['first', 'second'], $own->id))->toBe(['second']);
    $other->update(['status' => 'withdrawn']);
    expect($facts->conflictsFor($this->tenant->id, ['second'], $own->id))->toBeEmpty();
    $other->update(['status' => 'accepted']);
    expect($facts->conflictsFor($this->tenant->id, ['second'], $own->id))->toBe(['second']);
    $other->delete();
    expect($facts->conflictsFor($this->tenant->id, ['second'], $own->id))->toBeEmpty();
    allocationFactEvent($this, allocationFactData(['second'], $this->owner->id));
    expect($facts->conflictsFor($this->tenant->id, ['second'], $own->id))->toBe(['second']);
    [$foreign] = teamWorkspace();
    allocationFactEvent($this, allocationFactData(['foreign-only'], $this->owner->id), ['tenant_id' => $foreign->id]);
    expect($facts->conflictsFor($this->tenant->id, ['foreign-only'], $own->id))->toBeEmpty();
    app()->forgetInstance(EventSquareAllocationFacts::class);
    expect(app(EventSquareAllocationFacts::class))->not->toBe($facts);
});

test('effective scoped revisions ignore observation churn but retain allocation and reviewer changes', function () {
    $selected = allocationFactEvent($this, allocationFactData(['selected'], $this->owner->id));
    $facts = app(EventSquareAllocationFacts::class);
    $before = $facts->revisionFor($this->tenant->id, ['selected']);
    $selected->update(['data' => [...$selected->data, 'original_notes' => 'Unrelated revised notes', 'observed_at' => now()->toIso8601String()], 'version' => 2]);
    allocationFactEvent($this, allocationFactData(['unrelated'], $this->owner->id));
    expect($facts->revisionFor($this->tenant->id, ['selected']))->toBe($before);
    $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'member']);
    expect($facts->revisionFor($this->tenant->id, ['selected']))->not->toBe($before);
    $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'owner']);
    expect($facts->revisionFor($this->tenant->id, ['selected']))->toBe($before);
    $selected->update(['status' => 'withdrawn']);
    expect($facts->revisionFor($this->tenant->id, ['selected']))->not->toBe($before);
});

test('new competing commerce mappings and issuer selection stay in the effective source predicate', function () {
    $connection = TeamProviderConnection::create(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $this->owner->id,
        'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'settings' => ['source' => ['asana_project' => '200']]]);
    $record = allocationFactEvent($this, [...allocationFactData(['selected'], $this->owner->id), 'source_id' => '300', 'source_project' => '200', '_generation' => 1], ['connection_id' => $connection->id]);
    $facts = app(EventSquareAllocationFacts::class);
    $before = $facts->revisionFor($this->tenant->id, ['selected'], [100]);
    DB::table('commerce_stock_event_links')->insert(['tenant_id' => $this->tenant->id, 'event_id' => 999, 'provider' => 'asana', 'external_id' => '300', 'reviewed_by' => $this->owner->id,
        'metadata' => json_encode(['name' => 'Competing event', 'start_on' => '2025-11-01', 'end_on' => '2025-11-02', 'timezone' => 'UTC'])]);
    $mapped = $facts->revisionFor($this->tenant->id, ['selected'], [100]);
    expect($mapped)->not->toBe($before);
    $connection->update(['settings' => ['source' => ['asana_project' => '999']]]);
    expect($facts->revisionFor($this->tenant->id, ['selected'], [100]))->not->toBe($mapped);
    $record->update(['status' => 'withdrawn']);
    expect($facts->revisionFor($this->tenant->id, ['selected'], [100]))->not->toBe($before);
});

test('a missing changed row cannot cache old decoded facts under its new ciphertext revision', function () {
    $own = allocationFactEvent($this);
    $other = allocationFactEvent($this, allocationFactData(['old'], $this->owner->id));
    $facts = app(EventSquareAllocationFacts::class);
    expect($facts->conflictsFor($this->tenant->id, ['old'], $own->id))->toBe(['old']);
    $other->update(['data' => allocationFactData(['new'], $this->owner->id)]);
    $replacement = (array) DB::table('team_operation_records')->where('id', $other->id)->first();
    $phase = 0;
    DB::listen(function ($query) use (&$phase, $other, $replacement) {
        if (! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'team_operation_records')) {
            return;
        }
        $selectedIds = str_contains(strtolower($query->sql), ' in (');
        if ($phase === 0 && ! $selectedIds) {
            $phase = 1;
            DB::table('team_operation_records')->where('id', $other->id)->delete();
        } elseif ($phase === 1 && $selectedIds) {
            $phase = 2;
            DB::table('team_operation_records')->insert($replacement);
        }
    });
    expect($facts->conflictsFor($this->tenant->id, ['old', 'new'], $own->id))->toBe(['new'])->and($phase)->toBe(2);
});

test('a conflict allocation added during purchase normalization fails closed before returning purchases', function () {
    $event = allocationFactEvent($this);
    $competing = allocationFactEvent($this);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'selected-order', 'location_id' => 'location', 'state' => 'COMPLETED', 'closed_at' => '2025-11-01 12:00:00',
        'total_money_amount' => 1070, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 70, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'selected-payment', 'square_order_id' => 'selected-order', 'location_id' => 'location', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED',
        'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $changed = false;
    DB::listen(function ($query) use (&$changed, $competing) {
        if (! $changed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'square_payments')) {
            $changed = true;
            $competing->update(['data' => allocationFactData(['selected-order'], $this->owner->id)]);
        }
    });
    try {
        app(ReviewedSquareEventPurchases::class)->forOccurrence($this->tenant->id, $event, null, ['ids' => ['selected-order'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]);
        $this->fail('Changed allocation must discard the private purchase result.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($changed)->toBeTrue();
    }
});

test('changing an invalid explicit commerce selector to implicit Asana mapping changes its predicate', function () {
    $connection = TeamProviderConnection::create(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $this->owner->id,
        'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'settings' => []]);
    $record = allocationFactEvent($this, ['source_id' => '300', '_teams_enrichment' => ['commerce_event' => ['id' => 'invalid-explicit']]], ['connection_id' => $connection->id]);
    DB::table('commerce_stock_event_links')->insert(['tenant_id' => $this->tenant->id, 'event_id' => 100, 'provider' => 'asana', 'external_id' => '300', 'reviewed_by' => $this->owner->id, 'metadata' => '{}']);
    $facts = app(EventSquareAllocationFacts::class);
    $before = $facts->revisionFor($this->tenant->id, [], [100]);
    $record->update(['data' => ['source_id' => '300', '_teams_enrichment' => ['commerce_event' => ['id' => null]]]]);
    expect($facts->revisionFor($this->tenant->id, [], [100]))->not->toBe($before);
});

test('a withdrawal and restoration cannot pair a conflicting revision with a nonconflicting answer', function () {
    $event = allocationFactEvent($this);
    $competing = allocationFactEvent($this, allocationFactData(['selected-order'], $this->owner->id));
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'selected-order', 'location_id' => 'location', 'state' => 'COMPLETED', 'closed_at' => '2025-11-01 12:00:00',
        'total_money_amount' => 1070, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 70, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'selected-payment', 'square_order_id' => 'selected-order', 'location_id' => 'location', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED',
        'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    expect(app(EventSquareAllocationFacts::class)->conflictsFor($this->tenant->id, ['selected-order'], $event->id))->toBe(['selected-order']);
    $phase = 0;
    DB::listen(function ($query) use (&$phase, $competing) {
        if (! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }
        if ($phase === 0 && str_contains($query->sql, 'team_operation_records')) {
            $phase = 1;
            $competing->update(['data' => []]);
        } elseif ($phase === 1 && str_contains($query->sql, 'square_orders')) {
            $phase = 2;
            $competing->update(['data' => allocationFactData(['selected-order'], $this->owner->id)]);
        }
    });
    $result = app(ReviewedSquareEventPurchases::class)->forOccurrence($this->tenant->id, $event, null,
        ['ids' => ['selected-order'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]);
    expect($result['purchases'])->toBeEmpty()->and($result['review_items'])->not->toBeEmpty()->and($phase)->toBe(2);
});
