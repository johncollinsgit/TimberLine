<?php

namespace Tests\Support\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionRechargeConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingShopifySource;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionBraintreeMethodService;
use App\Services\Subscriptions\SubscriptionBraintreeTransport;
use App\Services\Subscriptions\SubscriptionPilotService;
use App\Services\Subscriptions\SubscriptionPilotSourceGuard;
use Braintree\Gateway;
use Braintree\ResourceCollection;
use Braintree\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;

/** Real SDK data/search nodes and transport, with no provider network or actual payment. */
class BraintreeScenario
{
    public Tenant $tenant;

    public User $actor;

    public ShopifyStore $store;

    public IntegrationConnection $connection;

    public SubscriptionBillingAgreement $agreement;

    public SubscriptionRechargeConnection $source;

    public array $sales = [];

    public array $transactions = [];

    public array $orderCreates = [];

    public array $searches = [];

    public array $preparation = [];

    public array $historicalOrderScopes = ['read_orders', 'read_all_orders'];

    public ?array $savedOrder = null;

    public ?string $defect = null;

    public bool $lostSaleResponse = false;

    public bool $lostOrderResponse = false;

    public bool $settled = true;

    public bool $sourceStopped = true;

    public bool $sourceRestartsAfterSale = false;

    public bool $sourceCollectsAfterSale = false;

    public bool $rejectSale = false;

    public bool $declineSale = false;

    public bool $card = false;

    public ?string $saleMarkerPath = null;

    public ?\Closure $beforeSale = null;

    public ?\Closure $beforeOrderCreate = null;

    public ?\Closure $amendmentResponder = null;

    public function __construct(public bool $production = false, public bool $candleClub = true)
    {
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => 'Fictional Braintree subscriptions', 'slug' => 'braintree-execution-'.Str::lower(Str::random(8))]);
        TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available',
            'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
        $this->store = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail-'.$this->tenant->id, 'shop_domain' => 'fictional-braintree-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fictional-shop-token']);
        $member = app(SubscriptionPilotService::class)->prepare($this->tenant->id, $this->actor, [
            'shopify_store_id' => $this->store->id, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'name' => 'Fictional member', 'email' => 'member@example.test',
            'target_date' => '2027-01-31', 'timezone' => 'America/New_York', 'currency' => 'USD', 'expected_amount_cents' => 2500,
            'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123', 'observed_at' => now()->toISOString(),
            'lines' => [['subscription_id' => '789', 'variant_id' => '999', 'label' => 'Monthly candle', 'quantity' => 1, 'unit_price_cents' => 2500,
                'billing_months' => 1, 'delivery_months' => 1, 'prepaid' => false]]]);
        $environment = $production ? 'production' : 'sandbox';
        $credentials = ['environment' => $environment, 'merchant_id' => 'fictionalmerchant', 'merchant_account_id' => 'fictionalUSD',
            'public_key' => 'fictional_public_key', 'private_key' => 'fictional_private_key'];
        $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'braintree', 'status' => 'connected',
            'external_account_id' => $environment.'|fictionalmerchant|fictionalUSD', 'access_token' => json_encode($credentials),
            'metadata' => ['purpose' => 'merchant_payment_verification', 'environment' => $environment, 'merchant_id' => 'fictionalmerchant',
                'merchant_account_id' => 'fictionalUSD', 'currency' => 'USD', 'credential_revision' => (string) Str::uuid(), 'account_read_verified' => true]]);
        $this->source = SubscriptionRechargeConnection::create(['tenant_id' => $this->tenant->id, 'platform' => 'shopify', 'identifier' => $this->store->shop_domain,
            'provider_store_id' => (string) (770 + $this->tenant->id), 'api_version' => '2021-11', 'status' => 'verified']);
        foreach (['setup', 'servicing', 'collection', 'handoff'] as $gate) {
            config()->set('subscriptions.replacement_'.$gate.'_enabled', true);
        }
        config()->set('subscriptions.replacement_activation_enabled', true);
        config()->set('subscriptions.replacement_braintree_collection_enabled', true);
        config()->set('subscriptions.replacement_tenant_ids', [$this->tenant->id]);
        config()->set('subscriptions.replacement_member_ids', [$member->id]);
        $input = ['processor' => 'braintree', 'shipping_cents' => 0, 'tax_cents' => 0, 'shipping_address' => $this->address(),
            'method_binding' => ['connection_id' => $this->connection->id, 'customer_id' => 'customer123', 'payment_method_token' => 'private_method_token', 'source_payment_reference' => 'source_txn'],
            'membership_program' => 'candle_club', 'membership_reference' => 'original-club:789', 'source_membership_reviewed' => true,
            'authorization_reference' => 'original-agreement:123', 'source_terms_reviewed' => true, 'continuing_authorization_reviewed' => true];
        if (! $candleClub) {
            unset($input['membership_program'], $input['membership_reference'], $input['source_membership_reviewed']);
        }
        $this->preparation = $input;
        $service = app(SubscriptionBillingAgreementService::class);
        $review = $service->review($this->tenant->id, $this->actor, $member->id, $input);
        $this->agreement = $service->prepare($this->tenant->id, $this->actor, $member->id, $input, $review['terms_hash']);
        $guard = Mockery::mock(SubscriptionPilotSourceGuard::class);
        $guard->shouldReceive('stopped')->andReturnUsing(function () {
            abort_unless($this->sourceStopped, 409, 'Source billing still active.');
            if ($this->sourceCollectsAfterSale && $this->sales !== []) {
                throw new \App\Exceptions\SubscriptionPilotCoverageException('recharge_collected_cycle_charge', 'Fictional additional source payment.');
            }

            return ['checked_at' => now()->toISOString(), 'pending_charges' => 0, 'collected_cycle_charges' => 0];
        });
        app()->instance(SubscriptionPilotSourceGuard::class, $guard);
        $this->mockSdk();
        Http::fake(['https://'.$this->store->shop_domain.'/admin/api/2026-10/graphql.json' => fn ($request) => $this->shopify($request)]);
    }

    public function address(): array
    {
        return ['firstName' => 'Fictional', 'lastName' => 'Member', 'address1' => '123 Example Street', 'city' => 'Greenville',
            'provinceCode' => 'SC', 'countryCode' => 'US', 'zip' => '29601'];
    }

    public function activate(): SubscriptionBillingAgreement
    {
        app(SubscriptionBraintreeMethodService::class)->verify($this->tenant->id, $this->actor, $this->agreement->id, '111');
        $this->agreement = app(SubscriptionBraintreeExecutionService::class)->activate($this->tenant->id, $this->actor, $this->agreement->id,
            $this->source->id, $this->agreement->terms_hash, $this->agreement->revision);

        return $this->agreement;
    }

    public function transaction(string $id, array $payload = []): Transaction
    {
        $source = $id === 'source_txn';
        $data = ['id' => $id, 'type' => 'sale', 'status' => $source || $this->settled ? 'settled' : 'submitted_for_settlement',
            'amount' => $source ? '25.00' : ($payload['amount'] ?? '25.00'), 'currencyIsoCode' => 'USD', 'merchantAccountId' => 'fictionalUSD', 'customer' => ['id' => 'customer123'],
            'orderId' => $source ? 'source_invoice' : ($payload['orderId'] ?? null), 'createdAt' => $source ? new \DateTimeImmutable('2027-01-01T15:00:00Z') : new \DateTimeImmutable(now()->toISOString()),
            'paymentInstrumentType' => $this->card ? 'credit_card' : 'paypal_account', 'recurring' => true, 'refundIds' => [], 'disputes' => []];
        if ($this->defect === 'original_customer' && $source) {
            $data['customer']['id'] = 'different_customer';
        }
        if ($this->defect === 'payment_amount' && ! $source) {
            $data['amount'] = '99.00';
        }

        return Transaction::factory($data);
    }

    private function mockSdk(): void
    {
        $account = Mockery::mock();
        $account->shouldReceive('find')->andReturn((object) ['id' => 'fictionalUSD', 'status' => 'active', 'currencyIsoCode' => 'USD']);
        $customer = Mockery::mock();
        $customer->shouldReceive('find')->andReturn((object) ['id' => 'customer123']);
        $method = Mockery::mock();
        $method->shouldReceive('find')->andReturnUsing(function () {
            $attributes = ['token' => 'private_method_token', 'customerId' => 'customer123',
                'billingAgreementId' => $this->defect === 'no_agreement' ? null : 'B-fictional', 'revokedAt' => $this->defect === 'revoked' ? new \DateTimeImmutable : null,
                'expired' => $this->defect === 'expired', 'last4' => '4242', 'subscriptions' => $this->defect === 'native_subscription' ? [['id' => 'native_subscription', 'status' => 'Active']] : []];
            if ($this->defect === 'rotated') {
                $meta = $this->connection->metadata;
                $meta['credential_revision'] = (string) Str::uuid();
                $this->connection->update(['metadata' => $meta]);
            }

            return $this->card ? \Braintree\CreditCard::factory($attributes) : \Braintree\PayPalAccount::factory($attributes);
        });
        $transactions = Mockery::mock();
        $transactions->shouldReceive('find')->andReturnUsing(fn ($id) => $id === 'source_txn' ? $this->transaction('source_txn') : $this->transactions[$id]);
        $transactions->shouldReceive('sale')->andReturnUsing(function (array $payload) {
            if ($hook = $this->beforeSale) {
                $this->beforeSale = null;
                $hook();
            }
            if ($this->saleMarkerPath) {
                file_put_contents($this->saleMarkerPath, "sale\n", FILE_APPEND | LOCK_EX);
            }
            $this->sales[] = $payload;
            if ($this->sourceRestartsAfterSale) {
                $this->sourceStopped = false;
            }
            if ($this->rejectSale) {
                return new \Braintree\Result\Error(['errors' => []]);
            }
            $id = 'txn_'.count($this->sales);
            $transaction = $this->transaction($id, $payload);
            if ($this->declineSale) {
                $transaction->_set('status', 'processor_declined');
            }
            $this->transactions[$id] = $transaction;
            if ($this->lostSaleResponse) {
                throw new \RuntimeException('Fictional lost response with sensitive provider details that must not escape.');
            }

            return (object) ['success' => true, 'transaction' => $transaction];
        });
        $transactions->shouldReceive('search')->andReturnUsing(function ($criteria) {
            $params = [];
            foreach ($criteria as $node) {
                $params[$node->name] = $node->toParam();
            }
            $this->searches[] = $params;
            if (isset($params['id'])) {
                $rows = $this->defect === 'wrong_token' ? [] : [$this->transaction('source_txn')];
            } elseif (isset($params['order_id'])) {
                $orderId = $params['order_id']['is'];
                $rows = array_values(array_filter($this->transactions, fn ($transaction) => $transaction->orderId === $orderId));
            } else {
                $rows = array_values($this->transactions);
            }
            $pager = new class($rows)
            {
                public function __construct(private array $rows) {}

                public function fetch(array $ids): array
                {
                    return array_values(array_filter($this->rows, fn ($row) => in_array($row->id, $ids, true)));
                }
            };

            return new ResourceCollection(['searchResults' => ['pageSize' => 50, 'ids' => array_map(fn ($row) => $row->id, $rows)]],
                ['object' => $pager, 'method' => 'fetch', 'methodArgs' => []]);
        });
        $gateway = Mockery::mock(Gateway::class);
        $gateway->shouldReceive('merchantAccount')->andReturn($account);
        $gateway->shouldReceive('customer')->andReturn($customer);
        $gateway->shouldReceive('paymentMethod')->andReturn($method);
        $gateway->shouldReceive('transaction')->andReturn($transactions);
        $transport = Mockery::mock(SubscriptionBraintreeTransport::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $transport->shouldReceive('gateway')->andReturn($gateway);
        app()->instance(SubscriptionBraintreeTransport::class, $transport);
    }

    private function shopify($request)
    {
        $query = $request['query'];
        if ($this->amendmentResponder && in_array($query, [\App\Services\Subscriptions\SubscriptionAmendmentQuote::CATALOG, \App\Services\Subscriptions\SubscriptionAmendmentQuote::CALCULATE], true)) {
            return ($this->amendmentResponder)($request);
        }
        $data = ['shop' => ['myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD', 'plan' => ['partnerDevelopment' => $this->defect !== 'production_store']]];
        if ($query === SubscriptionBillingShopifySource::SOURCE_QUERY) {
            $data['currentAppInstallation'] = ['accessScopes' => array_map(fn ($scope) => ['handle' => $scope], $this->historicalOrderScopes)];
            $address = $this->address();
            $address['countryCodeV2'] = $address['countryCode'];
            unset($address['countryCode']);
            $data['order'] = ['id' => 'gid://shopify/Order/111', 'updatedAt' => '2027-01-02T15:00:00Z', 'processedAt' => '2027-01-02T15:00:00Z',
                'createdAt' => '2027-01-02T15:00:00Z', 'test' => ! $this->production, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID', 'customer' => ['id' => 'gid://shopify/Customer/456'],
                'customAttributes' => [['key' => 'rc_payment_transaction_id', 'value' => $this->defect === 'source_reference' ? 'other_payment' : 'source_txn'], ['key' => 'rc_subscription_ids', 'value' => '789']],
                'totalPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                'totalShippingPriceSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
                'totalTaxSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']], 'shippingAddress' => $address,
                'lineItems' => ['nodes' => [['variant' => ['id' => 'gid://shopify/ProductVariant/999'], 'quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']]]], 'pageInfo' => ['hasNextPage' => false]]];
        } elseif (str_contains($query, 'PilotQuote')) {
            $data['customer'] = ['id' => 'gid://shopify/Customer/456'];
            $data['nodes'] = array_map(fn ($id) => ['id' => $id, 'product' => ['status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]], $request['variables']['variants']);
        } elseif (str_contains($query, 'PilotShopifyPermissions')) {
            $data['currentAppInstallation'] = ['accessScopes' => array_map(fn ($scope) => ['handle' => $scope], ['write_orders', 'read_customers', 'read_products'])];
        } elseif (str_contains($query, 'PilotOrderRecovery')) {
            $data['orders'] = ['nodes' => $this->savedOrder ? [$this->savedOrder] : [], 'pageInfo' => ['hasNextPage' => false]];
        } elseif (str_contains($query, 'mutation PilotPaidOrder')) {
            if ($hook = $this->beforeOrderCreate) {
                $this->beforeOrderCreate = null;
                $hook();
            }
            $payload = $request['variables'];
            $this->orderCreates[] = $payload;
            $cycle = SubscriptionBillingCycle::latest('id')->firstOrFail();
            $address = $cycle->terms['shipping_address'];
            $address['countryCodeV2'] = $address['countryCode'];
            unset($address['countryCode']);
            $this->savedOrder = ['id' => 'gid://shopify/Order/222', 'processedAt' => now()->toISOString(), 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString(),
                'test' => ! $this->production, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
                'customer' => ['id' => 'gid://shopify/Customer/456'], 'customAttributes' => $payload['order']['customAttributes'],
                'totalPriceSet' => ['shopMoney' => ['amount' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::decimal($cycle->amount_cents), 'currencyCode' => 'USD']],
                'totalShippingPriceSet' => ['shopMoney' => ['amount' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::decimal($cycle->terms['shipping_cents']), 'currencyCode' => 'USD']],
                'totalTaxSet' => ['shopMoney' => ['amount' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::decimal($cycle->terms['tax_cents']), 'currencyCode' => 'USD']], 'shippingAddress' => $address,
                'lineItems' => ['nodes' => array_map(fn ($line) => ['variant' => ['id' => 'gid://shopify/ProductVariant/'.$line['variant_id']], 'quantity' => $line['quantity'], 'originalUnitPriceSet' => ['shopMoney' => ['amount' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::decimal($line['unit_price_cents']), 'currencyCode' => 'USD']]], $cycle->terms['lines']), 'pageInfo' => ['hasNextPage' => false]],
                'transactions' => [['id' => 'gid://shopify/OrderTransaction/333', 'gateway' => 'everbranch_braintree', 'paymentId' => null,
                    'authorizationCode' => $cycle->provider_reference, 'kind' => 'SALE', 'status' => 'SUCCESS', 'amountSet' => ['shopMoney' => ['amount' => \App\Services\Subscriptions\SubscriptionPilotShopifyBridge::decimal($cycle->amount_cents), 'currencyCode' => 'USD']]]]];
            if ($this->defect === 'handoff_address') {
                $this->savedOrder['shippingAddress']['address1'] = 'Different delivery address';
            } elseif ($this->defect === 'handoff_tax') {
                $this->savedOrder['totalTaxSet']['shopMoney']['amount'] = '1.00';
            } elseif ($this->defect === 'handoff_receipt') {
                $this->savedOrder['transactions'][0]['authorizationCode'] = 'another_payment';
            } elseif ($this->defect === 'handoff_additional_payment') {
                $this->savedOrder['transactions'][] = array_replace($this->savedOrder['transactions'][0], ['id' => 'gid://shopify/OrderTransaction/444',
                    'gateway' => 'shopify_payments', 'authorizationCode' => 'another_payment']);
            }
            if ($this->lostOrderResponse) {
                return Http::failedConnection();
            }
            $data['orderCreate'] = ['order' => ['id' => $this->savedOrder['id']], 'userErrors' => []];
        } elseif (str_contains($query, 'PilotCreatedOrder')) {
            $data['order'] = $this->savedOrder;
        } elseif ($query !== SubscriptionBillingShopifySource::SANDBOX_QUERY) {
            throw new \RuntimeException('Unexpected fictional Shopify query.');
        }

        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }
}
