<?php

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\ReviewedSquareEventPurchases;
use App\Services\Team\Studio\EventSquareAllocationFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing allocation fixtures outside isolated disposable MySQL.');
    }
    Http::preventStrayRequests();
    $this->observe = false;
    $this->tenant = Tenant::create(['name' => 'Fictional allocation', 'slug' => 'allocation-'.Str::lower(Str::random(16))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Allocation events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $this->orderId = 'allocation-order-'.Str::uuid();
    $this->review = ['ids' => [$this->orderId], 'reviewed_by' => $this->owner->id, 'reviewed_at' => '2026-11-01T12:00:00Z'];
    $create = fn (array $data) => TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'title' => 'Fictional allocation', 'data' => $data]);
    $this->event = $create(['_teams_enrichment' => ['square_orders' => $this->review]]);
    $this->other = $create(['_teams_enrichment' => ['square_orders' => ['ids' => ['unrelated']]]]);
    config(['database.connections.event_allocation_writer' => $db->getConfig()]);
    $this->writer = DB::connection('event_allocation_writer');
    $this->facts = app(EventSquareAllocationFacts::class);
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    $this->observe = false;
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (DB::getPdo()->inTransaction()) {
        DB::getPdo()->rollBack();
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::purge('event_allocation_writer');
    foreach (['square_payments', 'square_orders', 'team_operation_records', 'team_areas', 'tenant_user'] as $table) {
        DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
    }
    $this->owner->delete();
    $this->tenant->delete();
});

function allocationCiphertext(TeamOperationRecord $record, array $data): string
{
    $copy = clone $record;
    $copy->data = $data;

    return $copy->getAttributes()['data'];
}

test('cold and changed allocation reloads remain coherent during committed observation churn without changing an RC session', function (bool $warm) {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $baseline = (new EventSquareAllocationFacts)->revisionFor($this->tenant->id, [$this->orderId]);
    if ($warm) {
        $this->facts->occurrencesFor($this->tenant->id, $this->orderId);
    }
    $this->writer->table('team_operation_records')->where('id', $this->other->id)->update([
        'data' => allocationCiphertext($this->other, [...$this->other->data, 'observed_at' => 'before-read']),
    ]);
    $writes = 0;
    $inside = 0;
    $this->observe = true;
    DB::listen(function ($query) use (&$writes, &$inside) {
        $sql = strtolower($query->sql);
        if (! $this->observe || $query->connectionName === 'event_allocation_writer' || ! str_contains($sql, 'as ciphertext_hash') || str_contains($sql, ' in (')) {
            return;
        }
        $inside += DB::transactionLevel() > 0 ? 1 : 0;
        $writes++;
        // Only ciphertext changes: no version/updated_at touch can accidentally
        // make this pass through a coarser scalar revision.
        $this->writer->table('team_operation_records')->where('id', $this->other->id)->update([
            'data' => allocationCiphertext($this->other, [...$this->other->data, 'observed_at' => 'during-read-'.$writes]),
        ]);
    });
    $record = $this->facts->occurrencesFor($this->tenant->id, $this->orderId)->sole();
    $this->observe = false;
    expect($record->id)->toBe($this->event->id)->and($record->data)->toBe($this->event->data)
        ->and($writes)->toBeGreaterThanOrEqual(3)->and($inside)->toBe(2)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('READ-COMMITTED')
        ->and($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->toBe($baseline);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $copy = $this->facts->occurrencesFor($this->tenant->id, $this->orderId)->sole();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($copy)->not->toBe($record)->and($queries)->toHaveCount(1)
        ->and(strtolower($queries[0]['query']))->toContain('sha2(`data`,256) as ciphertext_hash')->not->toContain('for update');
    Http::assertNothingSent();
})->with(['cold inventory' => false, 'changed inventory' => true]);

test('allocation reloads preserve caller-owned RC isolation and its conservative mixed-read failure', function (bool $rawPdo) {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    if ($rawPdo) {
        DB::getPdo()->beginTransaction();
    } else {
        DB::beginTransaction();
    }
    $this->observe = true;
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        $sql = strtolower($query->sql);
        if ($this->observe && $query->connectionName !== 'event_allocation_writer' && str_contains($sql, 'as ciphertext_hash') && ! str_contains($sql, ' in (')) {
            $this->writer->table('team_operation_records')->where('id', $this->other->id)->update([
                'data' => allocationCiphertext($this->other, [...$this->other->data, 'observation' => ++$writes]),
            ]);
        }
    });
    try {
        $this->facts->occurrencesFor($this->tenant->id, $this->orderId);
        $this->fail('A caller-owned RC transaction must not silently receive a different isolation level.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($writes)->toBe(3)
            ->and(DB::transactionLevel())->toBe($rawPdo ? 0 : 1)->and(DB::getPdo()->inTransaction())->toBeTrue()
            ->and(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('READ-COMMITTED');
    } finally {
        $this->observe = false;
        if ($rawPdo) {
            DB::getPdo()->rollBack();
        } else {
            DB::rollBack();
        }
    }
    expect($this->facts->occurrencesFor($this->tenant->id, $this->orderId)->sole()->id)->toBe($this->event->id);
})->with(['Laravel transaction' => false, 'raw PDO transaction' => true]);

test('coherent allocation snapshots retain fresh effective allocation reviewer and membership changes', function () {
    $original = $this->event->getRawOriginal('data');
    $baseline = $this->facts->revisionFor($this->tenant->id, [$this->orderId]);
    $this->writer->table('team_operation_records')->where('id', $this->event->id)->update([
        'data' => allocationCiphertext($this->event, ['_teams_enrichment' => ['square_orders' => [...$this->review, 'ids' => ['replacement']]]]),
    ]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->not->toBe($baseline)
        ->and($this->facts->occurrencesFor($this->tenant->id, $this->orderId))->toBeEmpty();
    $this->writer->table('team_operation_records')->where('id', $this->event->id)->update(['data' => $original]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->toBe($baseline);
    $this->writer->table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->not->toBe($baseline);
    $this->writer->table('users')->where('id', $this->owner->id)->update(['is_active' => true]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->toBe($baseline);
    $this->writer->table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['membership_active' => false]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->not->toBe($baseline);
    $this->writer->table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['membership_active' => true]);
    expect($this->facts->revisionFor($this->tenant->id, [$this->orderId]))->toBe($baseline);
    Http::assertNothingSent();
});

test('committed allocation or reviewer authority changes after the coherent view still fail the final purchase normalization fence', function (string $change) {
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $this->orderId, 'location_id' => 'fixture-location', 'state' => 'COMPLETED', 'closed_at' => '2026-11-01 12:00:00',
        'total_money_amount' => 1070, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 70, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$this->orderId, 'square_order_id' => $this->orderId, 'location_id' => 'fixture-location', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED',
        'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $changed = false;
    DB::listen(function ($query) use (&$changed, $change) {
        if (! $changed && $query->connectionName !== 'event_allocation_writer' && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'square_payments')) {
            expect(DB::transactionLevel())->toBe(0);
            $changed = true;
            if ($change === 'allocation') {
                $this->writer->table('team_operation_records')->where('id', $this->other->id)->update([
                    'data' => allocationCiphertext($this->other, ['_teams_enrichment' => ['square_orders' => $this->review]]),
                ]);
            } elseif ($change === 'reviewer') {
                $this->writer->table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
            } else {
                $this->writer->table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['membership_active' => false]);
            }
        }
    });
    try {
        app(ReviewedSquareEventPurchases::class)->forOccurrence($this->tenant->id, $this->event, null, $this->review);
        $this->fail('Allocation or authority changes committed after the short read must discard the purchase result.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($changed)->toBeTrue()->and(DB::transactionLevel())->toBe(0);
    }
    Http::assertNothingSent();
})->with(['allocation', 'reviewer', 'membership']);
