<?php

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    Http::fake(['*' => Http::response('demo-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
    config()->set('evergrove.canonical_host', 'evergrove.test');
    config()->set('evergrove.hosts', ['evergrove.test']);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', true);
    config()->set('commercial.billing_readiness.agreement_checkout.tenant_slugs', ['front-yard-foods']);
});

function frontYardReadinessAcceptancePayload(): array
{
    return [
        'signer_legal_name' => 'Laura K. Lee',
        'signer_title' => 'Owner',
        'signer_email' => 'laura@example.test',
        'electronic_signature_value' => 'Laura K. Lee',
        'authorized_to_bind' => '1',
        'accepted_scope' => '1',
        'accepted_pricing' => '1',
        'accepted_subscription' => '1',
        'accepted_hourly_rate' => '1',
        'accepted_termination' => '1',
        'electronic_consent' => '1',
    ];
}

function signedFrontYardAgreementForReadiness($test): array
{
    $test->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $agreement = Agreement::query()
        ->forTenant($tenant)
        ->where('template_key', 'front_yard_foods_launch_partner')
        ->firstOrFail();

    $access = app(AgreementManagementService::class)->send($agreement, null, 'ProposalPass123');
    $token = basename(parse_url($access['url'], PHP_URL_PATH));
    $url = 'http://evergrove.test/proposals/'.$token;

    $test->post($url.'/unlock', ['password' => 'ProposalPass123'])->assertRedirect();
    $test->post($url.'/accept', frontYardReadinessAcceptancePayload())->assertRedirect();

    return [
        'tenant' => $tenant->fresh(),
        'agreement' => $agreement->fresh(['acceptance']),
        'order' => TenantBillingOrder::query()->where('agreement_id', $agreement->id)->firstOrFail(),
    ];
}

function paidSandboxAgreementForReadiness($test, Tenant $tenant, bool $canceled = false): array
{
    $management = app(AgreementManagementService::class);
    $sandbox = $management->createFrontYardFoodsSandboxValidation($tenant, null);
    $access = $management->send($sandbox, null, 'SandboxPass123');
    $token = basename(parse_url($access['url'], PHP_URL_PATH));
    $url = 'http://evergrove.test/proposals/'.$token;
    $payload = frontYardReadinessAcceptancePayload();
    $payload['signer_legal_name'] = 'John Collins';
    $payload['signer_email'] = 'johncollinsemail@gmail.com';
    $payload['electronic_signature_value'] = 'John Collins';
    $test->post($url.'/unlock', ['password' => 'SandboxPass123'])->assertRedirect();
    $test->post($url.'/accept', $payload)->assertRedirect();
    $order = TenantBillingOrder::query()->where('agreement_id', $sandbox->id)->firstOrFail();
    $order->forceFill([
        'status' => 'paid',
        'paid_at' => now(),
        'provider_checkout_session_id' => 'cs_test_sandbox',
        'provider_customer_id' => 'cus_test_sandbox',
        'provider_invoice_id' => 'in_test_sandbox',
        'provider_subscription_id' => 'sub_test_sandbox',
        'provider_schedule_id' => 'sub_sched_test_sandbox',
        'provider_tax_cents' => 0,
        'provider_total_cents' => 35800,
        'metadata' => [...(array) $order->metadata, 'schedule_status' => 'configured'],
    ])->save();
    $authorization = SubscriptionAuthorization::query()->findOrFail((int) $order->subscription_authorization_id);
    $authorization->forceFill([
        'status' => $canceled ? 'canceled' : 'provider_verified',
        'provider_subscription_id' => 'sub_test_sandbox',
        'last_reconciled_at' => now(),
        'metadata' => [
            ...(array) $authorization->metadata,
            'last_provider_event_id' => $canceled ? 'evt_sandbox_canceled' : 'evt_sandbox_paid',
            'last_provider_event_type' => $canceled ? 'customer.subscription.deleted' : 'invoice.paid',
        ],
    ])->save();
    TenantBillingReceipt::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_billing_order_id' => $order->id,
        'subscription_authorization_id' => $authorization->id,
        'provider' => 'stripe',
        'provider_receipt_id' => 'in_test_sandbox',
        'provider_subscription_id' => 'sub_test_sandbox',
        'invoice_number' => 'FYF-SANDBOX-0001',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 35800,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 35800,
        'provider_calculated_tax' => true,
        'billed_at' => now(),
        'paid_at' => now(),
        'source_event_id' => 'evt_sandbox_paid',
    ]);
    TenantBillingFulfillment::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'provider_customer_reference' => 'cus_test_sandbox',
        'provider_subscription_reference' => 'sub_test_sandbox',
        'provider_checkout_session_id' => 'cs_test_sandbox',
        'state_hash' => 'fyf-sandbox-readiness-hash',
        'desired_plan_key' => 'validation_only',
        'desired_addon_keys' => [],
        'desired_operating_mode' => 'validation_only',
        'status' => 'noop',
        'message' => 'Validation only; no access changes.',
        'source_event_id' => 'evt_sandbox_paid',
        'source_event_type' => 'invoice.paid',
        'triggered_by' => 'test',
        'attempted_at' => now(),
    ]);

    return ['agreement' => $sandbox->fresh(['acceptance']), 'order' => $order->fresh(), 'authorization' => $authorization->fresh()];
}

function enableFrontYardLiveReadinessGates(): void
{
    config()->set('services.stripe.account_id', 'acct_frontyardlive');
    config()->set('services.stripe.publishable_key', 'pk_live_frontyard');
    config()->set('services.stripe.secret', 'sk_live_frontyard');
    config()->set('services.stripe.webhook_secret', 'whsec_frontyard');
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', true);
    config()->set('commercial.billing_readiness.agreement_checkout.tenant_slugs', ['front-yard-foods']);
    config()->set('commercial.billing_readiness.agreement_checkout.relay_payout_verified', true);
    config()->set('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', true);
    config()->set('mail.default', 'smtp');
}

test('front yard readiness validates stage arguments', function (): void {
    $this->artisan('everbranch:front-yard-foods-readiness --stage=unknown')->assertExitCode(Command::INVALID);
    $this->artisan('everbranch:front-yard-foods-readiness --stage=sandbox-paid')->assertExitCode(Command::INVALID);
    $this->artisan('everbranch:front-yard-foods-readiness --stage=pre-send --require-paid')->assertExitCode(Command::INVALID);
});

test('front yard prepare requires an explicit sent sandbox and never applies implementation pricing to it', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods --sandbox-agreement')->assertFailed();
    $this->artisan('everbranch:prepare-front-yard-foods --sandbox-agreement --send-agreement --implementation-fee=10')->assertFailed();
    $this->artisan('everbranch:prepare-front-yard-foods --sandbox-agreement --send-agreement --agreement-password=SandboxPass123')
        ->expectsOutputToContain('agreement_mode=sandbox_validation')
        ->assertSuccessful();

    $sandbox = Agreement::query()->where('agreement_type', Agreement::TYPE_SANDBOX_VALIDATION)->firstOrFail();
    expect($sandbox->status)->toBe('sent')
        ->and($sandbox->title)->toStartWith('TEST MODE ONLY')
        ->and(Agreement::query()->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)->count())->toBe(0);
});

test('sandbox paid readiness validates an exact disposable agreement without replacing the real one', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $clientAgreementId = Agreement::query()->where('tenant_id', $tenant->id)->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)->value('id');
    $state = paidSandboxAgreementForReadiness($this, $tenant);

    $this->artisan('everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id='.$state['agreement']->id)
        ->assertSuccessful();
    expect(Agreement::query()->where('tenant_id', $tenant->id)->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)->value('id'))->toBe($clientAgreementId)
        ->and($tenant->users()->whereRaw('LOWER(email) = ?', ['laura@frontyardfoods.com'])->exists())->toBeFalse();
});

test('pre send readiness requires canceled sandbox evidence and leaves the real proposal unsigned', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $client = Agreement::query()->where('tenant_id', $tenant->id)->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)->firstOrFail();
    app(AgreementManagementService::class)->send($client, null, 'LauraProposalPass123');
    paidSandboxAgreementForReadiness($this, $tenant, canceled: true);
    enableFrontYardLiveReadinessGates();

    $this->artisan('everbranch:front-yard-foods-readiness --stage=pre-send')->assertSuccessful();
    expect($client->fresh()->acceptance)->toBeNull()
        ->and(TenantBillingOrder::query()->where('agreement_id', $client->id)->exists())->toBeFalse();
});

test('front yard end to end readiness fails until paid fulfillment and client workspace access exist', function (): void {
    $state = signedFrontYardAgreementForReadiness($this);
    /** @var Tenant $tenant */
    $tenant = $state['tenant'];
    /** @var TenantBillingOrder $order */
    $order = $state['order'];

    $this->artisan('everbranch:front-yard-foods-readiness --require-paid')
        ->assertFailed();

    $order->forceFill([
        'status' => 'paid',
        'paid_at' => now(),
        'provider_checkout_session_id' => 'cs_test_fyf',
        'provider_customer_id' => 'cus_test_fyf',
        'provider_invoice_id' => 'in_test_fyf',
        'provider_subscription_id' => 'sub_test_fyf',
        'provider_total_cents' => 35800,
        'metadata' => [...(array) $order->metadata, 'schedule_status' => 'configured'],
    ])->save();

    SubscriptionAuthorization::query()
        ->findOrFail((int) $order->subscription_authorization_id)
        ->forceFill([
            'status' => 'provider_verified',
            'provider_subscription_id' => 'sub_test_fyf',
            'last_reconciled_at' => now(),
        ])
        ->save();

    TenantBillingReceipt::query()->create([
        'tenant_id' => (int) $tenant->id,
        'tenant_billing_order_id' => (int) $order->id,
        'subscription_authorization_id' => (int) $order->subscription_authorization_id,
        'provider' => 'stripe',
        'provider_receipt_id' => 'in_test_fyf',
        'provider_subscription_id' => 'sub_test_fyf',
        'invoice_number' => 'FYF-TEST-0001',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 35800,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 35800,
        'provider_calculated_tax' => true,
        'billed_at' => now(),
        'paid_at' => now(),
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_test_fyf',
        'source_event_id' => 'evt_invoice_paid_fyf',
    ]);

    TenantBillingFulfillment::query()->create([
        'tenant_id' => (int) $tenant->id,
        'provider' => 'stripe',
        'provider_customer_reference' => 'cus_test_fyf',
        'provider_subscription_reference' => 'sub_test_fyf',
        'provider_checkout_session_id' => 'cs_test_fyf',
        'state_hash' => 'fyf-readiness-hash',
        'desired_plan_key' => 'base',
        'desired_addon_keys' => [],
        'desired_operating_mode' => 'direct',
        'status' => 'noop',
        'message' => 'Stripe billing confirmed; existing access is ready.',
        'source_event_id' => 'evt_invoice_paid_fyf',
        'source_event_type' => 'invoice.paid',
        'triggered_by' => 'test',
        'attempted_at' => now(),
    ]);

    $this->artisan('everbranch:front-yard-foods-readiness --require-paid')
        ->assertFailed();

    $laura = User::factory()->create([
        'name' => 'Laura K. Lee',
        'email' => 'laura@example.test',
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $tenant->users()->syncWithoutDetaching([(int) $laura->id => ['role' => 'owner']]);

    $this->artisan('everbranch:front-yard-foods-readiness --require-paid')
        ->assertSuccessful();
});

test('front yard readiness rejects client workspace access before verified payment', function (): void {
    $state = signedFrontYardAgreementForReadiness($this);
    /** @var Tenant $tenant */
    $tenant = $state['tenant'];

    $laura = User::factory()->create([
        'name' => 'Laura K. Lee',
        'email' => 'laura@example.test',
        'role' => 'admin',
        'is_active' => true,
    ]);
    $tenant->users()->syncWithoutDetaching([(int) $laura->id => ['role' => 'owner']]);

    $this->artisan('everbranch:front-yard-foods-readiness')
        ->assertFailed();
});
