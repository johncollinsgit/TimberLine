<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL recovery only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing subscription recovery outside disposable local MySQL.');
    }
    Schema::dropIfExists('subscription_member_votes');
    Schema::dropIfExists('subscription_billing_actions');
    Schema::dropIfExists('subscription_billing_cycles');
    Schema::dropIfExists('subscription_billing_agreements');
    Schema::dropIfExists('subscription_pilot_cycles');
    Schema::dropIfExists('subscription_pilot_enrollments');
    Schema::dropIfExists('subscription_pilot_events');
    Schema::dropIfExists('subscription_pilot_members');
    $this->pilotDisposable = true;
});

afterEach(function () {
    if ($this->pilotDisposable ?? false) {
        Schema::dropIfExists('subscription_pilot_events');
        Schema::dropIfExists('subscription_pilot_members');
    }
});

function pilotRecoveryMember(): array
{
    $cipher = Crypt::encryptString('{"name":"Fictional recovery sentinel"}');
    $id = DB::table('subscription_pilot_members')->insertGetId(['tenant_id' => 101, 'public_id' => '00000000-0000-4000-8000-000000000101',
        'shopify_store_id' => 11, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'target_date' => '2026-11-02',
        'timezone' => 'America/New_York', 'currency' => 'USD', 'details' => $cipher, 'prepared_by' => 1]);

    return [$id, $cipher];
}

test('subscription pilot recovery retains encrypted evidence across partial DDL and code rollback', function (string $partial) {
    $migration = require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php');
    $migration->up();
    [$id, $cipher] = pilotRecoveryMember();
    if ($partial === 'parent-only') {
        Schema::drop('subscription_pilot_events');
        Schema::table('subscription_pilot_members', fn (Blueprint $t) => $t->dropUnique('spm_tenant_source_uq'));
    } elseif ($partial === 'constraints') {
        DB::table('subscription_pilot_events')->insert(['tenant_id' => 101, 'member_id' => $id, 'event_key' => str_repeat('a', 64),
            'provider' => 'recharge', 'kind' => 'payment', 'provider_reference' => 'charge-1', 'status' => 'success',
            'verification' => 'merchant_portal', 'occurred_at' => now(), 'observed_at' => now(), 'evidence' => $cipher]);
        Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->dropForeign('spe_member_fk'));
        Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->dropUnique('spe_event_uq'));
        Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->dropIndex('spe_cycle_idx'));
    }
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('subscription_pilot_members')->where('id', $id)->value('details'))->toBe($cipher)
        ->and(Schema::hasIndex('subscription_pilot_events', 'spe_event_uq'))->toBeTrue()
        ->and(Schema::hasIndex('subscription_pilot_members', 'spm_tenant_source_uq'))->toBeTrue();
    if ($partial === 'constraints') {
        expect(DB::table('subscription_pilot_events')->value('evidence'))->toBe($cipher);
    }
    expect(collect(Schema::getForeignKeys('subscription_pilot_events'))->firstWhere('name', 'spe_member_fk')['on_delete'])->toBe('restrict');
})->with(['parent-only', 'constraints', 'completed-before-bookkeeping']);

test('subscription pilot composite ownership rejects another tenants payment evidence', function () {
    $migration = require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php');
    $migration->up();
    [$id, $cipher] = pilotRecoveryMember();
    expect(fn () => DB::table('subscription_pilot_events')->insert(['tenant_id' => 202, 'member_id' => $id, 'event_key' => str_repeat('b', 64),
        'provider' => 'stripe', 'kind' => 'payment', 'provider_reference' => 'foreign', 'status' => 'success',
        'verification' => 'merchant_portal', 'occurred_at' => now(), 'observed_at' => now(), 'evidence' => $cipher]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('subscription pilot migration stops on changed named indexes without deleting evidence', function () {
    $migration = require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php');
    $migration->up();
    [$id, $cipher] = pilotRecoveryMember();
    Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->dropIndex('spe_cycle_idx'));
    Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->index(['status'], 'spe_cycle_idx'));
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'differs from the reviewed schema');
    expect(DB::table('subscription_pilot_members')->where('id', $id)->value('details'))->toBe($cipher);
});
