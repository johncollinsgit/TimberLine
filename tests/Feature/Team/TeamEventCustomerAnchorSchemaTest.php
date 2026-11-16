<?php

use App\Models\MarketingProfile;
use App\Services\Team\Studio\EventCustomerAnchorSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    [$this->tenant] = teamWorkspace();
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional visitor']);
    $this->schema = new EventCustomerAnchorSchema;
});

test('safe SQLite presence indexes retain the exact original JSON comparison including null and typed candidates', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite expression behavior is covered separately from MySQL generated values.');
    }
    $values = [null, 'null', '{}', '{"event_id":null}', '{"event_id":100}', '{"event_id":"100"}', '{"event_id":"0100"}', '{"event_id":true}', '{"event_id":[]}', '{"event_id":{}}'];
    foreach (['$.event_id' => 'event_id', '$.event_attribution.event_id' => 'event_attribution->event_id'] as $path => $jsonPath) {
        DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->delete();
        foreach ($values as $index => $value) {
            if ($jsonPath !== 'event_id' && $value !== null) {
                $value = json_encode(['event_attribution' => json_decode($value, true)], JSON_THROW_ON_ERROR);
            }
            DB::table('marketing_profile_links')->insert(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'square_order', 'source_id' => 'source-'.$index, 'source_meta' => $value]);
        }
        $original = DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->where('source_type', 'square_order')->whereIn('source_meta->'.$jsonPath, [100]);
        $narrowed = $this->schema->narrow(clone $original, $path);
        expect($narrowed->orderBy('id')->pluck('id')->all())->toBe((clone $original)->orderBy('id')->pluck('id')->all())
            ->and($narrowed->toSql())->toContain('indexed by')->toContain('CASE WHEN');
        $candidates = $this->schema->narrow(DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->where('source_type', 'square_order'), $path)->pluck('source_id')->all();
        expect($candidates)->toContain('source-3')->not->toContain('source-0', 'source-1', 'source-2');
        $plan = DB::select('EXPLAIN QUERY PLAN '.$narrowed->toSql(), $narrowed->getBindings());
        expect(collect($plan)->pluck('detail')->implode(' '))->toContain('INDEX mpl_event_');
    }
});

test('malformed SQLite JSON remains a candidate and the unchanged residual fails closed', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Native MySQL JSON rejects malformed source documents at write time.');
    }
    DB::table('marketing_profile_links')->insert(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'pos_event_capture', 'source_id' => 'invalid-json', 'source_meta' => '{']);
    $base = DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->where('source_type', 'pos_event_capture');
    expect($this->schema->narrow(clone $base, '$.event_id')->pluck('source_id')->all())->toBe(['invalid-json']);
    $original = (clone $base)->whereIn('source_meta->event_id', [100]);
    expect(fn () => (clone $original)->get())->toThrow(QueryException::class)
        ->and(fn () => $this->schema->narrow(clone $original, '$.event_id')->get())->toThrow(QueryException::class);
});

test('missing or incompatible SQLite expression indexes retain the original query without filtering candidates', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('MySQL partial-schema fallback is covered by committed recovery tests.');
    }
    $migration = require database_path('migrations/2026_11_16_170000_add_event_customer_anchor_presence_indexes.php');
    DB::statement('DROP INDEX mpl_event_capture_idx');
    $base = DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->whereIn('source_meta->event_id', [100]);
    expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql());
    foreach ([
        'CREATE INDEX mpl_event_capture_idx ON marketing_profile_links (tenant_id, source_type, id)',
        'CREATE INDEX mpl_event_capture_idx ON marketing_profile_links (tenant_id, source_type, (CASE WHEN source_meta IS NULL THEN 0 WHEN json_valid(source_meta) = 0 THEN 1 WHEN json_type(source_meta,\'$.Event_id\') IS NOT NULL THEN 1 ELSE 0 END), id)',
    ] as $incompatible) {
        DB::statement($incompatible);
        expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toBe($base->toSql());
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Incompatible event customer index');
        DB::statement('DROP INDEX mpl_event_capture_idx');
    }
    $migration->up();
    expect($this->schema->narrow(clone $base, '$.event_id')->toSql())->toContain('indexed by');
});
