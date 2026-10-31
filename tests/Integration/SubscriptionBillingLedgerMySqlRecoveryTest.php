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
        throw new RuntimeException('Refusing billing ledger recovery outside disposable local MySQL.');
    }
    Schema::dropIfExists('subscription_member_votes');
    Schema::dropIfExists('subscription_billing_actions');
    Schema::dropIfExists('subscription_billing_cycles');
    Schema::dropIfExists('subscription_billing_agreements');
    Schema::dropIfExists('subscription_pilot_cycles');
    Schema::dropIfExists('subscription_pilot_enrollments');
    Schema::dropIfExists('subscription_pilot_events');
    Schema::dropIfExists('subscription_pilot_members');
    (require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php'))->up();
    $this->billingDisposable = true;
});

afterEach(function () {
    if ($this->billingDisposable ?? false) {
        Schema::dropIfExists('subscription_billing_actions');
        Schema::dropIfExists('subscription_billing_cycles');
        Schema::dropIfExists('subscription_billing_agreements');
        Schema::dropIfExists('subscription_pilot_events');
        Schema::dropIfExists('subscription_pilot_members');
    }
});

function billingRecoveryRows(): array
{
    $cipher = Crypt::encryptString('{"method":"fictional encrypted sentinel"}');
    $member = DB::table('subscription_pilot_members')->insertGetId(['tenant_id' => 101, 'public_id' => '00000000-0000-4000-8000-000000000201',
        'shopify_store_id' => 11, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'target_date' => '2026-11-02',
        'timezone' => 'America/New_York', 'currency' => 'USD', 'details' => $cipher, 'prepared_by' => 1]);
    $agreement = DB::table('subscription_billing_agreements')->insertGetId(['tenant_id' => 101, 'member_id' => $member,
        'public_id' => '00000000-0000-4000-8000-000000000202', 'processor' => 'braintree', 'terms' => $cipher,
        'terms_hash' => str_repeat('a', 64), 'method_binding' => $cipher, 'authorization_evidence' => $cipher,
        'prepared_by' => 1, 'billing_anchor_date' => '2026-11-02']);

    return [$agreement, $cipher];
}

function billingRecoveryCycle(int $agreement, string $cipher, int $tenant = 101): array
{
    return ['tenant_id' => $tenant, 'agreement_id' => $agreement, 'public_id' => '00000000-0000-4000-8000-000000000203',
        'processor' => 'braintree', 'merchant_fingerprint' => str_repeat('b', 64), 'cycle_date' => '2026-11-02', 'agreement_revision' => 1,
        'amount_cents' => 2500, 'currency' => 'USD', 'terms' => $cipher, 'method_binding' => $cipher, 'source_evidence' => $cipher,
        'request_uuid' => '00000000-0000-4000-8000-000000000204', 'provider_request' => $cipher];
}

test('billing ledger recovery preserves frozen requests and mandates across partial DDL and rollback', function (string $partial) {
    $migration = require database_path('migrations/2026_10_30_220000_create_subscription_billing_ledger.php');
    $migration->up();
    [$agreement, $cipher] = billingRecoveryRows();
    if ($partial === 'parent-only') {
        Schema::drop('subscription_billing_actions');
        Schema::drop('subscription_billing_cycles');
    } else {
        DB::table('subscription_billing_cycles')->insert(billingRecoveryCycle($agreement, $cipher));
        if ($partial === 'constraints') {
            Schema::table('subscription_billing_cycles', fn (Blueprint $t) => $t->dropForeign('sbc_agreement_fk'));
            Schema::table('subscription_billing_cycles', fn (Blueprint $t) => $t->dropUnique('sbc_payment_uq'));
        }
    }
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('subscription_billing_agreements')->value('method_binding'))->toBe($cipher)
        ->and(Schema::hasIndex('subscription_billing_cycles', 'sbc_payment_uq'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('subscription_billing_cycles'))->firstWhere('name', 'sbc_agreement_fk')['on_delete'])->toBe('restrict');
    if ($partial !== 'parent-only') {
        expect(DB::table('subscription_billing_cycles')->value('provider_request'))->toBe($cipher);
    }
})->with(['parent-only', 'constraints', 'completed-before-bookkeeping']);

test('billing ledger composite ownership rejects foreign cycles and conflicting indexes retain evidence', function () {
    $migration = require database_path('migrations/2026_10_30_220000_create_subscription_billing_ledger.php');
    $migration->up();
    [$agreement, $cipher] = billingRecoveryRows();
    expect(fn () => DB::table('subscription_billing_cycles')->insert(billingRecoveryCycle($agreement, $cipher, 202)))->toThrow(\Illuminate\Database\QueryException::class);
    Schema::table('subscription_billing_cycles', fn (Blueprint $t) => $t->dropUnique('sbc_payment_uq'));
    Schema::table('subscription_billing_cycles', fn (Blueprint $t) => $t->index(['payment_status'], 'sbc_payment_uq'));
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(DB::table('subscription_billing_agreements')->value('method_binding'))->toBe($cipher);
});
