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
        ->and($queries->first(fn ($query) => str_contains($query['query'], 'dependency_payment_keys'))['query'])->toContain('inner join (')
        ->not->toContain(') AS `dependency_payment_keys` STRAIGHT_JOIN');
    Http::assertNothingSent();
});

test('guarded input keys preserve native IN outcomes without normalizing source identities', function (array $input, array $stored, bool $fast) {
    foreach ($stored as $i => $value) {
        DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'edge-'.$i,
            'square_order_id' => $value, 'status' => $i % 2 ? 'PENDING' : 'COMPLETED']);
    }
    $service = app(EventCustomerDependencies::class);
    $query = (new ReflectionMethod($service, 'paymentSources'))->invoke($service, $this->tenant, $input, []);
    expect(str_contains($query->toSql(), 'JSON_TABLE('))->toBe($fast);
    $reference = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', $input);
    $outcome = function ($read): array {
        try {
            return ['ids' => $read->orderBy('id')->pluck('id')->all()];
        } catch (\Illuminate\Database\QueryException $error) {
            return ['error' => get_class($error), 'state' => $error->errorInfo[0] ?? null];
        }
    };
    expect($outcome($query))->toBe($outcome($reference));
    Http::assertNothingSent();
})->with([
    'single lane case and pad duplicates' => [['mixed', 'MIXED', 'mixed '], ['MiXeD', 'mixed '], true],
    'ASCII key keeps accent insensitive source comparison' => [['e'], ['é', 'e'], true],
    'empty and escaped ASCII' => [['', "nul\0key", 'quote"key', 'back\\key'], ['', ' ', "nul\0key", 'quote"key', 'back\\key'], true],
    '255 ASCII characters' => [[str_repeat('x', 255)], [str_repeat('x', 255)], true],
    '256 characters cannot truncate into a source match' => [[str_repeat('x', 255).'z'], [str_repeat('x', 255)], false],
    'Unicode remains native' => [['é'], ['é', 'e'], false],
    '255 Unicode characters remain native' => [[str_repeat('é', 255)], [str_repeat('é', 255)], false],
    '256 Unicode characters remain native' => [[str_repeat('é', 256)], [str_repeat('é', 255)], false],
    'integer comparison remains native' => [[12], ['12', '012', '12tail'], false],
    'numeric string comparison stays a string' => [['12'], ['12', '012', '12tail'], true],
    'SQL NULL input stays native' => [[null], [null, 'null'], false],
    'invalid UTF8 is not JSON encoded' => [["\xff"], ['ordinary'], false],
    'sparse list keys do not become JSON object members' => [[4 => 'ordinary', 9 => 'other'], ['ordinary', 'other'], true],
]);

test('session encoding drift disables the input relation on the same reporting reader', function () {
    DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'session', 'square_order_id' => 'ordinary']);
    $service = app(EventCustomerDependencies::class);
    $method = new ReflectionMethod($service, 'paymentSources');
    expect($method->invoke($service, $this->tenant, ['ordinary'], [])->toSql())->toContain('JSON_TABLE(');
    try {
        DB::statement('SET NAMES latin1');
        $query = $method->invoke($service, $this->tenant, ['ordinary'], []);
        expect($query->toSql())->not->toContain('JSON_TABLE(')
            ->and($query->pluck('id')->all())->toBe(DB::table('square_payments')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', ['ordinary'])->pluck('id')->all());
    } finally {
        DB::statement('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
});

test('unverified source column or key metadata retains native membership predicates', function (string $drift) {
    DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'schema', 'square_order_id' => 'ordinary']);
    $service = app(EventCustomerDependencies::class);
    $method = new ReflectionMethod($service, 'paymentSources');
    expect($method->invoke($service, $this->tenant, ['ordinary'], [])->toSql())->toContain('JSON_TABLE(');
    $property = new ReflectionProperty($service, 'paymentKeySchema');
    $schema = $property->getValue($service);
    $definition = clone $schema['columns']['square_order_id'];
    $parts = array_map(fn ($part) => clone $part, $schema['indexes']['epp_order_hash_idx']);
    match ($drift) {
        'width' => $definition->type = 'varchar(254)',
        'type' => $definition->type = 'char(255)',
        'charset' => $definition->charset = 'latin1',
        'collation' => $definition->collation = 'utf8mb4_bin',
        'nullable' => $definition->nullable = 'NO',
        'generated' => $definition->expression = 'lower(square_payment_id)',
        'missing' => $parts = [],
        'prefix' => $parts[1]->sub_part = 16,
        'invisible' => $parts[0]->visible = 'NO',
        'wrong key order' => $parts = array_reverse($parts),
    };
    $schema['columns']['square_order_id'] = $definition;
    $schema['indexes']['epp_order_hash_idx'] = $parts;
    $property->setValue($service, $schema);
    $query = $method->invoke($service, $this->tenant, ['ordinary'], []);
    expect($query->toSql())->not->toContain('JSON_TABLE(')
        ->and($query->pluck('id')->all())->toBe(DB::table('square_payments')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', ['ordinary'])->pluck('id')->all());
})->with(['width', 'type', 'charset', 'collation', 'nullable', 'generated', 'missing', 'prefix', 'invisible', 'wrong key order']);

test('unsupported server families retain the native membership builder', function (string $version) {
    $service = app(EventCustomerDependencies::class);
    (new ReflectionProperty($service, 'readIndexes'))->setValue($service, ['square_payments' => \Illuminate\Support\Facades\Schema::getIndexes('square_payments')]);
    $db = DB::connection();
    $actual = $db->getPdo();
    $pdo = \Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($version);
    try {
        $db->setPdo($pdo);
        $query = (new ReflectionMethod($service, 'paymentSources'))->invoke($service, $this->tenant, ['ordinary'], []);
        expect($query->toSql())->not->toContain('JSON_TABLE(')->and($query->getBindings())->toContain('ordinary');
    } finally {
        $db->setPdo($actual);
    }
})->with(['8.0.36', '10.6.18-MariaDB', '9.0.1']);

test('actual invisible source index falls back without forcing it or changing typed financial fingerprints', function () {
    DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'visibility',
        'square_order_id' => 'ordinary', 'status' => 'COMPLETED', 'raw_payload' => '{"retained":{"refund":null}}']);
    $keys = ['square_order' => ['ordinary' => true]];
    $before = DB::transaction(fn () => ($this->captureFinancial)($keys));
    try {
        DB::statement('ALTER TABLE square_payments ALTER INDEX epp_order_hash_idx INVISIBLE');
        $service = app(EventCustomerDependencies::class);
        $query = (new ReflectionMethod($service, 'paymentSources'))->invoke($service, $this->tenant, ['ordinary'], []);
        expect($query->toSql())->not->toContain('JSON_TABLE(')->not->toContain('force index (`epp_order_hash_idx`)');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $after = DB::transaction(fn () => ($this->captureFinancial)($keys));
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $hash = $queries->first(fn ($query) => str_contains($query['query'], 'HEX(`event_content_hash`)'));
        expect(json_encode($after['revision']['payments']))->toBe(json_encode($before['revision']['payments']))
            ->and($hash)->not->toBeNull()->and(strtolower($hash['query']))->not->toContain('force index');
    } finally {
        DB::disableQueryLog();
        DB::statement('ALTER TABLE square_payments ALTER INDEX epp_order_hash_idx VISIBLE');
    }
});

test('single-lane collated input duplicates preserve the actual fifty thousand row boundary', function () {
    for ($offset = 0; $offset < 50000; $offset += 1000) {
        $rows = [];
        for ($i = $offset; $i < $offset + 1000; $i++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'boundary-'.$i,
                'square_order_id' => 'Boundary-Order', 'status' => 'COMPLETED'];
        }
        DB::table('square_payments')->insert($rows);
    }
    $keys = ['square_order' => ['boundary-order' => true, 'BOUNDARY-ORDER' => true, 'boundary-order ' => true]];
    $result = DB::transaction(fn () => ($this->captureFinancial)($keys));
    expect($result['revision']['payments'])->toHaveCount(50000)
        ->and($result['order_tenders']['Boundary-Order'])->toHaveCount(50000);
    unset($result);
    DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'boundary-50000',
        'square_order_id' => 'Boundary-Order', 'status' => 'COMPLETED']);
    try {
        DB::transaction(fn () => ($this->captureFinancial)($keys));
        $this->fail('The original global bound must reject 50,001 distinct source rows.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toBe('Customer dependency coverage needs review.');
    }
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
        expect($body['sql'])->toContain('JSON_TABLE(');
        expect($branches)->toHaveCount(2)
            ->and($branches->pluck('key')->unique()->sort()->values()->all())->toBe(['epp_order_hash_idx', 'square_payments_tenant_payment_unique']);
        foreach ($branches as $branch) {
            expect($branch->type)->toBeIn(['ref', 'eq_ref'])->and((int) $branch->key_len)->toBeGreaterThan(9);
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
