<?php

use App\Models\Tenant;
use App\Services\Team\Studio\EventCustomerAnchorSchema;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

function customerAnchorMigration(): \Illuminate\Database\Migrations\Migration
{
    return require database_path('migrations/2026_11_16_170000_add_event_customer_anchor_presence_indexes.php');
}

function customerAnchorDefinitions(): array
{
    return [
        '$.event_id' => ['column' => 'event_capture_present', 'index' => 'mpl_event_capture_idx', 'json' => 'event_id', 'type' => 'pos_event_capture'],
        '$.event_attribution.event_id' => ['column' => 'event_attribution_present', 'index' => 'mpl_event_attribution_idx', 'json' => 'event_attribution->event_id', 'type' => 'square_order'],
    ];
}

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing customer anchor recovery outside isolated disposable MySQL.');
    }
    customerAnchorMigration()->up();
    $this->tenant = Tenant::create(['name' => 'Fictional anchors', 'slug' => 'anchor-recovery-'.Str::lower(Str::random(14))]);
    $this->profile = DB::table('marketing_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional visitor']);
    $this->row = DB::table('marketing_profile_links')->insertGetId(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile,
        'source_type' => 'pos_event_capture', 'source_id' => 'original-source', 'source_meta' => '{"event_id":100}', 'match_method' => 'reviewed', 'confidence' => '1.00']);
    $this->schema = new EventCustomerAnchorSchema;
});

afterEach(function () {
    if (isset($this->tenant)) {
        DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('marketing_profiles')->where('tenant_id', $this->tenant->id)->delete();
        $this->tenant->delete();
    }
});

test('customer anchor migration resumes committed column-only and partial index states without changing source rows', function () {
    $before = (array) DB::table('marketing_profile_links')->find($this->row);
    foreach (customerAnchorDefinitions() as $definition) {
        DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `'.$definition['index'].'`, ALGORITHM=INPLACE, LOCK=NONE');
    }
    DB::statement('ALTER TABLE `marketing_profile_links` DROP COLUMN `event_attribution_present`, ALGORITHM=INPLACE, LOCK=NONE');
    $migration = customerAnchorMigration();
    $migration->up();
    DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `mpl_event_attribution_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    $migration->up();
    $migration->up();
    $migration->down();
    expect((array) DB::table('marketing_profile_links')->find($this->row))->toBe($before)
        ->not->toHaveKeys(['event_capture_present', 'event_attribution_present'])
        ->and($this->schema->redundantColumns(DB::connection()))->toBe(['event_capture_present', 'event_attribution_present']);
    foreach (customerAnchorDefinitions() as $definition) {
        $index = collect(Schema::getIndexes('marketing_profile_links'))->firstWhere('name', $definition['index']);
        expect($index['columns'])->toBe(['tenant_id', 'source_type', $definition['column'], 'id']);
    }
});

test('generated anchor presence narrows candidates without changing original JSON null typed or coercion comparisons', function () {
    DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->delete();
    $values = [null, 100, '100', '0100', '100tail', 100.0, true, false, [], new stdClass, '100.0', '1e2'];
    foreach (customerAnchorDefinitions() as $path => $definition) {
        foreach ($values as $index => $value) {
            $meta = $path === '$.event_id' ? ['event_id' => $value] : ['event_attribution' => ['event_id' => $value]];
            DB::table('marketing_profile_links')->insert(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile,
                'source_type' => $definition['type'], 'source_id' => $definition['column'].'-'.$index, 'source_meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
        }
        foreach (['sql-null' => null, 'json-null' => 'null', 'missing' => '{}'] as $name => $meta) {
            DB::table('marketing_profile_links')->insert(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile,
                'source_type' => $definition['type'], 'source_id' => $definition['column'].'-'.$name, 'source_meta' => $meta]);
        }
        $base = DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->where('source_type', $definition['type']);
        $candidates = $this->schema->narrow(clone $base, $path)->pluck('source_id')->all();
        expect($candidates)->toHaveCount(count($values))->toContain($definition['column'].'-0');
        $original = (clone $base)->whereIn('source_meta->'.$definition['json'], [100]);
        $narrowed = $this->schema->narrow(clone $original, $path);
        expect($narrowed->orderBy('id')->pluck('id')->all())->toBe((clone $original)->orderBy('id')->pluck('id')->all())
            ->and($narrowed->toSql())->toContain('force index')->toContain('json_unquote(json_extract');
        $plan = DB::select('EXPLAIN '.$narrowed->toSql(), $narrowed->getBindings());
        expect($plan[0]->key)->toBe($definition['index']);
    }
});

test('missing partial or incompatible anchor schema returns the original predicate and migration rejects drift', function () {
    $base = DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->where('source_type', 'pos_event_capture')->whereIn('source_meta->event_id', [100]);
    DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `mpl_event_capture_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql());
    DB::statement('ALTER TABLE `marketing_profile_links` ADD INDEX `mpl_event_capture_idx` (`tenant_id`,`source_type`,`id`), ALGORITHM=INPLACE, LOCK=NONE');
    try {
        expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql());
        expect(fn () => customerAnchorMigration()->up())->toThrow(RuntimeException::class, 'Incompatible event customer index');
    } finally {
        DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `mpl_event_capture_idx`, ALGORITHM=INPLACE, LOCK=NONE');
        customerAnchorMigration()->up();
    }
    foreach (['ALTER COLUMN `event_capture_present` SET VISIBLE', 'ALTER INDEX `mpl_event_capture_idx` INVISIBLE'] as $change) {
        DB::statement('ALTER TABLE `marketing_profile_links` '.$change);
        try {
            expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql());
            expect(fn () => customerAnchorMigration()->up())->toThrow(RuntimeException::class);
        } finally {
            DB::statement('ALTER TABLE `marketing_profile_links` ALTER COLUMN `event_capture_present` SET INVISIBLE');
            DB::statement('ALTER TABLE `marketing_profile_links` ALTER INDEX `mpl_event_capture_idx` VISIBLE');
        }
    }
    $original = (array) DB::table('marketing_profile_links')->find($this->row);
    DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `mpl_event_capture_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    DB::statement('ALTER TABLE `marketing_profile_links` DROP COLUMN `event_capture_present`, ALGORITHM=INPLACE, LOCK=NONE');
    DB::statement("ALTER TABLE `marketing_profile_links` ADD COLUMN `event_capture_present` TINYINT UNSIGNED GENERATED ALWAYS AS (JSON_EXTRACT(`source_meta`,'$.Event_id') IS NOT NULL) VIRTUAL INVISIBLE, ALGORITHM=INSTANT");
    try {
        expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql())
            ->and($this->schema->redundantColumns(DB::connection()))->not->toContain('event_capture_present');
        expect(fn () => customerAnchorMigration()->up())->toThrow(RuntimeException::class, 'Incompatible event customer presence');
        expect((array) DB::table('marketing_profile_links')->find($this->row))->toBe($original);
    } finally {
        DB::statement('ALTER TABLE `marketing_profile_links` DROP COLUMN `event_capture_present`, ALGORITHM=INPLACE, LOCK=NONE');
        customerAnchorMigration()->up();
    }
});

test('verified derived presence fields preserve the covering content digest while semantic field drift falls back', function () {
    $columns = ['id', 'marketing_profile_id', 'source_type', 'source_id', 'source_meta', 'match_method', 'confidence'];
    $requested = [...$columns, 'event_capture_present', 'event_attribution_present'];
    $method = new ReflectionMethod(EventPerformance::class, 'tableDigest');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $before = $method->invoke(app(EventPerformance::class), 'marketing_profile_links', $this->tenant->id, $requested);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    $aggregate = $queries->first(fn ($query) => str_contains($query['query'], 'HEX(`event_content_hash`)'));
    expect($aggregate)->not->toBeNull()->and($aggregate['query'])->toContain('force index')->not->toContain('`source_meta`');
    foreach (['{"event_id":"100"}', '{"event_id":null}', '{"event_attribution":{"event_id":100}}'] as $meta) {
        DB::table('marketing_profile_links')->where('id', $this->row)->update(['source_meta' => $meta]);
        $after = $method->invoke(app(EventPerformance::class), 'marketing_profile_links', $this->tenant->id, $requested);
        expect($after)->not->toBe($before);
        $before = $after;
    }
    $shape = new ReflectionMethod(EventPerformance::class, 'indexedDigest');
    $available = Schema::getColumnListing('marketing_profile_links');
    expect($shape->invoke(app(EventPerformance::class), 'marketing_profile_links', [...$requested, 'new_semantic_field'], [...$available, 'new_semantic_field']))->toBeNull();
    DB::statement('ALTER TABLE `marketing_profile_links` ALTER COLUMN `event_capture_present` SET VISIBLE');
    try {
        expect($shape->invoke(app(EventPerformance::class), 'marketing_profile_links', $requested, $available))->toBeNull();
    } finally {
        DB::statement('ALTER TABLE `marketing_profile_links` ALTER COLUMN `event_capture_present` SET INVISIBLE');
    }
});
