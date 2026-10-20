<?php

namespace Tests\Support;

use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsiteStripeService;

/** Provider fixture bound to a particular installment, never the latest payment on an order. */
class WebsiteInvoiceStripeFixture
{
    public static function session(WebsitePayment $payment, string $state = 'open'): array
    {
        $order = WebsiteOrder::query()->forTenantId($payment->tenant_id)->findOrFail($payment->website_order_id);
        if (data_get($payment->metadata, 'checkout_version') === 1) {
            $session = WebsiteStripeFixture::session($order);
            if (data_get($payment->metadata, 'routing_mode') === 'direct') {
                $session['automatic_tax']['liability'] = ['type' => 'self'];
                unset($session['payment_intent']['on_behalf_of'], $session['payment_intent']['transfer_data']);
            }
        } else {
            $metadata = app(WebsiteStripeService::class)->metadata($payment);
            $customer = data_get($payment->metadata, 'customer_id');
            $session = ['id' => 'cs_invoicefixture_'.$payment->id, 'mode' => 'payment', 'livemode' => false,
                'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'usd', 'client_reference_id' => (string) $order->id,
                'customer' => $customer, 'metadata' => $metadata, 'amount_total' => $payment->amount_cents, 'amount_subtotal' => $payment->amount_cents,
                'payment_method_types' => [data_get($payment->metadata, 'payment_method', 'card')],
                'total_details' => ['amount_tax' => 0, 'amount_shipping' => 0, 'amount_discount' => 0], 'automatic_tax' => ['enabled' => false],
                'payment_intent' => ['id' => 'pi_invoicefixture_'.$payment->id, 'status' => 'succeeded', 'livemode' => false,
                    'amount' => $payment->amount_cents, 'amount_received' => $payment->amount_cents, 'currency' => 'usd', 'customer' => $customer,
                    'metadata' => $metadata, 'application_fee_amount' => 0,
                    'latest_charge' => ['id' => 'ch_invoicefixture_'.$payment->id, 'status' => 'succeeded',
                        'payment_method_details' => ['type' => data_get($payment->metadata, 'payment_method', 'card')],
                        'balance_transaction' => ['id' => 'txn_fixture_'.$payment->id,
                            'fee' => 100, 'net' => $payment->amount_cents - 100, 'currency' => 'usd']]]];
        }
        $session['id'] = $payment->provider_session_id ?: 'cs_invoicefixture_'.$payment->id;
        $session['url'] = 'https://checkout.stripe.com/c/pay/'.$session['id'];
        if (in_array($state, ['processing', 'bank_failed', 'bank_canceled'], true)) {
            $session['status'] = 'complete';
            $session['payment_status'] = 'unpaid';
            $session['payment_intent']['status'] = match ($state) {
                'processing' => 'processing', 'bank_failed' => 'requires_payment_method', default => 'canceled'
            };
            $session['payment_intent']['amount_received'] = 0;
            $session['payment_intent']['latest_charge']['status'] = $state === 'processing' ? 'pending' : 'failed';
        } elseif ($state !== 'paid') {
            $session['status'] = $state;
            $session['payment_status'] = 'unpaid';
            $session['payment_intent'] = null;
        }

        return $session;
    }

    public static function event(WebsitePayment $payment, string $id = 'evt_invoicefixture_paid', string $type = 'checkout.session.completed', string $state = 'paid'): array
    {
        $event = ['id' => $id, 'type' => $type, 'livemode' => false,
            'data' => ['object' => self::session($payment, $state)]];
        if (data_get($payment->metadata, 'routing_mode') === 'direct') {
            $event['account'] = data_get($payment->metadata, 'account_id');
        }

        return $event;
    }
}
