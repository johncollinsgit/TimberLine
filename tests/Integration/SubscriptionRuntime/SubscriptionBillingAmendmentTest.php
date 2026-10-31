<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionAmendmentQuote;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAmendmentService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionBraintreeMethodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\AmendmentScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->s = new AmendmentScenario;
    $this->row = $this->s->agreement->fresh();
    $this->change = $this->s->change();
    $this->approval = $this->s->approval();
    $this->uuid = (string) Str::uuid();
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

function applyReviewedAmendment($case): SubscriptionBillingAction
{
    return app(SubscriptionBillingAmendmentService::class)->apply($case->row->tenant_id, $case->s->actor, $case->row->id,
        $case->change, $case->approval, $case->quote['quote_hash'], $case->uuid, $case->quote['subject']['revision']);
}

test('a reviewed Braintree delivery amendment preserves the original finite customer pause through only its signed terms revisions', function (bool $unexplainedRevision) {
    $this->s->store->update(['store_key' => 'retail']);
    $profile = App\Models\MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $customer = new App\Services\Mobile\ModernForestryMobileCustomerSession($profile, 'fictional-original-customer',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    app(SubscriptionBillingAgreementService::class)->customerChange($customer, 'pause', (string) Str::uuid(), $this->row->revision, ['duration_months' => 1]);
    $this->row = $this->row->fresh();
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    applyReviewedAmendment($this);
    expect($this->row->fresh()->resume_on->toDateString())->toBe('2027-02-28');
    if ($unexplainedRevision) {
        DB::table('subscription_billing_agreements')->where('id', $this->row->id)->increment('revision');
    }
    $this->travelTo(now('America/New_York')->setDate(2027, 2, 28)->setTime(10, 0)->utc());
    if ($unexplainedRevision) {
        expect(fn () => app(SubscriptionBillingAgreementService::class)->resumeScheduled($this->row->fresh()))->toThrow(HttpException::class);
        expect($this->row->fresh()->status)->toBe('paused');
    } else {
        $resumed = app(SubscriptionBillingAgreementService::class)->resumeScheduled($this->row->fresh());
        expect($resumed->status)->toBe('active')->and($resumed->resume_on)->toBeNull()->and($resumed->next_billing_date->toDateString())->toBe('2027-02-28');
    }
    expect($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([]);
})->with([false, true]);

test('accepted future terms retain original authorization payment binding billing anchor and historical member evidence', function () {
    $original = SubscriptionBillingAuthorization::original($this->row);
    $evidence = $this->row->authorization_evidence;
    $member = (array) DB::table('subscription_pilot_members')->where('id', $this->row->member_id)->first();
    $action = applyReviewedAmendment($this);
    $current = $this->row->fresh();
    expect($current->terms['amount_cents'])->toBe(2675)->and($current->terms['lines'][0]['variant_id'])->toBe('888')
        ->and($current->terms['shipping_address']['address1'])->toBe('456 New Example Road')
        ->and($current->terms['lines'][0]['unit_price_cents'])->toBe(2500)->and($current->revision)->toBe($this->row->revision + 1)
        ->and($current->terms_hash)->not->toBe($this->quote['quote_hash'])->not->toBe($this->row->terms_hash)
        ->and($current->authorization_evidence)->toBe($evidence)->and(SubscriptionBillingAuthorization::original($current))->toBe($original)
        ->and($current->method_binding)->toBe($this->row->method_binding)->and($current->next_billing_date)->toEqual($this->row->next_billing_date)
        ->and($current->billing_anchor_date)->toEqual($this->row->billing_anchor_date)->and($current->activated_at)->toEqual($this->row->activated_at)
        ->and((array) DB::table('subscription_pilot_members')->where('id', $current->member_id)->first())->toBe($member)
        ->and($action->before_state['terms'])->toBe($this->row->terms)
        ->and(DB::table('subscription_billing_actions')->where('id', $action->id)->value('after_state'))->not->toContain('456 New Example Road')
        ->and($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([]);
    SubscriptionBillingAuthorization::assertCurrent($current);
});

test('unchanged acceptance replay after a later pause keeps the original action without provider calls or undoing the pause', function () {
    $first = applyReviewedAmendment($this);
    $current = $this->row->fresh();
    app(SubscriptionBillingAgreementService::class)->change($current->tenant_id, $this->s->actor, $current->id, 'pause', (string) Str::uuid(), $current->revision);
    $requests = Http::recorded()->count();
    $retry = applyReviewedAmendment($this);
    expect($retry->id)->toBe($first->id)->and(Http::recorded()->count())->toBe($requests)
        ->and($this->row->fresh()->status)->toBe('paused')->and(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(1);
});

test('the amended due cycle charges once on the original vaulted method and records exact new delivery and tax in one Shopify order', function () {
    applyReviewedAmendment($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh());
    expect(fn () => app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh()))->toThrow(HttpException::class);
    $again = app(SubscriptionBraintreeExecutionService::class)->reconcile($cycle);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->handoff_status)->toBe('confirmed')->and($again->id)->toBe($cycle->id)
        ->and(count($this->s->sales))->toBe(1)->and($this->s->sales[0]['amount'])->toBe('26.75')
        ->and($this->s->sales[0]['paymentMethodToken'])->toBe($this->row->method_binding['payment_method_token'])
        ->and(count($this->s->orderCreates))->toBe(1)
        ->and($this->s->orderCreates[0]['order']['shippingAddress']['address1'])->toBe('456 New Example Road')
        ->and($this->s->orderCreates[0]['order']['lineItems'][0]['variantId'])->toBe('gid://shopify/ProductVariant/888')
        ->and(array_column($this->s->orderCreates[0]['order']['taxLines'], 'title'))->toBe(['State tax', 'Local tax'])
        ->and(array_column(array_column($this->s->orderCreates[0]['order']['taxLines'], 'priceSet'), 'shopMoney'))->toBe([
            ['amount' => '1.00', 'currencyCode' => 'USD'], ['amount' => '0.75', 'currencyCode' => 'USD'],
        ])
        ->and($cycle->terms['amount_cents'])->toBe(2675)->and($this->row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
    // The original $25 transaction/order still proves method ownership; it is never rewritten to the new price/address.
    $proof = app(SubscriptionBraintreeMethodService::class)->verify($this->row->tenant_id, $this->s->actor, $this->row->id, '111', false);
    expect($proof['original_terms_hash'])->toBe($this->quote['subject']['original_terms_hash']);
});

test('a second accepted address keeps prior paid cycle requests and the already created order unchanged', function () {
    applyReviewedAmendment($this);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh());
    $frozen = (array) DB::table('subscription_billing_cycles')->where('id', $cycle->id)->first();
    $order = $this->s->savedOrder;
    $this->row = $this->row->fresh();
    $this->change['shipping_address']['address1'] = '789 Next Example Lane';
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    $this->uuid = (string) Str::uuid();
    applyReviewedAmendment($this);
    expect((array) DB::table('subscription_billing_cycles')->where('id', $cycle->id)->first())->toBe($frozen)
        ->and($this->s->savedOrder)->toBe($order)->and(count($this->s->sales))->toBe(1)->and(count($this->s->orderCreates))->toBe(1)
        ->and($this->row->fresh()->terms['shipping_address']['address1'])->toBe('789 Next Example Lane')
        ->and(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(2);
    SubscriptionBillingAuthorization::assertCurrent($this->row->fresh());
});

test('fresh tax drift stops amended collection before a cycle sale or order is started', function () {
    applyReviewedAmendment($this);
    $this->s->taxCents = 200;
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    expect(fn () => app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh()))->toThrow(HttpException::class);
    expect($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('a servicing freeze stops new amendments while accepted terms retain independently enabled billing verification', function () {
    applyReviewedAmendment($this);
    config(['subscriptions.replacement_amendments_enabled' => false, 'subscriptions.replacement_amendment_apply_enabled' => false, 'subscriptions.replacement_servicing_enabled' => false]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh());
    expect($cycle->payment_status)->toBe('succeeded')->and(count($this->s->sales))->toBe(1);
});

test('stale altered unreviewed or ungated acceptances never change terms', function (string $defect) {
    if ($defect === 'quote') {
        $this->s->taxCents = 200;
    } elseif ($defect === 'revision') {
        $this->quote['subject']['revision']++;
    } elseif ($defect === 'price_review') {
        $this->approval['quoted_total_reviewed'] = false;
    } elseif ($defect === 'authority_review') {
        $this->approval['continuing_authorization_reviewed'] = false;
    } elseif ($defect === 'policy') {
        $this->change['policy_reference'] = 'another-shipping-policy';
    } elseif ($defect === 'uuid') {
        $this->uuid = 'invalid';
    } elseif ($defect === 'owner') {
        $this->s->actor->tenants()->updateExistingPivot($this->row->tenant_id, ['role' => 'manager']);
    } elseif ($defect === 'gate') {
        config()->set('subscriptions.replacement_amendment_apply_enabled', false);
    } elseif ($defect === 'due') {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    } else {
        app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', (string) Str::uuid(), $this->row->revision);
    }
    try {
        applyReviewedAmendment($this);
        $this->fail('An unreviewed amendment was accepted.');
    } catch (HttpException|\Illuminate\Validation\ValidationException) {
        expect($this->row->fresh()->terms_hash)->toBe($this->row->terms_hash)->and(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0);
    }
})->with(['quote', 'revision', 'price_review', 'authority_review', 'policy', 'uuid', 'owner', 'gate', 'due', 'schedule']);

test('an unresolved original payment or unconfirmed order blocks future term acceptance', function (string $status) {
    SubscriptionBillingCycle::create(['tenant_id' => $this->row->tenant_id, 'agreement_id' => $this->row->id, 'processor' => 'braintree',
        'merchant_fingerprint' => str_repeat('a', 64), 'cycle_date' => '2027-01-01', 'agreement_revision' => 1, 'amount_cents' => 2500, 'currency' => 'USD',
        'terms' => $this->row->terms, 'method_binding' => $this->row->method_binding, 'source_evidence' => [], 'provider_request' => [],
        'request_uuid' => (string) Str::uuid(), 'payment_status' => $status, 'handoff_status' => 'prepared']);
    expect(fn () => applyReviewedAmendment($this))->toThrow(HttpException::class);
    expect($this->row->fresh()->terms_hash)->toBe($this->row->terms_hash);
})->with(['prepared', 'unknown', 'processing', 'requires_action', 'review', 'succeeded', 'refunded', 'disputed']);

test('retained amendment evidence rejects a rolled back current snapshot or corrupted certificate', function (string $defect) {
    $action = applyReviewedAmendment($this);
    if ($defect === 'rollback') {
        $this->row->fresh()->update(['terms' => $this->row->terms, 'terms_hash' => $this->row->terms_hash]);
    } elseif ($defect === 'delete') {
        DB::table('subscription_billing_actions')->where('id', $action->id)->delete();
    } else {
        $after = $action->after_state;
        $after['terms']['lines'][0]['unit_price_cents'] = 1;
        $action->after_state = $after;
        DB::table('subscription_billing_actions')->where('id', $action->id)->update(['after_state' => $action->getAttributes()['after_state']]);
    }
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($this->row->fresh()))->toThrow(HttpException::class);
})->with(['rollback', 'delete', 'certificate']);

test('zero tax rates survive encrypted evidence without changing its keyed authorization', function () {
    $this->s->taxCents = 0;
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    applyReviewedAmendment($this);
    expect($this->row->fresh()->terms['tax_lines'][0]['rate'])->toBe(0);
    SubscriptionBillingAuthorization::assertCurrent($this->row->fresh());
});

test('the amendment owner command is offline by default and requires an exact explicit acceptance', function () {
    $path = tempnam(sys_get_temp_dir(), 'fictional-amendment-accept-');
    file_put_contents($path, json_encode(['change' => $this->change, 'approval' => $this->approval]));
    chmod($path, 0600);
    try {
        $options = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->row->id, '--file' => $path];
        $requests = Http::recorded()->count();
        $this->artisan('subscriptions:amend', $options)->expectsOutputToContain('offline_proposal')->assertSuccessful();
        expect(Http::recorded()->count())->toBe($requests);
        $this->artisan('subscriptions:amend', $options + ['--reviewed-hash' => $this->quote['quote_hash'], '--request-id' => $this->uuid,
            '--revision' => $this->quote['subject']['revision'], '--apply' => true])->expectsOutputToContain('future_terms_saved')->assertSuccessful();
    } finally {
        unlink($path);
    }
});

test('an existing acceptance UUID cannot be reused for changed content or approval evidence', function (string $field) {
    applyReviewedAmendment($this);
    $requests = Http::recorded()->count();
    if ($field === 'approval') {
        $this->approval['reference'] = 'different-customer-request';
    } else {
        $this->change['shipping_address']['address1'] = 'A different requested address';
    }
    expect(fn () => applyReviewedAmendment($this))->toThrow(HttpException::class);
    expect(Http::recorded()->count())->toBe($requests)->and(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(1);
})->with(['approval', 'address']);

test('an unrelated schedule UUID cannot become amendment authority', function () {
    app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', $this->uuid, $this->row->revision);
    $requests = Http::recorded()->count();
    expect(fn () => applyReviewedAmendment($this))->toThrow(HttpException::class);
    expect(Http::recorded()->count())->toBe($requests)->and($this->row->fresh()->terms_hash)->toBe($this->row->terms_hash);
});

test('missing intermediate review evidence breaks the later accepted terms chain', function () {
    $first = applyReviewedAmendment($this);
    $this->row = $this->row->fresh();
    $this->change['shipping_address']['address1'] = '789 Later Example Road';
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    $this->uuid = (string) Str::uuid();
    applyReviewedAmendment($this);
    DB::table('subscription_billing_actions')->where('id', $first->id)->delete();
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($this->row->fresh()))->toThrow(HttpException::class);
});

test('an indefinitely paused member can change future delivery after an old billing date without resuming or catching up', function () {
    app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', (string) Str::uuid(), $this->row->revision);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-02-20T15:00:00Z'));
    $this->row = $this->row->fresh();
    $this->quote = app(SubscriptionAmendmentQuote::class)->calculate($this->row, $this->change, $this->s->actor);
    $action = applyReviewedAmendment($this);
    expect($action->after_state['effective_from'])->toBeNull()->and($action->after_state['effective_after_resume'])->toBeTrue()
        ->and($this->row->fresh()->status)->toBe('paused')->and($this->s->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
    $current = $this->row->fresh();
    app(SubscriptionBillingAgreementService::class)->change($current->tenant_id, $this->s->actor, $current->id, 'resume', (string) Str::uuid(), $current->revision);
    expect($this->row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-02-28T15:00:00Z'));
    $cycle = app(SubscriptionBraintreeExecutionService::class)->collect($this->row->fresh());
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->cycle_date->toDateString())->toBe('2027-02-28')
        ->and(count($this->s->sales))->toBe(1)->and($this->s->sales[0]['amount'])->toBe('26.75');
});
