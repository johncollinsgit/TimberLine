<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionMembershipReviewService;
use App\Services\Subscriptions\SubscriptionReplacementCommunityService;
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
    $this->s = new BraintreeScenario(candleClub: false);
    $this->s->store->update(['store_key' => 'retail']);
    $this->s->activate();
    $this->row = $this->s->agreement->fresh();
    $this->service = app(SubscriptionMembershipReviewService::class);
    $this->input = ['membership_program' => 'candle_club', 'membership_reference' => 'original-club:789',
        'source_membership_reviewed' => true, 'customer_gid' => 'gid://shopify/Customer/456', 'source_subscription_ids' => ['789']];
    $this->uuid = (string) Str::uuid();
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

function acceptMembershipSupplement($case): SubscriptionBillingAction
{
    $preview = $case->service->preview($case->row->tenant_id, $case->s->actor, $case->row->id, $case->input);

    return $case->service->record($case->row->tenant_id, $case->s->actor, $case->row->id, $case->input,
        $preview['review_hash'], $case->uuid, $preview['revision']);
}

test('a later owner membership review preserves the complete agreement original authority and billing state', function () {
    $before = (array) DB::table('subscription_billing_agreements')->where('id', $this->row->id)->first();
    $member = (array) DB::table('subscription_pilot_members')->where('id', $this->row->member_id)->first();
    $requests = Http::recorded()->count();
    $count = SubscriptionBillingAction::count();
    expect($this->service->program($this->row))->toBeNull();
    $preview = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
    expect($preview['already_reviewed'])->toBeFalse()->and(SubscriptionBillingAction::count())->toBe($count);
    $action = acceptMembershipSupplement($this);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $this->row->tenant_id]);
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    expect($this->service->program($this->row))->toBe('candle_club')
        ->and(app(SubscriptionReplacementCommunityService::class)->membership($session, true)?->id)->toBe($this->row->id)
        ->and(SubscriptionBillingAction::count())->toBe($count + 1)
        ->and((array) DB::table('subscription_billing_agreements')->where('id', $this->row->id)->first())->toBe($before)
        ->and((array) DB::table('subscription_pilot_members')->where('id', $this->row->member_id)->first())->toBe($member)
        ->and(DB::table('subscription_billing_actions')->where('id', $action->id)->value('request'))->not->toContain('original-club:789', 'gid://shopify/Customer/456')
        ->and(Http::recorded()->count())->toBe($requests)->and($this->s->sales)->toBe([]);
    SubscriptionBillingAuthorization::assertCurrent($this->row->fresh());
});

test('unchanged membership replay keeps the original action after a later customer pause', function () {
    $review = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
    $first = acceptMembershipSupplement($this);
    app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', (string) Str::uuid(), $this->row->revision);
    $retry = $this->service->record($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input, $review['review_hash'], $this->uuid, $review['revision']);
    expect($retry->id)->toBe($first->id)->and($this->row->fresh()->status)->toBe('paused')
        ->and($this->service->program($this->row->fresh()))->toBe('candle_club');
    expect(fn () => $first->update(['after_state' => []]))->toThrow(LogicException::class);
});

test('membership review requires the exact source customer subscription set review and rollout', function (string $defect) {
    $preview = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
    $actor = $this->s->actor;
    $revision = $this->row->revision;
    if ($defect === 'customer') {
        $this->input['customer_gid'] = 'gid://shopify/Customer/999';
    } elseif ($defect === 'source') {
        $this->input['source_subscription_ids'] = ['999'];
    } elseif ($defect === 'unreviewed') {
        $this->input['source_membership_reviewed'] = false;
    } elseif ($defect === 'reference') {
        $this->input['membership_reference'] = 'another-reference';
    } elseif ($defect === 'uuid') {
        $this->uuid = 'invalid-request';
    } elseif ($defect === 'revision') {
        $revision++;
    } elseif ($defect === 'rollout') {
        config()->set('subscriptions.replacement_setup_enabled', false);
    } elseif ($defect === 'entitlement') {
        TenantModuleEntitlement::where('tenant_id', $this->row->tenant_id)->where('module_key', 'subscriptions')->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    } elseif ($defect === 'actor') {
        $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $actor->tenants()->attach($this->row->tenant_id, ['role' => 'owner', 'membership_active' => true]);
    } else {
        $this->s->actor->tenants()->updateExistingPivot($this->row->tenant_id, ['role' => 'manager']);
    }
    $before = SubscriptionBillingAction::count();
    try {
        $this->service->record($this->row->tenant_id, $actor, $this->row->id, $this->input, $preview['review_hash'], $this->uuid, $revision);
        $this->fail('An unreviewed membership change was accepted.');
    } catch (HttpException|\Illuminate\Validation\ValidationException) {
        expect(SubscriptionBillingAction::count())->toBe($before)->and($this->service->program($this->row))->toBeNull();
    }
})->with(['customer', 'source', 'unreviewed', 'reference', 'uuid', 'revision', 'rollout', 'entitlement', 'actor', 'manager']);

test('a second supplement or reused action id cannot replace original reviewed membership', function (bool $sameUuid) {
    $first = acceptMembershipSupplement($this);
    $this->input['membership_reference'] = 'replacement-review';
    $preview = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
    expect(fn () => $this->service->record($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input,
        $preview['review_hash'], $sameUuid ? $this->uuid : (string) Str::uuid(), $preview['revision']))->toThrow(HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'membership_review')->count())->toBe(1)
        ->and($first->fresh()->request['membership_reference'])->toBe('original-club:789');
})->with([true, false]);

test('forged membership evidence cannot borrow the original payment authorization', function () {
    $action = acceptMembershipSupplement($this);
    $request = $action->request;
    $request['membership_reference'] = 'forged-review';
    // Bypass model immutability to emulate restored/corrupted evidence; an old HMAC must not authorize the edit.
    $action->request = $request;
    DB::table('subscription_billing_actions')->where('id', $action->id)->update(['request' => $action->getAttributes()['request']]);
    expect(fn () => $this->service->program($this->row))->toThrow(HttpException::class);
    SubscriptionBillingAuthorization::assertCurrent($this->row->fresh());
});

test('another tenant cannot select or supplement the original agreement', function () {
    $other = Tenant::create(['name' => 'Other membership merchant', 'slug' => 'other-membership']);
    expect(fn () => $this->service->preview($other->id, $this->s->actor, $this->row->id, $this->input))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('missing original payment evidence cannot be reconstructed by a membership supplement', function () {
    $row = $this->row;
    $evidence = $row->authorization_evidence;
    unset($evidence['original_authorization']);
    $row->authorization_evidence = $evidence;
    DB::table('subscription_billing_agreements')->where('id', $row->id)->update(['authorization_evidence' => $row->getAttributes()['authorization_evidence']]);
    $count = SubscriptionBillingAction::count();
    expect(fn () => acceptMembershipSupplement($this))->toThrow(HttpException::class);
    expect(SubscriptionBillingAction::count())->toBe($count);
});

test('a schedule action id cannot become membership authority', function () {
    app(SubscriptionBillingAgreementService::class)->change($this->row->tenant_id, $this->s->actor, $this->row->id, 'pause', $this->uuid, $this->row->revision);
    $preview = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
    expect(fn () => $this->service->record($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input,
        $preview['review_hash'], $this->uuid, $preview['revision']))->toThrow(HttpException::class);
    expect($this->service->program($this->row->fresh()))->toBeNull();
});

test('the membership command previews by default and requires an explicit private-file reviewed apply', function () {
    $path = tempnam(sys_get_temp_dir(), 'club-membership-review-');
    file_put_contents($path, json_encode($this->input));
    chmod($path, 0600);
    try {
        $options = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->row->id, '--file' => $path];
        $before = SubscriptionBillingAction::count();
        $this->artisan('subscriptions:membership', $options)->expectsOutputToContain('preview_only')->assertSuccessful();
        expect(SubscriptionBillingAction::count())->toBe($before);
        $preview = $this->service->preview($this->row->tenant_id, $this->s->actor, $this->row->id, $this->input);
        $this->artisan('subscriptions:membership', $options + ['--apply' => true, '--reviewed-hash' => $preview['review_hash'],
            '--request-id' => $this->uuid, '--revision' => $preview['revision']])->expectsOutputToContain('membership_recorded')->assertSuccessful();
        expect($this->service->program($this->row))->toBe('candle_club');
        chmod($path, 0644);
        clearstatcache(true, $path);
        $this->artisan('subscriptions:membership', $options)->assertFailed();
    } finally {
        unlink($path);
    }
});
