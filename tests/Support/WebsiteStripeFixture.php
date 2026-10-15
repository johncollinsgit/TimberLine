<?php

namespace Tests\Support;

use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Support\Facades\Http;

class WebsiteStripeFixture
{
    public static function support(): array
    {
        return [
            'https://api.stripe.com/v1/customers' => fn ($request) => Http::response(['id' => 'cus_fixture_'.preg_replace('/\D/', '', $request->header('Idempotency-Key')[0])]),
            'https://api.stripe.com/v1/checkout/sessions/*' => function ($request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));
                $payment = WebsitePayment::query()->where('provider_session_id', $id)->firstOrFail();

                return Http::response(self::session(WebsiteOrder::query()->findOrFail($payment->website_order_id)));
            },
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [], 'has_more' => false]),
        ];
    }

    public static function session(WebsiteOrder $order, int $tax = 0): array
    {
        $payment = $order->payments()->latest('id')->firstOrFail();
        $metadata = app(WebsiteStripeService::class)->metadata($payment);
        $account = $payment->metadata['account_id'];
        $total = $order->subtotal_cents + $order->shipping_cents + $tax;
        $address = data_get($order->service_request, 'checkout.tax_address');
        $customer = ['id' => $payment->metadata['customer_id']];
        if ($address) {
            foreach (['street1' => 'line1', 'street2' => 'line2', 'city' => 'city', 'state' => 'state', 'zip' => 'postal_code', 'country' => 'country'] as $local => $remote) {
                $customer['shipping']['address'][$remote] = $address[$local] ?? '';
            }
        }

        return ['id' => $payment->provider_session_id, 'mode' => 'payment', 'livemode' => false, 'status' => 'complete', 'payment_status' => 'paid',
            'metadata' => $metadata, 'client_reference_id' => (string) $order->id, 'currency' => 'usd', 'amount_subtotal' => $order->subtotal_cents, 'amount_total' => $total,
            'total_details' => ['amount_tax' => $tax, 'amount_shipping' => $order->shipping_cents, 'amount_discount' => 0],
            'automatic_tax' => ['enabled' => true, 'status' => 'complete', 'liability' => ['type' => 'account', 'account' => $account]],
            'customer' => $customer, 'customer_details' => ['email' => data_get($order->customer_snapshot, 'email')],
            'payment_intent' => ['id' => 'pi_fixture_'.$payment->id, 'status' => 'succeeded', 'livemode' => false, 'currency' => 'usd', 'amount' => $total, 'amount_received' => $total,
                'customer' => $customer['id'], 'metadata' => $metadata, 'transfer_data' => ['destination' => $account], 'on_behalf_of' => $account, 'application_fee_amount' => $payment->metadata['application_fee_cents']],
        ];
    }

    public static function event(WebsiteOrder $order, string $id = 'evt_fixture_paid', string $type = 'checkout.session.completed'): array
    {
        return ['id' => $id, 'type' => $type, 'livemode' => false, 'data' => ['object' => self::session($order)]];
    }

    public static function origin($site): void
    {
        WebsiteFulfillmentLocation::query()->create(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'name' => 'Pickup', 'active' => true, 'is_default' => true,
            'address' => ['name' => 'Pickup', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US']]);
    }
}
