<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyContractService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyAdoptionScenario;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyAdoptionScenario;
    $this->contracts = app(SubscriptionShopifyContractService::class);
});

function originalShopifyCalculation($case): array
{
    $s = $case->s;

    return $case->contracts->calculate($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash, $s->agreement->revision);
}

test('calculation verifies exact original economics while Recharge remains primary and the result is reused', function () {
    $quote = originalShopifyCalculation($this);
    $again = originalShopifyCalculation($this);
    expect($quote['adoption_status'])->toBe('calculated')->and($quote['quote'])->toMatchArray([
        'currency' => 'USD', 'amount_cents' => 2500, 'shipping_cents' => 0, 'estimated_tax_cents' => 0,
        'tax_is_estimated' => true, 'charges_customer' => false, 'creates_order' => false,
    ])->and($again['quote']['review_hash'])->toBe($quote['quote']['review_hash'])
        ->and($this->s->calculates)->toBe(1)->and($this->s->commits)->toBe(0)
        ->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and(SubscriptionPilotMember::findOrFail($this->s->agreement->member_id)->billing_owner)->toBe('recharge');
});

test('adoption requires fresh original Recharge shutdown and ends with one paused owned contract at its original date', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    expect(fn () => $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1))->toThrow(HttpException::class);
    expect($s->commits)->toBe(0);
    $s->sourceStopped = true;
    $result = $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1);
    $again = $this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id);
    expect($result['adoption_status'])->toBe('adopted_paused')->and($again['contract_adopted'])->toBeTrue()
        ->and($s->contract['status'])->toBe('PAUSED')->and($s->contract['nextBillingDate'])->toBe('2027-01-31T14:00:00.000000Z')
        ->and($s->calculates)->toBe(1)->and($s->commits)->toBe(1)->and($s->pauses)->toBe(1)->and($s->dateChanges)->toBe(1)
        ->and($s->agreement->fresh()->status)->toBe('prepared')->and($s->agreement->fresh()->activated_at)->toBeNull()
        ->and(SubscriptionPilotMember::findOrFail($s->agreement->member_id)->billing_owner)->toBe('recharge')
        ->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('incomplete calculations and changed totals warnings or products cannot be committed', function (string $defect) {
    $s = $this->s;
    $s->adoptionDefect = $defect;
    $result = originalShopifyCalculation($this);
    expect($result['adoption_status'])->toBe('calculation_review')->and($result['quote'])->toBeNull();
    $s->sourceStopped = true;
    expect(fn () => $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, str_repeat('a', 64), 1))->toThrow(HttpException::class);
    expect($s->commits)->toBe(0)->and($s->contract)->toBeNull();
})->with(['tax', 'warning', 'quantity']);

test('pending calculations are polled by original id without another calculate mutation', function () {
    $s = $this->s;
    $s->pending = true;
    expect(originalShopifyCalculation($this)['adoption_status'])->toBe('calculation_pending');
    $s->pending = false;
    expect(originalShopifyCalculation($this)['adoption_status'])->toBe('calculated')->and($s->calculates)->toBe(1);
});

test('actual pinned API capability and write permission are required before any provider mutation', function (string $defect) {
    $this->s->adoptionDefect = $defect;
    expect(fn () => originalShopifyCalculation($this))->toThrow(HttpException::class);
    expect($this->s->calculates)->toBe(0)->and($this->s->commits)->toBe(0);
    $this->s->adoptionDefect = null;
    expect(originalShopifyCalculation($this)['adoption_status'])->toBe('calculated')->and($this->s->calculates)->toBe(1)
        ->and(SubscriptionBillingAction::where('kind', 'shopify_calculation_requested')->count())->toBe(1);
})->with(['unsupported_api', 'write_scope']);

test('a lost calculation response never creates another calculation or contract', function () {
    $this->s->adoptionDefect = 'lost_calculation';
    expect(originalShopifyCalculation($this)['adoption_status'])->toBe('calculation_unconfirmed');
    expect(fn () => originalShopifyCalculation($this))->toThrow(HttpException::class);
    expect($this->s->calculates)->toBe(1)->and($this->s->commits)->toBe(0);
});

test('lost commit response recovers the same original contract and never commits again', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'lost_commit';
    expect($this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1)['adoption_status'])->toBe('commit_unconfirmed');
    $s->adoptionDefect = null;
    expect($this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id)['contract_adopted'])->toBeTrue()
        ->and($s->commits)->toBe(1)->and($s->pauses)->toBe(1)->and(SubscriptionBillingCycle::count())->toBe(0);
    expect(fn () => app(SubscriptionShopifyBillingTransport::class)->contractMutation($s->agreement->fresh(), 'commit'))->toThrow(HttpException::class);
    expect($s->commits)->toBe(1);
});

test('uncertain missing duplicate or incomplete contract recovery never submits another commit', function (string $defect) {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'lost_commit';
    $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1);
    $s->adoptionDefect = $defect;
    if ($defect === 'missing') {
        $s->contract = null;
    }
    if ($defect === 'incomplete_recovery') {
        expect(fn () => $this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id))->toThrow(HttpException::class);
    } else {
        expect($this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id)['contract_adopted'])->toBeFalse();
    }
    expect($s->commits)->toBe(1)->and($s->pauses)->toBe(0)->and(SubscriptionBillingCycle::count())->toBe(0);
})->with(['missing', 'two_matches', 'incomplete_recovery']);

test('lost pause and billing date acknowledgements recover their original contract without duplicate patches', function (string $defect) {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = $defect;
    expect(fn () => $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1))->toThrow(HttpException::class);
    $s->adoptionDefect = null;
    expect($this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id)['contract_adopted'])->toBeTrue()
        ->and($s->commits)->toBe(1)->and($s->pauses)->toBe(1)->and($s->dateChanges)->toBe(1);
})->with(['lost_pause', 'lost_date']);

test('changed contract terms are stopped before review and never activate local or provider billing', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'changed_contract';
    $result = $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1);
    expect($result['adoption_status'])->toBe('adopted_paused_terms_review')->and($s->contract['status'])->toBe('PAUSED')
        ->and($s->dateChanges)->toBe(0)->and($s->agreement->fresh()->activated_at)->toBeNull();
});

test('a source pause between fresh stop coverage and dispatch blocks the commit', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'source_rotated';
    expect(fn () => $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1))->toThrow(HttpException::class);
    expect($s->commits)->toBe(0);
});

test('adoption previews are offline and no current or other tenant rollout defaults enable mutations', function () {
    $s = $this->s;
    $count = count(Http::recorded());
    config()->set('subscriptions.replacement_shopify_adoption_enabled', false);
    $this->artisan('subscriptions:shopify', ['action' => 'adopt', '--tenant' => $s->tenant->slug, '--actor' => $s->actor->id, '--agreement' => $s->agreement->id])
        ->expectsOutputToContain('"provider_requests": false')->assertExitCode(0);
    expect(fn () => originalShopifyCalculation($this))->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($count)->and($s->calculates)->toBe(0);
});

test('concurrent reentry cannot dispatch either original calculation or commit twice', function () {
    $s = $this->s;
    $s->beforeCalculate = function () use ($s) {
        expect(fn () => app(SubscriptionShopifyBillingTransport::class)->contractMutation($s->agreement->fresh(), 'calculate'))->toThrow(HttpException::class);
    };
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->beforeCommit = function () use ($s) {
        expect(fn () => app(SubscriptionShopifyBillingTransport::class)->contractMutation($s->agreement->fresh(), 'commit'))->toThrow(HttpException::class);
    };
    expect($this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1)['contract_adopted'])->toBeTrue()
        ->and($s->calculates)->toBe(1)->and($s->commits)->toBe(1);
});

test('original adoption requests dispatch markers references and stop proof cannot be replaced or erased', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1);
    foreach (['original', 'calculation_id', 'calculate_dispatched_at', 'commit_original', 'commit_dispatch_proof', 'commit_dispatched_at', 'contract_id'] as $field) {
        $row = $s->agreement->fresh();
        $evidence = $row->authorization_evidence;
        unset($evidence['shopify_adoption'][$field]);
        expect(fn () => $row->update(['authorization_evidence' => $evidence]))->toThrow(LogicException::class);
    }
    expect($s->agreement->fresh()->getRawOriginal('authorization_evidence'))->not->toContain('original_method', 'ORIGINAL_SUBSCRIPTION_TOKEN', 'ORIGINAL_RECHARGE_TOKEN');
});

test('expired original adoption recovery still pauses the same contract and requires a new date review', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'lost_commit';
    $this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1);
    $s->adoptionDefect = null;
    $this->travelTo(CarbonImmutable::parse('2027-02-02T15:00:00Z'));
    $result = $this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id);
    expect($result['adoption_status'])->toBe('adopted_paused_date_review')->and($s->contract['status'])->toBe('PAUSED')
        ->and($s->commits)->toBe(1)->and($s->dateChanges)->toBe(0)->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('incomplete successful provider payloads remain uncertain and only an existing original contract is recovered', function () {
    $s = $this->s;
    $quote = originalShopifyCalculation($this);
    $s->sourceStopped = true;
    $s->adoptionDefect = 'missing_commit_result';
    expect($this->contracts->adopt($s->tenant->id, $s->actor, $s->agreement->id, $quote['quote']['review_hash'], 1)['adoption_status'])->toBe('commit_unconfirmed');
    $s->adoptionDefect = null;
    expect($this->contracts->recover($s->tenant->id, $s->actor, $s->agreement->id)['contract_adopted'])->toBeTrue()->and($s->commits)->toBe(1);
});

test('an incomplete calculate payload does not permit a second provider calculation', function () {
    $this->s->adoptionDefect = 'missing_calculation_result';
    expect(originalShopifyCalculation($this)['adoption_status'])->toBe('calculation_unconfirmed');
    expect(fn () => originalShopifyCalculation($this))->toThrow(HttpException::class);
    expect($this->s->calculates)->toBe(1);
});
