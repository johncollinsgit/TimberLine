<?php

use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyExecutionScenario;
    $this->execution = app(SubscriptionShopifyExecutionService::class);
});

function shopifyCollectionDay($case): void
{
    $case->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
}

test('Shopify individual activation retains the original saved method without a charge or order', function () {
    $s = $this->s;
    $s->activate();
    expect($s->agreement->status)->toBe('active')->and($s->agreement->revision)->toBe(2)
        ->and($s->contract['status'])->toBe('ACTIVE')->and($s->activations)->toBe(1)
        ->and(SubscriptionPilotMember::first()->billing_owner)->toBe('everbranch')
        ->and($s->bills)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('one Shopify attempt confirms its own paid order and advances the original month end anchor once', function () {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($cycle->provider_reference)->toBe('gid://shopify/SubscriptionBillingAttempt/9101')->and($cycle->shopify_order_id)->toBe('9301')
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28')
        ->and($s->bills)->toHaveCount(1)->and($s->bills[0]['input']['idempotencyKey'])->toBe($cycle->request_uuid)
        ->and($s->bills[0]['input']['paymentProcessingPolicy'])->toBe('FAIL_UNLESS_VALID_PAYMENT_METHOD')
        ->and($s->bills[0]['input']['inventoryPolicy'])->toBe('PRODUCT_VARIANT_INVENTORY_POLICY')
        ->and($cycle->shopify_request)->toBeNull();
    $this->execution->reconcile($cycle);
    expect($s->bills)->toHaveCount(1)->and($s->agreement->fresh()->revision)->toBe(3);
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'orderCreate'));
});

test('a lost original Shopify billing response is recovered without another attempt or order', function () {
    $s = $this->s;
    $s->activate();
    $s->loseBillResponse = true;
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($cycle->provider_reference)->toBeNull()->and($s->bills)->toHaveCount(1);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->shopify_order_id)->toBe('9301')
        ->and($s->bills)->toHaveCount(1)->and(SubscriptionBillingCycle::count())->toBe(1);
});

test('lost activation acknowledgement recovers the same contract and a replay cannot undo a customer stop', function () {
    $s = $this->s;
    $s->loseActivationResponse = true;
    expect(fn () => $s->activate())->toThrow(HttpException::class);
    expect($s->agreement->fresh()->activated_at)->toBeNull()->and($s->contract['status'])->toBe('ACTIVE')->and($s->activations)->toBe(1);
    $s->loseActivationResponse = false;
    $s->activate();
    $row = $s->agreement->fresh();
    app(SubscriptionBillingAgreementService::class)->change($row->tenant_id, $s->actor, $row->id, 'cancel', (string) Str::uuid(), $row->revision);
    $replayed = $this->execution->activate($row->tenant_id, $s->actor, $row->id, $row->terms_hash, 1);
    expect($replayed->status)->toBe('cancelled')->and($s->activations)->toBe(1)->and($s->bills)->toBe([]);
});

test('Shopify activation requires exact rollout acceptance original source and current authority', function (string $defect) {
    $s = $this->s;
    match ($defect) {
        'collection' => config()->set('subscriptions.replacement_collection_enabled', false),
        'activation' => config()->set('subscriptions.replacement_activation_enabled', false),
        'processor' => config()->set('subscriptions.replacement_shopify_collection_enabled', false),
        'handoff' => config()->set('subscriptions.replacement_handoff_enabled', false),
        'tenant' => config()->set('subscriptions.replacement_tenant_ids', []),
        'member' => config()->set('subscriptions.replacement_member_ids', []),
        'source' => $s->sourceStopped = false,
        'source_scope' => $s->sourceRevision->update(['scopes' => ['read_store']]),
        'source_expired' => $s->sourceRevision->update(['expires_at' => now()->subMinute()]),
    };
    expect(fn () => $s->activate())->toThrow(HttpException::class);
    expect($s->agreement->fresh()->activated_at)->toBeNull()->and(SubscriptionPilotMember::first()->billing_owner)->toBe('recharge')
        ->and($s->activations)->toBe(0)->and($s->bills)->toBe([]);
})->with(['collection', 'activation', 'processor', 'handoff', 'tenant', 'member', 'source', 'source_scope', 'source_expired']);

test('edited skipped billed or previously attempted Shopify cycles cannot collect', function (string $defect) {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $s->billingDefect = $defect;
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(HttpException::class);
    expect($s->bills)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
})->with(['edited_cycle', 'skipped_cycle', 'billed_cycle']);

test('the pinned billing API and own-contract permission are required before claiming payment dispatch', function (string $defect) {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $s->billingDefect = $defect;
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('prepared')->and($cycle->payment_started_at)->toBeNull()
        ->and(data_get($cycle->reconciliation, 'dispatch_started_at'))->toBeNull()->and($s->bills)->toBe([]);
    $s->billingDefect = null;
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($s->bills)->toHaveCount(1)->and(SubscriptionBillingCycle::count())->toBe(1);
})->with(['write_scope', 'history_scope', 'unsupported_api']);

test('pending challenged and declined attempts retain the original request without resending', function (string $state) {
    $s = $this->s;
    $s->activate();
    $s->attemptState = $state;
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe($state)->and($cycle->paid_at)->toBeNull()->and($cycle->shopify_order_id)->toBeNull();
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe($state)->and($s->bills)->toHaveCount(1);
    if ($state !== 'declined') {
        expect(fn () => app(SubscriptionBillingAgreementService::class)->change($s->tenant->id, $s->actor, $s->agreement->id, 'skip', (string) Str::uuid(), 2))->toThrow(HttpException::class);
    }
    $s->complete($cycle->provider_reference);
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe('succeeded')->and($s->bills)->toHaveCount(1)
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
})->with(['processing', 'requires_action', 'declined']);

test('missing incomplete and duplicate original attempt recovery never permits another dispatch', function (string $defect) {
    $s = $this->s;
    $s->activate();
    $s->loseBillResponse = true;
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    if ($defect === 'missing') {
        $s->attempts = [];
        $this->execution->reconcile($cycle);
    } else {
        $s->billingDefect = $defect;
        expect(fn () => $this->execution->reconcile($cycle))->toThrow(HttpException::class);
    }
    expect($cycle->fresh()->payment_status)->toBe('unknown')->and($cycle->fresh()->paid_at)->toBeNull()
        ->and($s->bills)->toHaveCount(1)->and(SubscriptionBillingCycle::count())->toBe(1);
})->with(['missing', 'incomplete_recovery', 'duplicate_attempt']);

test('changed original attempt or native order evidence cannot become a confirmed payment', function (string $defect) {
    $s = $this->s;
    $s->activate();
    $s->billingDefect = $defect;
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('unknown')->and($cycle->paid_at)->toBeNull()->and($cycle->handoff_status)->not->toBe('confirmed')
        ->and($s->bills)->toHaveCount(1)->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-01-31');
    if ($defect === 'attempt_key') {
        expect($this->execution->reconcile($cycle)->payment_status)->toBe('unknown');
    } else {
        expect(fn () => $this->execution->reconcile($cycle))->toThrow(HttpException::class);
    }
    expect($s->bills)->toHaveCount(1);
})->with(['attempt_key', 'attempt_contract', 'attempt_customer', 'attempt_origin', 'attempt_time', 'attempt_inventory', 'partial_transactions',
    'unknown_state', 'order_customer', 'order_test', 'order_amount', 'order_address', 'order_quantity', 'order_partial', 'order_discount', 'order_gateway', 'order_transaction']);

test('another Shopify or Recharge collection after dispatch is retained for review and cannot advance the cycle', function (string $provider) {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $s->beforeBill = function () use ($s, $provider) {
        if ($provider === 'shopify') {
            $s->otherCollection = true;
        } else {
            $s->sourceCollects = true;
        }
    };
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('review')->and($cycle->shopify_order_id)->toBe('9301')
        ->and($cycle->paid_at)->toBeNull()->and($s->bills)->toHaveCount(1)
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-01-31');
    $this->execution->reconcile($cycle);
    expect($cycle->fresh()->payment_status)->toBe('review')->and($s->bills)->toHaveCount(1);
})->with(['shopify', 'recharge']);

test('customer pause or cancel during the original request preserves its order and stops future billing', function (string $kind) {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $s->beforeBill = fn () => app(SubscriptionBillingAgreementService::class)->change($s->tenant->id, $s->actor, $s->agreement->id, $kind, (string) Str::uuid(), 2);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')
        ->and($s->agreement->fresh()->status)->toBe($kind === 'pause' ? 'paused' : 'cancelled')->and($s->bills)->toHaveCount(1);
    $this->execution->syncSchedule($s->agreement);
    expect($s->contract['status'])->toBe($kind === 'pause' ? 'PAUSED' : 'CANCELLED');
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(HttpException::class);
    expect($s->bills)->toHaveCount(1);
})->with(['pause', 'cancel']);

test('a pause racing with the preflight stops the original payment before dispatch is claimed', function () {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $s->beforeBillingCapabilities = fn () => app(SubscriptionBillingAgreementService::class)->change($s->tenant->id, $s->actor, $s->agreement->id, 'pause', (string) Str::uuid(), 2);
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_started_at)->toBeNull()->and($s->bills)->toBe([])->and($s->agreement->fresh()->status)->toBe('paused');
});

test('late refunds and disputes retain the original order and block a later collection', function (string $defect) {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $cycle = $this->execution->collect($s->agreement);
    $s->billingDefect = $defect;
    $cycle = $this->execution->reconcile($cycle);
    expect($cycle->payment_status)->toBe($defect === 'refund' ? 'refunded' : 'disputed')
        ->and($cycle->shopify_order_id)->toBe('9301')->and($cycle->paid_at)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2027-02-28T15:00:00Z'));
    expect(fn () => $this->execution->collect($s->agreement))->toThrow(HttpException::class);
    expect($s->bills)->toHaveCount(1);
})->with(['refund', 'dispute']);

test('old confirmed receipts remain reconcilable and the next month keeps the original anchor', function () {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    $first = $this->execution->collect($s->agreement);
    $this->travelTo(CarbonImmutable::parse('2027-02-15T15:00:00Z'));
    expect($this->execution->reconcile($first)->payment_status)->toBe('succeeded');
    $this->travelTo(CarbonImmutable::parse('2027-02-28T15:00:00Z'));
    $second = $this->execution->collect($s->agreement);
    expect($second->payment_status)->toBe('succeeded')->and($s->bills)->toHaveCount(2)
        ->and($s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-03-31');
});

test('owner commands and the worker preview remain offline and cannot bill without the apply choice', function () {
    $s = $this->s;
    $before = Http::recorded()->count();
    $this->artisan('subscriptions:shopify-billing', ['action' => 'collect', '--tenant' => $s->tenant->slug, '--actor' => $s->actor->id, '--agreement' => $s->agreement->id])
        ->expectsOutputToContain('"provider_requests": false')->assertSuccessful();
    $this->artisan('subscriptions:replacement-process')->assertSuccessful();
    expect(Http::recorded()->count())->toBe($before)->and($s->bills)->toBe([])->and($s->agreement->fresh()->activated_at)->toBeNull();
});

test('the worker collects only allowed Shopify members and synchronizes their accepted stop', function () {
    $s = $this->s;
    $s->activate();
    shopifyCollectionDay($this);
    config()->set('subscriptions.replacement_member_ids', []);
    $this->artisan('subscriptions:replacement-process', ['--collect' => true, '--reconcile' => true])->assertSuccessful();
    expect($s->bills)->toBe([]);
    config()->set('subscriptions.replacement_member_ids', [$s->agreement->member_id]);
    $this->artisan('subscriptions:replacement-process', ['--collect' => true, '--reconcile' => true])->assertSuccessful();
    expect($s->bills)->toHaveCount(1)->and(SubscriptionBillingCycle::first()->payment_status)->toBe('succeeded');
    $row = $s->agreement->fresh();
    app(SubscriptionBillingAgreementService::class)->change($row->tenant_id, $s->actor, $row->id, 'pause', (string) Str::uuid(), $row->revision);
    config()->set('subscriptions.replacement_collection_enabled', false);
    $this->artisan('subscriptions:replacement-process', ['--reconcile' => true])->assertSuccessful();
    expect($s->contract['status'])->toBe('PAUSED')->and($s->bills)->toHaveCount(1);
});

test('unchanged confirmed schedule synchronization makes no provider requests', function () {
    $s = $this->s;
    $s->activate();
    $this->execution->syncSchedule($s->agreement);
    $before = Http::recorded()->count();
    $this->execution->syncSchedule($s->agreement);
    expect(Http::recorded()->count())->toBe($before)->and($s->bills)->toBe([]);
});
