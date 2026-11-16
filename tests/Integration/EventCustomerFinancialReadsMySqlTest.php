<?php

use App\Models\Tenant;
use App\Services\Team\Studio\EventCustomerDependencies;
use Illuminate\Database\Query\Builder;
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
    if (isset($this->foreignTenant)) {
        DB::table('square_payments')->where('tenant_id', $this->foreignTenant->id)->delete();
        DB::table('square_orders')->where('tenant_id', $this->foreignTenant->id)->delete();
        $this->foreignTenant->delete();
    }
});

/** Independent released projection: do not derive these fields or bytes from sourceRows(). */
function frozenFinancialPaymentRevisions(Builder $original): array
{
    // Field order and PDO scalar types are part of the existing PHP JSON hash.
    $rows = (clone $original)->orderBy('id')->get(['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'status',
        'created_at_source', 'tenant_id', 'source_type', 'created_at']);
    // Recompute the immutable 030000 expression from the actual original row,
    // independently of both the generated column and its optimized lookup.
    $hashes = $rows->isEmpty() ? collect() : DB::table('square_payments')->whereIn('id', $rows->pluck('id'))->select('id')
        ->selectRaw('HEX(UNHEX(SHA2(JSON_ARRAY(`square_payment_id`,`square_order_id`,`square_customer_id`,`location_id`,`amount_money`,`currency`,`status`,SHA2(CAST(`raw_payload` AS CHAR CHARACTER SET utf8mb4),256)),256))) AS frozen_revision')
        ->get()->keyBy('id');

    return $rows->map(fn ($row) => (object) ['id' => $row->id, 'square_payment_id' => $row->square_payment_id,
        'square_order_id' => $row->square_order_id, 'square_customer_id' => $row->square_customer_id, 'status' => $row->status,
        'created_at_source' => $row->created_at_source,
        'source_revision' => hash('sha256', json_encode([(array) $row, $hashes[$row->id]->frozen_revision], JSON_THROW_ON_ERROR))])->all();
}

/** Independent original order projection and immutable 030000 content expression. */
function frozenFinancialOrderRevisions(Builder $original): array
{
    $rows = (clone $original)->orderBy('id')->get(['id', 'square_order_id', 'square_customer_id', 'closed_at',
        'tenant_id', 'source_name', 'raw_tax_names', 'created_at']);
    $hashes = $rows->isEmpty() ? collect() : DB::table('square_orders')->whereIn('id', $rows->pluck('id'))->select('id')
        ->selectRaw('HEX(UNHEX(SHA2(JSON_ARRAY(`square_order_id`,`square_customer_id`,`location_id`,`state`,`total_money_amount`,`total_money_currency`,SHA2(CAST(`raw_payload` AS CHAR CHARACTER SET utf8mb4),256)),256))) AS frozen_revision')
        ->get()->keyBy('id');

    return $rows->map(fn ($row) => (object) ['id' => $row->id, 'square_order_id' => $row->square_order_id,
        'square_customer_id' => $row->square_customer_id, 'closed_at' => $row->closed_at,
        'source_revision' => hash('sha256', json_encode([(array) $row, $hashes[$row->id]->frozen_revision], JSON_THROW_ON_ERROR))])->all();
}

test('optimized payment membership preserves original collation null tender and typed source fingerprints', function () {
    foreach ([
        ['Mixed-Payment', 'Mixed-Order', 'COMPLETED'],
        ['second-tender', 'Mixed-Order', 'COMPLETED'],
        ['null-order-payment', null, 'PENDING'],
        ['unicode-order-payment', 'Ordér', 'COMPLETED'],
        ['padded-order-payment', 'Padded-Order  ', 'COMPLETED'],
        ['unrelated-payment', 'unrelated-order', null],
    ] as $i => [$payment, $order, $status]) {
        DB::table('square_payments')->insert(['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.$payment,
            'square_order_id' => $order === null ? null : $this->prefix.$order, 'status' => $status,
            'square_customer_id' => $i % 2 ? null : $this->prefix.'customer-'.$i, 'location_id' => $this->prefix.'location-'.$i,
            'amount_money' => $status === 'COMPLETED' ? 550 + $i : null, 'currency' => 'USD', 'source_type' => $i % 2 ? null : 'CARD',
            'created_at_source' => '2026-11-01 12:00:0'.$i, 'created_at' => '2026-11-02 12:00:0'.$i,
            'raw_payload' => [null, 'null', '{"retained":{"refund":null}}', '{"value":1}', '{"value":"1"}', '{"value":1.0}'][$i]]);
    }
    $this->foreignTenant = Tenant::create(['name' => 'Fictional unrelated source owner', 'slug' => 'source-foreign-'.Str::lower(Str::random(16))]);
    DB::table('square_payments')->insert(['tenant_id' => $this->foreignTenant->id, 'square_payment_id' => $this->prefix.'Mixed-Payment',
        'square_order_id' => $this->prefix.'Mixed-Order', 'status' => 'COMPLETED', 'amount_money' => 999999, 'raw_payload' => '{"foreign":true}']);
    $keys = ['square_order' => [$this->prefix.'mixed-order' => true, $this->prefix.'order' => true, $this->prefix.'padded-order' => true],
        'square_payment' => [$this->prefix.'MIXED-PAYMENT' => true, $this->prefix.'NULL-ORDER-PAYMENT ' => true]];
    $original = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where(fn ($query) => $query
        ->whereIn('square_order_id', array_keys($keys['square_order']))->orWhereIn('square_payment_id', array_keys($keys['square_payment'])));
    $expected = frozenFinancialPaymentRevisions($original);
    $actual = ($this->captureFinancial)($keys);
    expect(json_encode($actual['revision']['payments']))->toBe(json_encode($expected))
        ->and($actual['revision']['payments'])->toHaveCount(5)
        ->and($actual['revision']['payments'][0]->id)->toBeInt()
        ->and($actual['revision']['payments'][0]->created_at_source)->toBeString()
        ->and($actual['order_tenders'][$this->prefix.'Mixed-Order'])->toHaveCount(2);
    // The covered order prefix can encounter other tenders, but only the
    // already selected physical IDs belong in this source revision.
    foreach ([['square_payment' => [$this->prefix.'MIXED-PAYMENT' => true]],
        ['square_order' => [$this->prefix.'ordér' => true]],
        ['square_payment' => [$this->prefix.'NULL-ORDER-PAYMENT' => true]]] as $subset) {
        $reference = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where(fn ($query) => $query
            ->whereIn('square_order_id', array_keys($subset['square_order'] ?? []))->orWhereIn('square_payment_id', array_keys($subset['square_payment'] ?? [])));
        $selected = ($this->captureFinancial)($subset);
        expect(json_encode($selected['revision']['payments']))->toBe(json_encode(frozenFinancialPaymentRevisions($reference)))
            ->and($selected['revision']['payments'])->toHaveCount(1);
    }
    // The schema hint is optional: an unverified primary shape uses an ordinary
    // bound join and retains the same source membership and typed fingerprints.
    $fallback = app(EventCustomerDependencies::class);
    (new ReflectionProperty($fallback, 'readIndexes'))->setValue($fallback, ['square_payments' => []]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $fallbackResult = (new ReflectionMethod($fallback, 'financial'))->invoke($fallback, $this->tenant, $keys, []);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect(json_encode($fallbackResult['revision']['payments']))->toBe(json_encode($expected))
        ->and($queries->first(fn ($query) => str_contains($query['query'], 'dependency_payment_keys'))['query'])->toContain('inner join (')
        ->not->toContain(') AS `dependency_payment_keys` STRAIGHT_JOIN');
    Http::assertNothingSent();
});

test('the frozen original fingerprint retains every payment content and scalar mutation while excluding observations', function () {
    $original = ['tenant_id' => $this->tenant->id, 'square_payment_id' => $this->prefix.'mutation-payment',
        'square_order_id' => $this->prefix.'mutation-order', 'square_customer_id' => null, 'location_id' => 'original-location',
        'status' => 'COMPLETED', 'amount_money' => 1100, 'currency' => 'USD', 'source_type' => 'CARD',
        'created_at_source' => '2026-11-01 12:00:00', 'created_at' => '2026-11-02 12:00:00',
        'raw_payload' => '{"original":true}', 'updated_at' => '2026-11-02 12:00:00', 'synced_at' => '2026-11-02 12:00:00'];
    $id = DB::table('square_payments')->insertGetId($original);
    $query = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where('id', $id);
    $keys = ['square_payment' => [$original['square_payment_id'] => true]];
    $baseline = ($this->captureFinancial)($keys)['revision']['payments'];
    expect(json_encode($baseline))->toBe(json_encode(frozenFinancialPaymentRevisions($query)));
    $typed = [];
    foreach ([
        ['square_order_id', null], ['square_customer_id', 'original-customer'], ['location_id', 'different-location'],
        ['amount_money', 1101], ['currency', 'CAD'], ['status', 'PENDING'], ['source_type', 'CASH'],
        ['created_at_source', '2026-11-01 12:00:01'], ['created_at', '2026-11-02 12:00:01'],
        ['raw_payload', null], ['raw_payload', 'null'], ['raw_payload', '{"value":1}'],
        ['raw_payload', '{"value":"1"}'], ['raw_payload', '{"value":1.0}'],
    ] as [$column, $value]) {
        (clone $query)->update([$column => $value]);
        $changed = ($this->captureFinancial)($keys)['revision']['payments'];
        expect(json_encode($changed))->toBe(json_encode(frozenFinancialPaymentRevisions($query)))
            ->and($changed[0]->source_revision)->not->toBe($baseline[0]->source_revision);
        if ($column === 'raw_payload') {
            $typed[] = $changed[0]->source_revision;
        }
        (clone $query)->update([$column => $original[$column]]);
        expect(($this->captureFinancial)($keys)['revision']['payments'][0]->source_revision)->toBe($baseline[0]->source_revision);
    }
    expect(array_unique($typed))->toHaveCount(5);
    (clone $query)->update(['updated_at' => '2026-11-03 12:00:00', 'synced_at' => '2026-11-03 12:00:00']);
    expect(json_encode(($this->captureFinancial)($keys)['revision']['payments']))->toBe(json_encode($baseline));
    Http::assertNothingSent();
});

test('guarded original order inputs preserve the native IN identity and coercion outcomes', function (array $input, array $stored, bool $fast) {
    foreach ($stored as $value) {
        DB::table('square_orders')->insert(['tenant_id' => $this->tenant->id, 'square_order_id' => $value]);
    }
    $service = app(EventCustomerDependencies::class);
    $query = (new ReflectionMethod($service, 'orderSources'))->invoke($service, $this->tenant, $input);
    expect(str_contains($query->toSql(), 'JSON_TABLE('))->toBe($fast);
    $reference = DB::table('square_orders')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', $input);
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
    'collated duplicates identify one physical row' => [['mixed', 'MIXED', 'mixed '], ['MiXeD'], true],
    'ASCII input retains accent insensitive source comparison' => [['e'], ['é'], true],
    'empty and escaped ASCII' => [['', "nul\0key", 'quote"key', 'back\\key'], ['', "nul\0key", 'quote"key', 'back\\key'], true],
    '255 ASCII characters' => [[str_repeat('x', 255)], [str_repeat('x', 255)], true],
    '256 characters cannot truncate into a source match' => [[str_repeat('x', 255).'z'], [str_repeat('x', 255)], false],
    'Unicode remains native' => [['é'], ['é'], false],
    '255 Unicode characters remain native' => [[str_repeat('é', 255)], [str_repeat('é', 255)], false],
    '256 Unicode characters remain native' => [[str_repeat('é', 256)], [str_repeat('é', 255)], false],
    'integer comparison remains native' => [[12], ['12', '012', '12tail'], false],
    'numeric string comparison stays a string' => [['12'], ['12', '012', '12tail'], true],
    'SQL NULL input stays native' => [[null], ['null'], false],
    'invalid UTF8 is not JSON encoded' => [["\xff"], ['ordinary'], false],
    'sparse list keys do not become JSON members' => [[4 => 'ordinary', 9 => 'other'], ['ordinary', 'other'], true],
]);

test('original order schema and index drift retain the exact native membership builder', function (string $drift) {
    DB::table('square_orders')->insert(['tenant_id' => $this->tenant->id, 'square_order_id' => 'ordinary']);
    $service = app(EventCustomerDependencies::class);
    $method = new ReflectionMethod($service, 'orderSources');
    expect($method->invoke($service, $this->tenant, ['ordinary'])->toSql())->toContain('JSON_TABLE(');
    $property = new ReflectionProperty($service, 'orderKeySchema');
    $schema = $property->getValue($service);
    $definition = clone $schema['columns']['square_order_id'];
    $parts = array_map(fn ($part) => clone $part, $schema['indexes']['epo_order_hash_idx']);
    match ($drift) {
        'width' => $definition->type = 'varchar(254)',
        'type' => $definition->type = 'char(255)',
        'charset' => $definition->charset = 'latin1',
        'collation' => $definition->collation = 'utf8mb4_bin',
        'nullable' => $definition->nullable = 'YES',
        'generated' => $definition->expression = 'lower(square_order_id)',
        'missing' => $parts = [],
        'prefix' => $parts[1]->sub_part = 16,
        'invisible' => $parts[0]->visible = 'NO',
        'unique' => $parts[0]->non_unique = 0,
        'index type' => $parts[0]->type = 'HASH',
        'descending' => $parts[0]->collation = 'D',
        'functional' => $parts[0]->expression = 'lower(square_order_id)',
        'wrong column' => $parts[1]->column_name = 'square_customer_id',
        'missing part' => array_pop($parts),
        'wrong key order' => $parts = array_reverse($parts),
    };
    $schema['columns']['square_order_id'] = $definition;
    $schema['indexes']['epo_order_hash_idx'] = $parts;
    $property->setValue($service, $schema);
    $query = $method->invoke($service, $this->tenant, ['ordinary']);
    expect($query->toSql())->not->toContain('JSON_TABLE(')
        ->and($query->pluck('id')->all())->toBe(DB::table('square_orders')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', ['ordinary'])->pluck('id')->all());
})->with(['width', 'type', 'charset', 'collation', 'nullable', 'generated', 'missing', 'prefix', 'invisible',
    'unique', 'index type', 'descending', 'functional', 'wrong column', 'missing part', 'wrong key order']);

test('original order point reads require the current session encoding and verified primary shape', function (string $statement) {
    DB::table('square_orders')->insert(['tenant_id' => $this->tenant->id, 'square_order_id' => 'ordinary']);
    $service = app(EventCustomerDependencies::class);
    $method = new ReflectionMethod($service, 'orderSources');
    expect($method->invoke($service, $this->tenant, ['ordinary'])->toSql())->toContain('JSON_TABLE(');
    try {
        DB::statement($statement);
        $query = $method->invoke($service, $this->tenant, ['ordinary']);
        expect($query->toSql())->not->toContain('JSON_TABLE(')
            ->and($query->pluck('id')->all())->toBe(DB::table('square_orders')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', ['ordinary'])->pluck('id')->all());
    } finally {
        DB::statement('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $service = new EventCustomerDependencies;
    (new ReflectionProperty($service, 'readIndexes'))->setValue($service, ['square_orders' => []]);
    $fallback = $method->invoke($service, $this->tenant, ['ordinary']);
    expect($fallback->toSql())->toContain('inner join (')->not->toContain('FORCE INDEX (`PRIMARY`)')
        ->and($fallback->pluck('id')->all())->toBe(DB::table('square_orders')->where('tenant_id', $this->tenant->id)->whereIn('square_order_id', ['ordinary'])->pluck('id')->all());
})->with(['client encoding' => ['SET character_set_client = latin1'], 'connection encoding' => ['SET character_set_connection = latin1']]);

test('an actual invisible order source index falls back without changing the frozen typed fingerprint', function () {
    DB::table('square_orders')->insert(['tenant_id' => $this->tenant->id, 'square_order_id' => 'ordinary',
        'state' => 'COMPLETED', 'total_money_amount' => 1100, 'total_money_currency' => 'USD', 'raw_payload' => '{"retained":{"tax":null}}']);
    $keys = ['square_order' => ['ordinary' => true]];
    $reference = DB::table('square_orders')->where('tenant_id', $this->tenant->id)->where('square_order_id', 'ordinary');
    $expected = frozenFinancialOrderRevisions($reference);
    try {
        DB::statement('ALTER TABLE square_orders ALTER INDEX epo_order_hash_idx INVISIBLE');
        $service = new EventCustomerDependencies;
        $query = (new ReflectionMethod($service, 'orderSources'))->invoke($service, $this->tenant, ['ordinary']);
        expect($query->toSql())->not->toContain('JSON_TABLE(')->not->toContain('force index (`epo_order_hash_idx`)');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $after = DB::transaction(fn () => (new ReflectionMethod($service, 'financial'))->invoke($service, $this->tenant, $keys, []));
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $hash = $queries->first(fn ($query) => str_contains($query['query'], 'HEX(`event_content_hash`)'));
        expect(json_encode($after['revision']['orders']))->toBe(json_encode($expected))
            ->and($hash)->not->toBeNull()->and(strtolower($hash['query']))->not->toContain('force index');
    } finally {
        DB::disableQueryLog();
        DB::statement('ALTER TABLE square_orders ALTER INDEX epo_order_hash_idx VISIBLE');
    }
});

test('optimized order membership retains frozen physical hashes and every semantic source byte', function () {
    $original = ['tenant_id' => $this->tenant->id, 'square_order_id' => $this->prefix.'MiXeD-Order  ', 'square_customer_id' => null,
        'location_id' => 'original-location', 'state' => 'COMPLETED', 'total_money_amount' => 1100, 'total_money_currency' => 'USD',
        'closed_at' => '2026-11-01 12:00:00', 'source_name' => 'original source', 'raw_tax_names' => '["Original tax"]',
        'created_at' => '2026-11-02 12:00:00', 'raw_payload' => '{"original":true}',
        'updated_at' => '2026-11-02 12:00:00', 'synced_at' => '2026-11-02 12:00:00'];
    $id = DB::table('square_orders')->insertGetId($original);
    DB::table('square_orders')->insert([...$original, 'square_order_id' => $this->prefix.'unselected-order', 'raw_payload' => '{"unselected":true}']);
    $this->foreignTenant = Tenant::create(['name' => 'Fictional unrelated order owner', 'slug' => 'order-foreign-'.Str::lower(Str::random(16))]);
    DB::table('square_orders')->insert([...$original, 'tenant_id' => $this->foreignTenant->id, 'total_money_amount' => 999999]);
    $query = DB::table('square_orders')->where('tenant_id', $this->tenant->id)->where('id', $id);
    $keys = ['square_order' => [$this->prefix.'mixed-order' => true, $this->prefix.'MIXED-ORDER ' => true]];
    $baseline = ($this->captureFinancial)($keys)['revision']['orders'];
    expect(json_encode($baseline))->toBe(json_encode(frozenFinancialOrderRevisions($query)))
        ->and($baseline)->toHaveCount(1)->and($baseline[0]->id)->toBeInt()->and($baseline[0]->closed_at)->toBeString();
    $typed = [];
    foreach ([['square_customer_id', 'original-customer'], ['location_id', 'different-location'], ['state', 'OPEN'],
        ['total_money_amount', 1101], ['total_money_currency', 'CAD'], ['closed_at', '2026-11-01 12:00:01'],
        ['source_name', 'different source'], ['raw_tax_names', '["Different tax"]'], ['created_at', '2026-11-02 12:00:01'],
        ['raw_payload', null], ['raw_payload', 'null'], ['raw_payload', '{"value":1}'], ['raw_payload', '{"value":"1"}'], ['raw_payload', '{"value":1.0}']] as [$column, $value]) {
        (clone $query)->update([$column => $value]);
        $changed = ($this->captureFinancial)($keys)['revision']['orders'];
        expect(json_encode($changed))->toBe(json_encode(frozenFinancialOrderRevisions($query)))
            ->and($changed[0]->source_revision)->not->toBe($baseline[0]->source_revision);
        if ($column === 'raw_payload') {
            $typed[] = $changed[0]->source_revision;
        }
        (clone $query)->update([$column => $original[$column]]);
        expect(($this->captureFinancial)($keys)['revision']['orders'][0]->source_revision)->toBe($baseline[0]->source_revision);
    }
    expect(array_unique($typed))->toHaveCount(5);
    (clone $query)->update(['updated_at' => '2026-11-03 12:00:00', 'synced_at' => '2026-11-03 12:00:00']);
    expect(json_encode(($this->captureFinancial)($keys)['revision']['orders']))->toBe(json_encode($baseline));
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
        $orders = (new ReflectionMethod($service, 'orderSources'))->invoke($service, $this->tenant, ['ordinary']);
        expect($orders->toSql())->not->toContain('JSON_TABLE(')->and($orders->getBindings())->toContain('ordinary');
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

test('collated order input duplicates retain the global fifty thousand physical row boundary', function () {
    $keys = [];
    for ($offset = 0; $offset < 50000; $offset += 1000) {
        $rows = [];
        for ($i = $offset; $i < $offset + 1000; $i++) {
            $source = $this->prefix.'order-boundary-'.$i;
            $rows[] = ['tenant_id' => $this->tenant->id, 'square_order_id' => $source];
            $keys[$source] = true;
        }
        DB::table('square_orders')->insert($rows);
    }
    $keys[strtoupper($this->prefix.'order-boundary-0')] = true;
    $keys[$this->prefix.'order-boundary-0 '] = true;
    $result = DB::transaction(fn () => ($this->captureFinancial)(['square_order' => $keys]));
    expect($result['revision']['orders'])->toHaveCount(50000)
        ->and(array_unique(array_column($result['revision']['orders'], 'id')))->toHaveCount(50000);
    unset($result);
    $source = $this->prefix.'order-boundary-50000';
    DB::table('square_orders')->insert(['tenant_id' => $this->tenant->id, 'square_order_id' => $source]);
    $keys[$source] = true;
    try {
        DB::transaction(fn () => ($this->captureFinancial)(['square_order' => $keys]));
        $this->fail('The original global bound must reject 50,001 distinct source orders.');
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
            $samples[] = ['sample' => $sample + 1, 'elapsed_ms' => (hrtime(true) - $start) / 1e6, 'sql_ms' => $costs];
            expect($result['revision']['payments'])->toHaveCount(2385 * $scale)
                ->and($result['order_tenders'][$this->prefix.'order-0'])->toBe([$this->prefix.'payment-0']);
        }
        expect($body)->not->toBeNull();
        $plan = DB::select('EXPLAIN '.$body['sql'], $body['bindings']);
        $branches = collect($plan)->filter(fn ($row) => $row->table === 'square_payments' && preg_match('/(?:^|;\s*)Using index(?:;|$)/', $row->Extra ?? ''));
        $rankedSamples = $samples;
        usort($rankedSamples, fn ($left, $right) => $left['elapsed_ms'] <=> $right['elapsed_ms']);
        $p95Sample = $rankedSamples[(int) ceil(count($rankedSamples) * .95) - 1];
        $p95 = $p95Sample['elapsed_ms'];
        fwrite(STDERR, 'Original financial source component: '.json_encode(['scale' => $scale, 'p95_ms' => round($p95, 3),
            'p95_sample' => $p95Sample['sample'], 'p95_sample_sql_ms' => $p95Sample['sql_ms'],
            'samples' => array_map(fn ($sample) => [...$sample, 'elapsed_ms' => round($sample['elapsed_ms'], 3)], $samples),
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
