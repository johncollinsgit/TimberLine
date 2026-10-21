<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsitePayment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WebsiteStripeService
{
    public const API_VERSION = '2025-06-30.basil';

    public function client(?WebsitePayment $payment = null): PendingRequest
    {
        if ($payment) {
            WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id)->assertNotPosSimulation();
        }
        $key = (string) config('services.stripe.secret');
        abort_unless(str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_'), 423, 'Stripe is not configured.');
        if ($payment) {
            $fingerprint = data_get($payment->metadata, 'fingerprint_version') === 2 ? $this->accountFingerprint() : $this->fingerprint();
            abort_unless(hash_equals((string) data_get($payment->metadata, 'platform_fingerprint', ''), $fingerprint), 423, 'The payment account changed. Review this checkout before retrying.');
        }

        $client = Http::asForm()->withBasicAuth($key, '')->acceptJson()->timeout(20)
            ->withHeaders(['Stripe-Version' => (string) data_get($payment?->metadata, 'api_version', self::API_VERSION)]);
        if (data_get($payment?->metadata, 'routing_mode', 'destination') === 'direct') {
            $account = (string) data_get($payment->metadata, 'account_id');
            abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $account), 423, 'The merchant account needs verification.');
            $client = $client->withHeaders(['Stripe-Account' => $account]);
        }

        return $client;
    }

    public function fingerprint(): string
    {
        return hash_hmac('sha256', (string) config('services.stripe.secret'), (string) config('app.key'));
    }

    public function live(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_live_');
    }

    public function accountFingerprint(): string
    {
        $platform = (string) config('services.stripe.account_id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $platform), 423, 'The Stripe platform identity is not configured.');

        return hash('sha256', $platform.'|'.($this->live() ? 'live' : 'test'));
    }

    public function metadata(WebsitePayment $payment): array
    {
        return ['website_payment_id' => (string) $payment->id, 'website_order_id' => (string) $payment->website_order_id,
            'website_tenant_id' => (string) $payment->tenant_id, 'checkout_nonce' => (string) data_get($payment->metadata, 'nonce')];
    }

    public function matches(WebsitePayment $payment, array $metadata): bool
    {
        foreach ($this->metadata($payment) as $key => $value) {
            if (! is_scalar($metadata[$key] ?? null) || ! hash_equals($value, (string) $metadata[$key])) {
                return false;
            }
        }

        return true;
    }

    public function event(WebsiteOrder $order, string $type, string $message, array $data = [], ?int $actorId = null): void
    {
        $order->assertNotPosSimulation();
        WebsiteOrderEvent::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
            'user_id' => $actorId, 'event_type' => $type, 'visibility' => 'staff', 'message' => $message, 'data' => $data]);
    }

    public function retryMessage(): never
    {
        throw ValidationException::withMessages(['cart' => 'Checkout could not be confirmed yet. Retry to recover the same checkout; your stock is still held.']);
    }
}
