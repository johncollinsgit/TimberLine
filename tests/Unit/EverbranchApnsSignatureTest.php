<?php

use App\Services\Mobile\EverbranchApnsService;

test('Everbranch ES256 signatures retain both integers and verify with the public key', function (): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $publicKey = openssl_pkey_get_details($key)['key'];
    $convert = new ReflectionMethod(EverbranchApnsService::class, 'derToJose');
    $service = new EverbranchApnsService;

    for ($iteration = 0; $iteration < 32; $iteration++) {
        $payload = 'APNs signature probe '.$iteration;
        openssl_sign($payload, $der, $key, OPENSSL_ALGO_SHA256);
        $encoded = $convert->invoke($service, $der, 64);
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        expect(strlen($raw))->toBe(64);
        $integers = '';
        foreach ([substr($raw, 0, 32), substr($raw, 32)] as $integer) {
            $integer = ltrim($integer, "\x00");
            if (ord($integer[0]) & 0x80) {
                $integer = "\x00".$integer;
            }
            $integers .= "\x02".chr(strlen($integer)).$integer;
        }
        $reconstructed = "\x30".chr(strlen($integers)).$integers;
        expect(openssl_verify($payload, $reconstructed, $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
    }
});
