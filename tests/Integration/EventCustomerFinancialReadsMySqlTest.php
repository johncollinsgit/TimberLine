<?php

use App\Models\Tenant;
use App\Services\Team\Studio\EventCustomerDependencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing source fixtures outside isolated disposable MySQL.');
    }
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => '']);
    $this->tenant = Tenant::create(['name' => 'Fictional source workload', 'slug' => 'source-workload-'.Str::lower(Str::random(16))]);
    $this->prefix = 'source-'.$this->tenant->id.'-';
    $this->captureFinancial = fn (array $keys) => (new ReflectionMethod(EventCustomerDependencies::class, 'financial'))
        ->invoke(app(EventCustomerDependencies::class), $this->tenant, $keys, []);
});

afterEach(function () {
    if (isset($this->tenant)) {
        DB::table('square_payments')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('square_orders')->where('tenant_id', $this->tenant->id)->delete();
        $this->tenant->delete();
    }
});

test('optimized payment membership preserves original collation null tender and typed source fingerprints', function () {
    foreach ([
        ['Mixed-Payment', 'Mixed-Order', 'COMPLETED'],
        ['second-tender', 'Mixed-Order', 'COMPLETED'],
        ['null-order-payment', null, 'PENDING'],
        ['unrelated-payment', 'unrelated-order', null],
    ] as [$payment, $order, $status]) {
        DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.$payment,
            'square_order_id' => $order === null ? null : $this->prefix.$order, 'status' => $status,
            'amount_money' => $status === 'COMPLETED' ? 550 : null, 'currency' => 'USD', 'raw_payload' => '{"retained":{"refund":null}}']);
    }
    $keys = ['square_order' => [$this->prefix.'mixed-order' => true],
        'square_payment' => [$this->prefix.'MIXED-PAYMENT' => true, $this->prefix.'NULL-ORDER-PAYMENT' => true]];
    $original = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where(fn ($query) => $query
        ->whereIn('square_order_id', array_keys($keys['square_order']))->orWhereIn('square_payment_id', array_keys($keys['square_payment'])));
    $sourceRows = new ReflectionMethod(EventCustomerDependencies::class, 'sourceRows');
    $service = app(EventCustomerDependencies::class);
    $expected = $sourceRows->invoke($service, 'square_payments', $original, ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'status', 'created_at_source']);
    $actual = ($this->captureFinancial)($keys);
    expect(json_encode($actual['revision']['payments']))->toBe(json_encode($expected->all()))
        ->and($actual['revision']['payments'])->toHaveCount(3)
        ->and($actual['order_tenders'][$this->prefix.'Mixed-Order'])->toHaveCount(2);
    // The schema hint is optional: an unverified primary shape uses an ordinary
    // bound join and retains the same source membership and typed fingerprints.
    $fallback = app(EventCustomerDependencies::class);
    (new ReflectionProperty($fallback, 'readIndexes'))->setValue($fallback, ['square_payments' => []]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $fallbackResult = (new ReflectionMethod($fallback, 'financial'))->invoke($fallback, $this->tenant, $keys, []);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect(json_encode($fallbackResult['revision']['payments']))->toBe(json_encode($expected->all()))
        ->and($queries->first(fn ($query) => str_contains($query['query'], 'dependency_payment_keys'))['query'])->not->toContain('STRAIGHT_JOIN');
    Http::assertNothingSent();
});

test('current and doubled original payment populations use bounded indexed source membership with complete financial bytes', function () {
    $measurements = [];
    $body = null;
    $costs = [];
    DB::listen(function ($query) use (&$body, &$costs) {
        $phase = str_contains($query->sql, 'dependency_payment_keys') ? 'body' : (str_contains($query->sql, 'HEX(`event_content_hash`)') ? 'hashes' : 'other');
        $costs[$phase] = ($costs[$phase] ?? 0) + $query->time;
        if (str_contains($query->sql, 'dependency_payment_keys') && str_starts_with(strtolower($query->sql), 'select')) {
            $body = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });
    foreach ([1, 2] as $scale) {
        $from = $scale === 1 ? 0 : 63000;
        for ($offset = $from; $offset < 63000 * $scale; $offset += 1000) {
            $rows = [];
            for ($i = $offset; $i < min($offset + 1000, 63000 * $scale); $i++) {
                $rows[] = ['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'payment-'.$i,
                    'square_order_id' => $this->prefix.'order-'.$i, 'square_customer_id' => $this->prefix.'customer-'.($i % 1442),
                    'status' => 'COMPLETED', 'amount_money' => 1100, 'currency' => 'USD', 'created_at_source' => '2026-11-01 12:00:00',
                    'raw_payload' => json_encode(['retained_original' => str_repeat('original ', 64), 'refunded_money' => ['amount' => 0, 'currency' => 'USD']])];
            }
            DB::table('square_payments')->insert($rows);
        }
        $orders = $payments = [];
        for ($i = 0; $i < 2347 * $scale; $i++) {
            $orders[$this->prefix.'order-'.$i] = true;
        }
        for ($i = 0; $i < 2385 * $scale; $i++) {
            $payments[$this->prefix.'payment-'.$i] = true;
        }
        $keys = ['square_order' => $orders, 'square_payment' => $payments];
        $samples = [];
        $body = null;
        for ($sample = 0; $sample < 5; $sample++) {
            $costs = [];
            $start = hrtime(true);
            $result = DB::transaction(fn () => ($this->captureFinancial)($keys));
            $samples[] = (hrtime(true) - $start) / 1e6;
            expect($result['revision']['payments'])->toHaveCount(2385 * $scale)
                ->and($result['order_tenders'][$this->prefix.'order-0'])->toBe([$this->prefix.'payment-0']);
        }
        expect($body)->not->toBeNull();
        $plan = DB::select('EXPLAIN '.$body['sql'], $body['bindings']);
        $branches = collect($plan)->filter(fn ($row) => $row->table === 'square_payments' && preg_match('/(?:^|;\s*)Using index(?:;|$)/', $row->Extra ?? ''));
        sort($samples);
        $p95 = $samples[(int) ceil(count($samples) * .95) - 1];
        fwrite(STDERR, 'Original financial source component: '.json_encode(['scale' => $scale, 'p95_ms' => round($p95, 3), 'last_sample_sql_ms' => $costs,
            'covering_branch_count' => $branches->count(), 'branch_access' => $branches->groupBy('type')->map->count()->all()], JSON_UNESCAPED_SLASHES).PHP_EOL);
        expect($branches)->toHaveCount((int) ceil(count($orders) / 128) + (int) ceil(count($payments) / 128))
            ->and($branches->pluck('key')->unique()->sort()->values()->all())->toBe(['epp_order_hash_idx', 'square_payments_tenant_payment_unique']);
        foreach ($branches as $branch) {
            expect($branch->type)->toBe('range')->and((int) $branch->key_len)->toBeGreaterThan(9);
        }
        $lookup = collect($plan)->first(fn ($row) => $row->select_type === 'PRIMARY' && $row->table === 'square_payments');
        expect($lookup->key)->toBe('PRIMARY')->and($lookup->type)->toBe('eq_ref');
        expect($p95)->toBeLessThan(250);
        $measurements[] = ['population' => 63000 * $scale, 'relevant_orders' => count($orders), 'relevant_payments' => count($payments),
            'returned_payments' => count($result['revision']['payments']), 'p95_milliseconds' => round($p95, 3),
            'covering_branch_count' => $branches->count(), 'outer_lookup' => ['type' => $lookup->type, 'key' => $lookup->key]];
    }
    fwrite(STDERR, 'Original financial source workload: '.json_encode($measurements, JSON_UNESCAPED_SLASHES).PHP_EOL);
    Http::assertNothingSent();
});
