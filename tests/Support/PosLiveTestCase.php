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
