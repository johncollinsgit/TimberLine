<?php

use App\Services\Team\Operations\OperationsReadTransport;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Symfony\Component\Process\Process;

test('independent read clients reuse a scoped socket with fresh credentials and no cookies', function () {
    if (! function_exists('curl_init')) {
        $this->markTestSkipped('Actual cURL keepalive verification requires ext-curl.');
    }
    $server = new Process(['python3', dirname(__DIR__).'/Support/operations-read-transport-server.py'], timeout: 10);
    $port = null;
    try {
        $server->start();
        $server->waitUntil(function ($type, $output) use (&$port) {
            if ($type === Process::OUT && preg_match('/^(\d+)\s*$/', trim($output), $match)) {
                $port = (int) $match[1];

                return true;
            }

            return false;
        });
        expect($port)->toBeInt()->toBeGreaterThan(0);
        $shared = new OperationsReadTransport;
        $client = fn ($transport) => new Client(['handler' => HandlerStack::create($transport), 'cookies' => false, 'connect_timeout' => 1, 'timeout' => 3]);
        $read = fn ($transport, $token) => json_decode((string) $client($transport)->get('http://127.0.0.1:'.$port.'/', ['headers' => ['Authorization' => 'Bearer '.$token]])->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $first = $read($shared, 'fictional-first');
        $second = $read($shared, 'fictional-second');
        $nextScope = $read(new OperationsReadTransport, 'fictional-next-scope');
        expect($first)->toBe(['connections' => 1, 'authorization' => 'Bearer fictional-first', 'cookie' => null])
            ->and($second)->toBe(['connections' => 1, 'authorization' => 'Bearer fictional-second', 'cookie' => null])
            ->and($nextScope)->toBe(['connections' => 2, 'authorization' => 'Bearer fictional-next-scope', 'cookie' => null]);
    } finally {
        $server->stop(0.2);
    }
});
