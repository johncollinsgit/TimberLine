<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** An operator-only work intake draft on a separately approved supplier connection. */
class WebsiteDropshipDraftHandoff
{
    private const API_VERSION = '2026-07';

    private const DRAFT_FIELDS = 'id status tags visibleToCustomer totalPriceSet { shopMoney { amount } }';

    public function __construct(private readonly WebsiteShippingPlanService $shipping) {}

    public function submit(int $tenantId, int $siteId, int $orderId): string
    {
        $this->assertEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('The supplier handoff needs its own committed attempt.');
        }
        $criteria = $this->drafts($tenantId, $siteId, $orderId);
        if ($criteria->exists()) {
            throw new RuntimeException('A supplier draft was already attempted. Reconcile the existing attempt.');
        }

        // Verify the supplier account before sending any buyer data.
        [$supplier, $connection] = $this->preparedOrder($tenantId, $siteId, $orderId);
        $this->verifyShop($connection);

        [$tag, $payload] = DB::transaction(function () use ($tenantId, $siteId, $orderId, $criteria, $supplier, $connection) {
            [$currentSupplier, $currentConnection, $payload] = $this->preparedOrder($tenantId, $siteId, $orderId, lock: true);
            if ($currentSupplier !== $supplier || $currentConnection !== $connection || $criteria->exists()) {
                throw new RuntimeException('The supplier route or attempt has changed. Reconcile the existing attempt.');
            }
            $tag = 'everbranch-po-'.Str::uuid();
            $criteria->insert([
                'tenant_id' => $tenantId,
                'tenant_site_id' => $siteId,
                'website_order_id' => $orderId,
                'supplier_key' => $supplier,
                'shop_id' => $connection['shop_id'],
                'shop_domain' => $connection['shop_domain'],
                'correlation_tag' => $tag,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$tag, [...$payload, 'tags' => ['everbranch-dropship-intake', $tag]]];
        });

        // Shopify draft creation has no idempotency key. Commit the claim before
        // the single remote write, and leave it in place even on a timeout.
        $query = 'mutation SupplierDraftCreate($input: DraftOrderInput!) { draftOrderCreate(input: $input) { draftOrder { '.self::DRAFT_FIELDS.' } userErrors { field } } }';
        $response = $this->graphql($connection, $query, ['input' => $payload]);
        $draft = data_get($response, 'draftOrderCreate.draftOrder');
        $errors = data_get($response, 'draftOrderCreate.userErrors');
        if (! $this->validDraft($draft, $tag) || $errors !== []) {
            throw new RuntimeException('Shopify did not confirm a zero-dollar supplier draft. Review the draft with the supplier and reconcile before taking further action.');
        }

        return $this->record($criteria, $tag, $draft['id']);
    }

    public function reconcile(int $tenantId, int $siteId, int $orderId): string
    {
        $this->assertEnabled();
        $criteria = $this->drafts($tenantId, $siteId, $orderId);
        $attempt = $criteria->first();
        if ($attempt === null) {
            throw new RuntimeException('No supplier draft attempt exists for this seller order.');
        }
        if ($attempt->shopify_draft_id !== null) {
            return $attempt->shopify_draft_id;
        }

        $connection = $this->connection($tenantId, $siteId, $attempt->supplier_key);
        if ($attempt->shop_id !== $connection['shop_id'] || $attempt->shop_domain !== $connection['shop_domain']) {
            throw new RuntimeException('Restore access to the original supplier shop to reconcile this attempt.');
        }
        $this->verifyShop($connection);
        $query = 'query SupplierDraftLookup($filter: String!) { draftOrders(first: 2, query: $filter) { nodes { '.self::DRAFT_FIELDS.' } pageInfo { hasNextPage } } }';
        $response = $this->graphql($connection, $query, ['filter' => 'tag:'.$attempt->correlation_tag]);
        $drafts = $response['draftOrders'] ?? [];
        $matches = $drafts['nodes'] ?? [];
        if (data_get($drafts, 'pageInfo.hasNextPage') !== false || ! is_array($matches) || count($matches) !== 1
            || ! $this->validDraft($matches[0] ?? null, $attempt->correlation_tag)) {
            throw new RuntimeException('The supplier draft remains unverified. Search the original shop using '.$attempt->correlation_tag.' and review before taking further action.');
        }

        return $this->record($criteria, $attempt->correlation_tag, $matches[0]['id']);
    }

    private function preparedOrder(int $tenantId, int $siteId, int $orderId, bool $lock = false): array
    {
        $query = WebsiteOrder::query()->forTenantId($tenantId)->where('tenant_site_id', $siteId)->whereKey($orderId);
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $order || $order->source !== 'native' || $order->order_status !== 'open'
            || $order->payment_status !== 'paid' || $order->paid_at === null || $order->cancelled_at !== null
            || $order->fulfillment_method !== 'ship' || $order->fulfillment_status !== 'unfulfilled'
            || $order->currency !== 'usd' || $order->total_cents <= 0 || (int) $order->refunded_cents !== 0) {
            throw new RuntimeException('Select an unfulfilled US shipping order paid through native Website checkout.');
        }

        $payment = $order->payments()->forTenantId($tenantId)->latest('id')->first();
        if (! $payment || $payment->provider !== 'stripe' || $payment->status !== 'paid'
            || ! preg_match('/^pi_[A-Za-z0-9_]+$/D', (string) $payment->provider_payment_intent_id)
            || $payment->currency !== $order->currency || $payment->amount_cents !== $order->total_cents
            || data_get($payment->metadata, 'checkout_version') !== 1 || data_get($payment->metadata, 'livemode') !== true
            || data_get($payment->metadata, 'paid_confirmed') !== true || data_get($payment->metadata, 'inventory_committed') !== true
            || data_get($payment->metadata, 'paid_total_cents') !== $order->total_cents
            || ! empty($payment->metadata['refunds'])) {
            throw new RuntimeException('Verified live Stripe payment without refund activity is required for a supplier handoff.');
        }

        $supplier = null;
        $location = null;
        $items = [];
        foreach ($order->lines()->forTenantId($tenantId)->where('product_type', 'physical')->orderBy('id')->get() as $line) {
            $route = $line->snapshot['dropshipping'] ?? [];
            $candidate = $route['supplier'] ?? '';
            $variant = $route['variant'] ?? '';
            $origin = $route['location_id'] ?? null;
            if (($route['provider'] ?? null) !== 'shopify' || ! is_string($candidate)
                || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $candidate)
                || ! is_string($variant) || ! preg_match('/^[1-9][0-9]{0,19}$/D', $variant)
                || $line->quantity < 1 || ! is_int($origin) || $origin < 1
                || ($supplier !== null && ($candidate !== $supplier || $origin !== $location))) {
                throw new RuntimeException('All physical lines must have the same approved Shopify supplier and ship-from location.');
            }
            $supplier = $candidate;
            $location = $origin;
            $items[] = [
                'variantId' => 'gid://shopify/ProductVariant/'.$variant,
                'quantity' => $line->quantity,
                'priceOverride' => ['amount' => '0.00', 'currencyCode' => 'USD'],
            ];
        }
        if ($supplier === null || count($items) > 100) {
            throw new RuntimeException('No eligible Shopify supplier lines were found.');
        }

        $address = $this->shipping->address((array) $order->shipping_address);
        [$first, $last] = array_pad(explode(' ', $address['name'], 2), 2, '');
        $reference = (string) $order->number;

        return [$supplier, $this->connection($tenantId, $siteId, $supplier), [
            'presentmentCurrencyCode' => 'USD',
            'note' => 'Everbranch supplier work intake for '.$reference.'. Buyer paid the seller. Keep this as an internal draft. Do not invoice or complete in Shopify.',
            'poNumber' => $reference,
            'visibleToCustomer' => false,
            'acceptAutomaticDiscounts' => false,
            'allowDiscountCodesInCheckout' => false,
            'taxExempt' => true,
            'shippingAddress' => array_filter([
                'firstName' => $last === '' ? '' : $first, 'lastName' => $last === '' ? $first : $last,
                'address1' => $address['street1'], 'address2' => $address['street2'] ?? '',
                'city' => $address['city'], 'provinceCode' => $address['state'],
                'zip' => $address['zip'], 'countryCode' => $address['country'],
            ]),
            'lineItems' => $items,
        ]];
    }

    private function assertEnabled(): void
    {
        if (config('website_dropshipping.shopify_drafts_enabled') !== true) {
            throw new RuntimeException('The supplier draft handoff is disabled.');
        }
    }

    private function connection(int $tenantId, int $siteId, string $supplier): array
    {
        $suppliers = config('website_dropshipping.shopify_suppliers', []);
        $connection = $suppliers[$tenantId][$siteId][$supplier] ?? [];
        if (! is_array($connection) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.myshopify\.com$/D', (string) ($connection['shop_domain'] ?? ''))
            || ! preg_match('~^gid://shopify/Shop/[1-9][0-9]{0,19}$~D', (string) ($connection['shop_id'] ?? ''))
            || ! preg_match('/^[A-Za-z0-9_-]{16,255}$/D', (string) ($connection['admin_token'] ?? ''))) {
            throw new RuntimeException('The seller site lacks an approved Shopify supplier connection.');
        }

        return $connection;
    }

    private function verifyShop(array $connection): void
    {
        $data = $this->graphql($connection, 'query SupplierShop { shop { id myshopifyDomain } }');
        if (data_get($data, 'shop.id') !== $connection['shop_id'] || data_get($data, 'shop.myshopifyDomain') !== $connection['shop_domain']) {
            throw new RuntimeException('The supplier shop does not match the approved Shopify shop.');
        }
    }

    private function graphql(array $connection, string $query, array $variables = []): array
    {
        $url = 'https://'.$connection['shop_domain'].'/admin/api/'.self::API_VERSION.'/graphql.json';
        $body = $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables];
        try {
            $response = Http::acceptJson()->withHeaders(['X-Shopify-Access-Token' => $connection['admin_token']])
                ->withoutRedirecting()->connectTimeout(4)->timeout(12)
                ->post($url, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('Shopify did not respond. Reconcile any recorded attempt before taking further action.');
        }
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            throw new RuntimeException('Shopify returned an unverified response. Reconcile any recorded attempt before taking further action.');
        }

        return $response->json('data');
    }

    private function validDraft(mixed $draft, string $tag): bool
    {
        return is_array($draft) && preg_match('~^gid://shopify/DraftOrder/[1-9][0-9]{0,19}$~D', (string) ($draft['id'] ?? ''))
            && ($draft['status'] ?? null) === 'OPEN' && is_array($draft['tags'] ?? null) && in_array($tag, $draft['tags'], true)
            && ($draft['visibleToCustomer'] ?? null) === false
            && preg_match('/^0(?:\.0+)?$/D', (string) data_get($draft, 'totalPriceSet.shopMoney.amount', ''));
    }

    private function drafts(int $tenantId, int $siteId, int $orderId): Builder
    {
        return DB::table('website_dropship_drafts')->where([
            'tenant_id' => $tenantId, 'tenant_site_id' => $siteId, 'website_order_id' => $orderId,
        ]);
    }

    private function record(Builder $drafts, string $tag, string $id): string
    {
        $drafts->where('correlation_tag', $tag);
        (clone $drafts)->whereNull('shopify_draft_id')->update([
            'shopify_draft_id' => $id, 'updated_at' => now(),
        ]);
        if ($drafts->value('shopify_draft_id') !== $id) {
            throw new RuntimeException('The local supplier draft reference conflicts with Shopify. Review the supplier shop before taking further action.');
        }

        return $id;
    }
}
