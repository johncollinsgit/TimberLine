<?php

use App\Models\MarketingProfile;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
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
        throw new RuntimeException('Refusing frame-scale fixtures outside isolated disposable MySQL.');
    }
    Http::preventStrayRequests();
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-16T12:00:00Z'));
    config(['pos_live.runtime_id' => 'frame_scale_fixture']);
    $this->tenant = Tenant::create(['name' => 'Fictional frame workload', 'slug' => 'frame-workload-'.Str::lower(Str::random(16))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Fictional source events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $this->binding = ['environment' => 'production', 'merchant_id' => 'frame-scale-merchant', 'location_ids' => ['frame-scale-location'],
        'connection_id' => 'frame-scale-reader', 'runtime_id' => 'frame_scale_fixture', 'connection_generation' => 1, 'source_purpose' => 'customer_history'];
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (['marketing_profile_links', 'square_payments', 'square_orders', 'marketing_profiles', 'commerce_stock_event_links', 'team_operation_records', 'team_areas', 'tenant_user'] as $table) {
        DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
    }
    $this->tenant->delete();
    $this->owner->delete();
});

function frameScaleInsert(string $table, int $count, callable $row): void
{
    for ($offset = 0; $offset < $count; $offset += 500) {
        $batch = [];
        for ($number = $offset; $number < min($offset + 500, $count); $number++) {
            $batch[] = $row($number);
        }
        DB::table($table)->insert($batch);
    }
}

/** One independent current-volume block; the second doubles every measured population. */
function frameScalePopulate($test, int $block): array
{
    $prefix = 'frame-'.$test->tenant->id.'-'.$block.'-';
    $firstProfile = (int) DB::table('marketing_profiles')->max('id') + 1;
    frameScaleInsert('marketing_profiles', 76000, fn ($i) => ['id' => $firstProfile + $i, 'tenant_id' => $test->tenant->id,
        'first_name' => ($i < 1442 ? 'Relevant ' : 'Unrelated ').$block.' '.$i, 'last_name' => 'Visitor', 'source_channels' => '["square"]']);
    $events = [];
    $commerce = [];
    for ($i = 0; $i < 18; $i++) {
        $events[$i] = (string) Str::uuid();
        $commerce[$i] = $test->tenant->id * 1000 + $block * 100 + $i + 1;
    }
    frameScaleInsert('team_operation_records', 1070, function ($i) use ($test, $prefix, $events, $commerce) {
        $data = ['source_id' => $prefix.'task-'.$i, 'observed_at' => '2026-11-16T11:00:00Z'];
        if ($i < 18) {
            $orders = [];
            for ($order = $i; $order < 2347; $order += 18) {
                $orders[] = $prefix.'order-'.$order;
            }
            $data['_teams_enrichment'] = ['commerce_event' => ['id' => $commerce[$i]], 'square_orders' => [
                'ids' => $orders, 'reviewed_by' => $test->owner->id, 'reviewed_at' => '2026-11-03T12:00:00Z', 'source_binding' => $test->binding,
            ]];
        }
        $record = new TeamOperationRecord(['data' => $data]);

        return ['id' => $events[$i] ?? (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id,
            'kind' => 'event', 'source_key' => hash('sha256', $prefix.'task-'.$i), 'title' => 'Fictional original event '.$i,
            'status' => 'accepted', 'version' => 1, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02',
            'data' => $record->getAttributes()['data']];
    });
    frameScaleInsert('commerce_stock_event_links', 18, fn ($i) => ['tenant_id' => $test->tenant->id, 'event_id' => $commerce[$i],
        'provider' => 'manual', 'external_id' => $prefix.'review-'.$i, 'reviewed_by' => $test->owner->id,
        'metadata' => json_encode(['name' => 'Fictional original event '.$i, 'timezone' => 'UTC', 'start_on' => '2026-11-01', 'end_on' => '2026-11-02'])]);
    // Retain a sparse selected cohort in a larger original order archive too.
    frameScaleInsert('square_orders', 25000, function ($i) use ($test, $prefix) {
        $id = $prefix.'order-'.$i;

        return ['tenant_id' => $test->tenant->id, 'square_order_id' => $id, 'square_customer_id' => $prefix.'customer-'.($i % 1442),
            'location_id' => 'frame-scale-location', 'state' => 'COMPLETED', 'total_money_amount' => 1100, 'total_money_currency' => 'USD',
            'closed_at' => '2026-11-01 16:00:00', 'raw_payload' => json_encode(['id' => $id, '_source_binding' => $test->binding,
                'total_money' => ['amount' => 1100, 'currency' => 'USD'], 'total_tax_money' => ['amount' => 100, 'currency' => 'USD'],
                'total_tip_money' => ['amount' => 0, 'currency' => 'USD'], 'net_amounts' => ['total_money' => ['amount' => 1100, 'currency' => 'USD'], 'tax_money' => ['amount' => 100, 'currency' => 'USD']]], JSON_THROW_ON_ERROR)];
    });
    frameScaleInsert('square_payments', 63000, function ($i) use ($test, $prefix) {
        $id = $prefix.'payment-'.$i;
        $order = $i < 2347 ? $i : ($i < 2385 ? $i - 2347 : $i);
        $orderId = $prefix.'order-'.$order;
        $amount = $order < 38 ? 550 : 1100;

        return ['tenant_id' => $test->tenant->id, 'square_payment_id' => $id, 'square_order_id' => $orderId,
            'square_customer_id' => $prefix.'customer-'.($order % 1442), 'location_id' => 'frame-scale-location',
            'status' => 'COMPLETED', 'amount_money' => $amount, 'currency' => 'USD', 'source_type' => 'CARD', 'created_at_source' => '2026-11-01 16:00:00',
            'raw_payload' => json_encode(['id' => $id, 'order_id' => $orderId, 'location_id' => 'frame-scale-location', 'status' => 'COMPLETED',
                '_source_binding' => $test->binding, 'amount_money' => ['amount' => $amount, 'currency' => 'USD'], 'total_money' => ['amount' => $amount, 'currency' => 'USD'],
                'refunded_money' => ['amount' => 0, 'currency' => 'USD'], 'retained_original' => str_repeat('original ', 32)], JSON_THROW_ON_ERROR)];
    });
    frameScaleInsert('marketing_profile_links', 151000, function ($i) use ($test, $prefix, $firstProfile, $commerce) {
        if ($i < 2347) {
            $order = $i;
            $type = 'square_order';
            $id = $prefix.'order-'.$i;
        } elseif ($i < 4732) {
            $payment = $i - 2347;
            $order = $payment < 2347 ? $payment : $payment - 2347;
            $type = 'square_payment';
            $id = $prefix.'payment-'.$payment;
        } else {
            $number = $i - 4732;
            $type = $number < 56000 ? 'square_payment' : ($number < 70000 ? 'square_order' : ($number % 2 ? 'square_customer' : 'shopify_customer'));

            return ['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $firstProfile + 1442 + ($number % (76000 - 1442)),
                'source_type' => $type, 'source_id' => $prefix.'unrelated-'.$number,
                'source_meta' => json_encode(['source_system' => str_starts_with($type, 'square_') ? 'square' : 'shopify', 'source_record_type' => 'imported', 'retained_original' => ['sequence' => $number]]),
                'match_method' => 'original_provider_id', 'confidence' => '1.00'];
        }

        $meta = ['retained_original' => ['sequence' => $i]];
        if ($i < 18) {
            $meta['event_attribution'] = ['event_id' => $commerce[$order % 18], 'reviewed_by' => $test->owner->id,
                'reviewed_at' => '2026-11-03T12:00:00Z', 'evidence_reference' => $prefix.'original-review'];
        }

        return ['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $firstProfile + ($order % 1442), 'source_type' => $type, 'source_id' => $id,
            'source_meta' => json_encode($meta),
            'match_method' => 'explicit_source_review', 'confidence' => '1.00'];
    });

    return ['prefix' => $prefix, 'first_profile' => $firstProfile, 'first_event' => $events[0]];
}

function frameScaleQueryFamilies(array $queries): array
{
    $families = [];
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        $family = 'other';
        foreach (['square_payments', 'square_orders', 'marketing_profile_links', 'marketing_profiles', 'team_operation_records', 'commerce_stock_event_links', 'information_schema'] as $table) {
            if (str_contains($sql, $table)) {
                $family = $table;
                break;
            }
        }
        $families[$family] ??= ['queries' => 0, 'sql_ms' => 0.0, 'max_sql_ms' => 0.0];
        $families[$family]['queries']++;
        $families[$family]['sql_ms'] += $query['time'];
        $families[$family]['max_sql_ms'] = max($families[$family]['max_sql_ms'], $query['time']);
    }
    foreach ($families as &$family) {
        $family = ['queries_total' => $family['queries'], 'queries_per_frame' => $family['queries'] / 5,
            'sql_ms_total' => round($family['sql_ms'], 3), 'sql_ms_per_frame' => round($family['sql_ms'] / 5, 3),
            'max_single_sql_ms' => round($family['max_sql_ms'], 3)];
    }
    unset($family);

    return $families;
}

function frameScaleQueryPhases(array $queries): array
{
    $phases = [];
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        $phase = match (true) {
            str_contains($sql, 'dependency_payment_keys') => 'payment_membership',
            str_contains($sql, 'hex(`event_content_hash`)') => 'original_hashes',
            str_contains($sql, 'dependency_event_links') => 'reviewed_event_anchors',
            str_contains($sql, 'dependency_source_keys') => 'source_claims',
            str_contains($sql, 'from `square_payments`') => 'other_payment_reads',
            str_contains($sql, 'from `square_orders`') => 'other_order_reads',
            default => 'other',
        };
        $phases[$phase] ??= ['queries' => 0, 'sql_ms' => 0.0, 'max_sql_ms' => 0.0];
        $phases[$phase]['queries']++;
        $phases[$phase]['sql_ms'] += $query['time'];
        $phases[$phase]['max_sql_ms'] = max($phases[$phase]['max_sql_ms'], $query['time']);
    }
    foreach ($phases as &$phase) {
        $phase = ['queries_total' => $phase['queries'], 'queries_per_frame' => $phase['queries'] / 5,
            'sql_ms_total' => round($phase['sql_ms'], 3), 'sql_ms_per_frame' => round($phase['sql_ms'] / 5, 3),
            'max_single_sql_ms' => round($phase['max_sql_ms'], 3)];
    }
    unset($phase);

    return $phases;
}

test('realistic whole event frames preserve exact cohort and original evidence at current and doubled volume', function () {
    $metrics = [];
    $blocks = [];
    foreach ([1, 2] as $scale) {
        $blocks[] = frameScalePopulate($this, $scale);
        $dependencies = new EventCustomerDependencies;
        $baseline = $dependencies->capture($this->tenant, $this->area);
        expect($baseline['labels'])->toHaveCount(1442 * $scale)->and($baseline['scope']['events'])->toHaveCount(18 * $scale)
            ->and($baseline['claims'])->toHaveCount(4732 * $scale)->and($baseline['witnesses'])->toBeEmpty();
        foreach ($blocks as $block) {
            expect($baseline['claims']['square_order:'.$block['prefix'].'order-0'])->toBe($block['first_profile'])
                ->and($baseline['claims']['square_payment:'.$block['prefix'].'payment-2347'])->toBe($block['first_profile'])
                ->and($baseline['events']['square_order:'.$block['prefix'].'order-0'])->toHaveKey($block['first_event']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            $started = hrtime(true);
            $frame = $dependencies->capture($this->tenant, $this->area, $baseline['scope']);
            $samples[] = (hrtime(true) - $started) / 1e6;
            expect($frame['signature'])->toBe($baseline['signature'])->and($frame['labels'])->toBe($baseline['labels'])
                ->and($frame['claims'])->toBe($baseline['claims']);
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $sql = str_replace('`', '', strtolower($query['query']));
            if (! str_starts_with($sql, 'select') || str_contains($sql, 'information_schema')) {
                continue;
            }
            expect($sql)->not->toContain('for update');
            if (str_contains($sql, 'from marketing_profiles')) {
                expect((bool) preg_match('/\bid\s+in\s*\(|\bmerged_into_profile_id\s+in\s*\(/', $sql))->toBeTrue($sql);
            }
            if (str_contains($sql, 'from marketing_profile_links')) {
                expect($sql)->toContain('tenant_id');
                expect(str_contains($sql, 'marketing_profile_id in (') || str_contains($sql, 'source_id in (') || str_contains($sql, 'dependency_event_link_id'))->toBeTrue($sql);
            }
        }
        $financial = collect($queries)->last(fn ($query) => str_contains($query['query'], 'dependency_payment_keys'));
        $anchors = collect($queries)->first(fn ($query) => str_contains($query['query'], 'dependency_event_links'));
        $claims = collect($queries)->filter(fn ($query) => str_contains($query['query'], 'dependency_source_keys'))->sortByDesc('time')->first();
        expect($financial)->not->toBeNull()->and($anchors)->not->toBeNull()->and($claims)->not->toBeNull();
        $plans = [];
        foreach (['financial' => $financial, 'anchors' => $anchors, 'source_claims' => $claims] as $family => $query) {
            $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
            $originals = collect($plan)->filter(fn ($row) => in_array($row->table, ['square_payments', 'marketing_profile_links'], true));
            expect($originals)->not->toBeEmpty();
            foreach ($originals as $row) {
                expect($row->key)->not->toBeNull()->and($row->type)->not->toBe('ALL');
            }
            $plans[$family] = $originals->groupBy(fn ($row) => $row->type.':'.$row->key)->map(fn ($rows) => ['nodes' => $rows->count(), 'estimated_rows' => $rows->sum('rows'),
                'min_key_length' => (int) $rows->min('key_len'), 'max_key_length' => (int) $rows->max('key_len')])->all();
        }
        $meanWall = array_sum($samples) / count($samples);
        sort($samples);
        $metrics[$scale] = ['canonical_roots' => count($baseline['labels']), 'selected_events' => count($baseline['scope']['events']),
            'inventory_events' => DB::table('team_operation_records')->where('tenant_id', $this->tenant->id)->count(),
            'profile_links' => DB::table('marketing_profile_links')->where('tenant_id', $this->tenant->id)->count(),
            'retained_orders' => DB::table('square_orders')->where('tenant_id', $this->tenant->id)->count(),
            'retained_payments' => DB::table('square_payments')->where('tenant_id', $this->tenant->id)->count(),
            'relevant_orders' => 2347 * $scale, 'relevant_payments' => 2385 * $scale, 'samples' => count($samples),
            'p95_ms' => round($samples[(int) ceil(count($samples) * .95) - 1], 3), 'queries_per_frame' => count($queries) / 5,
            'mean_non_sql_ms' => round(max(0, $meanWall - array_sum(array_column($queries, 'time')) / 5), 3),
            'query_families' => frameScaleQueryFamilies($queries), 'query_phases' => frameScaleQueryPhases($queries), 'plans' => $plans];
        fwrite(STDERR, 'Whole event frame workload: '.json_encode(['scale' => $scale, ...$metrics[$scale]], JSON_THROW_ON_ERROR).PHP_EOL);

        // Relevant original bytes and canonical labels still fence the result at scale.
        $original = DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where('square_payment_id', $blocks[0]['prefix'].'payment-0')->value('raw_payload');
        $changed = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $changed['retained_original_type'] = 1;
        DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where('square_payment_id', $blocks[0]['prefix'].'payment-0')->update(['raw_payload' => json_encode($changed)]);
        expect($dependencies->capture($this->tenant, $this->area, $baseline['scope'])['signature'])->not->toBe($baseline['signature']);
        DB::table('square_payments')->where('tenant_id', $this->tenant->id)->where('square_payment_id', $blocks[0]['prefix'].'payment-0')->update(['raw_payload' => $original]);
        expect($dependencies->capture($this->tenant, $this->area, $baseline['scope'])['signature'])->toBe($baseline['signature']);
        $label = DB::table('marketing_profiles')->where('id', $blocks[0]['first_profile'])->value('first_name');
        DB::table('marketing_profiles')->where('id', $blocks[0]['first_profile'])->update(['first_name' => 'Changed canonical label']);
        expect($dependencies->capture($this->tenant, $this->area, $baseline['scope'])['signature'])->not->toBe($baseline['signature']);
        DB::table('marketing_profiles')->where('id', $blocks[0]['first_profile'])->update(['first_name' => $label]);
        expect($dependencies->capture($this->tenant, $this->area, $baseline['scope'])['signature'])->toBe($baseline['signature']);
    }
    // A small worker build uses the same retained originals and doubled directory.
    // Limit this correctness sample to one reviewed event/purchase after timings;
    // it is not a claim about the cost of a 2,884-customer lifetime build.
    $first = $blocks[0];
    DB::table('team_operation_records')->where('tenant_id', $this->tenant->id)->where('id', '<>', $first['first_event'])->delete();
    $event = TeamOperationRecord::findOrFail($first['first_event']);
    $data = $event->data;
    $data['_teams_enrichment']['square_orders']['ids'] = [$first['prefix'].'order-0'];
    $event->update(['data' => $data, 'version' => 2]);
    $dependencies = new EventCustomerDependencies;
    $start = hrtime(true);
    $built = $dependencies->capture($this->tenant, $this->area, null, true);
    $metrics['witness_sample'] = ['canonical_roots' => count($built['labels']), 'selected_events' => count($built['scope']['events']),
        'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3)];
    $profile = MarketingProfile::findOrFail($first['first_profile']);
    $dependencies->assertPurchase($built, $first['first_event'], $profile, ['source' => 'square_order', 'source_id' => $first['prefix'].'order-0',
        'amount_minor' => 1100, 'refunded_minor' => 0, 'currency' => 'USD', 'occurred_at' => '2026-11-01T16:00:00+00:00']);
    expect($built['labels'])->toHaveCount(1)->and($built['witnesses']['square_order:'.$first['prefix'].'order-0'][$profile->id]['confirmed'])->toBeTrue();
    $path = sys_get_temp_dir().'/event-customer-whole-frame-workload-'.getmypid().'.json';
    file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDERR, 'Whole event frame evidence: '.$path.PHP_EOL);
    Http::assertNothingSent();
});
