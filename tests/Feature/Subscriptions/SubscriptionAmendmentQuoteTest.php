<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionAmendmentQuote;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\BraintreeScenario;

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->s = new BraintreeScenario;
    $this->s->activate();
    $this->row = $this->s->agreement->fresh();
    config()->set('subscriptions.replacement_amendments_enabled', true);
    $address = $this->s->address();
    $address['address1'] = '456 New Example Road';
    $this->change = ['shipping_address' => $address, 'fixed_shipping_reviewed' => true, 'policy_reference' => 'original-shipping:789'];
    $bag = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD'], 'presentmentMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
    $this->catalog = ['shop' => ['myshopifyDomain' => $this->s->store->shop_domain, 'currencyCode' => 'USD', 'plan' => ['partnerDevelopment' => true]],
        'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/77', 'app' => ['id' => 'gid://shopify/App/88'], 'accessScopes' => [['handle' => 'write_draft_orders']]],
        'customer' => ['id' => 'gid://shopify/Customer/456'], 'nodes' => [['id' => 'gid://shopify/ProductVariant/999', 'title' => '16oz', 'product' => ['title' => 'Forest Rain', 'status' => 'ACTIVE'], 'inventoryItem' => ['requiresShipping' => true]]]];
    $this->calculation = ['customer' => ['id' => 'gid://shopify/Customer/456'], 'currencyCode' => 'USD', 'presentmentCurrencyCode' => 'USD', 'taxesIncluded' => false,
        'allVariantPricesOverridden' => true, 'alerts' => [], 'warnings' => [], 'appliedDiscount' => null, 'deposit' => null, 'discountCodes' => [], 'platformDiscounts' => [],
        'subtotalPriceSet' => $bag('25.00'), 'totalPriceSet' => $bag('26.75'), 'totalShippingPriceSet' => $bag('0.00'), 'totalTaxSet' => $bag('1.75'), 'totalDiscountsSet' => $bag('0.00'),
        'taxLines' => [['title' => 'State tax', 'rate' => 0.04, 'priceSet' => $bag('1.00')], ['title' => 'Local tax', 'rate' => 0.03, 'priceSet' => $bag('0.75')]],
        'lineItems' => [['variant' => ['id' => 'gid://shopify/ProductVariant/999'], 'quantity' => 1, 'requiresShipping' => true, 'custom' => false, 'isGiftCard' => false, 'components' => [], 'appliedDiscount' => null,
            'priceOverride' => ['amount' => '25.00', 'currencyCode' => 'USD'], 'originalUnitPriceSet' => $bag('25.00'), 'discountedTotalSet' => $bag('25.00')]]];
    $this->hook = null;
    $this->apiVersion = '2026-10';
    $this->httpStatus = 200;
    $this->userErrors = [];
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://'.$this->s->store->shop_domain.'/admin/api/2026-10/graphql.json' => function ($request) {
        if ($this->hook) {
            ($this->hook)($request);
        }
        if ($request['query'] === SubscriptionAmendmentQuote::CATALOG) {
            $data = $this->catalog;
        } elseif ($request['query'] === SubscriptionAmendmentQuote::CALCULATE) {
            $data = ['draftOrderCalculate' => ['userErrors' => $this->userErrors, 'calculatedDraftOrder' => $this->calculation]];
        } else {
            throw new RuntimeException('An unexpected provider operation was attempted.');
        }

        return Http::response(['data' => $data], $this->httpStatus, ['X-Shopify-API-Version' => $this->apiVersion]);
    }]);
    $this->requestsBeforeQuote = Http::recorded()->count();
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('delivery calculation preserves membership pricing and original payment evidence without writing an order or agreement', function () {
    $agreement = (array) DB::table('subscription_billing_agreements')->where('id', $this->row->id)->first();
    $actions = SubscriptionBillingAction::count();
    $quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    expect($quote['terms']['amount_cents'])->toBe(2675)->and($quote['terms']['tax_cents'])->toBe(175)
        ->and($quote['terms']['tax_lines'])->toBe([['title' => 'State tax', 'rate' => 0.04, 'price_cents' => 100], ['title' => 'Local tax', 'rate' => 0.03, 'price_cents' => 75]])
        ->and($quote['terms']['shipping_address']['address1'])->toBe('456 New Example Road')
        ->and($quote['subject']['original_terms_hash'])->toBe($this->row->terms_hash)
        ->and($quote['tax_is_estimated'])->toBeTrue()->and($quote['creates_order'])->toBeFalse()->and($quote['charges_customer'])->toBeFalse()
        ->and(Http::recorded()->count())->toBe($this->requestsBeforeQuote + 2)
        ->and((array) DB::table('subscription_billing_agreements')->where('id', $this->row->id)->first())->toBe($agreement)
        ->and(SubscriptionBillingAction::count())->toBe($actions)->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([]);
    Http::assertSent(fn ($request) => $request['query'] === SubscriptionAmendmentQuote::CALCULATE
        && $request['variables']['input']['shippingAddress'] === $this->change['shipping_address']
        && $request['variables']['input']['lineItems'] === [['variantId' => 'gid://shopify/ProductVariant/999', 'quantity' => 1, 'priceOverride' => ['amount' => '25.00', 'currencyCode' => 'USD']]]
        && $request['variables']['input']['useCustomerDefaultAddress'] === false && $request['variables']['input']['discountCodes'] === []);
    SubscriptionBillingAuthorization::assertCurrent($this->row->fresh());
});

test('an active 16oz scent change retains the original source line price quantity and schedule', function () {
    unset($this->change['shipping_address']);
    $this->change['swaps'] = [['subscription_id' => '789', 'variant_id' => '888']];
    $this->catalog['nodes'][0]['id'] = 'gid://shopify/ProductVariant/888';
    $this->calculation['lineItems'][0]['variant']['id'] = 'gid://shopify/ProductVariant/888';
    $quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    expect($quote['terms']['lines'][0]['variant_id'])->toBe('888')->and($quote['terms']['lines'][0]['label'])->toBe('Forest Rain / 16oz')
        ->and($quote['terms']['lines'][0]['subscription_id'])->toBe('789')->and($quote['terms']['lines'][0]['unit_price_cents'])->toBe(2500)
        ->and($quote['terms']['first_billing_date'])->toBe($this->row->terms['first_billing_date'])
        ->and($this->row->fresh()->terms['lines'][0]['variant_id'])->toBe('999');
});

test('incomplete altered repriced discounted or warned calculation evidence cannot become a quote', function (string $defect) {
    match ($defect) {
        'shop' => $this->catalog['shop']['myshopifyDomain'] = 'other.myshopify.com',
        'scope' => $this->catalog['currentAppInstallation']['accessScopes'] = [],
        'environment' => $this->catalog['shop']['plan']['partnerDevelopment'] = false,
        'customer' => $this->calculation['customer']['id'] = 'gid://shopify/Customer/999',
        'currency' => $this->calculation['presentmentCurrencyCode'] = 'CAD',
        'included_tax' => $this->calculation['taxesIncluded'] = true,
        'warning' => $this->calculation['warnings'] = [['__typename' => 'DraftOrderWarning']],
        'alert' => $this->calculation['alerts'] = [['__typename' => 'ResourceAlert']],
        'discount' => $this->calculation['discountCodes'] = ['UNREVIEWED'],
        'deposit' => $this->calculation['deposit'] = ['__typename' => 'DepositPercentage'],
        'line' => $this->calculation['lineItems'][0]['variant']['id'] = 'gid://shopify/ProductVariant/888',
        'quantity' => $this->calculation['lineItems'][0]['quantity'] = 2,
        'bundle' => $this->calculation['lineItems'][0]['components'] = [['__typename' => 'CalculatedDraftOrderLineItem']],
        'price' => $this->calculation['lineItems'][0]['priceOverride']['amount'] = '30.00',
        'total' => $this->calculation['totalPriceSet']['shopMoney']['amount'] = '27.00',
        'tax' => $this->calculation['taxLines'][0]['priceSet']['shopMoney']['amount'] = '2.00',
        'shipping' => $this->calculation['totalShippingPriceSet']['shopMoney']['amount'] = '5.00',
        'presentment' => $this->calculation['totalTaxSet']['presentmentMoney']['amount'] = '0.00',
        'version' => $this->apiVersion = '2026-07',
        'http' => $this->httpStatus = 429,
        'user_error' => $this->userErrors = [['field' => ['input', 'shippingAddress'], 'message' => 'Fictional provider validation error.']],
        'missing_installation' => $this->catalog['currentAppInstallation']['id'] = '',
    };
    expect(fn () => app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor))->toThrow(HttpException::class);
    expect($this->row->fresh()->terms_hash)->toBe($this->row->terms_hash)->and($this->s->sales)->toBe([]);
})->with(['shop', 'scope', 'environment', 'customer', 'currency', 'included_tax', 'warning', 'alert', 'discount', 'deposit',
    'line', 'quantity', 'bundle', 'price', 'total', 'tax', 'shipping', 'presentment', 'version', 'http', 'user_error', 'missing_installation']);

test('unreviewed policy arbitrary fields other source lines and invalid addresses fail before provider calls', function (string $defect) {
    if ($defect === 'policy') {
        $this->change['fixed_shipping_reviewed'] = false;
    } elseif ($defect === 'price') {
        $this->change['amount_cents'] = 1;
    } elseif ($defect === 'line') {
        $this->change['swaps'] = [['subscription_id' => '999999', 'variant_id' => '888']];
    } elseif ($defect === 'country') {
        $this->change['shipping_address']['countryCode'] = 'CA';
    } elseif ($defect === 'missing_address') {
        unset($this->change['shipping_address']['address1']);
    } elseif ($defect === 'unchanged') {
        $this->change['shipping_address'] = $this->row->terms['shipping_address'];
    } else {
        config()->set('subscriptions.replacement_amendments_enabled', false);
    }
    try {
        app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
        $this->fail('An unreviewed delivery proposal was accepted.');
    } catch (HttpException|\Illuminate\Validation\ValidationException) {
        expect(Http::recorded()->count())->toBe($this->requestsBeforeQuote);
    }
})->with(['policy', 'price', 'line', 'country', 'missing_address', 'unchanged', 'rollout']);

test('a changed credential pause or freeze between reads stops the calculation before a new provider request', function (string $race) {
    $this->hook = function ($request) use ($race) {
        if ($request['query'] !== SubscriptionAmendmentQuote::CATALOG) {
            return;
        }
        if ($race === 'credential') {
            $this->s->store->update(['access_token' => 'fictional-rotated-token']);
        } elseif ($race === 'pause') {
            app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', (string) Str::uuid(), $this->row->revision);
        } elseif ($race === 'owner') {
            $this->s->actor->tenants()->updateExistingPivot($this->row->tenant_id, ['role' => 'manager']);
        } else {
            config()->set('subscriptions.replacement_amendments_enabled', false);
        }
    };
    expect(fn () => app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor))->toThrow(HttpException::class);
    expect(Http::recorded()->count())->toBe($this->requestsBeforeQuote + 1)->and($this->s->sales)->toBe([]);
})->with(['credential', 'pause', 'freeze', 'owner']);

test('another member selection or an unverified manager cannot fetch the original store quote', function (bool $manager) {
    if ($manager) {
        $this->s->actor->tenants()->updateExistingPivot($this->row->tenant_id, ['role' => 'manager']);
    } else {
        config()->set('subscriptions.replacement_member_ids', []);
    }
    expect(fn () => app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor))->toThrow(HttpException::class);
    expect(Http::recorded()->count())->toBe($this->requestsBeforeQuote);
})->with([true, false]);

test('the proposed delivery quote never authorizes edited agreement terms or a new collection', function () {
    $quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    $this->row->update(['terms' => $quote['terms'], 'terms_hash' => $quote['quote_hash']]);
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($this->row->fresh()))->toThrow(HttpException::class);
    $this->travelTo(\Carbon\CarbonImmutable::parse($this->row->next_billing_date->toDateString(), $this->row->terms['timezone'])->setHour(10)->utc());
    expect(fn () => app(\App\Services\Subscriptions\SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh()))->toThrow(HttpException::class);
    expect($this->s->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('the owner quote command stays offline unless explicitly fetched and never saves proposed billing terms', function () {
    $path = tempnam(sys_get_temp_dir(), 'fictional-amendment-');
    file_put_contents($path, json_encode($this->change));
    chmod($path, 0600);
    try {
        $options = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->row->id, '--file' => $path];
        $this->artisan('subscriptions:amendment-quote', $options)->expectsOutputToContain('offline_proposal')->assertSuccessful();
        expect(Http::recorded()->count())->toBe($this->requestsBeforeQuote);
        $this->artisan('subscriptions:amendment-quote', $options + ['--fetch' => true])->expectsOutputToContain('calculated_only')->assertSuccessful();
        expect($this->row->fresh()->terms['amount_cents'])->toBe(2500)->and($this->s->orderCreates)->toBe([]);
    } finally {
        unlink($path);
    }
});
