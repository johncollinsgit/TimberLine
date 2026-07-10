<?php

namespace App\Services\Marketing;

class SendGridEventWebhookSignatureVerifier
{
    public function verify(string $payload, ?string $signature, ?string $timestamp): bool
    {
        if (! app()->environment('production') && ! (bool) config('marketing.messaging.responses.verify_sendgrid_event_signature')) {
            return true;
        }

        $signature = trim((string) $signature);
        $timestamp = trim((string) $timestamp);
        $publicKey = $this->publicKey();
        if ($signature === '' || $timestamp === '' || $publicKey === null) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($timestamp.$payload, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function publicKey(): ?string
    {
        $configured = trim((string) config('marketing.messaging.responses.sendgrid_event_public_key'));
        if ($configured === '') {
            return null;
        }
        if (str_contains($configured, 'BEGIN PUBLIC KEY')) {
            return str_replace('\\n', "\n", $configured);
        }

        $decoded = base64_decode($configured, true);
        $der = $decoded !== false ? $decoded : $configured;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
