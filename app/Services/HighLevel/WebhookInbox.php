<?php

namespace App\Services\HighLevel;

use App\Jobs\ProcessHighLevelWebhook;
use App\Models\HighLevel\WebhookEvent;
use Illuminate\Http\Request;

class WebhookInbox
{
    public function verifyHighLevel(Request $request): void
    {
        $signature = base64_decode((string) $request->header('X-GHL-Signature'), true);
        $key = base64_decode((string) config('highlevel.webhook_public_key'), true);
        abort_unless(is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($signature, $request->getContent(), $key), 401, 'Invalid webhook signature.');
    }

    public function accept(string $provider, array $payload, string $raw): WebhookEvent
    {
        $identifier = $provider === 'highlevel' ? ($payload['webhookId'] ?? null) : null;
        $key = hash('sha256', is_string($identifier) && $identifier !== '' ? $identifier : $raw);
        $event = WebhookEvent::firstOrCreate(['provider' => $provider, 'event_key' => $key], ['payload' => $payload, 'received_at' => now()]);
        if (! $event->processed_at) {
            ProcessHighLevelWebhook::dispatch($event->id)->onQueue(config('highlevel.queue'))->afterCommit();
        }

        return $event;
    }
}
