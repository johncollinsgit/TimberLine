<?php

namespace Tests\Support\Subscriptions;

use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionShopifyContractService;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Fictional pinned original account; all source, contract, billing and order responses remain local. */
class ShopifyExecutionScenario extends ShopifyAdoptionScenario
{
    public array $bills = [];

    public array $attempts = [];

    public int $activations = 0;

    public int $cancellations = 0;

    public string $attemptState = 'succeeded';

    public ?string $billingDefect = null;

    public bool $loseBillResponse = false;

    public bool $loseActivationResponse = false;

    public bool $otherCollection = false;

    public bool $sourceCollects = false;

    public ?\Closure $beforeBill = null;

    public ?\Closure $beforeBillingCapabilities = null;

    public ?string $billingMarkerPath = null;

    public function __construct(bool $production = false)
    {
        config()->set('subscriptions.replacement_shopify_live_acceptance_verified', $production);
        parent::__construct($production);
        foreach (['servicing', 'collection', 'handoff', 'activation', 'shopify_collection'] as $gate) {
            config()->set('subscriptions.replacement_'.$gate.'_enabled', true);
        }
        $contracts = app(SubscriptionShopifyContractService::class);
        $quote = $contracts->calculate($this->tenant->id, $this->actor, $this->agreement->id, $this->agreement->terms_hash, 1);
        $this->sourceStopped = true;
        $contracts->adopt($this->tenant->id, $this->actor, $this->agreement->id, $quote['quote']['review_hash'], 1);
        $this->agreement->refresh();
        app(SubscriptionShopifyExecutionService::class)->acceptMerchant($this->tenant->id, $this->actor, $this->agreement->id, $this->agreement->terms_hash);
        $this->agreement->refresh();
    }

    public function activate(): void
    {
        $this->agreement = app(SubscriptionShopifyExecutionService::class)->activate($this->tenant->id, $this->actor,
            $this->agreement->id, $this->agreement->terms_hash, $this->agreement->revision);
    }

    private function identity(): array
    {
        return ['shop' => ['id' => 'gid://shopify/Shop/1001', 'myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD',
            'ianaTimezone' => 'America/New_York', 'plan' => ['partnerDevelopment' => ! $this->production]],
            'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001', 'app' => ['id' => 'gid://shopify/App/2001', 'apiKey' => 'fictional-subscription-client'],
                'accessScopes' => array_map(fn ($handle) => ['handle' => $handle], ['read_customer_payment_methods', 'read_own_subscription_contracts',
                    'read_customers', 'read_orders', 'read_products', ...($this->billingDefect === 'history_scope' ? [] : ['read_all_orders']),
                    ...($this->billingDefect === 'write_scope' ? [] : ['write_own_subscription_contracts'])])]];
    }

    public function complete(string $id): void
    {
        $attempt = $this->attempts[$id];
        $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
        $address = $this->address();
        $address['countryCodeV2'] = $address['countryCode'];
        $address['province'] = 'South Carolina';
        unset($address['countryCode']);
        $transaction = ['id' => 'gid://shopify/OrderTransaction/'.(9200 + count($this->attempts)), 'gateway' => 'shopify_payments',
            'kind' => 'SALE', 'status' => 'SUCCESS', 'test' => ! $this->production, 'processedAt' => now()->toISOString(), 'amountSet' => $money('25.00')];
        $order = ['id' => 'gid://shopify/Order/'.(9300 + count($this->attempts)), 'createdAt' => now()->toISOString(), 'processedAt' => now()->toISOString(),
            'updatedAt' => now()->toISOString(), 'test' => ! $this->production, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
            'customer' => ['id' => 'gid://shopify/Customer/456'], 'customAttributes' => [], 'totalPriceSet' => $money('25.00'), 'currentTotalPriceSet' => $money('25.00'),
            'totalShippingPriceSet' => $money('0.00'), 'totalTaxSet' => $money('0.00'), 'totalDiscountsSet' => $money('0.00'), 'refunds' => [], 'disputes' => [],
            'shippingAddress' => $address, 'transactions' => [$transaction],
            'lineItems' => ['nodes' => [['variant' => ['id' => 'gid://shopify/ProductVariant/999'], 'quantity' => 1, 'originalUnitPriceSet' => $money('25.00')]], 'pageInfo' => ['hasNextPage' => false]]];
        $this->attempts[$id] = array_replace($attempt, ['completedAt' => now()->toISOString(),
            'state' => ['__typename' => 'SubscriptionBillingAttemptSuccessState', 'order' => $order],
            'transactions' => ['nodes' => [$transaction], 'pageInfo' => ['hasNextPage' => false]]]);
    }

    private function returnedAttempt(array $attempt): array
    {
        $order = &$attempt['state']['order'];
        switch ($this->billingDefect) {
            case 'attempt_key': $attempt['idempotencyKey'] = 'another-original-key';
                break;
            case 'attempt_contract': $attempt['subscriptionContract']['id'] = 'gid://shopify/SubscriptionContract/8002';
                break;
            case 'attempt_customer': $attempt['subscriptionContract']['customer']['id'] = 'gid://shopify/Customer/999';
                break;
            case 'attempt_origin': $attempt['originTime'] = '2027-02-01T14:00:00Z';
                break;
            case 'attempt_time': $attempt['createdAt'] = '2027-01-01T14:00:00Z';
                break;
            case 'attempt_inventory': $attempt['respectInventoryPolicy'] = false;
                break;
            case 'partial_transactions': $attempt['transactions']['pageInfo']['hasNextPage'] = true;
                break;
            case 'unknown_state': $attempt['state']['__typename'] = 'UnexpectedFutureState';
                break;
            case 'order_customer': $order['customer']['id'] = 'gid://shopify/Customer/999';
                break;
            case 'order_test': $order['test'] = false;
                break;
            case 'order_amount': $order['totalPriceSet']['shopMoney']['amount'] = '30.00';
                break;
            case 'order_address': $order['shippingAddress']['address1'] = 'Changed street';
                break;
            case 'order_quantity': $order['lineItems']['nodes'][0]['quantity'] = 2;
                break;
            case 'order_partial': $order['lineItems']['pageInfo']['hasNextPage'] = true;
                break;
            case 'order_discount': $order['totalDiscountsSet']['shopMoney']['amount'] = '5.00';
                break;
            case 'order_gateway': $order['transactions'][0]['gateway'] = 'manual';
                break;
            case 'order_transaction': $order['transactions'][0]['id'] = 'gid://shopify/OrderTransaction/99999';
                break;
            case 'refund': $order['refunds'] = [['id' => 'gid://shopify/Refund/123']];
                $order['displayFinancialStatus'] = 'REFUNDED';
                break;
            case 'dispute': $order['disputes'] = [['id' => 'gid://shopify/OrderDisputeSummary/123', 'status' => 'NEEDS_RESPONSE']];
                break;
        }

        return $attempt;
    }

    protected function shopify($request)
    {
        $query = $request['query'];
        if (str_contains($query, 'PilotShopifyOrders')) {
            if ($request->header('X-Shopify-Access-Token') !== ['UNRELATED_STORE_TOKEN']) {
                throw new \RuntimeException('Source coverage must use only the owned retail read token.');
            }
            $orders = array_values(array_filter(array_map(fn ($a) => data_get($a, 'state.order'), $this->attempts)));
            if ($this->otherCollection) {
                $other = $orders[0] ?? ['processedAt' => now()->toISOString(), 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString(),
                    'test' => ! $this->production, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'customAttributes' => [],
                    'customer' => ['id' => 'gid://shopify/Customer/456'], 'totalPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                    'transactions' => [['id' => 'gid://shopify/OrderTransaction/777', 'gateway' => 'shopify_payments', 'kind' => 'SALE', 'status' => 'SUCCESS',
                        'amountSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']]]]];
                $other['id'] = 'gid://shopify/Order/777';
                $orders[] = $other;
            }
            $test = str_contains($request['variables']['query'] ?? '', 'test:true');
            $orders = array_values(array_filter($orders, fn ($order) => $order['test'] === $test));

            return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain],
                'orders' => ['nodes' => $orders, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]], 200, ['X-Shopify-API-Version' => '2026-10']);
        }
        if (! preg_match('/Replacement(BillingCapabilities|BillingCycle|OriginalBillingAttempt|OriginalContractAttempts|BillOriginalCycle|ActivateOwnedContract|CancelOwnedContract)/', $query)) {
            return parent::shopify($request);
        }
        if ($request->url() !== 'https://'.$this->store->shop_domain.'/admin/api/2026-10/graphql.json'
            || $request->header('X-Shopify-Access-Token') !== ['ORIGINAL_SUBSCRIPTION_TOKEN']) {
            throw new \RuntimeException('Use only the pinned original subscription installation.');
        }
        $identity = $this->identity();
        if (str_starts_with($query, 'query ReplacementBillingCapabilities')) {
            if ($hook = $this->beforeBillingCapabilities) {
                $this->beforeBillingCapabilities = null;
                $hook();
            }
            $names = fn ($values) => array_map(fn ($name) => ['name' => $name], $values);
            $data = $identity + ['mutationType' => ['fields' => $names($this->billingDefect === 'unsupported_api' ? [] : ['subscriptionBillingAttemptCreate', 'subscriptionContractActivate', 'subscriptionContractCancel', 'subscriptionContractPause', 'subscriptionContractSetNextBillingDate'])],
                'attemptType' => ['fields' => $names(['state'])], 'inputType' => ['inputFields' => $names(['actor', 'billingCycleSelector', 'idempotencyKey', 'inventoryPolicy', 'originTime', 'paymentProcessingPolicy'])],
                'stateType' => ['possibleTypes' => $names(['SubscriptionBillingAttemptPendingState', 'SubscriptionBillingAttemptActionRequiredState', 'SubscriptionBillingAttemptFailedState', 'SubscriptionBillingAttemptSuccessState'])]];
        } elseif (str_starts_with($query, 'query ReplacementBillingCycle')) {
            $instant = $request['variables']['input']['selector']['date'];
            $matching = array_values(array_filter($this->attempts, fn ($a) => $a['originTime'] === $instant));
            $data = $identity + ['subscriptionBillingCycle' => ['billingAttemptExpectedDate' => $instant, 'cycleIndex' => 1,
                'cycleStartAt' => CarbonImmutable::parse($instant)->startOfDay()->toISOString(), 'cycleEndAt' => CarbonImmutable::parse($instant)->addMonthNoOverflow()->toISOString(),
                'edited' => $this->billingDefect === 'edited_cycle', 'skipped' => $this->billingDefect === 'skipped_cycle', 'status' => $this->billingDefect === 'billed_cycle' ? 'BILLED' : 'UNBILLED',
                'sourceContract' => ['id' => $this->contract['id'], 'app' => $this->contract['app'], 'customer' => $this->contract['customer']],
                'billingAttempts' => ['nodes' => array_map(fn ($a) => ['id' => $a['id']], $matching), 'pageInfo' => ['hasNextPage' => false]]]];
        } elseif (str_starts_with($query, 'mutation ReplacementActivateOwnedContract')) {
            $this->activations++;
            $this->contract['status'] = 'ACTIVE';
            if ($this->loseActivationResponse) {
                throw new ConnectionException('Fictional activation response lost.');
            }
            $data = ['subscriptionContractActivate' => ['contract' => ['id' => $this->contract['id'], 'status' => 'ACTIVE'], 'userErrors' => []]];
        } elseif (str_starts_with($query, 'mutation ReplacementCancelOwnedContract')) {
            $this->cancellations++;
            $this->contract['status'] = 'CANCELLED';
            $data = ['subscriptionContractCancel' => ['contract' => ['id' => $this->contract['id'], 'status' => 'CANCELLED'], 'userErrors' => []]];
        } elseif (str_starts_with($query, 'mutation ReplacementBillOriginalCycle')) {
            $cycle = SubscriptionBillingCycle::where('request_uuid', $request['variables']['input']['idempotencyKey'])->firstOrFail();
            if (! $cycle->payment_started_at || ! data_get($cycle->reconciliation, 'dispatch_started_at')) {
                throw new \RuntimeException('A durable original dispatch is required before the provider receives a request.');
            }
            $this->bills[] = $request['variables'];
            if ($this->billingMarkerPath) {
                file_put_contents($this->billingMarkerPath, "bill\n", FILE_APPEND | LOCK_EX);
            }
            $id = 'gid://shopify/SubscriptionBillingAttempt/'.(9100 + count($this->bills));
            $this->attempts[$id] = ['id' => $id, 'idempotencyKey' => $cycle->request_uuid, 'createdAt' => now()->toISOString(), 'completedAt' => null,
                'originTime' => $request['variables']['input']['originTime'], 'respectInventoryPolicy' => true,
                'subscriptionContract' => ['id' => $this->contract['id'], 'app' => $this->contract['app'], 'customer' => $this->contract['customer']],
                'state' => match ($this->attemptState) {
                    'processing' => ['__typename' => 'SubscriptionBillingAttemptPendingState', 'processing' => true],
                    'requires_action' => ['__typename' => 'SubscriptionBillingAttemptActionRequiredState', 'action' => ['__typename' => 'SubscriptionBillingAttemptPaymentChallenge']],
                    'declined' => ['__typename' => 'SubscriptionBillingAttemptFailedState', 'error' => ['__typename' => 'SubscriptionBillingAttemptPaymentError']],
                    default => [],
                }, 'transactions' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]];
            if ($this->attemptState === 'succeeded') {
                $this->complete($id);
            }
            if ($hook = $this->beforeBill) {
                $this->beforeBill = null;
                $hook();
            }
            if ($this->loseBillResponse) {
                throw new ConnectionException('Fictional billing response lost after provider receipt.');
            }
            $data = ['subscriptionBillingAttemptCreate' => ['subscriptionBillingAttempt' => $this->returnedAttempt($this->attempts[$id]), 'userErrors' => []]];
        } elseif (str_starts_with($query, 'query ReplacementOriginalBillingAttempt')) {
            $attempt = $this->attempts[$request['variables']['id']] ?? null;
            $data = $identity + ['subscriptionBillingAttempt' => $attempt ? $this->returnedAttempt($attempt) : null];
        } else {
            $nodes = array_values(array_map(fn ($a) => $this->returnedAttempt($a), $this->attempts));
            if ($this->billingDefect === 'duplicate_attempt' && $nodes !== []) {
                $nodes[] = array_replace($nodes[0], ['id' => 'gid://shopify/SubscriptionBillingAttempt/9999']);
            }
            $data = $identity + ['subscriptionContract' => ['id' => $this->contract['id'], 'app' => $this->contract['app'], 'customer' => $this->contract['customer'],
                'billingAttempts' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $this->billingDefect === 'incomplete_recovery', 'endCursor' => null]]]];
        }

        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }

    protected function recharge($request)
    {
        if ($this->sourceCollects && str_contains($request->url(), '/charges')) {
            return Http::response(['charges' => [['id' => 777, 'status' => 'success']], 'next_cursor' => null], 200, ['X-Recharge-Version' => '2021-11']);
        }

        return parent::recharge($request);
    }
}
