<?php

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionBraintreeTransport;
use App\Services\Subscriptions\SubscriptionPilotShopifyBridge;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyContractService;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\BraintreeScenario;
use Tests\Support\Subscriptions\ShopifyAdoptionScenario;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    config()->set('database.connections.subscription_dispatch_observer', config('database.connections.mysql'));
    DB::purge('subscription_dispatch_observer');
});

afterEach(fn () => DB::purge('subscription_dispatch_observer'));

function observedSubscriptionAdoption(ShopifyAdoptionScenario $s): array
{
    return data_get(SubscriptionBillingAgreement::on('subscription_dispatch_observer')->where('tenant_id', $s->tenant->id)->findOrFail($s->agreement->id)->authorization_evidence, 'shopify_adoption', []);
}

function observedSubscriptionCycle($s): ?SubscriptionBillingCycle
{
    return SubscriptionBillingCycle::on('subscription_dispatch_observer')->where('tenant_id', $s->tenant->id)->where('agreement_id', $s->agreement->id)->first();
}

test('new adoption calculation and commit reject outer transactions before provider work', function () {
    $s = new ShopifyAdoptionScenario;
    $contracts = app(SubscriptionShopifyContractService::class);
    $reads = count(Http::recorded());
    DB::beginTransaction();
    try {
        expect(fn () => $contracts->calculate($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash, 1))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(fn () => app(SubscriptionShopifyBillingTransport::class)->contractMutation($s->agreement, 'calculate'))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(count(Http::recorded()))->toBe($reads)->and(observedSubscriptionAdoption($s))->toBe([]);
    } finally {
        DB::rollBack();
    }
    $quote = $contracts->calculate($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash, 1);
    $s->sourceStopped = true;
    $reads = count(Http::recorded());
    DB::beginTransaction();
    try {
        expect(fn () => $contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(fn () => app(SubscriptionShopifyBillingTransport::class)->contractMutation($s->agreement->fresh(), 'commit'))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(count(Http::recorded()))->toBe($reads)->and(observedSubscriptionAdoption($s))->not->toHaveKey('commit_original');
    } finally {
        DB::rollBack();
    }
    expect($s->calculates)->toBe(1)->and($s->commits)->toBe(0);
});

test('adoption dispatch markers are visible on an independent connection before each provider request', function () {
    $s = new ShopifyAdoptionScenario;
    $contracts = app(SubscriptionShopifyContractService::class);
    $observed = [];
    $s->beforeCalculate = function () use ($s, &$observed) {
        $observed['calculate'] = observedSubscriptionAdoption($s);
    };
    $quote = $contracts->calculate($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash, 1);
    $s->sourceStopped = true;
    $s->beforeCommit = function () use ($s, &$observed) {
        $observed['commit'] = observedSubscriptionAdoption($s);
    };
    $s->adoptionDefect = 'lost_commit';
    expect($contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1)['adoption_status'])->toBe('commit_unconfirmed');
    expect($observed['calculate'])->toHaveKeys(['original', 'calculate_dispatched_at'])
        ->and($observed['commit'])->toHaveKeys(['commit_original', 'commit_dispatched_at', 'commit_dispatch_proof'])
        ->and($observed['commit']['commit_original']['calculation_id'])->toBe($quote['quote']['calculation_id']);
    $s->adoptionDefect = null;
    DB::purge('mysql');
    $result = DB::transaction(fn () => $contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1));
    expect($result['contract_adopted'])->toBeTrue()->and($s->calculates)->toBe(1)->and($s->commits)->toBe(1);
});

test('new original-method collection rejects an outer transaction before reads or cycle creation', function (string $provider) {
    $s = $provider === 'shopify' ? new ShopifyExecutionScenario : new BraintreeScenario;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $execution = app($provider === 'shopify' ? SubscriptionShopifyExecutionService::class : SubscriptionBraintreeExecutionService::class);
    $reads = count(Http::recorded());
    DB::beginTransaction();
    try {
        expect(fn () => $execution->collect($s->agreement))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(count(Http::recorded()))->toBe($reads)->and(observedSubscriptionCycle($s))->toBeNull();
    } finally {
        DB::rollBack();
    }
    expect($execution->collect($s->agreement)->payment_status)->toBe('succeeded');
    expect($provider === 'shopify' ? $s->bills : $s->sales)->toHaveCount(1);
})->with(['shopify', 'braintree']);

test('payment dispatch commits before the provider request and a lost response recovers inside an outer transaction', function (string $provider) {
    $s = $provider === 'shopify' ? new ShopifyExecutionScenario : new BraintreeScenario;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $execution = app($provider === 'shopify' ? SubscriptionShopifyExecutionService::class : SubscriptionBraintreeExecutionService::class);
    $observed = null;
    $hook = function () use ($s, &$observed) {
        $observed = observedSubscriptionCycle($s);
    };
    if ($provider === 'shopify') {
        $s->beforeBill = $hook;
        $s->loseBillResponse = true;
    } else {
        $s->beforeSale = $hook;
        $s->lostSaleResponse = true;
    }
    $cycle = $execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($observed)->not->toBeNull()
        ->and($observed->payment_started_at)->not->toBeNull()->and(data_get($observed->reconciliation, 'dispatch_started_at'))->not->toBeNull()
        ->and($observed->request_uuid)->toBe($cycle->request_uuid)->and($observed->provider_request)->toBe($cycle->provider_request);
    $reads = count(Http::recorded());
    DB::beginTransaction();
    try {
        expect(fn () => $provider === 'shopify'
            ? app(SubscriptionShopifyBillingTransport::class)->bill($cycle, $cycle->source_evidence)
            : app(SubscriptionBraintreeTransport::class)->sale($cycle))->toThrow(HttpException::class, 'outside an enclosing transaction');
        expect(count(Http::recorded()))->toBe($reads);
    } finally {
        DB::rollBack();
    }
    DB::purge('mysql');
    $recovered = DB::transaction(fn () => $execution->collect($s->agreement));
    expect($recovered->payment_status)->toBe('succeeded')->and($provider === 'shopify' ? $s->bills : $s->sales)->toHaveCount(1);
    if ($provider === 'braintree') {
        expect($s->orderCreates)->toBe([])->and($recovered->handoff_started_at)->toBeNull();
        $reads = count(Http::recorded());
        DB::beginTransaction();
        try {
            expect(fn () => app(SubscriptionPilotShopifyBridge::class)->handoff($recovered))->toThrow(HttpException::class, 'outside an enclosing transaction');
            expect(count(Http::recorded()))->toBe($reads);
        } finally {
            DB::rollBack();
        }
        $receipt = null;
        $s->beforeOrderCreate = function () use ($s, &$receipt) {
            $receipt = observedSubscriptionCycle($s);
        };
        expect($execution->reconcile($recovered)->handoff_status)->toBe('confirmed')->and($s->orderCreates)->toHaveCount(1)->and($s->sales)->toHaveCount(1)
            ->and($receipt->handoff_started_at)->not->toBeNull()->and($receipt->shopify_request)->not->toBeNull();
    }
})->with(['shopify', 'braintree']);
