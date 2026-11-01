<?php

namespace Tests\Support;

/** Own in-memory schema without transaction wrappers or destructive legacy down migrations. */
class PosLiveTestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') === 'mysql' && getenv('POS_LIVE_DISPOSABLE_MYSQL') === '1') {
            $db = \Illuminate\Support\Facades\DB::connection();
            if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
                || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
                throw new \RuntimeException('Refusing POS fixtures outside local disposable MySQL.');
            }
            \App\Services\Pos\PosSchema::install();
            \App\Services\Inventory\StockSchema::install();
            // The MySQL recovery job builds its domain schemas directly, rather
            // than migrating the entire application before this suite. Install
            // these additive dependencies as well; never run their down methods.
            foreach ([
                '2026_11_01_100000_create_tenant_commerce_rule_sets.php',
                '2026_11_01_110000_create_pos_candle_cash_reservations.php',
                '2026_11_01_120000_create_pos_reward_authorizations.php',
                '2026_11_01_130000_create_pos_payment_start_claims.php',
                '2026_11_01_150000_create_pos_birthday_cycle_fences.php',
                '2026_11_01_160000_add_pos_reward_authorization_terms.php',
            ] as $migration) {
                $path = database_path('migrations/'.$migration);
                // The proof and start-claim migrations may land separately from the initial POS release.
                if (in_array($migration, ['2026_11_01_120000_create_pos_reward_authorizations.php',
                    '2026_11_01_130000_create_pos_payment_start_claims.php'], true) && ! is_file($path)) {
                    continue;
                }
                (require $path)->up();
            }
            foreach (['events', 'outbox', 'holds', 'sales', 'items', 'assignments', 'registers', 'connections', 'merchants'] as $name) {
                $db->table('pos_live_'.$name)->delete();
            }
            $db->table('payment_core_events')->delete();
            $db->table('payment_core_operations')->where('kind', 'refund')->delete();
            foreach (['payment_core_events', 'payment_core_operations', 'payment_core_bindings', 'payment_core_admissions'] as $table) {
                $db->table($table)->delete();
            }

            return;
        }
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('POS HTTP tests require an authorized disposable database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }
}
