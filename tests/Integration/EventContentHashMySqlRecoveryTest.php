<?php

use App\Models\Tenant;
use App\Services\Operations\MySqlSchemaFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

function eventContentHashIndexes(): array
{
    return [
        'square_orders' => [
            'epo_window_hash_idx' => ['tenant_id', 'location_id', 'closed_at', 'square_order_id', 'event_content_hash'],
            'epo_order_hash_idx' => ['tenant_id', 'square_order_id', 'location_id', 'closed_at', 'event_content_hash'],
        ],
        'square_payments' => ['epp_order_hash_idx' => ['tenant_id', 'square_order_id', 'event_content_hash']],
        'marketing_profiles' => ['ep_profile_hash_idx' => ['tenant_id', 'archived_at', 'event_content_hash']],
        'marketing_profile_links' => ['ep_link_hash_idx' => ['tenant_id', 'event_content_hash']],
    ];
}

function eventContentHashMigration(): \Illuminate\Database\Migrations\Migration
{
    return require database_path('migrations/2026_11_16_030000_add_indexed_event_content_hashes.php');
}

function eventContentHashRow(string $table): string
{
    $timestamp = match ($table) {
        'square_orders' => 'UNIX_TIMESTAMP(`closed_at`),',
        'marketing_profiles' => 'UNIX_TIMESTAMP(`archived_at`),',
        default => '',
    };

    return 'UNHEX(SHA2(JSON_ARRAY(`id`,'.$timestamp.'HEX(`event_content_hash`)),256))';
}

beforeEach(function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing source hash recovery outside isolated disposable MySQL.');
    }
    DB::statement("SET time_zone = '+00:00'");
    eventContentHashMigration()->up();
    $this->tenant = Tenant::create(['name' => 'Fictional hash recovery', 'slug' => 'hash-recovery-'.Str::lower(Str::random(12))]);
    $this->otherProfile = DB::table('marketing_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional parent']);
    $profile = DB::table('marketing_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional visitor',
        'merged_into_profile_id' => $this->otherProfile, 'archived_at' => '2026-11-15 12:34:56', 'source_channels' => '["square"]']);
    $key = (string) Str::uuid();
    $fixtures = [
        'square_orders' => ['square_order_id' => 'order-'.$key, 'square_customer_id' => 'customer-'.$key, 'location_id' => 'fictional-location', 'state' => 'COMPLETED',
            'total_money_amount' => 100, 'total_money_currency' => 'USD', 'closed_at' => '2026-11-15 12:34:56', 'raw_payload' => '{"value":1}'],
        'square_payments' => ['square_payment_id' => 'payment-'.$key, 'square_order_id' => 'order-'.$key, 'square_customer_id' => 'customer-'.$key, 'location_id' => 'fictional-location',
            'amount_money' => 100, 'currency' => 'USD', 'status' => 'COMPLETED', 'raw_payload' => '{"value":1}'],
        'marketing_profiles' => ['merged_into_profile_id' => $this->otherProfile, 'archived_at' => '2026-11-15 12:34:56', 'source_channels' => '["square"]'],
        'marketing_profile_links' => ['marketing_profile_id' => $profile, 'source_type' => 'square', 'source_id' => 'customer-'.$key,
            'source_meta' => '{"value":1}', 'match_method' => 'reviewed', 'confidence' => '1.00'],
    ];
    $this->sourceRows = [];
    foreach ($fixtures as $table => $row) {
        $data = ['tenant_id' => $this->tenant->id, ...$row];
        $this->sourceRows[$table] = ['id' => $table === 'marketing_profiles' ? $profile : DB::table($table)->insertGetId($data), 'data' => $data];
    }
    $this->readHash = function (string $table): string {
        $index = array_key_first(eventContentHashIndexes()[$table]);

        return DB::table(DB::raw('`'.$table.'` FORCE INDEX (`'.$index.'`)'))->where('tenant_id', $this->tenant->id)
            ->where('id', $this->sourceRows[$table]['id'])->selectRaw('HEX('.eventContentHashRow($table).') as digest')->value('digest');
    };
});

afterEach(function (): void {
    if (! isset($this->sourceRows)) {
        return;
    }
    DB::statement("SET time_zone = '+00:00'");
    foreach (['marketing_profile_links', 'square_payments', 'square_orders', 'marketing_profiles'] as $table) {
        DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
    }
    DB::table('tenants')->where('id', $this->tenant->id)->delete();
});

it('recovers committed columns and partial indexes without changing original source rows or exposing internal hashes', function (): void {
    $before = [];
    foreach ($this->sourceRows as $table => $row) {
        $before[$table] = (array) DB::table($table)->find($row['id']);
        foreach (array_keys(eventContentHashIndexes()[$table]) as $index) {
            DB::statement('ALTER TABLE `'.$table.'` DROP INDEX `'.$index.'`, ALGORITHM=INPLACE, LOCK=NONE');
        }
        if ($table !== 'square_orders') {
            DB::statement('ALTER TABLE `'.$table.'` DROP COLUMN `event_content_hash`, ALGORITHM=INSTANT');
        }
    }
    // MySQL has committed only the first column; Laravel has not recorded up().
    $migration = eventContentHashMigration();
    $migration->up();
    // A later interruption retains the first order index but misses the second.
    DB::statement('ALTER TABLE `square_orders` DROP INDEX `epo_order_hash_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    $migration->up();
    $migration->up();
    $migration->down();
    foreach ($this->sourceRows as $table => $row) {
        $star = (array) DB::table($table)->find($row['id']);
        expect($star)->toBe($before[$table])->not->toHaveKey('event_content_hash');
        expect(json_encode($star, JSON_THROW_ON_ERROR))->toBeString();
        expect(strlen(DB::table($table)->where('id', $row['id'])->value('event_content_hash')))->toBe(32);
        foreach (array_keys(eventContentHashIndexes()[$table]) as $index) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
        $index = array_key_first(eventContentHashIndexes()[$table]);
        $plan = DB::select('EXPLAIN SELECT COUNT(*),HEX(BIT_XOR('.eventContentHashRow($table).')) FROM `'.$table.'` FORCE INDEX (`'.$index.'`) WHERE tenant_id = ?', [$this->tenant->id]);
        expect($plan[0]->Extra)->toContain('Using index');
    }
});

it('rejects incompatible generated definitions visibility and named indexes without replacing source evidence', function (): void {
    $migration = eventContentHashMigration();
    $before = (array) DB::table('square_orders')->find($this->sourceRows['square_orders']['id']);
    DB::statement('ALTER TABLE `square_orders` DROP INDEX `epo_order_hash_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    DB::statement('ALTER TABLE `square_orders` ADD INDEX `epo_order_hash_idx` (`tenant_id`,`state`), ALGORITHM=INPLACE, LOCK=NONE');
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Incompatible event content index epo_order_hash_idx');
        expect((array) DB::table('square_orders')->find($this->sourceRows['square_orders']['id']))->toBe($before);
    } finally {
        DB::statement('ALTER TABLE `square_orders` DROP INDEX `epo_order_hash_idx`, ALGORITHM=INPLACE, LOCK=NONE');
        $migration->up();
    }
    DB::statement('ALTER TABLE `marketing_profiles` ALTER COLUMN `event_content_hash` SET VISIBLE');
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Incompatible event content hash on marketing_profiles');
    } finally {
        DB::statement('ALTER TABLE `marketing_profiles` ALTER COLUMN `event_content_hash` SET INVISIBLE');
    }
    $definition = DB::selectOne("SELECT GENERATION_EXPRESSION AS expression FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_profile_links' AND COLUMN_NAME = 'event_content_hash'")->expression;
    DB::statement('ALTER TABLE `marketing_profile_links` DROP INDEX `ep_link_hash_idx`, ALGORITHM=INPLACE, LOCK=NONE');
    $beforeExpression = app(MySqlSchemaFingerprint::class)->inspect(DB::connection());
    DB::statement('ALTER TABLE `marketing_profile_links` DROP COLUMN `event_content_hash`, ALGORITHM=INSTANT');
    DB::statement("ALTER TABLE `marketing_profile_links` ADD COLUMN `event_content_hash` BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2('changed-expression',256))) VIRTUAL INVISIBLE, ALGORITHM=INSTANT");
    try {
        $changedExpression = app(MySqlSchemaFingerprint::class)->inspect(DB::connection());
        expect($changedExpression['raw_fingerprint'])->toBe($beforeExpression['raw_fingerprint']);
        expect($changedExpression['fingerprint'])->not->toBe($beforeExpression['fingerprint']);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Incompatible event content hash on marketing_profile_links');
    } finally {
        DB::statement('ALTER TABLE `marketing_profile_links` DROP COLUMN `event_content_hash`, ALGORITHM=INSTANT');
        DB::statement('ALTER TABLE `marketing_profile_links` ADD COLUMN `event_content_hash` BINARY(32) GENERATED ALWAYS AS ('.$definition.') VIRTUAL INVISIBLE, ALGORITHM=INSTANT');
        $migration->up();
    }
});

it('detects direct same-second mutations typed JSON values replacement ids timestamps and identity cascades using indexed values', function (): void {
    $jsonColumns = ['raw_payload', 'source_channels', 'source_meta'];
    foreach ($this->sourceRows as $table => $fixture) {
        $before = ($this->readHash)($table);
        foreach (array_diff(array_keys($fixture['data']), ['tenant_id']) as $column) {
            $original = $fixture['data'][$column];
            $changed = match (true) {
                in_array($column, $jsonColumns, true) => '{"value":"1"}',
                in_array($column, ['closed_at', 'archived_at'], true) => '2026-11-15 12:34:57',
                $column === 'marketing_profile_id' => $this->otherProfile,
                $column === 'merged_into_profile_id' => null,
                $column === 'confidence' => '0.75',
                is_int($original) => $original + 1,
                default => $original.'x',
            };
            DB::table($table)->where('id', $fixture['id'])->update([$column => $changed]);
            expect(($this->readHash)($table))->not->toBe($before);
            DB::table($table)->where('id', $fixture['id'])->update([$column => $original]);
            expect(($this->readHash)($table))->toBe($before);
        }
        $jsonColumn = array_values(array_intersect(array_keys($fixture['data']), $jsonColumns))[0];
        $hashes = [];
        foreach ([null, 'null', '0', '"0"', 'false', '"false"', '""', '{}', '[]'] as $value) {
            DB::table($table)->where('id', $fixture['id'])->update([$jsonColumn => $value]);
            $hashes[] = ($this->readHash)($table);
        }
        expect(array_unique($hashes))->toHaveCount(9);
        DB::table($table)->where('id', $fixture['id'])->update([$jsonColumn => $fixture['data'][$jsonColumn]]);
        DB::statement("SET time_zone = '+05:30'");
        expect(($this->readHash)($table))->toBe($before);
        DB::statement("SET time_zone = '+00:00'");
        // Preserve the profile referenced by the link until its cascade test.
        if ($table !== 'marketing_profiles') {
            DB::table($table)->where('id', $fixture['id'])->delete();
            $this->sourceRows[$table]['id'] = DB::table($table)->insertGetId($fixture['data']);
            expect(($this->readHash)($table))->not->toBe($before);
        } else {
            $copy = DB::table($table)->insertGetId($fixture['data']);
            $copyHash = DB::table($table)->where('id', $copy)->selectRaw('HEX('.eventContentHashRow($table).') as digest')->value('digest');
            expect($copyHash)->not->toBe($before);
            DB::table($table)->where('id', $copy)->delete();
        }
    }
    $before = ($this->readHash)('marketing_profiles');
    DB::table('marketing_profiles')->where('id', $this->otherProfile)->delete();
    expect(DB::table('marketing_profiles')->where('id', $this->sourceRows['marketing_profiles']['id'])->value('merged_into_profile_id'))->toBeNull();
    expect(($this->readHash)('marketing_profiles'))->not->toBe($before);
});
