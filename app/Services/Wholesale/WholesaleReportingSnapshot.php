<?php

namespace App\Services\Wholesale;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\TenantWholesaleSetting;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/** Private read model. Never imports or saves Shopify history as production work. */
class WholesaleReportingSnapshot
{
    public const PAYMENT_QUERY = 'query WholesaleOrderPayments($ids:[ID!]!){nodes(ids:$ids){... on Order{id processedAt displayFinancialStatus netPaymentSet{shopMoney{amount currencyCode}} totalReceivedSet{shopMoney{amount currencyCode}} totalRefundedSet{shopMoney{amount currencyCode}}}}}';

    public const SALES_QUERY = 'query WholesaleSalesReport($query:String!){shopifyqlQuery(query:$query){tableData{columns{name dataType} rows} parseErrors}}';

    /** @return array<string,mixed> */
    public function refresh(int $tenantId): array
    {
        $setting = $this->setting($tenantId);
        $store = ShopifyStores::find((string) $setting->shopifyStore->store_key, true);
        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== $tenantId || strtolower((string) ($store['store_role'] ?? '')) !== 'wholesale') {
            throw new \DomainException('The wholesale connection does not belong to this workspace.');
        }
        if (! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'wholesale_operations')) {
            throw new \DomainException('Wholesale reporting is unavailable for this workspace.');
        }
        $rest = new ShopifyClient($store['shop'], $store['token'], $store['api_version']);
        $graphql = new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']);
        $before = (int) ($rest->get('orders/count.json', ['status' => 'any'])['count'] ?? -1);
        $payloads = $rest->get('orders.json', [
            'status' => 'any', 'limit' => 250,
            'fields' => 'id,name,created_at,processed_at,updated_at,source_name,cancelled_at,test,financial_status,currency,customer,email,shipping_address,billing_address,line_items',
        ])['orders'] ?? [];
        $payloads = collect($payloads)->keyBy(fn (array $order): string => (string) $order['id']);
        $after = (int) ($rest->get('orders/count.json', ['status' => 'any'])['count'] ?? -1);
        if ($before < 0 || $before !== $after || $payloads->count() !== $after) {
            throw new \RuntimeException('Wholesale history is incomplete or changed during refresh. The previous snapshot was retained.');
        }
        $payments = collect();
        foreach ($payloads->keys()->chunk(100) as $ids) {
            $result = $graphql->query(self::PAYMENT_QUERY, ['ids' => $ids->map(fn ($id): string => 'gid://shopify/Order/'.$id)->values()->all()]);
            foreach ($result['nodes'] ?? [] as $payment) {
                if (! is_array($payment) || ! isset($payment['id'])) {
                    throw new \RuntimeException('Shopify payment evidence is incomplete.');
                }
                $payments->put((string) basename($payment['id']), $payment);
            }
        }
        if ($payments->count() !== $payloads->count()) {
            throw new \RuntimeException('Some wholesale orders have no payment evidence.');
        }
        $currencies = [];
        $rows = [];
        $cancelledCustomers = [];
        $excluded = ['test' => 0, 'cancelled' => 0];
        foreach ($payloads as $id => $raw) {
            $payment = $payments->get((string) $id);
            if (! is_string($payment['processedAt'] ?? null) || trim($payment['processedAt']) === '') {
                throw new \RuntimeException('The original wholesale sales date is unavailable.');
            }
            $processedAt = CarbonImmutable::parse($payment['processedAt'])->utc();
            if (! empty($raw['test'])) {
                $excluded['test']++;

                continue;
            }
            if (! empty($raw['cancelled_at'])) {
                $excluded['cancelled']++;
                $customerId = isset($raw['customer']['id']) ? (string) $raw['customer']['id'] : null;
                if ($customerId !== null) {
                    $createdAt = $processedAt->timestamp;
                    $cancelledCustomers[$customerId] = max($createdAt, $cancelledCustomers[$customerId] ?? 0);
                }

                continue;
            }
            $received = $this->money(data_get($payment, 'totalReceivedSet.shopMoney'));
            $refunded = $this->money(data_get($payment, 'totalRefundedSet.shopMoney'));
            $net = $this->money(data_get($payment, 'netPaymentSet.shopMoney'));
            if ($received['currency'] !== $net['currency'] || $refunded['currency'] !== $net['currency'] || $net['cents'] !== $received['cents'] - $refunded['cents']) {
                throw new \RuntimeException('Wholesale payment amounts or currencies disagree.');
            }
            $currencies[$net['currency']] = true;
            $rows[] = [
                'shopify_order_id' => (string) $id, 'shopify_name' => (string) ($raw['name'] ?? ''),
                'shopify_customer_id' => isset($raw['customer']['id']) ? (string) $raw['customer']['id'] : null,
                'customer_name' => trim((string) data_get($raw, 'customer.first_name').' '.(string) data_get($raw, 'customer.last_name')) ?: ($raw['shipping_address']['name'] ?? $raw['billing_address']['name'] ?? null),
                'customer_email' => trim((string) data_get($raw, 'customer.email')) ?: (trim((string) ($raw['email'] ?? '')) ?: null),
                'customer_phone' => trim((string) data_get($raw, 'customer.phone')) ?: (trim((string) data_get($raw, 'shipping_address.phone')) ?: (trim((string) data_get($raw, 'billing_address.phone')) ?: null)),
                'shipping_company' => trim((string) data_get($raw, 'shipping_address.company')) ?: (trim((string) data_get($raw, 'billing_address.company')) ?: null),
                'ordered_at' => $processedAt->toIso8601String(),
                'provider_created_at' => CarbonImmutable::parse($raw['created_at'])->toIso8601String(),
                'source_name' => (string) ($raw['source_name'] ?? ''),
                'financial_status' => strtolower((string) $payment['displayFinancialStatus']),
                'received_cents' => $received['cents'], 'refunded_cents' => $refunded['cents'],
                'currency' => $net['currency'],
                'lines' => collect($raw['line_items'] ?? [])->map(fn (array $line): array => [
                    'shopify_product_id' => $line['product_id'] ?? null,
                    'shopify_variant_id' => $line['variant_id'] ?? null,
                    'raw_title' => $line['title'] ?? $line['name'] ?? null,
                    'raw_variant' => $line['variant_title'] ?? null, 'sku' => $line['sku'] ?? null,
                    'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                    'line_total' => max(0, (float) ($line['price'] ?? 0) * (int) ($line['quantity'] ?? 0) - (float) ($line['total_discount'] ?? 0)),
                ])->all(),
            ];
        }
        if (count($currencies) > 1) {
            throw new \RuntimeException('Wholesale reporting cannot combine different currencies.');
        }
        $asOf = now('America/New_York');
        $sales = [];
        foreach (['month' => $asOf->copy()->startOfMonth(), 'year' => $asOf->copy()->startOfYear(), 'trailing_12' => $asOf->copy()->subYear()->addDay()->startOfDay()] as $window => $start) {
            $query = 'FROM sales SHOW total_sales, net_sales, orders, average_order_value SINCE '.$start->toDateString().' UNTIL '.$asOf->toDateString();
            $report = $graphql->query(self::SALES_QUERY, ['query' => $query])['shopifyqlQuery'] ?? null;
            $data = $report['tableData']['rows'] ?? null;
            if (! is_array($report) || ! empty($report['parseErrors']) || ! is_array($data) || count($data) !== 1) {
                throw new \RuntimeException('Shopify sales reporting could not be verified.');
            }
            foreach (['total_sales', 'net_sales', 'orders', 'average_order_value'] as $metric) {
                if (! is_numeric($data[0][$metric] ?? null)) {
                    throw new \RuntimeException('Shopify sales metric is unavailable.');
                }
            }
            $sales[$window] = [...$data[0], 'since' => $start->toDateString(), 'until' => $asOf->toDateString()];
        }
        $snapshot = [
            'tenant_id' => $tenantId, 'shop_domain' => $store['shop'], 'store_key' => $store['key'],
            'source_order_count' => $after, 'included_order_count' => count($rows),
            'currency' => array_key_first($currencies), 'excluded' => $excluded,
            'last_cancelled_order_by_customer' => $cancelledCustomers,
            'sales' => $sales,
            'refreshed_at' => now()->toIso8601String(), 'rows' => $rows,
        ];
        // Replace only after all pages and payment facts have been checked.
        Cache::put($this->key($tenantId, (int) $setting->shopify_store_id), $snapshot, now()->addDays(7));

        return $snapshot;
    }

    public function read(int $tenantId): ?array
    {
        $setting = TenantWholesaleSetting::query()->where('tenant_id', $tenantId)->whereNotNull('confirmed_at')->with('shopifyStore')->first();
        if (! $setting?->shopifyStore || (int) $setting->shopifyStore->tenant_id !== $tenantId || strtolower((string) $setting->shopifyStore->store_role) !== 'wholesale') {
            return null;
        }
        $snapshot = Cache::get($this->key($tenantId, (int) $setting->shopify_store_id));

        return is_array($snapshot) && ($snapshot['tenant_id'] ?? null) === $tenantId && ($snapshot['shop_domain'] ?? null) === $setting->shopifyStore->shop_domain ? $snapshot : null;
    }

    /** @return Collection<int,Order>|null */
    public function orders(int $tenantId): ?Collection
    {
        $snapshot = $this->read($tenantId);
        if ($snapshot === null) {
            return null;
        }
        $local = Order::query()->where('tenant_id', $tenantId)->where('shopify_store_key', $snapshot['store_key'])->get()->keyBy('shopify_order_id');
        $retailOnly = \App\Models\WholesaleOrderClassification::query()->forAllTenants()->where('tenant_id', $tenantId)->where('status', 'retail_only')->pluck('order_id')->all();
        $orders = [];
        foreach ($snapshot['rows'] as $row) {
            $original = $local->get($row['shopify_order_id']);
            if ($original && in_array($original->id, $retailOnly, true)) {
                continue;
            }
            // These instances are never persisted or passed to mutation services.
            $order = new Order([
                'id' => $original?->id, 'tenant_id' => $tenantId, 'shopify_store_key' => $snapshot['store_key'], 'shopify_store' => $snapshot['store_key'],
                'source' => 'shopify_wholesale', 'order_type' => 'wholesale',
                'shopify_order_id' => $row['shopify_order_id'], 'shopify_name' => $row['shopify_name'], 'order_number' => $row['shopify_name'],
                'shopify_customer_id' => $row['shopify_customer_id'], 'customer_name' => $row['customer_name'],
                'customer_email' => $row['customer_email'], 'customer_phone' => $row['customer_phone'], 'shipping_company' => $row['shipping_company'],
                'ordered_at' => $row['ordered_at'], 'currency_code' => $row['currency'],
                'total_price' => $row['received_cents'] / 100, 'refund_total' => $row['refunded_cents'] / 100,
                'status' => $original?->status ?: $row['financial_status'],
                'attribution_meta' => [...(array) $original?->attribution_meta, 'wholesale_reporting' => ['financial_status' => $row['financial_status'], 'refreshed_at' => $snapshot['refreshed_at'], 'provider_created_at' => $row['provider_created_at']]],
            ]);
            $order->setRelation('lines', new Collection(array_map(fn (array $line): OrderLine => new OrderLine($line), $row['lines'])));
            $orders[] = $order;
        }

        return new Collection($orders);
    }

    private function setting(int $tenantId): TenantWholesaleSetting
    {
        $setting = TenantWholesaleSetting::query()->where('tenant_id', $tenantId)->whereNotNull('confirmed_at')->with('shopifyStore')->first();
        if (! $setting?->shopifyStore || (int) $setting->shopifyStore->tenant_id !== $tenantId) {
            throw new \DomainException('A confirmed wholesale connection is required.');
        }

        return $setting;
    }

    private function key(int $tenantId, int $storeId): string
    {
        return 'wholesale:reporting:v1:'.$tenantId.':'.$storeId;
    }

    /** Exact two-decimal amounts. Other precision is withheld rather than rounded. */
    private function money(mixed $money): array
    {
        if (! is_array($money) || ! preg_match('/^-?\d+(?:\.\d{1,2})?$/D', (string) ($money['amount'] ?? '')) || ! preg_match('/^[A-Z]{3}$/D', (string) ($money['currencyCode'] ?? ''))) {
            throw new \RuntimeException('Unsupported or missing wholesale payment amount.');
        }
        $amount = (string) $money['amount'];
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');

        return ['cents' => ((int) $whole * 100 + (int) str_pad($fraction, 2, '0')) * ($negative ? -1 : 1), 'currency' => $money['currencyCode']];
    }
}
