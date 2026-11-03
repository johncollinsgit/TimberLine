<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable POS MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing reward recovery outside local disposable MySQL.');
    }
});

it('restores missing indexes and preserves held evidence through replay and rollback', function (): void {
    $migration = require database_path('migrations/2026_11_01_110000_create_pos_candle_cash_reservations.php');
    $migration->up();
    $id = (string) Str::uuid();
    $row = ['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'sale_id' => bin2hex(random_bytes(16)), 'marketing_profile_id' => 900000002, 'amount_minor' => 500,
        'policy_version' => 'test-v1', 'authorization_reference' => 'proof-test', 'state' => 'held',
        'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_candle_cash_reservations')->insert($row);
    try {
        $before = (array) DB::table('pos_candle_cash_reservations')->where('id', $id)->first();
        Schema::table('pos_candle_cash_reservations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_sale_unique');
            $table->dropIndex('pos_reward_customer_index');
        });
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('pos_candle_cash_reservations')->where('id', $id)->first())->toBe($before)
            ->and(Schema::hasIndex('pos_candle_cash_reservations', 'pos_reward_sale_unique'))->toBeTrue()
            ->and(Schema::hasIndex('pos_candle_cash_reservations', 'pos_reward_customer_index'))->toBeTrue();
        $duplicate = $row;
        $duplicate['id'] = (string) Str::uuid();
        expect(fn () => DB::table('pos_candle_cash_reservations')->insert($duplicate))->toThrow(QueryException::class);
        Schema::table('pos_candle_cash_reservations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_sale_unique');
            $table->index('tenant_id', 'pos_reward_sale_unique');
        });
        try {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS reward index definition changed');
            expect(DB::table('pos_candle_cash_reservations')->where('id', $id)->value('state'))->toBe('held');
        } finally {
            Schema::table('pos_candle_cash_reservations', fn (Blueprint $table) => $table->dropIndex('pos_reward_sale_unique'));
            $migration->up();
        }
    } finally {
        DB::table('pos_candle_cash_reservations')->where('id', $id)->delete();
    }
});

it('recovers customer authorization indexes without exposing or replacing a bound proof', function (): void {
    $migration = require database_path('migrations/2026_11_01_120000_create_pos_reward_authorizations.php');
    $migration->up();
    $id = (string) Str::uuid();
    $row = ['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'marketing_profile_id' => 900000002, 'token_hash' => hash('sha256', (string) Str::uuid()),
        'sale_id' => bin2hex(random_bytes(16)), 'bound_at' => now(), 'expires_at' => now()->addMinutes(2),
        'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_reward_authorizations')->insert($row);
    try {
        $before = (array) DB::table('pos_reward_authorizations')->where('id', $id)->first();
        Schema::table('pos_reward_authorizations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_auth_token_uq');
            $table->dropIndex('pos_reward_auth_customer_idx');
        });
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('pos_reward_authorizations')->where('id', $id)->first())->toBe($before)
            ->and(Schema::hasIndex('pos_reward_authorizations', 'pos_reward_auth_token_uq'))->toBeTrue()
            ->and(Schema::hasIndex('pos_reward_authorizations', 'pos_reward_auth_customer_idx'))->toBeTrue();
        $duplicate = $row;
        $duplicate['id'] = (string) Str::uuid();
        expect(fn () => DB::table('pos_reward_authorizations')->insert($duplicate))->toThrow(QueryException::class);
        Schema::table('pos_reward_authorizations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_auth_token_uq');
            $table->index('tenant_id', 'pos_reward_auth_token_uq');
        });
        try {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS reward authorization index definition changed');
            expect(DB::table('pos_reward_authorizations')->where('id', $id)->value('sale_id'))->toBe($row['sale_id']);
        } finally {
            Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->dropIndex('pos_reward_auth_token_uq'));
            $migration->up();
        }
    } finally {
        DB::table('pos_reward_authorizations')->where('id', $id)->delete();
    }
});

it('preserves capped authorization evidence after partial terms migration and rollback', function (): void {
    (require database_path('migrations/2026_11_01_120000_create_pos_reward_authorizations.php'))->up();
    $migration = require database_path('migrations/2026_11_01_160000_add_pos_reward_authorization_terms.php');
    $migration->up();
    $id = (string) Str::uuid();
    DB::table('pos_reward_authorizations')->insert(['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'marketing_profile_id' => 900000002, 'token_hash' => hash('sha256', (string) Str::uuid()), 'cap_minor' => 500,
        'policy_version' => str_repeat('a', 64), 'disclosure_version' => 'capped_scan_v1',
        'expires_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now()]);
    try {
        Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->dropColumn(['policy_version', 'disclosure_version']));
        $migration->up();
        $migration->down();
        $migration->up();
        $row = DB::table('pos_reward_authorizations')->where('id', $id)->first();
        expect((int) $row->cap_minor)->toBe(500)->and($row->policy_version)->toBeNull()
            ->and(Schema::hasColumn('pos_reward_authorizations', 'disclosure_version'))->toBeTrue();
    } finally {
        DB::table('pos_reward_authorizations')->where('id', $id)->delete();
    }
});

it('recovers the durable start fence indexes while retaining a starting claim', function (): void {
    $migration = require database_path('migrations/2026_11_01_130000_create_pos_payment_start_claims.php');
    $migration->up();
    $id = (string) Str::uuid();
    $row = ['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'sale_id' => bin2hex(random_bytes(16)), 'payment_request_id' => (string) Str::uuid(), 'state' => 'starting',
        'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_payment_start_claims')->insert($row);
    try {
        $before = (array) DB::table('pos_payment_start_claims')->where('id', $id)->first();
        Schema::table('pos_payment_start_claims', function (Blueprint $table): void {
            $table->dropUnique('pos_start_claim_sale_uq');
            $table->dropUnique('pos_start_claim_request_uq');
        });
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('pos_payment_start_claims')->where('id', $id)->first())->toBe($before)
            ->and(Schema::hasIndex('pos_payment_start_claims', 'pos_start_claim_sale_uq'))->toBeTrue()
            ->and(Schema::hasIndex('pos_payment_start_claims', 'pos_start_claim_request_uq'))->toBeTrue();
        $duplicate = $row;
        $duplicate['id'] = (string) Str::uuid();
        expect(fn () => DB::table('pos_payment_start_claims')->insert($duplicate))->toThrow(QueryException::class);
        Schema::table('pos_payment_start_claims', function (Blueprint $table): void {
            $table->dropUnique('pos_start_claim_sale_uq');
            $table->index('tenant_id', 'pos_start_claim_sale_uq');
        });
        try {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS payment start claim index definition changed');
            expect(DB::table('pos_payment_start_claims')->where('id', $id)->value('state'))->toBe('starting');
        } finally {
            Schema::table('pos_payment_start_claims', fn (Blueprint $table) => $table->dropIndex('pos_start_claim_sale_uq'));
            $migration->up();
        }
    } finally {
        DB::table('pos_payment_start_claims')->where('id', $id)->delete();
    }
});
