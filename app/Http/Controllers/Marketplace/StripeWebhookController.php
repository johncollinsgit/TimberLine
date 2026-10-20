<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\PaymentEvent;
use App\Services\Marketplace\MarketplaceNativeCheckout;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function receive(Request $request)
    {
        $raw = $request->getContent();
        $secret = (string) config('marketplace.stripe_webhook_secret');
        $parts = [];
        foreach (explode(',', (string) $request->header('Stripe-Signature')) as $piece) {
            $pair = explode('=', $piece, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]][] = $pair[1];
            }
        }
        $time = $parts['t'][0] ?? '';
        abort_unless($secret !== '' && strlen($raw) <= 2000000 && ctype_digit($time) && abs(time() - (int) $time) <= 300, 401);
        $expected = hash_hmac('sha256', $time.'.'.$raw, $secret);
        abort_unless(collect($parts['v1'] ?? [])->contains(fn ($signature) => hash_equals($expected, $signature)), 401);
        $payload = json_decode($raw, true, 128);
        abort_unless(is_array($payload) && preg_match('/^evt_[A-Za-z0-9]+$/D', $payload['id'] ?? '') && preg_match('/^acct_[A-Za-z0-9]+$/D', $payload['account'] ?? ''), 400);
        $event = PaymentEvent::firstOrCreate(['provider' => 'stripe', 'merchant_key' => $payload['account'], 'event_id' => $payload['id']], ['payload_hash' => hash('sha256', $raw), 'payload' => $payload]);
        abort_unless(hash_equals($event->payload_hash, hash('sha256', $raw)), 409);
        $this->process($event);

        return response()->json(['received' => true], $event->fresh()->status === 'failed' ? 202 : 200);
    }

    public function process(PaymentEvent $event): void
    {
        if ($event->status === 'processed') {
            return;
        }
        try {
            $payload = $event->payload;
            $object = $payload['data']['object'] ?? [];
            $uuid = $object['metadata']['marketplace_checkout'] ?? null;
            $checkout = $uuid ? Checkout::where('uuid', $uuid)->where('provider', 'stripe')->first() : Checkout::where('provider_payment_ref', is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : ($object['id'] ?? ''))->where('provider', 'stripe')->first();
            if ($checkout) {
                abort_unless($payload['account'] === $checkout->snapshot['merchant']['account_id'] && (bool) $payload['livemode'] === (bool) $checkout->snapshot['merchant']['livemode'], 422);
                if (str_starts_with($payload['type'], 'checkout.session.')) {
                    abort_unless(($object['metadata']['marketplace_nonce'] ?? '') === $checkout->snapshot['nonce'], 422);
                    if (! $checkout->provider_session_ref) {
                        $checkout->forceFill(['provider_session_ref' => $object['id'], 'provider_cart' => [...($checkout->provider_cart ?? []), 'id' => $object['id']]])->save();
                    }
                    abort_unless($checkout->provider_session_ref === $object['id'], 422);
                }
                $order = app(MarketplaceNativeCheckout::class)->reconcile($checkout->fresh());
                if ($order && $order->test_mode && ! $order->review_required) {
                    $settings = app(\App\Services\Marketplace\MarketplaceAccess::class)->settings();
                    $settings->forceFill(['readiness' => [...($settings->readiness ?? []), 'stripe_test_account_id' => $checkout->snapshot['merchant']['account_id'], 'stripe_test_store_id' => $checkout->snapshot['merchant']['store_id'], 'stripe_test_order_id' => $order->id]])->save();
                }
            }
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'failure_code' => null])->save();
        } catch (\Throwable) {
            $event->forceFill(['status' => 'failed', 'failure_code' => 'native_reconciliation_required'])->save();
        }
    }
}
