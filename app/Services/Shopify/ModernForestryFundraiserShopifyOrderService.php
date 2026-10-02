<?php

namespace App\Services\Shopify;

use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserShopifyOrderService
{
    /**
     * The August invoice predates this queue and is already in QuickBooks.
     * Do not backfill it into a second payable invoice.
     */
    // September 1 at midnight Eastern, stored in UTC by the Shopify importer.
    private const AUTOMATED_START = '2026-09-01 04:00:00';

    public function discover(Tenant $tenant): int
    {
        $created = 0;
        Order::query()->forTenant($tenant)->where('shopify_store_key', 'retail')
            ->where('ordered_at', '>=', self::AUTOMATED_START)
            ->whereJsonContains('attribution_meta->order_tags', 'BSF')
            ->orderBy('id')->chunkById(100, function ($orders) use ($tenant, &$created): void {
                foreach ($orders as $order) {
                    $id = trim((string) $order->shopify_order_id);
                    if ($id === '' || $this->proceedsCents($order) <= 0 || $this->moneyCents($order->refund_total) > 0) {
                        continue;
                    }
                    $exists = ModernForestryFundraiserOrder::query()->forTenant($tenant)
                        ->where('source', 'shopify')->where('external_order_id', $id)->exists();
                    if ($exists) {
                        $queued = ModernForestryFundraiserOrder::query()->forTenant($tenant)
                            ->where('source', 'shopify')->where('external_order_id', $id)->first();
                        if ($queued && $queued->status === 'needs_review' && ! (bool) data_get($queued->source_payload, 'shipping_verified')) {
                            $this->syncShopifyLabelCost($queued);
                        }

                        continue;
                    }
                    $proceeds = $this->proceedsCents($order);
                    $payload = [
                        'shopify_order_id' => $id,
                        'everbranch_order_id' => (int) $order->id,
                        'shopify_proceeds_cents' => $proceeds,
                        'billing_month' => $order->ordered_at->copy()->timezone('America/New_York')->format('Y-m'),
                        'shipping_verified' => false,
                    ];
                    $queued = ModernForestryFundraiserOrder::query()->firstOrCreate(
                        ['tenant_id' => $tenant->id, 'source' => 'shopify', 'external_order_id' => $id],
                        [
                            'order_reference' => (string) ($order->shopify_name ?: $order->order_number),
                            'recipient_name' => (string) ($order->shipping_name ?: $order->customer_name ?: 'Shopify customer'),
                            'recipient_email' => $order->shipping_email ?: $order->customer_email,
                            'shipping_address' => [],
                            'currency' => strtolower((string) ($order->currency_code ?: 'usd')),
                            'subtotal_cents' => $proceeds,
                            'discount_cents' => 0,
                            'shipping_cents' => 0,
                            'tax_cents' => 0,
                            'total_cents' => $proceeds,
                            'line_items' => [[
                                'description' => 'Candle proceeds',
                                'quantity' => 1,
                                'unit_amount_cents' => $proceeds,
                                'line_total_cents' => $proceeds,
                            ]],
                            'source_payload' => $payload,
                            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                            'source_created_at' => $order->ordered_at,
                            'received_at' => now(),
                            'status' => 'needs_review',
                            'review_notes' => 'Detected from a BSF-tagged Shopify order. Actual purchased-label cost must be verified before approval.',
                        ]
                    );
                    $this->syncShopifyLabelCost($queued);
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Shopify's shipping_labels report is the purchased-label cost, not the
     * customer-facing checkout shipping charge stored on the imported order.
     */
    public function syncShopifyLabelCost(ModernForestryFundraiserOrder $row): void
    {
        if ($row->source !== 'shopify' || $row->status !== 'needs_review'
            || (bool) data_get($row->source_payload, 'shipping_verified')) {
            return;
        }
        try {
            $cost = $this->shopifyLabelCost($row);
        } catch (\Throwable) {
            // A missing report permission or transient Shopify failure leaves
            // the row in review. It must never become billable on a guess.
            return;
        }
        if ($cost === null) {
            return;
        }
        $payload = (array) $row->source_payload;
        $payload['shipping_verified'] = true;
        $payload['shipping_evidence'] = 'Shopify shipping_labels report, order '.$row->external_order_id;
        $payload['shipping_evidence_source'] = 'shopify_shipping_labels';
        $payload['shipping_verified_at'] = now()->toIso8601String();
        $row->forceFill([
            'shipping_cents' => $cost,
            'total_cents' => (int) $row->subtotal_cents + $cost,
            'source_payload' => $payload,
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'review_notes' => 'Shopify purchased-label cost detected. Review the live order and approve before invoicing.',
        ])->save();
    }

    private function shopifyLabelCost(ModernForestryFundraiserOrder $row): ?int
    {
        $store = ShopifyStores::find('retail');
        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== (int) $row->tenant_id
            || ! ctype_digit((string) $row->external_order_id)) {
            return null;
        }
        $since = CarbonImmutable::parse($row->source_created_at)->subDay()->format('Y-m-d');
        $until = CarbonImmutable::now()->addDay()->format('Y-m-d');
        $shopifyql = 'FROM shipping_labels SHOW shipping_label_costs, shipping_labels WHERE order_id IN ('.(string) $row->external_order_id.') GROUP BY order_id, order_name SINCE '.$since.' UNTIL '.$until.' LIMIT 10';
        $query = 'query ShippingLabelCost($shopifyql: String!) { shopifyqlQuery(query: $shopifyql) { tableData { rows } parseErrors } }';
        $client = new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version'] ?? '2026-01');
        $result = $client->query($query, ['shopifyql' => $shopifyql]);
        if (data_get($result, 'shopifyqlQuery.parseErrors') !== []) {
            return null;
        }
        $rows = collect(data_get($result, 'shopifyqlQuery.tableData.rows', []))
            ->filter(fn ($item): bool => (string) ($item['order_id'] ?? '') === (string) $row->external_order_id);
        if ($rows->count() !== 1) {
            return null;
        }
        $item = $rows->first();
        $cost = (string) ($item['shipping_label_costs'] ?? '');
        if (! preg_match('/^\d+\.\d{2}$/', $cost) || (int) ($item['shipping_labels'] ?? 0) < 1) {
            return null;
        }

        return (int) round(((float) $cost) * 100);
    }

    public function verifyShipping(Tenant $tenant, int $id, int $cents, string $evidence, string $actor): ModernForestryFundraiserOrder
    {
        if ($cents < 0 || $cents > 99999999 || trim($evidence) === '') {
            throw ValidationException::withMessages(['shipping' => ['Enter the actual label cost in cents and its receipt or reference.']]);
        }

        return DB::transaction(function () use ($tenant, $id, $cents, $evidence, $actor): ModernForestryFundraiserOrder {
            $row = ModernForestryFundraiserOrder::query()->forTenant($tenant)->lockForUpdate()->findOrFail($id);
            if ($row->source !== 'shopify' || $row->status === 'packaged') {
                throw ValidationException::withMessages(['order' => ['Only an unbilled Shopify fundraiser order can have its label cost verified.']]);
            }
            $this->assertCurrent($row);
            $payload = (array) $row->source_payload;
            $payload['shipping_verified'] = true;
            unset($payload['shipping_evidence_source']);
            $payload['shipping_evidence'] = trim($evidence);
            $payload['shipping_verified_by'] = $actor;
            $payload['shipping_verified_at'] = now()->toIso8601String();
            $row->forceFill([
                'shipping_cents' => $cents,
                'total_cents' => (int) $row->subtotal_cents + $cents,
                'source_payload' => $payload,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'status' => 'needs_review',
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_notes' => 'Shopify order amount checked; purchased-label cost reviewed against '.$evidence.'. Awaiting approval.',
            ])->save();

            return $row->fresh();
        });
    }

    public function assertCurrent(ModernForestryFundraiserOrder $row): void
    {
        if ($row->source !== 'shopify') {
            return;
        }
        $store = ShopifyStores::find('retail');
        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== (int) $row->tenant_id) {
            throw ValidationException::withMessages(['shopify' => ['The Modern Forestry retail Shopify connection is unavailable.']]);
        }
        $client = new ShopifyClient($store['shop'], $store['token'], $store['api_version'] ?? '2026-01');
        try {
            $order = (array) data_get($client->get('orders/'.$row->external_order_id.'.json'), 'order', []);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['shopify' => ['The live Shopify order could not be checked. Nothing was approved or sent.']]);
        }
        $tags = collect(explode(',', (string) ($order['tags'] ?? '')))->map(fn ($tag) => strtoupper(trim($tag)));
        $proceeds = $this->moneyCents($order['current_total_price'] ?? $order['total_price'] ?? null)
            - $this->moneyCents($order['current_total_tax'] ?? $order['total_tax'] ?? 0)
            - $this->moneyCents(data_get($order, 'current_total_shipping_price_set.shop_money.amount') ?? 0);
        if ((string) ($order['id'] ?? '') !== (string) $row->external_order_id
            || ! $tags->contains('BSF')
            || filled($order['cancelled_at'] ?? null)
            || ! empty($order['refunds'] ?? [])
            || in_array(strtolower((string) ($order['financial_status'] ?? '')), ['refunded', 'partially_refunded'], true)
            || strtolower((string) ($order['currency'] ?? '')) !== strtolower((string) $row->currency)
            || $proceeds !== (int) $row->subtotal_cents
            || $this->moneyCents($order['current_total_tax'] ?? 0) !== 0
        ) {
            throw ValidationException::withMessages(['shopify' => ['The live Shopify order no longer matches the BSF queue amount or tag. Reconcile it before invoicing.']]);
        }
        if (data_get($row->source_payload, 'shipping_evidence_source') === 'shopify_shipping_labels') {
            try {
                $cost = $this->shopifyLabelCost($row);
            } catch (\Throwable) {
                $cost = null;
            }
            if ($cost === null || $cost !== (int) $row->shipping_cents) {
                throw ValidationException::withMessages(['shipping' => ['The live Shopify label cost has changed or is unavailable. Reconcile it before invoicing.']]);
            }
        }
    }

    private function proceedsCents(Order $order): int
    {
        return $this->moneyCents($order->total_price)
            - $this->moneyCents($order->tax_total)
            - $this->moneyCents($order->shipping_total);
    }

    private function moneyCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
