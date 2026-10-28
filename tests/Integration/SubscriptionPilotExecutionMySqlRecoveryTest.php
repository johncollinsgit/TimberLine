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
        throw new RuntimeException('Refusing execution recovery outside disposable local MySQL.');
    }
    Schema::dropIfExists('subscription_pilot_cycles');
    Schema::dropIfExists('subscription_pilot_enrollments');
    Schema::dropIfExists('subscription_pilot_events');
    Schema::dropIfExists('subscription_pilot_members');
    (require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php'))->up();
    $this->executionDisposable = true;
});

afterEach(function () {
    if ($this->executionDisposable ?? false) {
        Schema::dropIfExists('subscription_pilot_cycles');
        Schema::dropIfExists('subscription_pilot_enrollments');
        Schema::dropIfExists('subscription_pilot_events');
        Schema::dropIfExists('subscription_pilot_members');
    }
});

function executionRecoveryRows(): array
{
    $cipher = Crypt::encryptString('{"authorization":"fictional immutable sentinel"}');
    $member = DB::table('subscription_pilot_members')->insertGetId(['tenant_id' => 101, 'public_id' => '00000000-0000-4000-8000-000000000101',
        'shopify_store_id' => 11, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'target_date' => '2026-11-02',
        'timezone' => 'America/New_York', 'currency' => 'USD', 'details' => $cipher, 'prepared_by' => 1]);
    $enrollment = DB::table('subscription_pilot_enrollments')->insertGetId(['tenant_id' => 101, 'member_id' => $member,
        'public_id' => '00000000-0000-4000-8000-000000000102', 'terms' => $cipher, 'terms_hash' => str_repeat('a', 64), 'requests' => $cipher]);

    return [$member, $enrollment, $cipher];
}

function executionRecoveryCycle(int $enrollment, string $cipher, int $tenant = 101): array
{
    return ['tenant_id' => $tenant, 'enrollment_id' => $enrollment, 'public_id' => '00000000-0000-4000-8000-000000000103',
        'cycle_date' => '2026-11-02', 'amount_cents' => 2500, 'currency' => 'USD', 'terms' => $cipher, 'source_evidence' => $cipher,
        'stripe_key' => '00000000-0000-4000-8000-000000000104', 'stripe_payload' => $cipher];
}

test('execution recovery preserves original authorizations requests and payments through durable partial DDL', function (string $partial) {
    $migration = require database_path('migrations/2026_10_27_223000_create_subscription_pilot_execution.php');
    $migration->up();
    [$member,$enrollment,$cipher] = executionRecoveryRows();
    if ($partial === 'parent-only') {
        Schema::drop('subscription_pilot_cycles');
        Schema::table('subscription_pilot_enrollments', fn (Blueprint $t) => $t->dropUnique('spen_access_uq'));
    } else {
        DB::table('subscription_pilot_cycles')->insert(executionRecoveryCycle($enrollment, $cipher));
        if ($partial === 'constraints') {
            Schema::table('subscription_pilot_cycles', fn (Blueprint $t) => $t->dropForeign('spcy_enrollment_fk'));
            Schema::table('subscription_pilot_cycles', fn (Blueprint $t) => $t->dropUnique('spcy_payment_uq'));
        }
    }
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('subscription_pilot_enrollments')->where('id', $enrollment)->value('requests'))->toBe($cipher)
        ->and(Schema::hasIndex('subscription_pilot_enrollments', 'spen_access_uq'))->toBeTrue()
        ->and(Schema::hasIndex('subscription_pilot_cycles', 'spcy_payment_uq'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('subscription_pilot_cycles'))->firstWhere('name', 'spcy_enrollment_fk')['on_delete'])->toBe('restrict');
    if ($partial !== 'parent-only') {
        expect(DB::table('subscription_pilot_cycles')->value('stripe_payload'))->toBe($cipher);
    }
})->with(['parent-only', 'constraints', 'completed-before-bookkeeping']);

test('execution composite ownership refuses a cycle for another tenants authorization', function () {
    (require database_path('migrations/2026_10_27_223000_create_subscription_pilot_execution.php'))->up();
    [$member,$enrollment,$cipher] = executionRecoveryRows();
    expect(fn () => DB::table('subscription_pilot_cycles')->insert(executionRecoveryCycle($enrollment, $cipher, 202)))->toThrow(\Illuminate\Database\QueryException::class);
});

test('execution changed named index fails without changing original encrypted requests', function () {
    $migration = require database_path('migrations/2026_10_27_223000_create_subscription_pilot_execution.php');
    $migration->up();
    [$member,$enrollment,$cipher] = executionRecoveryRows();
    Schema::table('subscription_pilot_cycles', fn (Blueprint $t) => $t->dropUnique('spcy_payment_uq'));
    Schema::table('subscription_pilot_cycles', fn (Blueprint $t) => $t->index(['payment_status'], 'spcy_payment_uq'));
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(DB::table('subscription_pilot_enrollments')->where('id',$enrollment)->value('requests'))->toBe($cipher);
});
