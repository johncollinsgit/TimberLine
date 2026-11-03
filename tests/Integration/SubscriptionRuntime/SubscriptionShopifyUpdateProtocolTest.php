<?php

use App\Services\Subscriptions\SubscriptionShopifyUpdateProtocol;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->s = new ShopifyExecutionScenario;
    $this->s->activate();
    $this->row = $this->s->agreement->fresh();
    $this->protocol = app(SubscriptionShopifyUpdateProtocol::class);
    $this->before = $this->s->contract;
    $this->before['note'] = 'Retain the original delivery note';
    $this->before['updatedAt'] = '2027-01-15T14:00:00Z';
    $this->before['lines']['nodes'][0] += ['id' => 'gid://shopify/SubscriptionLine/original_line', 'sellingPlanId' => null, 'sellingPlanName' => null];
    $address = $this->s->address();
    $address['address1'] = '456 New Example Road';
    $this->change = ['shipping_address' => $address, 'swaps' => [['subscription_id' => '789', 'variant_id' => '888']],
        'fixed_shipping_reviewed' => true, 'policy_reference' => 'merchant-policy:fictional'];
    $this->catalog = ['shop' => ['myshopifyDomain' => $this->s->store->shop_domain, 'currencyCode' => 'USD', 'plan' => ['partnerDevelopment' => true]],
        'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001', 'app' => ['id' => 'gid://shopify/App/2001']],
        'customer' => ['id' => 'gid://shopify/Customer/456'], 'nodes' => [['id' => 'gid://shopify/ProductVariant/888', 'title' => '16oz',
            'product' => ['title' => 'Forest Rain', 'status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]]]];
});

function nativeUpdateIntent($test): array
{
    return $test->protocol->intent($test->row, $test->before, $test->change, $test->catalog, (string) Str::uuid(), 'CUSTOMER');
}

function nativeUpdateCalculation($test, array $intent): array
{
    $contract = $test->before;
    $contract['customAttributes'] = $intent['variables']['input']['customAttributes'];
    $contract['deliveryMethod']['address'] = $intent['proposal']['terms']['shipping_address'];
    $contract['deliveryMethod']['address']['countryCodeV2'] = $contract['deliveryMethod']['address']['countryCode'];
    unset($contract['deliveryMethod']['address']['countryCode']);
    $contract['lines']['nodes'][0]['variantId'] = 'gid://shopify/ProductVariant/'.$intent['proposal']['terms']['lines'][0]['variant_id'];
    $money = fn ($amount) => ['amount' => $amount, 'currencyCode' => 'USD'];

    return ['__typename' => 'SubscriptionContractCalculationSuccess', 'id' => 'gid://shopify/SubscriptionContractCalculation/original_update',
        'warnings' => [], 'withMerchandiseCustomizations' => false, 'calculatedContract' => $contract,
        'projectedOrderTotals' => ['subtotal' => $money('25.00'), 'totalDelivery' => $money('0.00'), 'totalTax' => $money('1.75'), 'total' => $money('26.75'),
            'totalDeliveryDiscounts' => $money('0.00'), 'totalMerchandiseDiscounts' => $money('0.00')]];
}

test('native update intent preserves original provider line price policy metadata and saved method without a write', function () {
    $before = $this->row->getAttributes();
    $reads = count(Http::recorded());
    $intent = nativeUpdateIntent($this);
    $input = $intent['variables']['input'];
    if ($path = getenv('SUBSCRIPTION_NATIVE_UPDATE_VALIDATION_FILE')) {
        file_put_contents($path, json_encode(['update.calculate' => $intent['variables'],
            'contract.calculate' => ['input' => data_get($this->row->authorization_evidence, 'shopify_adoption.original.input')]], JSON_PRETTY_PRINT));
    }
    $line = $input['lines'][0]['productVariantLine'];
    expect($intent['variables']['actor'])->toBe('CUSTOMER')->and($intent['variables']['id'])->toBe($this->s->contract['id'])
        ->and($line['id'])->toBe('gid://shopify/SubscriptionLine/original_line')->and($line['productVariantId'])->toBe('gid://shopify/ProductVariant/888')
        ->and($line['priceOverride'])->toBe(['amount' => '25.00', 'currencyCode' => 'USD'])
        ->and($line['appManagedPricingPolicy'])->toBe(['basePrice' => ['amount' => '25.00', 'currencyCode' => 'USD'], 'cyclePriceAdjustments' => []])
        ->and($line['customAttributes'])->toBe([])->and($line['discounts'])->toBe([])->and($line['originSellingPlanId'])->toBeNull()
        ->and($input)->not->toHaveKeys(['paymentMethod', 'billingPolicy', 'deliveryPolicy', 'appManagedBillingConfig', 'note', 'manualDiscounts'])
        ->and($input['deliveryMethod']['shipping']['deliveryPrice'])->toBe(['amount' => '0.00', 'currencyCode' => 'USD'])
        ->and($this->row->fresh()->getAttributes())->toBe($before)->and(count(Http::recorded()))->toBe($reads)
        ->and($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1);
});

test('address-only native update omits line replacement and scent-only update omits delivery replacement', function () {
    unset($this->change['swaps']);
    $this->catalog['nodes'][0]['id'] = 'gid://shopify/ProductVariant/999';
    $address = nativeUpdateIntent($this);
    expect($address['variables']['input'])->not->toHaveKey('lines');
    $this->change['shipping_address'] = $this->s->address();
    $this->change['swaps'] = [['subscription_id' => '789', 'variant_id' => '888']];
    $this->catalog['nodes'][0]['id'] = 'gid://shopify/ProductVariant/888';
    $scent = nativeUpdateIntent($this);
    expect($scent['variables']['input'])->not->toHaveKey('deliveryMethod')->and($scent['variables']['input'])->toHaveKey('lines');
});

test('native update quote verifies original line ids and exact projected totals without changing current terms', function () {
    $intent = nativeUpdateIntent($this);
    $quote = $this->protocol->quote($this->row, $intent, nativeUpdateCalculation($this, $intent));
    expect($quote['terms']['amount_cents'])->toBe(2675)->and($quote['terms']['tax_cents'])->toBe(175)
        ->and($quote['terms']['lines'][0]['label'])->toBe('Forest Rain / 16oz')->and($quote['tax_is_estimated'])->toBeTrue()
        ->and($quote['changes_contract'])->toBeFalse()->and($quote['charges_customer'])->toBeFalse()
        ->and($this->row->fresh()->terms['amount_cents'])->toBe(2500)->and($this->s->bills)->toBe([]);
    $applied = nativeUpdateCalculation($this, $intent)['calculatedContract'];
    $applied['updatedAt'] = '2027-01-15T15:00:00Z';
    $this->protocol->assertApplied($this->row, $intent, $quote, $applied);
});

test('a paused native update retains the provider date even when a finite local pause moved the next date', function () {
    $this->row->update(['status' => 'paused', 'next_billing_date' => '2027-03-31', 'resume_on' => '2027-03-31']);
    $this->before['status'] = 'PAUSED';
    $originalDate = $this->before['nextBillingDate'];
    $intent = nativeUpdateIntent($this);
    expect($intent['before']['nextBillingDate'])->toBe($originalDate)->and($intent['variables']['input'])->not->toHaveKeys(['nextBillingDate', 'status'])
        ->and($this->row->fresh()->next_billing_date->toDateString())->toBe('2027-03-31')->and($this->row->fresh()->status)->toBe('paused');
});

test('a paused native update refuses a malformed provider billing date', function () {
    $this->row->update(['status' => 'paused']);
    $this->before['status'] = 'PAUSED';
    $this->before['nextBillingDate'] = '2027-02-31T15:00:00Z';
    expect(fn () => nativeUpdateIntent($this))->toThrow(HttpException::class);
});

test('native update refuses missing line identity changed original metadata schedule or unrelated catalog', function (string $defect) {
    if ($defect === 'line_id') {
        unset($this->before['lines']['nodes'][0]['id']);
    } elseif ($defect === 'selling_plan') {
        unset($this->before['lines']['nodes'][0]['sellingPlanName']);
    } elseif ($defect === 'note') {
        unset($this->before['note']);
    } elseif ($defect === 'date') {
        $this->before['nextBillingDate'] = '2027-02-28T15:00:00Z';
    } elseif ($defect === 'price') {
        $this->before['lines']['nodes'][0]['currentPrice']['amount'] = '30.00';
    } elseif ($defect === 'catalog_app') {
        $this->catalog['currentAppInstallation']['app']['id'] = 'gid://shopify/App/999';
    } elseif ($defect === 'inactive') {
        $this->catalog['nodes'][0]['product']['status'] = 'ARCHIVED';
    } else {
        $this->catalog['nodes'][0]['title'] = '8oz';
    }
    expect(fn () => nativeUpdateIntent($this))->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1);
})->with(['line_id', 'selling_plan', 'note', 'date', 'price', 'catalog_app', 'inactive', 'size']);

test('native quote rejects provider repricing replacement ids policy loss warnings and hidden changed economics', function (string $defect) {
    $intent = nativeUpdateIntent($this);
    $calculation = nativeUpdateCalculation($this, $intent);
    if ($defect === 'line_id') {
        $calculation['calculatedContract']['lines']['nodes'][0]['id'] = 'gid://shopify/SubscriptionLine/replacement';
    } elseif ($defect === 'price') {
        $calculation['calculatedContract']['lines']['nodes'][0]['currentPrice']['amount'] = '30.00';
    } elseif ($defect === 'policy') {
        $calculation['calculatedContract']['lines']['nodes'][0]['pricingPolicy']['basePrice']['amount'] = '30.00';
    } elseif ($defect === 'selling_plan') {
        $calculation['calculatedContract']['lines']['nodes'][0]['sellingPlanName'] = 'Another plan';
    } elseif ($defect === 'note') {
        $calculation['calculatedContract']['note'] = null;
    } elseif ($defect === 'warning') {
        $calculation['warnings'] = [['code' => 'DELIVERY_OPTION_NOT_FOUND', 'field' => []]];
    } elseif ($defect === 'pending') {
        $calculation['__typename'] = 'SubscriptionContractCalculationPending';
    } elseif ($defect === 'total') {
        $calculation['projectedOrderTotals']['total']['amount'] = '30.00';
    } else {
        $calculation['projectedOrderTotals']['totalMerchandiseDiscounts']['amount'] = '5.00';
    }
    expect(fn () => $this->protocol->quote($this->row, $intent, $calculation))->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and($this->row->fresh()->terms['amount_cents'])->toBe(2500);
})->with(['line_id', 'price', 'policy', 'selling_plan', 'note', 'warning', 'pending', 'total', 'discount']);

test('native applied result cannot restore schedule replace method or accept altered original intent', function (string $defect) {
    $intent = nativeUpdateIntent($this);
    $calculation = nativeUpdateCalculation($this, $intent);
    $quote = $this->protocol->quote($this->row, $intent, $calculation);
    $applied = $calculation['calculatedContract'];
    if ($defect === 'pause') {
        $applied['status'] = 'PAUSED';
    } elseif ($defect === 'date') {
        $applied['nextBillingDate'] = '2027-02-28T15:00:00Z';
    } elseif ($defect === 'method') {
        $applied['customerPaymentMethod']['id'] = 'gid://shopify/CustomerPaymentMethod/other';
    } elseif ($defect === 'marker') {
        array_pop($applied['customAttributes']);
    } else {
        $intent['before']['note'] = 'Changed before state';
        $applied['note'] = 'Changed before state';
    }
    expect(fn () => $this->protocol->assertApplied($this->row, $intent, $quote, $applied))->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1);
})->with(['pause', 'date', 'method', 'marker', 'intent']);

test('original subscription credential revocation requires a fresh native update review', function () {
    $intent = nativeUpdateIntent($this);
    $calculation = nativeUpdateCalculation($this, $intent);
    $this->s->revision->update(['revoked_at' => now()]);
    expect(fn () => $this->protocol->quote($this->row, $intent, $calculation))->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([]);
});
