<?php

use App\Models\IntegrationConnection;
use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionPilotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0));
    $this->tenant = Tenant::create(['name' => 'Fictional replacement merchant', 'slug' => 'replacement-merchant']);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->store = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail', 'shop_domain' => 'fictional-replacement.myshopify.com', 'access_token' => 'fictional-store-token']);
    $this->member = app(SubscriptionPilotService::class)->prepare($this->tenant->id, $this->owner, [
        'shopify_store_id' => $this->store->id, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'name' => 'Fictional Member',
        'email' => 'member@example.test', 'target_date' => '2027-01-31', 'timezone' => 'America/New_York', 'currency' => 'USD', 'expected_amount_cents' => 2500,
        'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123', 'observed_at' => now()->toISOString(),
        'lines' => [['subscription_id' => '789', 'variant_id' => '999', 'label' => 'Monthly candle', 'quantity' => 1, 'unit_price_cents' => 2500,
            'billing_months' => 1, 'delivery_months' => 1, 'prepaid' => false]]]);
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'braintree', 'status' => 'connected',
        'external_account_id' => 'sandbox|fictional|fictionalUSD', 'access_token' => 'fictional_encrypted_credentials',
        'metadata' => ['purpose' => 'merchant_payment_verification', 'currency' => 'USD', 'environment' => 'sandbox',
            'merchant_id' => 'fictional', 'merchant_account_id' => 'fictionalUSD', 'account_read_verified' => true, 'credential_revision' => (string) Str::uuid()]]);
    config()->set('subscriptions.replacement_setup_enabled', true);
    config()->set('subscriptions.replacement_servicing_enabled', true);
    config()->set('subscriptions.replacement_tenant_ids', [$this->tenant->id]);
    config()->set('subscriptions.replacement_member_ids', [$this->member->id]);
    $this->input = ['processor' => 'braintree', 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_address' => ['firstName' => 'Fictional', 'lastName' => 'Member', 'address1' => '123 Example Street', 'city' => 'Greenville', 'provinceCode' => 'SC', 'countryCode' => 'US', 'zip' => '29601'],
        'method_binding' => ['connection_id' => $this->connection->id, 'customer_id' => 'customer123', 'payment_method_token' => 'private_vault_token', 'source_payment_reference' => 'original_txn'],
        'authorization_reference' => 'original-agreement:123', 'continuing_authorization_reviewed' => true, 'source_terms_reviewed' => true];
    $this->service = app(SubscriptionBillingAgreementService::class);
});

function prepareBillingAgreement($case): SubscriptionBillingAgreement
{
    $review = $case->service->review($case->tenant->id, $case->owner, $case->member->id, $case->input);

    return $case->service->prepare($case->tenant->id, $case->owner, $case->member->id, $case->input, $review['terms_hash']);
}

/** Operator schedule fixtures avoid network; the separate execution suite exercises actual activation checks. */
function activateBillingFixture($case): SubscriptionBillingAgreement
{
    $row = prepareBillingAgreement($case);
    $row->update(['status' => 'active', 'method_verified_at' => now(), 'activated_at' => now(), 'activated_by' => $case->owner->id, 'next_billing_date' => '2027-01-31']);
    $case->member->update(['billing_owner' => 'everbranch', 'status' => 'active']);

    return $row->fresh();
}

function billingCycleFixture($case, SubscriptionBillingAgreement $row, string $status = 'prepared'): SubscriptionBillingCycle
{
    return SubscriptionBillingCycle::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'processor' => $row->processor,
        'merchant_fingerprint' => str_repeat('a', 64), 'cycle_date' => '2027-01-31', 'agreement_revision' => $row->revision,
        'amount_cents' => 2500, 'currency' => 'USD', 'terms' => $row->terms, 'method_binding' => $row->method_binding,
        'source_evidence' => ['checked_at' => now()->toISOString()], 'request_uuid' => (string) Str::uuid(),
        'provider_request' => ['paymentMethodToken' => 'private_vault_token', 'orderId' => 'original_cycle'], 'payment_status' => $status]);
}

test('reuse preparation is encrypted idempotent and does not grant billing or presume reauthorization', function () {
    $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $this->input);
    expect($review['method_verified'])->toBeFalse()->and($review['reauthorization_required'])->toBeNull()
        ->and($review['collection_enabled'])->toBeFalse()->and(json_encode($review))->not->toContain('private_vault_token', 'original_txn');
    $row = prepareBillingAgreement($this);
    expect(prepareBillingAgreement($this)->id)->toBe($row->id)->and($row->status)->toBe('prepared')->and($row->revision)->toBe(1)
        ->and($row->activated_at)->toBeNull()->and($row->next_billing_date)->toBeNull()
        ->and($this->member->fresh()->billing_owner)->toBe('recharge')
        ->and($row->authorization_evidence['provider_verified'])->toBeFalse()
        ->and(DB::table('subscription_billing_agreements')->value('method_binding'))->not->toContain('private_vault_token')
        ->and(json_encode($row))->not->toContain('private_vault_token', '123 Example Street');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('reuse preparation rejects changed quotes and another tenants Braintree connection', function () {
    $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $this->input);
    $changed = $this->input;
    $changed['method_binding']['payment_method_token'] = 'different_method';
    expect(fn () => $this->service->prepare($this->tenant->id, $this->owner, $this->member->id, $changed, $review['terms_hash']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-replacement']);
    $this->connection->update(['tenant_id' => $other->id]);
    expect(fn () => prepareBillingAgreement($this))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(SubscriptionBillingAgreement::count())->toBe(0);
});

test('preparation retains encrypted original reviewed terms without changing the reviewed hash', function () {
    $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $this->input);
    $row = prepareBillingAgreement($this);
    $original = SubscriptionBillingAuthorization::original($row);
    expect($original['terms'])->toBe($row->terms)->and($original['terms_hash'])->toBe($review['terms_hash'])
        ->and($original['authorization']['reference'])->toBe('original-agreement:123')
        ->and($original['authorization']['source_terms_reviewed_by'])->toBe($this->owner->id)
        ->and($original['authorization']['provider_verified'])->toBeFalse()
        ->and(DB::table('subscription_billing_agreements')->value('authorization_evidence'))->not->toContain('123 Example Street', 'original-agreement:123')
        ->and(json_encode($row))->not->toContain('123 Example Street', 'original-agreement:123');
    Http::assertNothingSent();
});

test('original authorization terms hash and review cannot be overwritten removed or retrofitted', function (string $field) {
    $row = prepareBillingAgreement($this);
    $evidence = $row->authorization_evidence;
    if ($field === 'remove') {
        unset($evidence['original_authorization']);
    } else {
        data_set($evidence, $field, 'replacement');
    }
    expect(fn () => $row->update(['authorization_evidence' => $evidence]))->toThrow(LogicException::class);
    expect(SubscriptionBillingAuthorization::original($row->fresh())['terms_hash'])->toBe($row->terms_hash);
})->with(['original_authorization.terms.amount_cents', 'original_authorization.terms.shipping_address.address1',
    'original_authorization.terms_hash', 'original_authorization.authorization.reference', 'reference',
    'continuing_authorization_reviewed_by', 'source_terms_reviewed_by', 'remove']);

test('missing original authorization is never backfilled from an existing agreements current terms', function () {
    $row = prepareBillingAgreement($this);
    $evidence = $row->authorization_evidence;
    unset($evidence['original_authorization']);
    DB::table('subscription_billing_agreements')->where('id', $row->id)->update([
        'authorization_evidence' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($evidence)),
    ]);
    $legacy = $row->fresh();
    expect(fn () => SubscriptionBillingAuthorization::original($legacy))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => prepareBillingAgreement($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $legacy->update(['authorization_evidence' => $row->authorization_evidence]))->toThrow(LogicException::class);
    expect(data_get($row->fresh()->authorization_evidence, 'original_authorization'))->toBeNull();
    Http::assertNothingSent();
});

test('a corrupted stored original snapshot is rejected even when the encrypted value is readable', function () {
    $row = prepareBillingAgreement($this);
    $evidence = $row->authorization_evidence;
    $evidence['original_authorization']['terms']['amount_cents'] = 3000;
    DB::table('subscription_billing_agreements')->where('id', $row->id)->update([
        'authorization_evidence' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($evidence)),
    ]);
    expect(fn () => SubscriptionBillingAuthorization::original($row->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('a changed current amount address hash or identity cannot acquire billing authority', function (string $field, mixed $value) {
    $row = prepareBillingAgreement($this);
    $terms = $row->terms;
    data_set($terms, $field, $value);
    $row->update(['terms' => $terms, 'terms_hash' => str_repeat('f', 64)]);
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($row->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(data_get($row->fresh()->authorization_evidence, 'original_authorization.terms_hash'))->not->toBe(str_repeat('f', 64));
    Http::assertNothingSent();
})->with([['amount_cents', 3000], ['shipping_address.address1', 'New address'], ['currency', 'CAD'],
    ['billing_months', 2], ['timezone', 'UTC'], ['shopify_customer_id', '999'], ['candle_cash_allowed', true]]);

test('reuse setup requires exact rollout member owner and verified email', function (string $defect) {
    match ($defect) {
        'gate' => config()->set('subscriptions.replacement_setup_enabled', false),
        'member' => config()->set('subscriptions.replacement_member_ids', []),
        'tenant' => config()->set('subscriptions.replacement_tenant_ids', []),
        'role' => $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'manager']),
        'email' => $this->owner->forceFill(['email_verified_at' => null])->save(),
    };
    expect(fn () => prepareBillingAgreement($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAgreement::count())->toBe(0);
})->with(['gate', 'member', 'tenant', 'role', 'email']);

test('an already authorized Stripe pilot cannot acquire a second replacement agreement', function () {
    SubscriptionPilotEnrollment::create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'status' => 'authorized', 'terms' => [], 'terms_hash' => str_repeat('a', 64)]);
    expect(fn () => prepareBillingAgreement($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAgreement::count())->toBe(0);
});

test('a prepared replacement cannot change Recharge customer billing', function () {
    $row = prepareBillingAgreement($this);
    expect(fn () => $this->service->change($row->tenant_id, $this->owner, $row->id, 'cancel', (string) Str::uuid(), 1))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->member->fresh()->billing_owner)->toBe('recharge')->and(SubscriptionBillingAction::count())->toBe(0);
});

test('skips preserve the original month end anchor and unchanged retries do not skip twice', function () {
    $row = activateBillingFixture($this);
    $uuid = (string) Str::uuid();
    $action = $this->service->change($row->tenant_id, $this->owner, $row->id, 'skip', $uuid, 1);
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
    expect($this->service->change($row->tenant_id, $this->owner, $row->id, 'skip', $uuid, 1)->id)->toBe($action->id);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'skip', (string) Str::uuid(), 2);
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-03-31')->and(SubscriptionBillingAction::count())->toBe(2);
    expect(fn () => $this->service->change($row->tenant_id, $this->owner, $row->id, 'pause', $uuid, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('stale schedule reviews and cross tenant requests fail without changing the agreement', function () {
    $row = activateBillingFixture($this);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'pause', (string) Str::uuid(), 1);
    expect(fn () => $this->service->change($row->tenant_id, $this->owner, $row->id, 'resume', (string) Str::uuid(), 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->service->change($row->tenant_id + 1, $this->owner, $row->id, 'cancel', (string) Str::uuid(), 2))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($row->fresh()->status)->toBe('paused');
});

test('resume keeps the original anchor and cannot collect an overdue cycle or same day after cutoff', function () {
    $row = activateBillingFixture($this);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'pause', (string) Str::uuid(), 1);
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0));
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'resume', (string) Str::uuid(), 2);
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
});

test('resuming a pause preserves a cycle already skipped by the member', function () {
    $row = activateBillingFixture($this);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'skip', (string) Str::uuid(), 1);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'pause', (string) Str::uuid(), 2);
    $this->service->change($row->tenant_id, $this->owner, $row->id, 'resume', (string) Str::uuid(), 3);
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
});

test('uncertain attempts block skips but cancellation stops future billing while retaining original attempts', function () {
    $row = activateBillingFixture($this);
    $cycle = billingCycleFixture($this, $row, 'unknown');
    $cycle->update(['payment_started_at' => now(), 'provider_reference' => 'original_transaction']);
    expect(fn () => $this->service->change($row->tenant_id, $this->owner, $row->id, 'skip', (string) Str::uuid(), 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $action = $this->service->change($row->tenant_id, $this->owner, $row->id, 'cancel', (string) Str::uuid(), 1);
    expect($row->fresh()->status)->toBe('cancelled')->and($row->fresh()->next_billing_date)->toBeNull()
        ->and($action->after_state['original_payment_requires_review'])->toBeTrue()
        ->and($cycle->fresh()->provider_reference)->toBe('original_transaction')->and($cycle->fresh()->payment_status)->toBe('unknown');
    Http::assertNothingSent();
});

test('original cycle request and action audit cannot be overwritten or deleted', function () {
    $row = activateBillingFixture($this);
    $cycle = billingCycleFixture($this, $row, 'declined');
    expect(fn () => $cycle->update(['provider_request' => ['different' => 'request']]))->toThrow(LogicException::class);
    $cycle = $cycle->fresh();
    $cycle->update(['provider_reference' => 'original_transaction']);
    expect(fn () => $cycle->update(['provider_reference' => 'replacement_transaction']))->toThrow(LogicException::class);
    $action = $this->service->change($row->tenant_id, $this->owner, $row->id, 'cancel', (string) Str::uuid(), 1);
    expect(fn () => $action->update(['kind' => 'resume']))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(DB::table('subscription_billing_cycles')->value('provider_request'))->not->toContain('private_vault_token');
});

test('replacement CLI previews private input and requires the exact reviewed hash before saving', function () {
    $path = tempnam(sys_get_temp_dir(), 'replacement-private-');
    file_put_contents($path, json_encode($this->input));
    chmod($path, 0600);
    try {
        $options = ['action' => 'prepare', '--tenant' => $this->tenant->slug, '--actor' => $this->owner->id, '--member' => $this->member->id, '--file' => $path];
        $this->artisan('subscriptions:replacement', $options)->assertSuccessful();
        expect(SubscriptionBillingAgreement::count())->toBe(0);
        $this->artisan('subscriptions:replacement', $options + ['--apply' => true, '--reviewed-hash' => str_repeat('0', 64)])->assertFailed();
        $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $this->input);
        $this->artisan('subscriptions:replacement', $options + ['--apply' => true, '--reviewed-hash' => $review['terms_hash']])->assertSuccessful();
        expect(SubscriptionBillingAgreement::count())->toBe(1)->and($this->member->fresh()->billing_owner)->toBe('recharge');
    } finally {
        unlink($path);
    }
});

test('replacement CLI rejects files that expose private method references', function () {
    $path = tempnam(sys_get_temp_dir(), 'replacement-insecure-');
    file_put_contents($path, json_encode($this->input));
    chmod($path, 0644);
    try {
        $this->artisan('subscriptions:replacement', ['action' => 'prepare', '--tenant' => $this->tenant->slug, '--actor' => $this->owner->id,
            '--member' => $this->member->id, '--file' => $path, '--apply' => true])->assertFailed();
        expect(SubscriptionBillingAgreement::count())->toBe(0);
    } finally {
        unlink($path);
    }
});

test('replacement CLI schedule preview rolls back and apply changes exactly once', function () {
    $row = activateBillingFixture($this);
    $options = ['action' => 'skip', '--tenant' => $this->tenant->slug, '--actor' => $this->owner->id,
        '--agreement' => $row->id, '--revision' => 1, '--request-id' => (string) Str::uuid()];
    $this->artisan('subscriptions:replacement', $options)->assertSuccessful();
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-01-31')->and(SubscriptionBillingAction::count())->toBe(0);
    $this->artisan('subscriptions:replacement', $options + ['--apply' => true])->assertSuccessful();
    $this->artisan('subscriptions:replacement', $options + ['--apply' => true])->assertSuccessful();
    expect($row->fresh()->next_billing_date->toDateString())->toBe('2027-02-28')->and(SubscriptionBillingAction::count())->toBe(1);
});

test('pilot readout shows Braintree reuse review without demanding a new Stripe authorization', function () {
    prepareBillingAgreement($this);
    $report = app(SubscriptionPilotService::class)->dashboard($this->tenant->id, $this->owner);
    expect($report['members'][0]['replacement_processor'])->toBe('braintree')
        ->and($report['members'][0]['replacement_status'])->toBe('prepared')
        ->and($report['members'][0]['activation_blockers'])->toContain('Braintree saved payment method and continuing authorization need verification')
        ->not->toContain('Customer Stripe authorization required');
});

test('Candle Club benefits require their own explicit original membership review', function (string $defect) {
    $input = $this->input + ['membership_program' => 'candle_club', 'membership_reference' => 'original-club:789', 'source_membership_reviewed' => true];
    if ($defect === 'missing_review') {
        unset($input['source_membership_reviewed']);
    } elseif ($defect === 'unaccepted') {
        $input['source_membership_reviewed'] = false;
    } elseif ($defect === 'reference') {
        unset($input['membership_reference']);
    } else {
        $input['membership_program'] = 'unreviewed-benefits';
    }
    expect(fn () => $this->service->review($this->tenant->id, $this->owner, $this->member->id, $input))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(SubscriptionBillingAgreement::count())->toBe(0);
    Http::assertNothingSent();
})->with(['missing_review', 'unaccepted', 'reference', 'program']);

test('the original membership review is retained without changing money or granting collection', function () {
    $this->input += ['membership_program' => 'candle_club', 'membership_reference' => 'original-club:789', 'source_membership_reviewed' => true];
    $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $this->input);
    expect($review['membership_program'])->toBe('candle_club')->and($review['membership_reviewed'])->toBeTrue();
    $row = prepareBillingAgreement($this);
    $original = SubscriptionBillingAuthorization::original($row);
    expect($original['terms']['membership_program'])->toBe('candle_club')
        ->and($original['authorization']['membership_review'])->toBe(['reference' => 'original-club:789', 'reviewed_by' => $this->owner->id])
        ->and($row->terms['amount_cents'])->toBe(2500)->and($row->activated_at)->toBeNull()->and($this->member->fresh()->billing_owner)->toBe('recharge');
    $changed = array_replace($this->input, ['membership_reference' => 'different-club:789']);
    $review = $this->service->review($this->tenant->id, $this->owner, $this->member->id, $changed);
    expect(fn () => $this->service->prepare($this->tenant->id, $this->owner, $this->member->id, $changed, $review['terms_hash']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});
