<?php

namespace App\SoftwareSupport\Transport;

use Illuminate\Support\Facades\Validator;

final class TestVerifier
{
    /** Exact key ID => ['public_key' => bytes, 'issuer' => opaque runtime ref]. Never an app key. */
    public function __construct(private readonly array $trustedKeys) {}

    public function verify(string $direction, string $audience, array $wire): array
    {
        abort_unless(app()->environment('testing'), 403);
        Validator::make(['packet' => $wire], ['packet' => ['array:key_id,issued_at,body,signature'],
            'packet.key_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]{1,80}$/D'],
            'packet.issued_at' => ['required', 'integer'], 'packet.body' => ['required', 'string', 'max:32768'],
            'packet.signature' => ['required', 'string', 'max:100'],
        ])->validate();
        $key = $this->trustedKeys[$wire['key_id']] ?? null;
        $signature = base64_decode($wire['signature'], true);
        abort_unless($key && is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && strlen($key['public_key']) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && $wire['issued_at'] >= now()->timestamp - 60 && $wire['issued_at'] <= now()->timestamp + 5, 401);
        abort_unless(sodium_crypto_sign_verify_detached($signature,
            Packet::frame($direction, $wire['key_id'], (int) $wire['issued_at'], $wire['body']), $key['public_key']), 401);
        try {
            $body = json_decode($wire['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422);
        }
        abort_unless(is_array($body) && ($body['issuer'] ?? null) === $key['issuer'] && ($body['audience'] ?? null) === $audience, 401);

        return $body;
    }
}
