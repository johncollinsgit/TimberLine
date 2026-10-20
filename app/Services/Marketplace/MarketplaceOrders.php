<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\Setting;
use App\Models\Marketplace\Statement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceOrders
{
    public const QUERY = <<<'GRAPHQL'
        query MarketplaceOrder($id: ID!) {
          order(id: $id) {
            id name test email currencyCode displayFinancialStatus taxesIncluded sourceIdentifier
            customAttributes { key value }
            shippingAddress { name address1 address2 city provinceCode zip countryCodeV2 phone }
            totalPriceSet { shopMoney { amount currencyCode } }
            totalTaxSet { shopMoney { amount currencyCode } }
            shippingLines(first: 100) { nodes { discountedPriceSet { shopMoney { amount } } } pageInfo { hasNextPage } }
            transactions(first: 100) { id status kind gateway amountSet { shopMoney { amount } } }
            lineItems(first: 100) {
              nodes { id title quantity sku variant { id } customAttributes { key value }
                originalUnitPriceSet { shopMoney { amount } }
                discountedTotalSet { shopMoney { amount } }
              }
              pageInfo { hasNextPage }
            }
            fulfillmentOrders(first: 100) {
              nodes { id status assignedLocation { location { id } } deliveryMethod { methodType }
                lineItems(first: 100) { nodes { id remainingQuantity lineItem { id } } pageInfo { hasNextPage } }
              }
              pageInfo { hasNextPage }
            }
            fulfillments(first: 100) {
              id status trackingInfo { number url }
              fulfillmentLineItems(first: 100) { nodes { quantity lineItem { id } } pageInfo { hasNextPage } }
            }
            refunds {
              id totalRefundedSet { shopMoney { amount } }
              refundLineItems(first: 100) { nodes { quantity lineItem { id } } pageInfo { hasNextPage } }
              refundShippingLines(first: 100) { nodes { subtotalAmountSet { shopMoney { amount } } } pageInfo { hasNextPage } }
              transactions(first: 100) { nodes { status kind amountSet { shopMoney { amount } } } pageInfo { hasNextPage } }
            }
          }
        }
        GRAPHQL;

    public function __construct(private MarketplaceShopify $shopify) {}

    public function read(Connection $connection, string $gid): array
    {
        abort_unless(preg_match('#^gid://shopify/Order/[0-9]+$#', $gid), 422);
        $data = $this->shopify->graph($connection, self::QUERY, ['id' => $gid]);
        abort_unless(is_array($data['order'] ?? null) && ($data['order']['id'] ?? '') === $gid, 422, 'The original merchant could not find this order.');
        $order = $data['order'];
        foreach (['lineItems', 'shippingLines', 'fulfillmentOrders'] as $field) {
            abort_if($order[$field]['pageInfo']['hasNextPage'] ?? true, 422, 'Order reconciliation needs a complete provider response.');
        }
        foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment) {
            abort_if($fulfillment['lineItems']['pageInfo']['hasNextPage'] ?? true, 422);
        }
        foreach ($order['refunds'] as $refund) {
            abort_if($refund['refundLineItems']['pageInfo']['hasNextPage'] ?? true, 422);
            abort_if($refund['transactions']['pageInfo']['hasNextPage'] ?? true, 422);
            abort_if($refund['refundShippingLines']['pageInfo']['hasNextPage'] ?? true, 422);
        }

        return $order;
    }

    public function reconcile(Connection $connection, string $gid): ?Order
    {
        if ($native = Order::where('connection_id', $connection->id)->where('provider_order_gid', $gid)->where('provider', 'shop_pay')->first()) {
            return app(MarketplaceWallet::class)->reconcile($native->checkout);
        }

        return $this->sync($connection, $this->read($connection, $gid));
    }

    public function sync(Connection $connection, array $provider): ?Order
    {
        $uuid = collect($provider['customAttributes'] ?? [])->firstWhere('key', '_mm_checkout')['value'] ?? null;
        $checkout = Checkout::query()->where('uuid', $uuid)->where('connection_id', $connection->id)->first();
        if (! $checkout) {
            return null;
        } // A store order outside this marketplace is never imported.
        if (! in_array($provider['displayFinancialStatus'], ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
            return null;
        }
        abort_unless(($provider['currencyCode'] ?? '') === 'USD' && (bool) $provider['test'] === (bool) $connection->test_mode, 422, 'The order currency or payment test mode does not match its merchant binding.');
        $total = MarketplaceShopify::cents($provider['totalPriceSet']['shopMoney']['amount']);
        $received = 0;
        foreach ($provider['transactions'] as $transaction) {
            if ($transaction['status'] === 'SUCCESS' && in_array($transaction['kind'], ['SALE', 'CAPTURE'], true)) {
                $received += MarketplaceShopify::cents($transaction['amountSet']['shopMoney']['amount']);
            }
        }
        abort_unless($received >= $total, 422, 'A successful captured payment has not been verified.');

        return DB::transaction(function () use ($connection, $checkout, $provider, $total): Order {
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            abort_unless(! $checkout->provider_order_gid || $checkout->provider_order_gid === $provider['id'], 409, 'A second paid order references an already completed checkout.');
            $expected = collect($checkout->snapshot['lines'])->keyBy('cart_key');
            $actual = collect($provider['lineItems']['nodes']);
            $review = [];
            $seen = [];
            $gross = $discount = 0;
            $mapped = [];
            foreach ($actual as $line) {
                $key = collect($line['customAttributes'])->firstWhere('key', '_mm_line')['value'] ?? '';
                $snapshot = $expected->get($key);
                $unit = MarketplaceShopify::cents($line['originalUnitPriceSet']['shopMoney']['amount']);
                $net = MarketplaceShopify::cents($line['discountedTotalSet']['shopMoney']['amount']);
                if (! $snapshot || isset($seen[$key]) || $snapshot['variant_gid'] !== ($line['variant']['id'] ?? '') || $snapshot['quantity'] !== $line['quantity'] || $snapshot['price_cents'] !== $unit) {
                    $review[] = 'The purchased items differ from the agreed checkout.';

                    continue;
                }
                $seen[$key] = true;
                $gross += $unit * $line['quantity'];
                $lineDiscount = $unit * $line['quantity'] - $net;
                if ($lineDiscount < 0) {
                    $review[] = 'Unexpected item pricing.';
                }
                $discount += $lineDiscount;
                $mapped[] = [$snapshot, $line, max(0, $lineDiscount)];
                $fulfillments = collect($provider['fulfillmentOrders']['nodes'])->filter(fn ($fo) => collect($fo['lineItems']['nodes'])->contains(fn ($item) => $item['lineItem']['id'] === $line['id']));
                if ($fulfillments->isEmpty()) {
                    $review[] = 'Fulfillment routing has not been confirmed.';
                }
                foreach ($fulfillments as $fulfillment) {
                    $method = $fulfillment['deliveryMethod']['methodType'] ?? '';
                    $wanted = $checkout->group_key === 'shipping' ? 'SHIPPING' : 'PICK_UP';
                    if ($method !== $wanted || ($fulfillment['assignedLocation']['location']['id'] ?? '') !== $snapshot['location_gid']) {
                        $review[] = 'Checkout delivery changed; review the maker and pickup or shipping location.';
                    }
                }
            }
            if (count($seen) !== $expected->count()) {
                $review[] = 'Not all checkout items were purchased.';
            }
            $shipping = collect($provider['shippingLines']['nodes'])->sum(fn ($line) => MarketplaceShopify::cents($line['discountedPriceSet']['shopMoney']['amount']));
            $tax = MarketplaceShopify::cents($provider['totalTaxSet']['shopMoney']['amount']);
            if ($provider['taxesIncluded'] || $gross - $discount + $shipping + $tax !== $total) {
                $review[] = 'Reconcile item, shipping, discount, and tax totals.';
            }
            if ($checkout->group_key === 'shipping' && ($provider['shippingAddress']['countryCodeV2'] ?? '') !== 'US') {
                $review[] = 'Shipping is available in the United States only.';
            }
            $margin = $expected->sum(fn ($line) => $line['fee_cents'] * $line['quantity']);
            if ($discount > $margin + (int) ($checkout->snapshot['subsidy_limit_cents'] ?? 0)) {
                $review[] = 'The promotion exceeds the approved marketplace subsidy.';
            }
            $email = strtolower(trim((string) ($provider['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $review[] = 'The customer contact email is missing.';
            }
            $order = Order::query()->firstOrCreate(['checkout_id' => $checkout->id], [
                'connection_id' => $connection->id, 'provider_order_gid' => $provider['id'], 'number' => $provider['name'], 'identity_id' => null,
                'customer' => ['email' => $email, 'shipping_address' => $provider['shippingAddress']], 'email_hash' => hash('sha256', $email),
                'group_key' => $checkout->group_key, 'merchandise_cents' => $gross, 'discount_cents' => max(0, $discount), 'shipping_cents' => $shipping,
                'tax_cents' => $tax, 'total_cents' => $total, 'test_mode' => $connection->test_mode, 'status' => 'paid',
                'review_required' => $review !== [], 'review_reason' => implode(' ', array_unique($review)),
            ]);
            if ($review !== []) {
                $order->forceFill(['review_required' => true, 'review_reason' => implode(' ', array_unique($review))])->save();
                // Payment remains recorded, but inconsistent line mappings cannot create supplier obligations.
                if (count($mapped) !== $actual->count() || count($seen) !== $expected->count()) {
                    $mapped = [];
                }
            }
            foreach ($mapped as [$snapshot, $line, $lineDiscount]) {
                $local = OrderLine::query()->firstOrCreate(['order_id' => $order->id, 'provider_line_gid' => $line['id']], [
                    'maker_id' => $snapshot['maker_id'], 'listing_id' => $snapshot['listing_id'], 'title' => $snapshot['title'], 'quantity' => $snapshot['quantity'],
                    'unit_price_cents' => $snapshot['price_cents'], 'maker_unit_cents' => $snapshot['maker_cents'], 'rate_id' => $snapshot['rate_id'], 'discount_cents' => $lineDiscount,
                ]);
                LedgerEntry::query()->firstOrCreate(['source_key' => 'sale:'.$local->id], ['maker_id' => $local->maker_id, 'order_line_id' => $local->id, 'kind' => 'merchandise', 'amount_cents' => $local->maker_unit_cents * $local->quantity]);
            }
            $checkout->forceFill(['status' => 'paid', 'provider_order_gid' => $provider['id'], 'paid_at' => $checkout->paid_at ?? now()])->save();
            if ($connection->test_mode && $review === []) {
                $settings = app(MarketplaceAccess::class)->settings();
                $settings->forceFill(['readiness' => [...($settings->readiness ?? []), 'test_paid_shop_gid' => $connection->shop_gid, 'test_order_gid' => $provider['id']]])->save();
            }
            if (! $order->shipping_allocated_at && ($checkout->group_key !== 'shipping' || ($shipping > 0 && $order->lines()->distinct()->count('maker_id') === 1))) {
                $credits = [];
                if ($first = $order->lines()->first()) {
                    $credits[$first->id] = $shipping;
                }
                $this->allocateShipping($order, $credits, 0, null);
            }
            $this->syncFulfillment($order, $provider);
            $this->syncRefunds($order, $provider);
            $this->releaseEarnings($order->fresh());

            return $order->fresh();
        });
    }

    public function applyProviderAdjustments(Order $order, array $provider): void
    {
        DB::transaction(function () use ($order, $provider): void {
            Setting::whereKey(1)->lockForUpdate()->firstOrFail();
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->syncFulfillment($order, $provider);
            $this->syncRefunds($order, $provider);
            $this->releaseEarnings($order->fresh());
        });
    }

    public function allocateShipping(Order $order, array $credits, int $subsidy, ?int $actor): void
    {
        DB::transaction(function () use ($order, $credits, $subsidy, $actor): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($order->shipping_allocated_at, 409, 'Shipping has already been allocated. Use a recorded adjustment to correct it.');
            abort_unless($subsidy >= 0 && ($actor || $subsidy === 0) && array_sum($credits) === $order->shipping_cents + $subsidy, 422, 'Credits must equal collected shipping plus the explicitly approved marketplace subsidy.');
            foreach ($credits as $lineId => $amount) {
                $line = $order->lines()->findOrFail($lineId);
                abort_unless(is_int($amount) && $amount >= 0, 422);
                $line->forceFill(['shipping_credit_cents' => $amount])->save();
                if ($amount) {
                    LedgerEntry::query()->firstOrCreate(['source_key' => 'shipping:'.$line->id], ['maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'shipping', 'amount_cents' => $amount]);
                }
            }
            $order->forceFill(['shipping_allocated_at' => now(), 'shipping_subsidy_cents' => $subsidy])->save();
            Audit::query()->create(['actor_user_id' => $actor, 'action' => 'order.shipping_allocated', 'subject_id' => $order->id, 'details' => ['credits' => $credits, 'subsidy_cents' => $subsidy]]);
            $this->releaseEarnings($order);
        });
    }

    private function syncFulfillment(Order $order, array $provider): void
    {
        foreach ($provider['fulfillments'] as $fulfillment) {
            if ($fulfillment['status'] !== 'SUCCESS') {
                continue;
            }
            abort_if($fulfillment['fulfillmentLineItems']['pageInfo']['hasNextPage'] ?? true, 422);
            foreach ($fulfillment['fulfillmentLineItems']['nodes'] as $item) {
                $line = $order->lines()->where('provider_line_gid', $item['lineItem']['id'])->first();
                if (! $line || $item['quantity'] < $line->quantity - $line->refunded_quantity) {
                    continue;
                }
                $tracking = $fulfillment['trackingInfo'][0] ?? [];
                $line->forceFill(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => $line->fulfilled_at ?? now(), 'tracking_number' => $tracking['number'] ?? null, 'tracking_url' => $tracking['url'] ?? null])->save();
            }
        }
    }

    private function syncRefunds(Order $order, array $provider): void
    {
        foreach ($provider['refunds'] as $refund) {
            $successful = collect($refund['transactions']['nodes'])->filter(fn ($tx) => $tx['kind'] === 'REFUND' && $tx['status'] === 'SUCCESS')->sum(fn ($tx) => MarketplaceShopify::cents($tx['amountSet']['shopMoney']['amount']));
            if ($successful < MarketplaceShopify::cents($refund['totalRefundedSet']['shopMoney']['amount']) || $successful === 0) {
                continue;
            }
            foreach ($refund['refundLineItems']['nodes'] as $item) {
                $line = $order->lines()->where('provider_line_gid', $item['lineItem']['id'])->lockForUpdate()->first();
                if (! $line) {
                    continue;
                }
                $key = 'refund:'.Str::afterLast($refund['id'], '/').':'.$line->id;
                if (LedgerEntry::query()->where('source_key', $key)->exists()) {
                    continue;
                }
                abort_unless($item['quantity'] > 0 && $line->quantity >= $item['quantity'] + $line->refunded_quantity, 422);
                $saleEligible = LedgerEntry::query()->where('source_key', 'sale:'.$line->id)->whereNotNull('eligible_at')->exists();
                LedgerEntry::query()->create(['source_key' => $key, 'maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'refund', 'amount_cents' => -$line->maker_unit_cents * $item['quantity'], 'eligible_at' => ! $order->test_mode && $saleEligible ? now() : null]);
                $line->increment('refunded_quantity', $item['quantity']);
                // Refunds against already reviewed statements must stop an unpaid bank batch.
                $statementIds = LedgerEntry::query()->where('order_line_id', $line->id)->whereNotNull('statement_id')->pluck('statement_id');
                Statement::query()->whereIn('id', $statementIds)->where('status', 'approved')->update(['status' => 'review', 'approved_by' => null, 'approved_at' => null]);
            }
            $shipping = collect($refund['refundShippingLines']['nodes'] ?? [])->sum(fn ($line) => MarketplaceShopify::cents($line['subtotalAmountSet']['shopMoney']['amount']));
            if ($shipping > 0) {
                $refunds = $order->fresh()->shipping_refunds ?? [];
                if (! isset($refunds[$refund['id']])) {
                    $refunds[$refund['id']] = ['amount_cents' => $shipping, 'status' => 'review'];
                    $order->forceFill(['shipping_refunds' => $refunds])->save();
                    $statementIds = LedgerEntry::query()->whereIn('order_line_id', $order->lines()->pluck('id'))->whereNotNull('statement_id')->pluck('statement_id');
                    Statement::query()->whereIn('id', $statementIds)->where('status', 'approved')->update(['status' => 'review', 'approved_by' => null, 'approved_at' => null]);
                }
            }
        }
    }

    public function allocateShippingRefund(Order $order, string $refundGid, array $credits, int $platformCost, int $actor): void
    {
        DB::transaction(function () use ($order, $refundGid, $credits, $platformCost, $actor): void {
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $refunds = $order->shipping_refunds ?? [];
            $refund = $refunds[$refundGid] ?? null;
            abort_unless($refund && $refund['status'] === 'review' && $platformCost >= 0 && array_sum($credits) + $platformCost === $refund['amount_cents'], 422, 'Allocate the verified refunded shipping between maker reversals and the marketplace expense.');
            foreach ($credits as $lineId => $amount) {
                $line = $order->lines()->findOrFail($lineId);
                $reversed = -(int) LedgerEntry::query()->where('order_line_id', $line->id)->where('kind', 'shipping_refund')->sum('amount_cents');
                abort_unless(is_int($amount) && $amount >= 0 && $amount <= $line->shipping_credit_cents - $reversed, 422, 'The maker reversal exceeds the original remaining shipping credit.');
                if ($amount > 0) {
                    LedgerEntry::query()->create(['source_key' => 'shiprefund:'.Str::afterLast($refundGid, '/').':'.$line->id, 'maker_id' => $line->maker_id, 'order_line_id' => $line->id, 'kind' => 'shipping_refund', 'amount_cents' => -$amount]);
                }
            }
            $refunds[$refundGid] = [...$refund, 'status' => 'allocated', 'platform_cost_cents' => $platformCost, 'credits' => $credits];
            $order->forceFill(['shipping_refunds' => $refunds])->save();
            Audit::query()->create(['actor_user_id' => $actor, 'action' => 'order.shipping_refund_allocated', 'subject_id' => $order->id, 'details' => ['refund_gid' => $refundGid, 'credits' => $credits, 'platform_cost_cents' => $platformCost]]);
            $this->releaseEarnings($order);
        });
    }

    private function financialReviewPending(Order $order): bool
    {
        return $order->review_required || ! $order->shipping_allocated_at || collect($order->shipping_refunds ?? [])->contains(fn ($refund) => $refund['status'] !== 'allocated');
    }

    private function assertSupplierReady(int $makerId): void
    {
        $orderIds = OrderLine::query()->where('maker_id', $makerId)->pluck('order_id')->unique();
        foreach (Order::query()->whereIn('id', $orderIds)->where('test_mode', false)->get() as $order) {
            abort_if($this->financialReviewPending($order), 409, 'Resolve this maker’s order and shipping refund reviews before approving a bank payment.');
        }
    }

    public function releaseEarnings(Order $order): void
    {
        if ($order->test_mode || $this->financialReviewPending($order)) {
            return;
        }
        foreach ($order->lines()->get() as $line) {
            $entries = LedgerEntry::query()->where('order_line_id', $line->id)->whereNull('eligible_at');
            if ($line->fulfilled_at) {
                $entries->update(['eligible_at' => now()]);
            } elseif ($line->refunded_quantity === $line->quantity) {
                // Cancelled merchandise nets to zero. Unshipped shipping credits
                // remain held, with their reversals, until fulfillment is verified.
                $entries->whereIn('kind', ['merchandise', 'refund'])->update(['eligible_at' => now()]);
            }
        }
    }

    public function statement(int $makerId, int $actor): Statement
    {
        return DB::transaction(function () use ($makerId, $actor): Statement {
            // Serialize selections; an entry can belong to exactly one statement.
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $entries = LedgerEntry::query()->where('maker_id', $makerId)->whereNull('statement_id')->whereNotNull('eligible_at')->lockForUpdate()->get();
            abort_unless($entries->isNotEmpty(), 422, 'There are no eligible unpaid entries.');
            abort_if(Statement::query()->where('maker_id', $makerId)->where('status', 'approved')->exists(), 409, 'Finish or reopen the approved statement before preparing another.');
            $statement = Statement::query()->where('maker_id', $makerId)->where('status', 'review')->lockForUpdate()->first();
            if ($statement) {
                $statement->forceFill(['amount_cents' => $statement->amount_cents + $entries->sum('amount_cents')])->save();
            } else {
                $statement = Statement::query()->create(['uuid' => (string) Str::uuid(), 'maker_id' => $makerId, 'amount_cents' => $entries->sum('amount_cents'), 'status' => 'review']);
            }
            LedgerEntry::query()->whereIn('id', $entries->modelKeys())->whereNull('statement_id')->update(['statement_id' => $statement->id]);
            Audit::query()->create(['actor_user_id' => $actor, 'action' => 'statement.created', 'subject_id' => $statement->id]);

            return $statement;
        });
    }

    public function approveStatement(Statement $statement, int $actor): void
    {
        $changed = DB::transaction(function () use ($statement, $actor): bool {
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $statement = Statement::query()->lockForUpdate()->findOrFail($statement->id);
            $this->assertSupplierReady($statement->maker_id);
            abort_unless($statement->status === 'review' && $statement->amount_cents > 0, 422, 'A positive reviewed balance is required.');
            $unassignedAdjustments = LedgerEntry::query()->where('maker_id', $statement->maker_id)->whereNull('statement_id')->where('amount_cents', '<', 0)->whereNotNull('eligible_at')->lockForUpdate()->get();
            if ($unassignedAdjustments->isNotEmpty()) {
                LedgerEntry::query()->whereIn('id', $unassignedAdjustments->modelKeys())->update(['statement_id' => $statement->id]);
                $statement->forceFill(['amount_cents' => $statement->amount_cents + $unassignedAdjustments->sum('amount_cents')])->save();

                return true;
            }
            $statement->forceFill(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()])->save();

            return false;
        });
        abort_if($changed, 409, 'Refund adjustments changed this statement. Review the updated amount before approving.');
    }

    public function recordPayment(Statement $statement, int $actor, string $reference, int $expected): void
    {
        DB::transaction(function () use ($statement, $actor, $reference, $expected): void {
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $statement = Statement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->status === 'paid') {
                abort_unless($statement->bank_reference === $reference && $statement->amount_cents === $expected, 409);

                return;
            }
            abort_unless($statement->status === 'approved' && $statement->amount_cents === $expected && $expected > 0 && trim($reference) !== '', 409, 'Review and approve the current statement before recording its bank payment.');
            $this->assertSupplierReady($statement->maker_id);
            abort_if(LedgerEntry::query()->where('maker_id', $statement->maker_id)->whereNull('statement_id')->where('amount_cents', '<', 0)->whereNotNull('eligible_at')->exists(), 409, 'Reconcile outstanding refunds before paying this maker.');
            $statement->forceFill(['status' => 'paid', 'paid_at' => now(), 'bank_reference' => $reference])->save();
            Audit::query()->create(['actor_user_id' => $actor, 'action' => 'statement.payment_recorded', 'subject_id' => $statement->id, 'details' => ['amount_cents' => $expected, 'bank_reference' => $reference]]);
        });
    }
}
