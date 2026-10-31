<?php

namespace Tests\Support\Subscriptions;

use App\Services\Subscriptions\SubscriptionAmendmentQuote;
use App\Services\Subscriptions\SubscriptionPilotShopifyBridge;
use Illuminate\Support\Facades\Http;

class AmendmentScenario extends BraintreeScenario
{
    public int $taxCents = 175;

    public ?\Closure $quoteHook = null;

    public array $quoteRequests = [];

    public function __construct()
    {
        parent::__construct();
        $this->activate();
        config(['subscriptions.replacement_amendments_enabled' => true, 'subscriptions.replacement_amendment_apply_enabled' => true]);
        $this->amendmentResponder = function ($request) {
            $this->quoteRequests[] = $request->data();
            if ($this->quoteHook) {
                ($this->quoteHook)($request);
            }
            $bag = fn ($cents) => ['shopMoney' => ['amount' => SubscriptionPilotShopifyBridge::decimal($cents), 'currencyCode' => 'USD'],
                'presentmentMoney' => ['amount' => SubscriptionPilotShopifyBridge::decimal($cents), 'currencyCode' => 'USD']];
            if ($request['query'] === SubscriptionAmendmentQuote::CATALOG) {
                $data = ['shop' => ['myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD', 'plan' => ['partnerDevelopment' => true]],
                    'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/77', 'app' => ['id' => 'gid://shopify/App/88'], 'accessScopes' => [['handle' => 'write_draft_orders']]],
                    'customer' => ['id' => 'gid://shopify/Customer/456'],
                    'nodes' => array_map(fn ($id) => ['id' => $id, 'title' => '16oz', 'product' => ['title' => 'Forest Rain', 'status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]], $request['variables']['variants'])];
            } else {
                $input = $request['variables']['input'];
                $subtotal = array_sum(array_map(fn ($line) => SubscriptionPilotShopifyBridge::cents($line['priceOverride']['amount']) * $line['quantity'], $input['lineItems']));
                $shipping = SubscriptionPilotShopifyBridge::cents($input['shippingLine']['priceWithCurrency']['amount']);
                $calculation = ['customer' => ['id' => 'gid://shopify/Customer/456'], 'currencyCode' => 'USD', 'presentmentCurrencyCode' => 'USD',
                    'taxesIncluded' => false, 'allVariantPricesOverridden' => true, 'alerts' => [], 'warnings' => [], 'appliedDiscount' => null, 'deposit' => null, 'discountCodes' => [], 'platformDiscounts' => [],
                    'subtotalPriceSet' => $bag($subtotal), 'totalPriceSet' => $bag($subtotal + $shipping + $this->taxCents), 'totalShippingPriceSet' => $bag($shipping),
                    'totalTaxSet' => $bag($this->taxCents), 'totalDiscountsSet' => $bag(0),
                    'taxLines' => $this->taxCents === 175 ? [['title' => 'State tax', 'rate' => 0.04, 'priceSet' => $bag(100)], ['title' => 'Local tax', 'rate' => 0.03, 'priceSet' => $bag(75)]]
                        : [['title' => 'Reviewed tax', 'rate' => $this->taxCents / 2500, 'priceSet' => $bag($this->taxCents)]],
                    'lineItems' => array_map(fn ($line) => ['variant' => ['id' => $line['variantId']], 'quantity' => $line['quantity'],
                        'requiresShipping' => true, 'custom' => false, 'isGiftCard' => false, 'components' => [], 'appliedDiscount' => null, 'priceOverride' => $line['priceOverride'],
                        'originalUnitPriceSet' => $bag(SubscriptionPilotShopifyBridge::cents($line['priceOverride']['amount'])),
                        'discountedTotalSet' => $bag(SubscriptionPilotShopifyBridge::cents($line['priceOverride']['amount']) * $line['quantity'])], $input['lineItems'])];
                $data = ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => $calculation]];
            }

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        };
    }

    public function change(): array
    {
        $address = $this->address();
        $address['address1'] = '456 New Example Road';

        return ['shipping_address' => $address, 'swaps' => [['subscription_id' => '789', 'variant_id' => '888']],
            'fixed_shipping_reviewed' => true, 'policy_reference' => 'source-shipping:789'];
    }

    public function approval(): array
    {
        return ['reference' => 'customer-request:fictional-1', 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true];
    }
}
