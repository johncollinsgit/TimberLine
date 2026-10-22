<?php

namespace App\SoftwareSupport\Transport;

final class TestSigner
{
    public function __construct(private readonly string $keyId, private readonly string $secretKey)
    {
        abort_unless(app()->environment('testing') && strlen($secretKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 403);
    }

    public function sign(string $direction, string $body, ?int $issuedAt = null): Packet
    {
        abort_unless(app()->environment('testing'), 403);
        $issuedAt ??= now()->timestamp;
        $signature = sodium_crypto_sign_detached(Packet::frame($direction, $this->keyId, $issuedAt, $body), $this->secretKey);

        return new Packet($this->keyId, $issuedAt, $body, base64_encode($signature));
    }
}
