<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\PaymentEvent;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplacePayPal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayPalWebhookController extends Controller
{
    public function receive(Request $request, MarketplacePayPal $paypal)
    {
        abort_unless(config('marketplace.paypal_webhook_id'), 423);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $event = $request->json()->all();
        abort_unless($paypal->id($event['id'] ?? null) && is_string($event['event_type'] ?? null), 400);
        $headers = [];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $key) {
            $value = $request->header('PAYPAL-'.strtoupper(str_replace('_', '-', $key)));
            abort_unless(is_string($value) && strlen($value) > 0 && strlen($value) <= 2048, 400);
            $headers[$key] = $value;
        }
        $r = $paypal->client()->post('/v1/notifications/verify-webhook-signature', [...$headers, 'webhook_id' => config('marketplace.paypal_webhook_id'), 'webhook_event' => $event]);
        abort_unless($r->successful() && $r->json('verification_status') === 'SUCCESS', 401);
        $resource = $event['resource'] ?? [];
        $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
        if (str_starts_with($event['event_type'], 'CHECKOUT.ORDER.')) {
            $orderId = $resource['id'] ?? null;
        }
        $checkout = $orderId ? Checkout::where('provider', 'paypal')->where('provider_session_ref', $orderId)->first() : null;
        if (! $checkout && str_contains($event['event_type'], 'REFUND')) {
            $uuid = Str::after($resource['invoice_id'] ?? '', 'MM-REFUND-');
            $op = Operation::where('uuid', $uuid)->where('kind', 'paypal_refund')->first();
            $checkout = $op ? \App\Models\Marketplace\OrderLine::find($op->subject_id)?->order?->checkout : null;
        }
        if (! $checkout && str_contains($event['event_type'], 'DISPUTE')) {
            $capture = data_get($resource, 'disputed_transactions.0.seller_transaction_id');
            $checkout = $capture ? Checkout::where('provider', 'paypal')->where('provider_payment_ref', $capture)->first() : null;
        }
        if (! $checkout) {
            return response()->json(['received' => true]);
        }
        $row = PaymentEvent::firstOrCreate(['provider' => 'paypal', 'merchant_key' => $checkout->snapshot['paypal']['merchant_id'], 'event_id' => $event['id']], ['payload_hash' => hash('sha256', $request->getContent()), 'payload' => ['checkout_id' => $checkout->id, 'event' => $event], 'status' => 'pending']);
        abort_unless(hash_equals($row->payload_hash, hash('sha256', $request->getContent())), 409);
        $this->process($row);

        return response()->json(['received' => true], $row->fresh()->status === 'failed' ? 202 : 200);
    }

    public function process(PaymentEvent $event): void
    {
        if ($event->status === 'processed') {
            return;
        }
        try {
            abort_unless($event->provider === 'paypal', 422);
            $checkout = Checkout::where('provider', 'paypal')->findOrFail($event->payload['checkout_id']);
            abort_unless($checkout->snapshot['paypal']['merchant_id'] === $event->merchant_key, 422);
            $order = app(MarketplacePayPal::class)->reconcile($checkout);
            if ($order && str_contains($event->payload['event']['event_type'], 'DISPUTE')) {
                $order->update(['review_required' => true, 'review_reason' => 'A PayPal dispute needs merchant review.']);
            }
            if ($order && $order->test_mode && ! $order->fresh()->review_required) {
                $store = app(MarketplaceAccess::class)->store();
                if ($store && $checkout->snapshot['paypal']['store_id'] === $store->id) {
                    $store->update(['settings' => [...($store->settings ?? []), 'paypal_test_store_id' => $store->id]]);
                }
            }
            $event->update(['status' => 'processed', 'processed_at' => now(), 'failure_code' => null]);
        } catch (\Throwable) {
            $event->update(['status' => 'failed', 'failure_code' => 'paypal_reconciliation_required']);
        }
    }
}
