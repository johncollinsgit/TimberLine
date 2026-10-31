<?php

namespace Tests\Support\Subscriptions;

use App\Models\SubscriptionBillingAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Fictional native update server. Actual commits/payments remain inherited and countable. */
class ShopifyUpdateScenario extends ShopifyExecutionScenario
{
    public array $updateInputs = [];

    public bool $updatePending = false;

    public bool $loseUpdateResponse = false;

    public bool $updateWarning = false;

    public bool $updateUserError = false;

    public bool $updateFailure = false;

    public bool $concreteCalculationIds = false;

    public bool $wrongUpdateMarker = false;

    public int $updatePolls = 0;

    public int $updateCommits = 0;

    public bool $loseUpdateCommitResponse = false;

    public ?string $updateCommitError = null;

    public bool $updateCommitDeferred = false;

    public ?array $deferredUpdateContract = null;

    public ?\Closure $afterUpdateCommit = null;

    public ?\Closure $beforeUpdateCalculationRead = null;

    public ?\Closure $beforeUpdateCatalog = null;

    public ?\Closure $beforeUpdateSend = null;

    public ?\Closure $beforeUpdatePoll = null;

    public ?string $updateMarkerPath = null;

    public ?array $proposedChange = null;

    public function __construct()
    {
        parent::__construct();
        $this->activate();
        config()->set('subscriptions.replacement_amendments_enabled', true);
        config()->set('subscriptions.replacement_shopify_amendments_enabled', true);
        $this->contract['note'] = 'Retain the original delivery note';
        $this->contract['updatedAt'] = '2027-01-15T14:00:00Z';
        $this->contract['lines']['nodes'][0] += ['id' => 'gid://shopify/SubscriptionLine/original_line', 'sellingPlanId' => null, 'sellingPlanName' => null];
    }

    public function change(): array
    {
        if ($this->proposedChange) {
            return $this->proposedChange;
        }
        $address = $this->address();
        $address['address1'] = '456 New Example Road';

        return ['shipping_address' => $address, 'swaps' => [['subscription_id' => '789', 'variant_id' => '888']],
            'fixed_shipping_reviewed' => true, 'policy_reference' => 'merchant-policy:fictional'];
    }

    public function updateCalculation(): array
    {
        $type = $this->concreteCalculationIds ? ($this->updatePending ? 'Pending' : ($this->updateFailure ? 'Failure' : 'Success')) : '';
        $id = 'gid://shopify/SubscriptionContractCalculation'.$type.'/original_update'.(count($this->updateInputs) > 1 ? '_'.count($this->updateInputs) : '');
        if ($this->updatePending) {
            return ['__typename' => 'SubscriptionContractCalculationPending', 'id' => $id];
        }
        if ($this->updateFailure) {
            return ['__typename' => 'SubscriptionContractCalculationFailure', 'id' => $id, 'errors' => [['code' => 'INVALID_DELIVERY_METHOD', 'field' => []]]];
        }
        $input = $this->updateInputs[array_key_last($this->updateInputs)]['input'];
        $contract = $this->contract;
        $contract['customAttributes'] = $input['customAttributes'];
        if ($this->wrongUpdateMarker) {
            $contract['customAttributes'] = array_map(fn ($attribute) => $attribute['key'] === 'everbranch_amendment'
                ? ['key' => 'everbranch_amendment', 'value' => 'another-original-request'] : $attribute, $contract['customAttributes']);
        }
        $contract['deliveryMethod']['address'] = $input['deliveryMethod']['shipping']['address'];
        $contract['deliveryMethod']['address']['countryCodeV2'] = $contract['deliveryMethod']['address']['countryCode'];
        unset($contract['deliveryMethod']['address']['countryCode']);
        $contract['lines']['nodes'][0]['variantId'] = $input['lines'][0]['productVariantLine']['productVariantId'];
        $money = fn ($amount) => ['amount' => $amount, 'currencyCode' => 'USD'];

        return ['__typename' => 'SubscriptionContractCalculationSuccess', 'id' => $id, 'withMerchandiseCustomizations' => false,
            'warnings' => $this->updateWarning ? [['code' => 'DELIVERY_OPTION_NOT_FOUND', 'field' => []]] : [], 'calculatedContract' => $contract,
            'projectedOrderTotals' => ['subtotal' => $money('25.00'), 'totalDelivery' => $money('0.00'), 'totalTax' => $money('1.75'),
                'total' => $money('26.75'), 'totalDeliveryDiscounts' => $money('0.00'), 'totalMerchandiseDiscounts' => $money('0.00')]];
    }

    protected function shopify($request)
    {
        $query = $request['query'];
        if (! preg_match('/Replacement(Update|CalculateUpdate|CommitUpdate)/', $query)) {
            return parent::shopify($request);
        }
        if ($request->url() !== 'https://'.$this->store->shop_domain.'/admin/api/2026-10/graphql.json'
            || $request->header('X-Shopify-Access-Token') !== ['ORIGINAL_SUBSCRIPTION_TOKEN']) {
            throw new \RuntimeException('Use only the pinned original subscription installation.');
        }
        $names = fn ($items) => array_map(fn ($name) => ['name' => $name], $items);
        $identity = ['shop' => ['id' => 'gid://shopify/Shop/1001', 'myshopifyDomain' => $this->store->shop_domain,
            'currencyCode' => 'USD', 'ianaTimezone' => 'America/New_York', 'plan' => ['partnerDevelopment' => true]],
            'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001', 'app' => ['id' => 'gid://shopify/App/2001', 'apiKey' => 'fictional-subscription-client'],
                'accessScopes' => array_map(fn ($handle) => ['handle' => $handle], ['read_customer_payment_methods', 'write_own_subscription_contracts', 'read_customers', 'read_orders', 'read_products'])]];
        if (str_starts_with($query, 'query ReplacementUpdateCapabilities')) {
            $data = $identity + ['mutationType' => ['fields' => $names(['subscriptionContractUpdateCalculate', 'subscriptionContractCalculationCommit'])],
                'queryType' => ['fields' => $names(['subscriptionContractCalculation', 'subscriptionContract'])],
                'updateInputType' => ['inputFields' => $names(['withMerchandiseCustomizations', 'discountCodes', 'customAttributes', 'deliveryMethod', 'lines'])],
                'lineInputType' => ['inputFields' => $names(['id', 'productVariantId', 'quantity', 'priceOverride', 'customAttributes', 'discounts', 'originSellingPlanId', 'sellingPlanName', 'appManagedPricingPolicy'])]];
        } elseif (str_starts_with($query, 'query ReplacementUpdateOriginalContract')) {
            $data = $identity + ['subscriptionContract' => $this->contract];
        } elseif (str_starts_with($query, 'query ReplacementUpdateCatalog')) {
            if ($hook = $this->beforeUpdateCatalog) {
                $this->beforeUpdateCatalog = null;
                $hook();
            }
            $data = $identity + ['customer' => ['id' => 'gid://shopify/Customer/456'], 'nodes' => [['id' => $request['variables']['variants'][0],
                'title' => '16oz', 'product' => ['title' => 'Forest Rain', 'status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]]]];
        } elseif (str_starts_with($query, 'mutation ReplacementCalculateUpdate')) {
            $sent = SubscriptionBillingAction::query()->where('agreement_id', $this->agreement->id)->where('kind', 'shopify_update_calculate_sent')->firstOrFail();
            // A separate connection must see the committed marker even though the send holds the original row locks.
            $name = 'native_update_observer';
            config()->set('database.connections.'.$name, config('database.connections.'.DB::getDefaultConnection()));
            try {
                if (! DB::connection($name)->table('subscription_billing_actions')->where('id', $sent->id)->exists()) {
                    throw new \RuntimeException('The native update dispatch marker was not durable before HTTP.');
                }
            } finally {
                DB::purge($name);
            }
            if ($hook = $this->beforeUpdateSend) {
                $this->beforeUpdateSend = null;
                $hook();
            }
            $this->updateInputs[] = $request['variables'];
            if ($this->updateMarkerPath) {
                file_put_contents($this->updateMarkerPath, $sent->request_uuid."\n", FILE_APPEND | LOCK_EX);
            }
            if ($this->loseUpdateResponse) {
                throw new ConnectionException('Fictional response lost after original native calculation dispatch.');
            }
            $data = ['subscriptionContractUpdateCalculate' => ['subscriptionContractCalculation' => $this->updateCalculation(),
                'userErrors' => $this->updateUserError ? [['code' => 'INVALID', 'field' => ['contractId']]] : []]];
        } elseif (str_starts_with($query, 'mutation ReplacementCommitUpdate')) {
            $sent = SubscriptionBillingAction::query()->where('agreement_id', $this->agreement->id)->where('kind', 'shopify_update_commit_sent')->firstOrFail();
            $name = 'native_update_commit_observer';
            config()->set('database.connections.'.$name, config('database.connections.'.DB::getDefaultConnection()));
            try {
                if (! DB::connection($name)->table('subscription_billing_actions')->where('id', $sent->id)->exists()) {
                    throw new \RuntimeException('The native commit marker was not committed before HTTP.');
                }
            } finally {
                DB::purge($name);
            }
            if ($request['variables']['id'] !== $this->updateCalculation()['id']) {
                throw new \RuntimeException('Commit only the originally reviewed update calculation.');
            }
            $this->updateCommits++;
            if ($this->updateMarkerPath) {
                file_put_contents($this->updateMarkerPath, 'commit:'.$sent->request_uuid."\n", FILE_APPEND | LOCK_EX);
            }
            if ($this->updateCommitError) {
                return Http::response(['data' => ['subscriptionContractCalculationCommit' => ['contract' => null,
                    'userErrors' => [['code' => $this->updateCommitError, 'field' => ['id']]]]]], 200, ['X-Shopify-API-Version' => '2026-10']);
            }
            $applied = $this->updateCalculation()['calculatedContract'];
            $applied['updatedAt'] = now()->toISOString();
            $this->deferredUpdateContract = $applied;
            if (! $this->updateCommitDeferred) {
                $this->contract = $applied;
            }
            if ($hook = $this->afterUpdateCommit) {
                $this->afterUpdateCommit = null;
                $hook();
            }
            if ($this->loseUpdateCommitResponse || $this->updateCommitDeferred) {
                throw new ConnectionException('Fictional native commit response lost.');
            }
            $data = ['subscriptionContractCalculationCommit' => ['contract' => $applied, 'userErrors' => []]];
        } elseif (str_starts_with($query, 'query ReplacementUpdateCalculation')) {
            $this->updatePolls++;
            if ($hook = $this->beforeUpdateCalculationRead) {
                $this->beforeUpdateCalculationRead = null;
                $hook();
            }
            if ($hook = $this->beforeUpdatePoll) {
                $this->beforeUpdatePoll = null;
                $hook();
            }
            $expected = $this->updateCalculation()['id'];
            $initialPending = str_replace('SubscriptionContractCalculationSuccess/', 'SubscriptionContractCalculationPending/', $expected);
            $initialPending = str_replace('SubscriptionContractCalculationFailure/', 'SubscriptionContractCalculationPending/', $initialPending);
            if (! in_array($request['variables']['id'], [$expected, $initialPending], true)) {
                throw new \RuntimeException('Poll only the originally retained calculation ID.');
            }
            $data = $identity + ['subscriptionContractCalculation' => $this->updateCalculation()];
        } else {
            throw new \RuntimeException('Unsupported native update operation; no update commit is exposed.');
        }

        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }
}
