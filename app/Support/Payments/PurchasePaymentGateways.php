<?php

namespace App\Support\Payments;

use App\Models\Order;
use Carbon\CarbonImmutable;

/** Display evidence only. A gateway label never establishes payment success or billing authority. */
class PurchasePaymentGateways
{
    public function capture(array $metadata, array $payload): array
    {
        $names = $payload['payment_gateway_names'] ?? (isset($payload['gateway']) ? [$payload['gateway']] : null);
        $successful = [];
        foreach (is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [] as $transaction) {
            if (is_array($transaction) && strtolower((string) ($transaction['status'] ?? '')) === 'success'
                && in_array(strtolower((string) ($transaction['kind'] ?? '')), ['sale', 'capture'], true)
                && is_string($transaction['gateway'] ?? null)) {
                $successful[] = $transaction['gateway'];
            }
        }
        if ($successful !== []) {
            $names = $successful;
        }
        if (! is_array($names) || ! array_is_list($names)) {
            return $metadata;
        }
        $names = array_values(array_unique(array_filter(array_map(fn ($name) => is_string($name)
            ? trim(preg_replace('/[\x00-\x1f\x7f]/u', '', mb_substr($name, 0, 100))) : '', array_slice($names, 0, 12)))));
        if ($names === []) {
            return $metadata;
        }
        try {
            $at = isset($payload['updated_at']) ? CarbonImmutable::parse($payload['updated_at'])->toISOString() : null;
        } catch (\Throwable) {
            $at = null;
        }
        $prior = data_get($metadata, 'payment_gateways.source_updated_at');
        if (is_string($prior) && ($at === null || $at < $prior)) {
            return $metadata;
        }
        $metadata['payment_gateways'] = ['version' => 1, 'source' => 'shopify_order', 'names' => $names,
            'evidence' => $successful !== [] ? 'successful_transactions' : 'reported_gateways', 'source_updated_at' => $at];

        return $metadata;
    }

    public function forOrder(Order $order): array
    {
        $names = data_get($order->attribution_meta, 'payment_gateways.names', []);

        return $this->badges(is_array($names) ? $names : []);
    }

    public function badges(array $names): array
    {
        $badges = [];
        foreach (array_slice($names, 0, 12) as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $key = strtolower(trim(preg_replace('/[\s-]+/', '_', $name)));
            [$id, $label] = match ($key) {
                'stripe', 'everbranch_stripe', 'stripe_payments' => ['stripe', 'Stripe'],
                'braintree', 'everbranch_braintree', 'braintree_credit_card', 'braintree_paypal' => ['braintree', 'Braintree'],
                'paypal', 'paypal_express', 'paypal_express_checkout', 'paypal_express_checkout_(paypal)' => ['paypal', 'PayPal'],
                'shopify_payments', 'shopify_payment' => ['shopify', 'Shopify Payments'],
                'shop_pay', 'shopify_pay', 'shoppay' => ['shop-pay', 'Shop Pay'],
                'square', 'square_payments' => ['square', 'Square'],
                'gift_card', 'giftcard' => ['gift-card', 'Gift card'],
                'cash', 'cash_on_delivery_(cod)' => ['cash', 'Cash'],
                'manual' => ['manual', 'Manual payment'],
                default => ['other', mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name)), 0, 64)],
            };
            $badges[$id.'|'.$label] = ['id' => $id, 'label' => $label,
                'icon' => in_array($id, ['stripe', 'braintree', 'paypal', 'shopify', 'square'], true) ? '/brand/payments/'.$id.'.svg' : null];
        }

        return array_values($badges);
    }
}
