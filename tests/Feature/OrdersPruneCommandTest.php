<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('prunes only pre-cutoff orders for the target tenant and cleans dependents safely', function () {
    $old1 = DB::table('orders')->insertGetId(['tenant_id' => 1, 'ordered_at' => '2018-05-01 00:00:00', 'source' => 'manual']);
    $old2 = DB::table('orders')->insertGetId(['tenant_id' => 1, 'ordered_at' => '2025-12-31 23:59:59', 'source' => 'manual']);
    $keep = DB::table('orders')->insertGetId(['tenant_id' => 1, 'ordered_at' => '2026-01-02 00:00:00', 'source' => 'manual']);
    $otherTenant = DB::table('orders')->insertGetId(['tenant_id' => 2, 'ordered_at' => '2019-01-01 00:00:00', 'source' => 'manual']);
    $nullDated = DB::table('orders')->insertGetId(['tenant_id' => 1, 'ordered_at' => null, 'source' => 'manual']);

    // Dependent rows hanging off the oldest order.
    $line = DB::table('order_lines')->insertGetId(['order_id' => $old1]);
    $split = DB::table('order_line_scent_splits')->insertGetId(['order_line_id' => $line]);
    $attrId = DB::table('marketing_message_order_attributions')->insertGetId(['order_id' => $old1]);

    $this->artisan('orders:prune', [
        '--before' => '2026-01-01',
        '--tenant' => 1,
        '--force' => true,
        '--skip-backup' => true,
    ])->assertSuccessful();

    // Pre-cutoff tenant-1 orders and their owned artifacts are gone.
    expect(DB::table('orders')->whereIn('id', [$old1, $old2])->count())->toBe(0);
    expect(DB::table('order_lines')->where('id', $line)->count())->toBe(0);
    expect(DB::table('order_line_scent_splits')->where('id', $split)->count())->toBe(0);

    // Post-cutoff order, other-tenant order, and null-dated order are preserved.
    expect(DB::table('orders')->where('id', $keep)->exists())->toBeTrue();
    expect(DB::table('orders')->where('id', $otherTenant)->exists())->toBeTrue();
    expect(DB::table('orders')->where('id', $nullDated)->exists())->toBeTrue();

    // Value-bearing attribution row survives with its order link nulled (not deleted).
    $attr = DB::table('marketing_message_order_attributions')->find($attrId);
    expect($attr)->not->toBeNull();
    expect($attr->order_id)->toBeNull();
});

it('does nothing and exits cleanly when no orders match', function () {
    $this->artisan('orders:prune', [
        '--before' => '1900-01-01',
        '--tenant' => 1,
        '--force' => true,
        '--skip-backup' => true,
    ])->expectsOutputToContain('Nothing to prune')->assertSuccessful();
});
