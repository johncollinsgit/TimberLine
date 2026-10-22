<?php

namespace App\SoftwareSupport\Transport;

/** Test protocol only: no production trust, keys, HTTP sender or identity binding is installed. */
final readonly class Packet
{
    public function __construct(public string $keyId, public int $issuedAt, public string $body, public string $signature) {}

    public function wire(): array
    {
        return ['key_id' => $this->keyId, 'issued_at' => $this->issuedAt, 'body' => $this->body, 'signature' => $this->signature];
    }

    public static function frame(string $direction, string $keyId, int $issuedAt, string $body): string
    {
        return "evergrove.software-support.test.v1\n{$direction}\n{$keyId}\n{$issuedAt}\n{$body}";
    }
}
