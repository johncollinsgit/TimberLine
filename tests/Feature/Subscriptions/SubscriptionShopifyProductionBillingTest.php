<?php

use App\Models\SubscriptionPilotEvent;
use App\Models\SubscriptionPilotMember;
use App\Services\Subscriptions\SubscriptionPilotService;
use App\Services\Subscriptions\SubscriptionPilotSourceGuard;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyExecutionScenario(production: true); // Fictional production-shaped replies; every request is locally faked.
    $this->execution = app(SubscriptionShopifyExecutionService::class);
});

test('production Shopify activation requires independent live acceptance and the exact original account proof', function (string $defect) {
    $s = $this->s;
    if ($defect === 'global_gate') {
        config()->set('subscriptions.replacement_shopify_live_acceptance_verified', false);
    } else {
        $evidence = $s->agreement->authorization_evidence;
        if ($defect === 'missing_merchant') {
            unset($evidence['shopify_billing_acceptance']);
        } else {
            $evidence['shopify_billing_acceptance']['processor_context']['credential_fingerprint'] = 'different-original-credential';
        }
        $s->agreement->update(['authorization_evidence' => $evidence]);
    }
    expect(fn () => $s->activate())->toThrow(HttpException::class);
    expect($s->agreement->fresh()->activated_at)->toBeNull()->and($s->bills)->toBe([])->and($s->activations)->toBe(0);
})->with(['global_gate', 'missing_merchant', 'credential_context']);

test('a native production receipt is reported once as Shopify payment and later fulfillment', function () {
    $s = $this->s;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = $this->execution->collect($s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed');
    $service = app(SubscriptionPilotService::class);
    $report = $service->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0])->toMatchArray(['paid_cents' => 2500, 'payment_status' => 'Confirmed Shopify payment',
        'shopify_status' => 'Confirmed Shopify order', 'duplicate_alert' => false, 'coverage_current' => true])
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Shopify Payments collections')['data'])->toBe([25.0])
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Braintree collections')['data'])->toBe([null]);
    $s->attempts[$cycle->provider_reference]['state']['order']['displayFulfillmentStatus'] = 'FULFILLED';
    $s->attempts[$cycle->provider_reference]['state']['order']['updatedAt'] = now()->addSecond()->toISOString();
    $this->travel(1)->seconds();
    $this->execution->reconcile($cycle);
    $this->execution->reconcile($cycle);
    $report = $service->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0]['paid_cents'])->toBe(2500)->and($report['members'][0]['fulfillment_observed'])->toBeTrue()
        ->and($s->bills)->toHaveCount(1);
});

test('a plain own-order string cannot hide a real Shopify Payments collection', function () {
    $s = $this->s;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = $this->execution->collect($s->agreement);
    expect(fn () => app(SubscriptionPilotSourceGuard::class)->stopped(SubscriptionPilotMember::first(), $s->source->id, '2027-01-31', $s->actor, $cycle->shopify_order_id))
        ->toThrow(\App\Exceptions\SubscriptionPilotCoverageException::class);
    expect($s->bills)->toHaveCount(1);
});

test('a native refund or dispute removes the original payment from confirmed collection totals', function (string $defect) {
    $s = $this->s;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = $this->execution->collect($s->agreement);
    $s->billingDefect = $defect;
    $this->execution->reconcile($cycle);
    $report = app(SubscriptionPilotService::class)->dashboard($s->tenant->id, $s->actor);
    expect($report['members'][0]['paid_cents'])->toBe(0)
        ->and(collect($report['chart']['datasets'])->firstWhere('label', 'Shopify Payments collections')['data'])->toBe([null])
        ->and(SubscriptionPilotEvent::where('kind', 'payment')->count())->toBe(2)->and($s->bills)->toHaveCount(1);
})->with(['refund', 'dispute']);
