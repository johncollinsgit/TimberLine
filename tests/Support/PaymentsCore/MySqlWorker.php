<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/MySqlFixture.php';

use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsCore\DurableFixtureProvider;
use Tests\Support\PaymentsCore\MySqlFixture;

function waitForBarrier(string $file): void
{
    $deadline = microtime(true) + 15;
    while (! file_exists($file) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($file)) {
        throw new RuntimeException('Payment concurrency barrier timed out.');
    }
}

try {
    MySqlFixture::guard();
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $provider = new DurableFixtureProvider;
    $provider->nextState = $data['scenario'] === 'register' ? 'processing' : 'succeeded';
    if ($data['scenario'] === 'effect') {
        $provider->fixedEffect = 'shared_fixture_effect';
    }
    $core = MySqlFixture::core($data['tenant'], $provider);
    $db = DB::connection();
    $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if ($data['scenario'] === 'refund') {
        // Establish every contender's old consistent RR snapshot INSIDE the
        // journal-owned transaction, before admission. Locked reads must still
        // see refunds committed by previous contenders after the barrier.
        $primed = false;
        $db->beforeExecuting(function (string $query) use (&$primed, $db, $data): void {
            if (! $primed && str_starts_with($query, 'INSERT INTO payment_core_admissions')) {
                $primed = true;
                $db->table('payment_core_operations')->where('tenant_id', $data['tenant'])->get();
                file_put_contents($data['directory'].'/snapshot-'.getmypid(), 'ready');
                waitForBarrier($data['directory'].'/snapshot-go');
            }
        });
    }
    file_put_contents($data['directory'].'/ready-'.getmypid(), 'ready');
    waitForBarrier($data['directory'].'/go');
    try {
        if ($data['scenario'] === 'refund') {
            $row = $core->refund(MySqlFixture::binding($data['tenant']), $data['payment_id'], 'refund_'.$data['index'], 300, 'other');
        } else {
            $row = $core->start(MySqlFixture::binding($data['tenant'], $data['register']),
                MySqlFixture::sale($data['sale']), $data['request']);
        }
        echo json_encode(['state' => $row['state'], 'id' => $row['id']], JSON_THROW_ON_ERROR);
    } catch (DomainException) {
        echo json_encode(['state' => 'blocked'], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
