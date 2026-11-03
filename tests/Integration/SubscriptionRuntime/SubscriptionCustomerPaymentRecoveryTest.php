<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Models\TenantModuleEntitlement;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionCustomerPaymentRecoveryService;
use App\Services\Subscriptions\SubscriptionReplacementCustomerAccess;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => 'https://app.theeverbranch.com', 'tenancy.landlord.primary_host' => 'app.theeverbranch.com',
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com']]);
    \Illuminate\Support\Facades\URL::forceRootUrl('https://app.theeverbranch.com');
    \Illuminate\Support\Facades\URL::forceScheme('https');
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyExecutionScenario;
    $this->s->activate();
    $this->s->tenant->update(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $this->s->attemptState = 'requires_action';
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $this->cycle = app(SubscriptionShopifyExecutionService::class)->collect($this->s->agreement);
    expect($this->cycle->payment_status)->toBe('requires_action');
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-authenticated-customer',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    config()->set('subscriptions.replacement_customer_payment_recovery_enabled', true);
    $this->recovery = app(SubscriptionCustomerPaymentRecoveryService::class);
    $this->status = fn () => $this->recovery->status($this->customer, $this->cycle->public_id);
    $this->begin = fn ($hash) => $this->recovery->begin($this->customer, $this->cycle->public_id, $hash);
    $this->challenge = 'https://authentication.shopify.com/fictional-payment-challenge?token=FICTIONAL_CHALLENGE_TOKEN';
});

test('customer payment verification retains the original attempt amount method and one signed link issuance audit', function (string $challengeStatus) {
    $this->s->attempts[$this->cycle->provider_reference]['state']['action']['status'] = $challengeStatus;
    $before = $this->s->agreement->fresh()->getAttributes();
    $request = $this->cycle->provider_request;
    $status = ($this->status)();
    expect($status['can_authenticate'])->toBeTrue()->and($status['amount_cents'])->toBe(2500)
        ->and($status['sends_new_charge_attempt'])->toBeFalse()->and(json_encode($status))->not->toContain('FICTIONAL_CHALLENGE_TOKEN', 'redirect_url', 'gid://shopify/');
    $result = ($this->begin)($status['review_hash']);
    expect($result['redirect_url'])->toBe($this->challenge)->and(($this->begin)($status['review_hash']))->toBe($result)
        ->and($this->s->bills)->toHaveCount(1)->and($this->cycle->fresh()->provider_request)->toBe($request)
        ->and($this->s->agreement->fresh()->getAttributes())->toBe($before)->and($this->cycle->fresh()->paid_at)->toBeNull();
    $audit = SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_challenge_link_issued')->sole();
    expect($audit->actor_type)->toBe('shopify_customer')->and($audit->actor_reference)->toBe('gid://shopify/Customer/456')
        ->and($audit->request['original_request_uuid'])->toBe($this->cycle->request_uuid)
        ->and($audit->request['original_attempt_id'])->toBe($this->cycle->provider_reference)
        ->and(json_encode($audit->request))->not->toContain('FICTIONAL_CHALLENGE_TOKEN');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'authentication.shopify.com'));
})->with(['OFF_SESSION_REJECTED', 'ON_SESSION_CHALLENGED']);

test('malformed or unsupported provider challenges remain unresolved without another charge or redirect', function (string $defect) {
    $action = &$this->s->attempts[$this->cycle->provider_reference]['state']['action'];
    if ($defect === 'missing_url') {
        unset($action['nextActionUrl']);
    } elseif ($defect === 'unknown_type') {
        $action['__typename'] = 'UnverifiedCustomerAction';
    } elseif ($defect === 'unknown_status') {
        $action['status'] = 'UNVERIFIED';
    } else {
        $action['nextActionUrl'] = $defect;
    }
    $status = ($this->status)();
    expect($status['status'])->toBe('requires_action')->and($status['can_authenticate'])->toBeFalse();
    expect(($this->begin)($status['review_hash']))->not->toHaveKey('redirect_url');
    expect($this->s->bills)->toHaveCount(1)->and($this->cycle->fresh()->paid_at)->toBeNull()
        ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_challenge_link_issued')->exists())->toBeFalse();
})->with(['missing_url', 'unknown_type', 'unknown_status', 'http://authentication.shopify.com/challenge',
    'https://username:password@authentication.shopify.com/challenge', 'https://127.0.0.1/challenge', 'javascript:alert(1)',
    'https://authentication.shopify.com/unsafe\\path', 'https://authentication.shopify.com:444/challenge', 'https://issuer.local/challenge',
    "https://authentication.shopify.com/challenge\nLocation: https://invalid.example"]);

test('payment verification rechecks original customer profile module owner credential and exact rollout', function (string $defect) {
    expect(app(TenantModuleAccessResolver::class)->module($this->s->tenant->id, 'subscriptions')['has_access'])->toBeTrue();
    match ($defect) {
        'customer' => $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-other-customer', ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/999']),
        'demo' => $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-demo', ['source_type' => 'app_review_demo', 'shopify_customer_id' => 'gid://shopify/Customer/456']),
        'profile' => $this->profile->delete(),
        'module' => TenantModuleEntitlement::where('tenant_id', $this->s->tenant->id)->update(['enabled_status' => 'disabled']),
        'owner' => $this->s->actor->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]),
        'credential' => $this->s->revision->update(['revoked_at' => now()]),
        'gate' => config()->set('subscriptions.replacement_customer_payment_recovery_enabled', false),
        'member' => config()->set('subscriptions.replacement_member_ids', []),
    };
    $reads = count(Http::recorded());
    expect($this->status)->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->bills)->toHaveCount(1);
})->with(['customer', 'demo', 'profile', 'module', 'owner', 'credential', 'gate', 'member']);

test('authority withdrawn during the provider read cannot expose a previously valid verification URL', function (string $defect) {
    $status = ($this->status)();
    $this->s->beforeAttemptRead = function () use ($defect) {
        match ($defect) {
            'profile' => $this->profile->delete(),
            'owner' => $this->s->actor->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]),
            'credential' => $this->s->revision->update(['revoked_at' => now()]),
            'module' => TenantModuleEntitlement::where('tenant_id', $this->s->tenant->id)->update(['enabled_status' => 'disabled']),
            'gate' => config()->set('subscriptions.replacement_customer_payment_recovery_enabled', false),
        };
    };
    expect(fn () => ($this->begin)($status['review_hash']))->toThrow(HttpException::class);
    expect($this->s->bills)->toHaveCount(1)->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_challenge_link_issued')->exists())->toBeFalse();
})->with(['profile', 'owner', 'credential', 'module', 'gate']);

test('a changed challenge or an unrelated original attempt cannot reuse the customer review hash', function (string $defect) {
    $status = ($this->status)();
    $attempt = &$this->s->attempts[$this->cycle->provider_reference];
    match ($defect) {
        'challenge' => $attempt['state']['action']['nextActionUrl'] .= '&generation=2',
        'customer' => $attempt['subscriptionContract']['customer']['id'] = 'gid://shopify/Customer/999',
        'request' => $attempt['idempotencyKey'] = (string) Str::uuid(),
        'hash' => $status['review_hash'] = str_repeat('f', 64),
    };
    expect(fn () => ($this->begin)($status['review_hash']))->toThrow(HttpException::class);
    expect($this->s->bills)->toHaveCount(1)->and($this->cycle->fresh()->paid_at)->toBeNull();
})->with(['challenge', 'customer', 'request', 'hash']);

test('verification after a future stop and editing collection freeze retains the original stopped schedule', function (string $stop) {
    app(SubscriptionBillingAgreementService::class)->customerChange($this->customer, $stop, (string) Str::uuid(), $this->s->agreement->fresh()->revision);
    config(['subscriptions.replacement_collection_enabled' => false, 'subscriptions.replacement_shopify_collection_enabled' => false,
        'subscriptions.replacement_servicing_enabled' => false, 'subscriptions.replacement_customer_delivery_enabled' => false]);
    $before = $this->s->agreement->fresh()->getAttributes();
    $status = ($this->status)();
    expect($status['can_authenticate'])->toBeTrue()->and(($this->begin)($status['review_hash'])['redirect_url'])->toBe($this->challenge);
    $this->s->complete($this->cycle->provider_reference);
    $paid = ($this->status)();
    expect($paid['status'])->toBe('succeeded')->and($paid['order_confirmed'])->toBeTrue()->and($paid['can_authenticate'])->toBeFalse()
        ->and($this->s->agreement->fresh()->getAttributes())->toBe($before)->and($this->s->bills)->toHaveCount(1);
})->with(['pause', 'cancel']);

test('a payment that completes before the button is pressed confirms the original receipt without redirect or another attempt', function () {
    $status = ($this->status)();
    $this->s->complete($this->cycle->provider_reference);
    $result = ($this->begin)($status['review_hash']);
    expect($result['status'])->toBe('succeeded')->and($result['order_confirmed'])->toBeTrue()->and($result)->not->toHaveKey('redirect_url')
        ->and($this->s->bills)->toHaveCount(1)->and($this->cycle->fresh()->shopify_order_id)->toBe('9301');
    $before = $this->s->agreement->fresh()->getAttributes();
    ($this->begin)($status['review_hash']);
    expect($this->s->agreement->fresh()->getAttributes())->toBe($before);
});

test('a private member session cannot enumerate an unrelated payment UUID', function () {
    $reads = count(Http::recorded());
    expect(fn () => $this->recovery->status($this->customer, (string) Str::uuid()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->bills)->toHaveCount(1);
});

test('private payment page hides provider secrets and only an explicit POST opens original verification', function () {
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $path = parse_url($url, PHP_URL_PATH);
    $token = basename($path);
    $statusUrl = $url.'/payments/'.$this->cycle->public_id;
    $response = $this->get($statusUrl)->assertOk()->assertSeeText('Verify your USD 25.00 payment')->assertSeeText('Modern Forestry')
        ->assertDontSee('FICTIONAL_CHALLENGE_TOKEN')->assertDontSee('authentication.shopify.com')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->get($url)->assertOk()->assertSeeText('Check payment verification');
    expect(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_challenge_link_issued')->exists())->toBeFalse();
    $status = $response->viewData('payment');
    if ($preview = getenv('SUBSCRIPTION_PAYMENT_RECOVERY_PREVIEW_FILE')) {
        file_put_contents($preview, $response->getContent());
    }
    $this->post($statusUrl.'/verify', ['review_hash' => $status['review_hash'], 'url' => 'https://untrusted.invalid'])
        ->assertStatus(303)->assertHeader('Location', $this->challenge)->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('https://unrelated.example'.$path.'/payments/'.$this->cycle->public_id)->assertNotFound();
    $this->travel(16)->minutes();
    $this->get($statusUrl)->assertNotFound();
    expect($this->s->bills)->toHaveCount(1);
});

test('two customer verification requests retain one original charge and one opening audit', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $status = ($this->status)();
    $marker = tempnam(sys_get_temp_dir(), 'native-payment-verification-');
    $this->s->billingMarkerPath = $marker;
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start original payment-verification worker.');
            }
            if ($pid === 0) {
                pcntl_alarm(15);
                DB::purge('mysql');
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                file_put_contents($marker.'.ready.'.$index, 'ready');
                while (! is_file($marker.'.start')) {
                    usleep(1000);
                }
                try {
                    $result = ($this->begin)($status['review_hash']);
                    $outcome = ($result['redirect_url'] ?? null) === $this->challenge ? 'original_challenge' : 'unexpected_result';
                } catch (Throwable) {
                    $outcome = 'unexpected_error';
                }
                file_put_contents($marker.'.result.'.$index, $outcome);
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while ((! is_file($marker.'.ready.0') || ! is_file($marker.'.ready.1')) && microtime(true) < $deadline) {
            usleep(1000);
        }
        expect(is_file($marker.'.ready.0') && is_file($marker.'.ready.1'))->toBeTrue();
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $result);
            expect(pcntl_wifexited($result) && pcntl_wexitstatus($result) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        expect(file_get_contents($marker.'.result.0'))->toBe('original_challenge')->and(file_get_contents($marker.'.result.1'))->toBe('original_challenge')
            ->and(trim(file_get_contents($marker)))->toBe('')->and($this->s->bills)->toHaveCount(1)
            ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_challenge_link_issued')->count())->toBe(1);
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});
