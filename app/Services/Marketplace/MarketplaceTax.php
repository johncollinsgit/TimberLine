<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Checkout;

/** Tax calculated in the marketplace merchant account, never from browser amounts. */
class MarketplaceTax
{
    public function quote(Checkout $checkout, array $address): array
    {
        abort_unless(($address['country'] ?? '') === 'US' && collect(['line1', 'city', 'state', 'postal_code'])->every(fn ($key) => ! empty($address[$key])), 422, 'A complete US delivery address is required.');
        $payload = ['expand' => ['line_items'], 'currency' => 'usd', 'customer_details' => ['address' => $address, 'address_source' => 'shipping'], 'line_items' => array_map(fn ($line) => ['amount' => $line['net_unit_cents'] * $line['quantity'], 'reference' => 'MM-'.$line['listing_id'], 'quantity' => $line['quantity'], 'tax_behavior' => 'exclusive'], $checkout->snapshot['lines']), 'shipping_cost' => ['amount' => $checkout->snapshot['shipping_cents'], 'tax_behavior' => 'exclusive']];
        $r = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->timeout(8)->post('https://api.stripe.com/v1/tax/calculations', $payload);
        abort_unless($r->successful() && preg_match('/^taxcalc_[A-Za-z0-9]+$/D', (string) $r->json('id')) && $r->json('tax_amount_inclusive') === 0 && is_int($r->json('tax_amount_exclusive')) && $r->json('tax_amount_exclusive') >= 0, 422, 'The merchant could not confirm tax for this delivery.');
        $net = collect($checkout->snapshot['lines'])->sum(fn ($line) => $line['net_unit_cents'] * $line['quantity']);
        abort_unless($r->json('amount_total') === $net + $checkout->snapshot['shipping_cents'] + $r->json('tax_amount_exclusive'), 422, 'The tax quote total changed.');
        $expected = collect($checkout->snapshot['lines'])->keyBy(fn ($l) => 'MM-'.$l['listing_id']);
        $lineTax = [];
        $items = $r->json('line_items.data', []);
        $more = $r->json('line_items.has_more', true);
        $pages = 0;
        while ($more && $pages++ < 3) {
            $last = end($items)['id'] ?? '';
            abort_unless(preg_match('/^tax_li_[A-Za-z0-9]+$/D', $last), 422, 'A complete tax breakdown is required.');
            $page = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->get('https://api.stripe.com/v1/tax/calculations/'.$r->json('id').'/line_items', ['limit' => 100, 'starting_after' => $last]);
            abort_unless($page->successful() && is_array($page->json('data')), 422);
            $items = [...$items, ...$page->json('data')];
            $more = $page->json('has_more', true);
        }
        abort_if($more, 422, 'A complete tax breakdown is required.');
        foreach ($items as $item) {
            $line = $expected->get($item['reference'] ?? '');
            abort_unless($line && ! isset($lineTax[$line['listing_id']]) && $item['quantity'] === $line['quantity'] && $item['amount'] === $line['net_unit_cents'] * $line['quantity'] && is_int($item['amount_tax']) && $item['amount_tax'] >= 0, 422);
            $lineTax[$line['listing_id']] = $item['amount_tax'];
        }
        $shippingTax = (int) $r->json('shipping_cost.amount_tax', 0);
        abort_unless(count($lineTax) === $expected->count() && array_sum($lineTax) + $shippingTax === $r->json('tax_amount_exclusive'), 422, 'The item tax breakdown could not be balanced.');

        return ['line_tax_cents' => $lineTax, 'shipping_tax_cents' => $shippingTax, 'calculation_id' => $r->json('id'), 'tax_cents' => $r->json('tax_amount_exclusive'), 'total_cents' => $r->json('amount_total'), 'expires_at' => min(now()->addMinutes(15)->timestamp, (int) $r->json('expires_at', now()->addMinutes(15)->timestamp)), 'address' => $address];
    }

    public function post(Checkout $checkout): array
    {
        $input = ['calculation' => $checkout->provider_cart['tax_quote']['calculation_id'], 'reference' => 'marketplace-'.$checkout->provider.'-'.$checkout->uuid];

        return $this->write($checkout, 'tax_sale:'.$checkout->uuid, $checkout->provider.'_tax_sale', $input, 'create_from_calculation');
    }

    public function refund(Checkout $checkout, string $refundId, int $amount, array $original): void
    {
        $input = ['mode' => 'partial', 'original_transaction' => $original['id'], 'reference' => 'marketplace-'.$checkout->provider.'-refund-'.$refundId, 'flat_amount' => -$amount];
        $this->write($checkout, 'tax_refund:'.$checkout->uuid.':'.$refundId, $checkout->provider.'_tax_refund', $input, 'create_reversal');
    }

    private function write(Checkout $checkout, string $key, string $kind, array $input, string $path): array
    {
        $ops = app(MarketplaceNativeOperation::class);

        return $ops->run($ops->key($key), $kind, $checkout->id, ['merchant' => $checkout->snapshot['merchant'], 'input' => $input], function () use ($checkout, $input, $path, $ops, $key): array {
            $r = app(MarketplaceNativeCheckout::class)->client($checkout->snapshot['merchant'])->withHeaders(['Idempotency-Key' => $ops->key($key)])->post('https://api.stripe.com/v1/tax/transactions/'.$path, $input);
            abort_unless($r->successful() && is_string($r->json('id')),502,'Payment tax posting needs reconciliation.');

            return $r->json();
        });
    }
}
