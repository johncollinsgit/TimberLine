<?php

use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionBraintreeMethodService;
use App\Services\Subscriptions\SubscriptionBraintreeTransport;
use App\Services\Subscriptions\SubscriptionPilotService;
use App\Services\Subscriptions\SubscriptionPilotShopifyBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Subscriptions\BraintreeScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->scenario = new BraintreeScenario;
    $this->execution = app(SubscriptionBraintreeExecutionService::class);
});

test('Braintree historical proof retains original amount and address while edited terms cannot collect', function () {
    $s = $this->scenario;
    $hash = $s->agreement->terms_hash;
    $terms = $s->agreement->terms;
    $terms['amount_cents'] = $terms['subtotal_cents'] = 3000;
    $terms['lines'][0]['unit_price_cents'] = 3000;
    $terms['shipping_address']['address1'] = '456 New Street';
    // Even keeping a previously reviewed hash cannot turn an arbitrary terms edit into authority.
    $s->agreement->update(['terms' => $terms]);
    $proof = app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111');
    expect($proof['original_terms_hash'])->toBe($hash)->and($proof['source_transaction_id'])->toBe('source_txn')
        ->and(SubscriptionBillingAuthorization::original($s->agreement->fresh())['terms']['amount_cents'])->toBe(2500);
    expect(fn () => $this->execution->activate($s->tenant->id, $s->actor, $s->agreement->id, $s->source->id, $hash, $s->agreement->revision))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toBe([])->and($s->orderCreates)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and(SubscriptionPilotMember::first()->billing_owner)->toBe('recharge');
});

test('exact original Braintree and Shopify mapping retains a saved method without new customer authorization or payment', function () {
    $s = $this->scenario;
    $proof = app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111');
    expect($proof['provider_verified'])->toBeTrue()->and($proof['reauthorization_required'])->toBeFalse()
        ->and($s->agreement->fresh()->method_verified_at)->not->toBeNull()->and($s->sales)->toBe([])
        ->and($s->agreement->fresh()->authorization_evidence['provider_verified'])->toBeTrue()
        ->and($s->orderCreates)->toBe([])->and(SubscriptionPilotMember::first()->billing_owner)->toBe('recharge')
        ->and(json_encode($proof))->not->toContain('private_method_token', 'fictional_private_key');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    $again = app(\App\Services\Subscriptions\SubscriptionBillingAgreementService::class)->prepare($s->tenant->id, $s->actor,
        $s->agreement->member_id, $s->preparation, $s->agreement->terms_hash);
    expect($again->id)->toBe($s->agreement->id)->and($again->authorization_evidence['provider_verified'])->toBeTrue();
});

test('wrong original customer token revoked method missing agreement or source reference cannot authorize reuse', function (string $defect) {
    $s = $this->scenario;
    $s->defect = $defect;
    expect(fn () => app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->agreement->fresh()->method_verified_at)->toBeNull()->and($s->sales)->toBe([]);
})->with(['original_customer', 'wrong_token', 'revoked', 'no_agreement', 'source_reference']);

test('recent original orders do not authorize Braintree reuse without continuing historical order access', function (array $scopes) {
    $s = $this->scenario;
    $s->historicalOrderScopes = $scopes;
    expect(fn () => app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'historical order access');
    expect($s->agreement->fresh()->method_verified_at)->toBeNull()->and($s->sales)->toBe([])
        ->and($s->orderCreates)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and(SubscriptionPilotMember::first()->billing_owner)->toBe('recharge');
})->with([['scopes' => ['read_orders']], ['scopes' => ['write_orders']], ['scopes' => ['read_all_orders']]]);

test('revoked historical order access blocks an activated Braintree collection before a payment or cycle', function () {
    $s = $this->scenario;
    $s->activate();
    $s->historicalOrderScopes = ['write_orders'];
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    expect(fn () => $this->execution->collect($s->agreement->fresh()))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'historical order access');
    expect($s->sales)->toBe([])->and($s->orderCreates)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-01-31');
});

test('original order write permission plus historical access supports exact Braintree method verification', function () {
    $s = $this->scenario;
    $s->historicalOrderScopes = ['write_orders', 'read_all_orders'];
    $proof = app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111');
    expect($proof['provider_verified'])->toBeTrue()->and($s->sales)->toBe([])->and($s->orderCreates)->toBe([]);
});

test('an existing unexpired card uses the same saved-method recurring path without card collection', function () {
    $s = $this->scenario;
    $s->card = true;
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->terms['method_type'])->toBe('card')
        ->and($s->sales)->toHaveCount(1)->and($s->sales[0]['paymentMethodToken'])->toBe('private_method_token')
        ->and($s->sales[0])->not->toHaveKeys(['creditCard', 'paymentMethodNonce']);
});

test('expired cards native automatic subscriptions and credential rotation cannot verify a reusable method', function (string $defect) {
    $s = $this->scenario;
    $s->defect = $defect;
    $s->card = $defect === 'expired';
    expect(fn () => app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->agreement->fresh()->method_verified_at)->toBeNull()->and($s->sales)->toBe([]);
})->with(['expired', 'native_subscription', 'rotated']);

test('one Braintree recurring sale produces one paid external Shopify order and advances the original anchor once', function () {
    $s = $this->scenario;
    $s->activate();
    expect(SubscriptionPilotMember::first()->billing_owner)->toBe('everbranch')->and($s->sales)->toBe([]);
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($cycle->provider_reference)->toBe('txn_1')->and($cycle->shopify_order_id)->toBe('222')
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28')
        ->and($s->sales)->toHaveCount(1)->and($s->sales[0]['transactionSource'])->toBe('recurring')
        ->and($s->sales[0]['merchantAccountId'])->toBe('fictionalUSD')->and($s->orderCreates)->toHaveCount(1)
        ->and($s->orderCreates[0]['order']['transactions'][0]['gateway'])->toBe('everbranch_braintree');
    $this->execution->reconcile($cycle);
    expect($s->sales)->toHaveCount(1)->and($s->orderCreates)->toHaveCount(1)
        ->and(DB::table('subscription_billing_cycles')->value('provider_request'))->not->toContain('private_method_token');
});

test('a lost sale response is recovered by original cycle identity without ever submitting another sale', function () {
    $s = $this->scenario;
    $s->activate();
    $s->lostSaleResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($cycle->provider_reference)->toBeNull()->and($s->sales)->toHaveCount(1)->and($s->orderCreates)->toBe([]);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($s->sales)->toHaveCount(1)->and(SubscriptionBillingCycle::count())->toBe(1);
});

test('an edited future agreement does not rewrite or prevent reconciliation of its original attempted cycle', function () {
    $s = $this->scenario;
    $s->activate();
    $s->lostSaleResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    $originalTerms = $cycle->terms;
    $terms = $s->agreement->terms;
    $terms['amount_cents'] = $terms['subtotal_cents'] = 3000;
    $terms['lines'][0]['unit_price_cents'] = 3000;
    $terms['shipping_address']['address1'] = '456 New Street';
    $s->agreement->update(['terms' => $terms]);
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($cycle->amount_cents)->toBe(2500)->and($cycle->terms)->toBe($originalTerms)
        ->and($s->sales)->toHaveCount(1)->and($s->orderCreates)->toHaveCount(1)
        ->and($s->orderCreates[0]['order']['shippingAddress']['address1'])->toBe('123 Example Street');
    $this->travelTo(now('America/New_York')->setDate(2027, 2, 28)->setTime(10, 0)->utc());
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toHaveCount(1);
});

test('a lost Shopify response recovers the original order without another create or Braintree charge', function () {
    $s = $this->scenario;
    $s->activate();
    $s->lostOrderResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('unknown');
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->handoff_status)->toBe('confirmed')->and($s->orderCreates)->toHaveCount(1)->and($s->sales)->toHaveCount(1);
});

test('processing settlement creates no Shopify order until the original transaction actually settles', function () {
    $s = $this->scenario;
    $s->activate();
    $s->settled = false;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('processing')->and($s->orderCreates)->toBe([]);
    $s->transactions['txn_1']->_set('status', 'settled');
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')->and($s->sales)->toHaveCount(1);
});

test('stopped source billing and isolated sandbox store are required before replacement activation', function (string $defect) {
    $s = $this->scenario;
    if ($defect === 'source_active') {
        $s->sourceStopped = false;
    } else {
        $s->defect = 'production_store';
    }
    expect(fn () => $s->activate())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->agreement->fresh()->activated_at)->toBeNull()->and(SubscriptionPilotMember::first()->billing_owner)->toBe('recharge')
        ->and($s->sales)->toBe([])->and($s->orderCreates)->toBe([]);
})->with(['source_active', 'production_store']);

test('early overdue or independently paused collection cannot submit a sale', function (string $defect) {
    $s = $this->scenario;
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    match ($defect) {
        'early' => $this->travelTo(now('America/New_York')->setTime(8, 0)->utc()),
        'overdue' => $this->travelTo(now('America/New_York')->addDay()->utc()),
        'gate' => config()->set('subscriptions.replacement_braintree_collection_enabled', false),
    };
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
})->with(['early', 'overdue', 'gate']);

test('the durable dispatch marker rejects a second direct SDK sale attempt and cannot be erased', function () {
    $s = $this->scenario;
    $s->activate();
    $s->lostSaleResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect(data_get($cycle->reconciliation, 'dispatch_started_at'))->not->toBeNull();
    $cycle->update(['payment_status' => 'sending']);
    expect(fn () => app(SubscriptionBraintreeTransport::class)->sale($cycle->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $cycle->fresh()->update(['reconciliation' => []]))->toThrow(LogicException::class);
    expect($s->sales)->toHaveCount(1);
});

test('a different provider amount cannot create a paid Shopify order or trigger another sale', function () {
    $s = $this->scenario;
    $s->activate();
    $s->defect = 'payment_amount';
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($s->orderCreates)->toBe([]);
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toHaveCount(1);
});

test('an ambiguous zero-result recovery never resubmits a sale even after the original result is absent', function () {
    $s = $this->scenario;
    $s->activate();
    $s->lostSaleResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    $s->transactions = [];
    $this->travel(10)->minutes();
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($s->sales)->toHaveCount(1)->and($s->orderCreates)->toBe([]);
});

test('late refunds disputes and additional transactions block later collection and preserve existing orders', function (string $defect) {
    $s = $this->scenario;
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    if ($defect === 'refund') {
        $s->transactions['txn_1']->_set('refundIds', ['refund_1']);
    } elseif ($defect === 'dispute') {
        $s->transactions['txn_1']->_set('disputes', [(object) ['id' => 'dispute_1']]);
    } else {
        $s->transactions['txn_extra'] = $s->transaction('txn_extra', ['orderId' => 'another_order']);
    }
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe(match ($defect) {
        'refund' => 'refunded', 'dispute' => 'disputed', default => 'review'
    })
        ->and($cycle->shopify_order_id)->toBe('222')->and($s->orderCreates)->toHaveCount(1);
    $this->travelTo(now('America/New_York')->setDate(2027, 2, 28)->setTime(10, 0)->utc());
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toHaveCount(1);
})->with(['refund', 'dispute', 'additional']);

test('billing advancement invalidates stale customer schedule reviews and never advances twice', function () {
    $s = $this->scenario;
    $s->activate();
    $revision = $s->agreement->revision;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($s->agreement->fresh()->revision)->toBe($revision + 1);
    expect(fn () => app(\App\Services\Subscriptions\SubscriptionBillingAgreementService::class)->change($s->tenant->id, $s->actor, $s->agreement->id, 'skip', (string) \Illuminate\Support\Str::uuid(), $revision))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->execution->reconcile($cycle);
    expect($s->agreement->fresh()->revision)->toBe($revision + 1);
});

test('operator previews and default processing make no provider requests and cannot activate billing', function () {
    $s = $this->scenario;
    $options = ['action' => 'collect', '--tenant' => $s->tenant->slug, '--actor' => $s->actor->id, '--agreement' => $s->agreement->id];
    $this->artisan('subscriptions:braintree', $options)->assertSuccessful();
    $this->artisan('subscriptions:replacement-process')->assertSuccessful();
    \Illuminate\Support\Facades\Http::assertNothingSent();
    expect($s->sales)->toBe([])->and($s->agreement->fresh()->activated_at)->toBeNull();
});

test('scheduled processing is limited to the exact allowed members and can be stopped independently', function () {
    $s = $this->scenario;
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    config()->set('subscriptions.replacement_member_ids', []);
    $this->artisan('subscriptions:replacement-process', ['--collect' => true, '--reconcile' => true])->assertSuccessful();
    expect($s->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
    config()->set('subscriptions.replacement_member_ids', [$s->agreement->member_id]);
    $this->artisan('subscriptions:replacement-process', ['--collect' => true])->assertSuccessful();
    expect($s->sales)->toHaveCount(1)->and(SubscriptionBillingCycle::count())->toBe(1);
});

function acceptedProductionBraintreeScenario(): BraintreeScenario
{
    $s = new BraintreeScenario(production: true);
    app(SubscriptionBraintreeMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111');
    app(SubscriptionBraintreeExecutionService::class)->acceptMerchant($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash);
    config()->set('subscriptions.replacement_braintree_live_acceptance_verified', true);

    return $s;
}

function braintreeProjectedOrder(BraintreeScenario $s): array
{
    return ['id' => '222', 'test' => false, 'customer' => ['id' => '456'], 'processed_at' => $s->savedOrder['processedAt'],
        'updated_at' => $s->savedOrder['updatedAt'], 'currency' => 'USD', 'total_price' => '25.00', 'financial_status' => 'paid',
        'fulfillment_status' => 'fulfilled',
        'note_attributes' => array_map(fn ($a) => ['name' => $a['key'], 'value' => $a['value']], $s->savedOrder['customAttributes']),
        'transactions' => [['id' => '333', 'gateway' => 'everbranch_braintree', 'kind' => 'SALE', 'status' => 'SUCCESS', 'amount' => '25.00']]];
}

test('production activation requires both the live acceptance gate and the exact original merchant proof', function (string $defect) {
    $s = acceptedProductionBraintreeScenario();
    if ($defect === 'global_gate') {
        config()->set('subscriptions.replacement_braintree_live_acceptance_verified', false);
    } else {
        $metadata = $s->connection->refresh()->metadata;
        if ($defect === 'missing_merchant') {
            unset($metadata['subscription_acceptance']);
        } else {
            $metadata['subscription_acceptance']['processor_context']['credential_fingerprint'] = 'another_original_credential';
        }
        $s->connection->update(['metadata' => $metadata]);
    }
    expect(fn () => $s->activate())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->agreement->fresh()->activated_at)->toBeNull()->and($s->sales)->toBe([])->and($s->orderCreates)->toBe([]);
})->with(['global_gate', 'missing_merchant', 'credential_context']);

test('production payment and owned fulfillment appear in the dashboard without counting the external Shopify receipt twice', function () {
    $s = acceptedProductionBraintreeScenario();
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    $service = app(SubscriptionPilotService::class);
    $order = braintreeProjectedOrder($s);
    expect($service->projectShopifyOrder($s->tenant->id, $s->store->store_key, $order))->toBe(1);
    $service->projectShopifyOrder($s->tenant->id, $s->store->store_key, $order);
    $report = $service->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0])->toMatchArray(['paid_cents' => 2500, 'payment_status' => 'Confirmed Braintree payment',
        'shopify_status' => 'Confirmed Shopify order', 'fulfillment_status' => 'Observed fulfillment', 'duplicate_alert' => false,
        'payment_observed' => true, 'order_observed' => true, 'fulfillment_observed' => true, 'coverage_current' => true])
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Braintree collections')['data'])->toBe([25.0])
        ->and(DB::table('subscription_pilot_events')->where('kind', 'payment')->count())->toBe(0)
        ->and(DB::table('subscription_pilot_events')->where('kind', 'fulfillment')->count())->toBe(1);
    if ($output = getenv('SUBSCRIPTIONS_REPLACEMENT_RENDER_PATH')) {
        file_put_contents($output, view('subscriptions.pilot-report', ['pilot' => $report, 'pilotTenant' => $s->tenant])->render());
    }
    $s->transactions['txn_1']->_set('refundIds', ['refund_1']);
    $this->execution->reconcile($cycle);
    $report = $service->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0]['paid_cents'])->toBe(0)
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Braintree collections')['data'])->toBe([null]);
});

test('a Shopify note alone or wrong original order customer processor receipt account or terms cannot adopt a Braintree fulfillment', function (string $defect) {
    $s = acceptedProductionBraintreeScenario();
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $this->execution->collect($s->agreement);
    $order = braintreeProjectedOrder($s);
    if ($defect === 'order') {
        $order['id'] = '999';
    } elseif ($defect === 'customer') {
        $order['customer']['id'] = '999';
    } else {
        $name = match ($defect) {
            'receipt' => 'everbranch_braintree_transaction', 'account' => 'everbranch_processor_account',
            'terms' => 'everbranch_terms_hash', default => 'everbranch_billing_cycle',
        };
        $order['note_attributes'] = array_map(fn ($a) => $a['name'] === $name ? array_replace($a, ['value' => 'different_original']) : $a, $order['note_attributes']);
    }
    expect(app(SubscriptionPilotService::class)->projectShopifyOrder($s->tenant->id, $s->store->store_key, $order))->toBe(0)
        ->and(DB::table('subscription_pilot_events')->where('kind', 'fulfillment')->count())->toBe(0);
})->with(['order', 'customer', 'receipt', 'account', 'terms', 'cycle']);

test('sandbox acceptance is displayed separately and never enters paid totals or production collections', function () {
    $s = $this->scenario;
    $s->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $this->execution->collect($s->agreement);
    $report = app(SubscriptionPilotService::class)->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0])->toMatchArray(['paid_cents' => 0, 'payment_status' => 'Confirmed sandbox payment', 'shopify_status' => 'Confirmed sandbox order',
        'payment_observed' => false, 'order_observed' => false, 'fulfillment_observed' => false])
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Braintree collections')['data'])->toBe([null])
        ->and(DB::table('subscription_pilot_events')->count())->toBe(0);
});

test('source billing restarting after capture holds fulfillment and recovers the same paid transaction after fresh source proof', function () {
    $s = $this->scenario;
    $s->activate();
    $s->sourceRestartsAfterSale = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('review')->and($cycle->paid_at)->not->toBeNull()->and($s->orderCreates)->toBe([])
        ->and(data_get($cycle->reconciliation, 'coverage.reason'))->toBe('source_billing_or_coverage_requires_review');
    $s->sourceStopped = true;
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($s->sales)->toHaveCount(1)->and($s->orderCreates)->toHaveCount(1);
});

test('additional source collection after capture remains a duplicate alert even if source billing is later stopped', function () {
    $s = acceptedProductionBraintreeScenario();
    $s->activate();
    $s->sourceCollectsAfterSale = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('review')->and(data_get($cycle->reconciliation, 'possible_duplicate'))->toBeTrue()
        ->and($cycle->paid_at)->not->toBeNull()->and($s->orderCreates)->toBe([]);
    $s->sourceCollectsAfterSale = false;
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe('review')->and($s->orderCreates)->toBe([])->and($s->sales)->toHaveCount(1)
        ->and(app(SubscriptionPilotService::class)->dashboard($s->tenant->id, $s->actor)['members'][0])->toMatchArray(['paid_cents' => 2500, 'duplicate_alert' => true]);
});

test('a definite validation rejection or processor decline never resubmits the original cycle or creates an order', function (string $failure) {
    $s = $this->scenario;
    $s->activate();
    $s->rejectSale = $failure === 'validation';
    $s->declineSale = $failure === 'decline';
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('declined')->and($cycle->paid_at)->toBeNull();
    $this->execution->collect($s->agreement);
    expect($s->sales)->toHaveCount(1)->and($s->orderCreates)->toBe([]);
})->with(['validation', 'decline']);

test('external Shopify tax uses a numeric GraphQL rate and keeps the exact captured total', function () {
    $terms = $this->scenario->agreement->terms;
    $terms['tax_rate_bps'] = 700;
    $terms['tax_cents'] = 175;
    $terms['shipping_cents'] = 500;
    $terms['terms_hash'] = $this->scenario->agreement->terms_hash;
    $cycle = new SubscriptionBillingCycle(['tenant_id' => $this->scenario->tenant->id, 'processor' => 'braintree',
        'amount_cents' => 3175, 'currency' => 'USD', 'public_id' => (string) \Illuminate\Support\Str::uuid(), 'provider_reference' => 'fictional_paid_transaction',
        'method_binding' => $this->scenario->agreement->method_binding, 'terms' => $terms]);
    $payload = app(SubscriptionPilotShopifyBridge::class)->payload($cycle);
    expect($payload['order']['taxLines'][0]['rate'])->toBe(0.07)
        ->and($payload['order']['transactions'][0]['amountSet']['shopMoney']['amount'])->toBe('31.75')
        ->and($payload['options'])->toMatchArray(['sendReceipt' => false, 'sendFulfillmentReceipt' => false]);
});

test('changed delivery tax receipt or additional Shopify collection cannot confirm fulfillment or recreate either original request', function (string $defect) {
    $s = $this->scenario;
    $s->activate();
    $s->defect = $defect;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('unknown')->and($cycle->shopify_order_id)->toBeNull();
    expect(fn () => $this->execution->reconcile($cycle))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($s->sales)->toHaveCount(1)->and($s->orderCreates)->toHaveCount(1);
})->with(['handoff_address', 'handoff_tax', 'handoff_receipt', 'handoff_additional_payment']);
