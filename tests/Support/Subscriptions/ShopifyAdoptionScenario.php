<?php

namespace Tests\Support\Subscriptions;

use App\Services\Subscriptions\SubscriptionShopifyContractProtocol;
use App\Services\Subscriptions\SubscriptionShopifyMethodService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Fictional original-app calculation/commit server; a commit creates a contract, never a payment/order. */
class ShopifyAdoptionScenario extends ShopifyReuseScenario
{
    public bool $sourceStopped = false;

    public bool $pending = false;

    public ?string $adoptionDefect = null;

    public int $calculates = 0;

    public int $commits = 0;

    public int $pauses = 0;

    public int $dateChanges = 0;

    public ?array $contract = null;

    public ?\Closure $beforeCalculate = null;

    public ?\Closure $beforeCommit = null;

    public ?string $dispatchMarkerPath = null;

    public function __construct()
    {
        parent::__construct();
        $scopes = ['read_store', 'read_customers', 'read_subscriptions', 'read_payment_methods', 'read_orders'];
        $this->source->update(['scopes' => $scopes]);
        $this->sourceRevision->update(['scopes' => $scopes]);
        config()->set('subscriptions.replacement_shopify_adoption_enabled', true);
        app(SubscriptionShopifyMethodService::class)->verify($this->tenant->id, $this->actor, $this->agreement->id, '111');
        $this->agreement->refresh();
    }

    public function terms(): array
    {
        $state = data_get($this->agreement->fresh()->authorization_evidence, 'shopify_adoption');
        $money = fn ($amount) => ['amount' => $amount, 'currencyCode' => 'USD'];
        $address = $this->address();
        $address['countryCodeV2'] = $address['countryCode'];
        unset($address['countryCode']);
        $anchors = [['type' => 'MONTHDAY', 'day' => 31, 'month' => null, 'cutoffDay' => null]];

        return ['currencyCode' => 'USD', 'customer' => ['id' => 'gid://shopify/Customer/456'],
            'customerPaymentMethod' => ['id' => 'gid://shopify/CustomerPaymentMethod/original_method', 'revokedAt' => null],
            'customAttributes' => SubscriptionShopifyContractProtocol::input($this->agreement, $state['original']['request_uuid'])['customAttributes'],
            'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1, 'minCycles' => null, 'maxCycles' => null, 'anchors' => $anchors],
            'deliveryPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1, 'anchors' => $anchors, 'multiFulfillment' => null],
            'deliveryPrice' => $money('0.00'), 'deliveryMethod' => ['__typename' => 'SubscriptionDeliveryMethodShipping', 'address' => $address],
            'lines' => ['nodes' => [['variantId' => 'gid://shopify/ProductVariant/999', 'quantity' => 1, 'currentPrice' => $money('25.00'),
                'requiresShipping' => true, 'customAttributes' => [], 'discountAllocations' => [], 'pricingPolicy' => ['basePrice' => $money('25.00'), 'cycleDiscounts' => []]]], 'pageInfo' => ['hasNextPage' => false]],
            'discounts' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]];
    }

    public function calculation(): array
    {
        $id = 'gid://shopify/SubscriptionContractCalculation/original_calculation';
        if ($this->pending) {
            return ['__typename' => 'SubscriptionContractCalculationPending', 'id' => $id];
        }
        $money = fn ($amount) => ['amount' => $amount, 'currencyCode' => 'USD'];
        $result = ['__typename' => 'SubscriptionContractCalculationSuccess', 'id' => $id, 'withMerchandiseCustomizations' => false,
            'warnings' => $this->adoptionDefect === 'warning' ? [['code' => 'DELIVERY_OPTION_NOT_FOUND', 'field' => ['deliveryMethod']]] : [],
            'projectedOrderTotals' => ['subtotal' => $money('25.00'), 'total' => $money('25.00'), 'totalDelivery' => $money('0.00'),
                'totalTax' => $money($this->adoptionDefect === 'tax' ? '2.00' : '0.00'), 'totalDeliveryDiscounts' => $money('0.00'), 'totalMerchandiseDiscounts' => $money('0.00')],
            'calculatedContract' => $this->terms()];
        if ($this->adoptionDefect === 'quantity') {
            $result['calculatedContract']['lines']['nodes'][0]['quantity'] = 2;
        }

        return $result;
    }

    protected function shopify($request)
    {
        $query = $request['query'];
        if (str_contains($query, 'ReplacementSavedMethod') || str_contains($query, 'ReplacementSourceOrder')) {
            return parent::shopify($request);
        }
        if (str_contains($query, 'PilotShopifyOrders')) {
            if ($request->header('X-Shopify-Access-Token') !== ['UNRELATED_STORE_TOKEN']) {
                throw new \RuntimeException('Source coverage must use its exact owned retail read credential.');
            }

            return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain], 'orders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        }
        if ($request->url() !== 'https://'.$this->store->shop_domain.'/admin/api/2026-10/graphql.json'
            || $request->header('X-Shopify-Access-Token') !== ['ORIGINAL_SUBSCRIPTION_TOKEN']) {
            throw new \RuntimeException('Only the original subscription app can calculate/adopt contracts.');
        }
        $state = data_get($this->agreement->fresh()->authorization_evidence, 'shopify_adoption', []);
        $identity = ['shop' => ['id' => 'gid://shopify/Shop/1001', 'myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD',
            'ianaTimezone' => 'America/New_York', 'plan' => ['partnerDevelopment' => true]],
            'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001', 'app' => ['id' => 'gid://shopify/App/2001', 'apiKey' => 'fictional-subscription-client'],
                'accessScopes' => array_map(fn ($handle) => ['handle' => $handle], ['read_customer_payment_methods', 'read_own_subscription_contracts',
                    'read_customers', 'read_orders', 'read_products', ...($this->adoptionDefect === 'write_scope' ? [] : ['write_own_subscription_contracts'])])]];
        if (str_starts_with($query, 'query ReplacementContractCapabilities')) {
            if ($this->adoptionDefect === 'source_rotated' && isset($state['commit_original'])) {
                $this->source->update(['read_pause_reason' => 'fictional source pause before commit']);
            }
            $names = fn ($values) => array_map(fn ($name) => ['name' => $name], $values);
            $data = $identity + ['mutationType' => ['fields' => $names($this->adoptionDefect === 'unsupported_api' ? [] : ['subscriptionContractCreateCalculate', 'subscriptionContractCalculationCommit', 'subscriptionContractPause', 'subscriptionContractSetNextBillingDate'])],
                'queryType' => ['fields' => $names(['subscriptionContractCalculation'])], 'deliveryPolicyType' => ['fields' => $names(['multiFulfillment'])],
                'createInputType' => ['inputFields' => $names(['actor', 'appManagedBillingConfig', 'billingPolicy', 'currencyCode', 'customAttributes',
                    'customerId', 'deliveryMethod', 'deliveryPolicy', 'discountCodes', 'lines', 'manualDiscounts', 'note', 'paymentMethod', 'withMerchandiseCustomizations'])]];
        } elseif (str_starts_with($query, 'mutation ReplacementCalculateContract')) {
            if (! isset($state['calculate_dispatched_at']) || $request['variables']['input'] !== $state['original']['input']) {
                throw new \RuntimeException('Original calculation must be durable before dispatch.');
            }
            $this->calculates++;
            if ($this->dispatchMarkerPath) {
                file_put_contents($this->dispatchMarkerPath, "calculate\n", FILE_APPEND | LOCK_EX);
            }
            if ($hook = $this->beforeCalculate) {
                $this->beforeCalculate = null;
                $hook();
            }
            if ($this->adoptionDefect === 'lost_calculation') {
                throw new ConnectionException('Fictional lost calculation response.');
            }
            $data = ['subscriptionContractCreateCalculate' => ['subscriptionContractCalculation' => $this->adoptionDefect === 'missing_calculation_result' ? null : $this->calculation(), 'userErrors' => []]];
        } elseif (str_starts_with($query, 'query ReplacementContractCalculation')) {
            $data = $identity + ['subscriptionContractCalculation' => $this->calculation()];
        } elseif (str_starts_with($query, 'mutation ReplacementCommitContract')) {
            if (! isset($state['commit_dispatched_at'], $state['commit_dispatch_proof']) || $request['variables']['id'] !== $state['calculation_id']) {
                throw new \RuntimeException('Original commit/source-stop proof must be durable before dispatch.');
            }
            $this->commits++;
            if ($this->dispatchMarkerPath) {
                file_put_contents($this->dispatchMarkerPath, "commit\n", FILE_APPEND | LOCK_EX);
            }
            if ($hook = $this->beforeCommit) {
                $this->beforeCommit = null;
                $hook();
            }
            $this->contract = $this->terms() + ['id' => 'gid://shopify/SubscriptionContract/8001', 'app' => ['id' => 'gid://shopify/App/2001'], 'status' => 'ACTIVE', 'nextBillingDate' => '2027-02-01T14:00:00Z'];
            if ($this->adoptionDefect === 'lost_commit') {
                throw new ConnectionException('Fictional commit succeeded but response was lost.');
            }
            if ($this->adoptionDefect === 'changed_contract') {
                $this->contract['lines']['nodes'][0]['currentPrice']['amount'] = '30.00';
            }
            $data = ['subscriptionContractCalculationCommit' => ['contract' => $this->adoptionDefect === 'missing_commit_result' ? null : ['__typename' => 'SubscriptionContract'] + $this->contract, 'userErrors' => []]];
        } elseif (str_starts_with($query, 'query ReplacementOriginalCustomerContracts')) {
            $nodes = $this->contract ? [$this->contract] : [];
            if ($this->adoptionDefect === 'two_matches' && $nodes !== []) {
                $nodes[] = array_replace($this->contract, ['id' => 'gid://shopify/SubscriptionContract/8002']);
            }
            $data = $identity + ['customer' => ['id' => 'gid://shopify/Customer/456', 'subscriptionContracts' => ['nodes' => $nodes,
                'pageInfo' => ['hasNextPage' => $this->adoptionDefect === 'incomplete_recovery']]]];
        } elseif (str_starts_with($query, 'query ReplacementOwnedContract')) {
            $data = $identity + ['subscriptionContract' => $this->contract];
        } elseif (str_starts_with($query, 'mutation ReplacementPauseAdoptedContract')) {
            $this->pauses++;
            $this->contract['status'] = 'PAUSED';
            if ($this->adoptionDefect === 'lost_pause') {
                throw new ConnectionException('Fictional pause succeeded but response was lost.');
            }
            $data = ['subscriptionContractPause' => ['contract' => ['id' => $this->contract['id'], 'status' => 'PAUSED'], 'userErrors' => []]];
        } elseif (str_starts_with($query, 'mutation ReplacementSetOriginalBillingDate')) {
            $this->dateChanges++;
            $this->contract['nextBillingDate'] = $request['variables']['date'];
            if ($this->adoptionDefect === 'lost_date') {
                throw new ConnectionException('Fictional date update succeeded but response was lost.');
            }
            $data = ['subscriptionContractSetNextBillingDate' => ['contract' => ['id' => $this->contract['id'], 'nextBillingDate' => $this->contract['nextBillingDate']], 'userErrors' => []]];
        } else {
            throw new \RuntimeException('Unexpected fictional contract operation; billing and order creation are forbidden.');
        }

        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }

    protected function recharge($request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (in_array($path, ['/token_information', '/charges'], true)) {
            if ($request->method() !== 'GET' || $request->header('X-Recharge-Access-Token') !== ['ORIGINAL_RECHARGE_TOKEN']) {
                throw new \RuntimeException('Use the original source read credential only.');
            }
            $data = $path === '/charges' ? ['charges' => [], 'next_cursor' => null]
                : ['token_information' => ['scopes' => ['read_store', 'read_customers', 'read_subscriptions', 'read_orders', 'read_payment_methods']]];
        } else {
            $data = json_decode((string) parent::recharge($request)->wait()->getBody(), true, 64, JSON_THROW_ON_ERROR);
            if ($path === '/customers/123') {
                $data['customer']['subscriptions_active_count'] = $this->sourceStopped ? 0 : 1;
            }
            if ($path === '/subscriptions/789' && $this->sourceStopped) {
                $data['subscription'] = array_replace($data['subscription'], ['status' => 'cancelled', 'has_queued_charges' => false, 'next_charge_scheduled_at' => null]);
            }
        }

        return Http::response($data, 200, ['X-Recharge-Version' => '2021-11']);
    }
}
