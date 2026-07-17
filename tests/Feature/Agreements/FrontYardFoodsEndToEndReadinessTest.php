<?php

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
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
