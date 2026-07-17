<?php

use App\Models\LandlordOperatorAction;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingReceipt;
use App\Models\TenantCommercialOverride;
use App\Models\TenantDirectInvoice;
use App\Models\User;
use App\Services\Billing\DirectInvoiceManagementService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('services.stripe.account_id', 'acct_directinvoice');
    config()->set('services.stripe.publishable_key', 'pk_test_directinvoice');
    config()->set('services.stripe.secret', 'sk_test_directinvoice');
    config()->set('services.stripe.webhook_secret', 'whsec_directinvoice');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', true);
    config()->set('commercial.billing_readiness.direct_invoicing.tenant_slugs', ['acme']);
    config()->set('commercial.billing_readiness.direct_invoicing.automatic_tax_enabled', false);
    config()->set('commercial.billing_readiness.direct_invoicing.tax_decision_confirmed', false);
});

function directInvoicePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'customer_name' => 'Acme Candle Co',
        'customer_email' => 'billing@acme.example',
        'billing_address' => ['line1' => '10 Main Street', 'line2' => '', 'city' => 'Greenville', 'state' => 'SC', 'postal_code' => '29601', 'country' => 'US'],
        'days_until_due' => 30,
        'authorization_reference' => 'Accepted implementation agreement v1',
        'memo' => 'Approved launch implementation work.',
        'footer' => 'Thank you.',
        'lines' => [['category' => 'evergrove_implementation', 'description' => 'Shopify catalog implementation', 'quantity' => 2, 'unit_amount' => '125.00']],
    ], $overrides);
}

function createDirectInvoice(Tenant $tenant, ?User $actor = null): TenantDirectInvoice
{
    return app(DirectInvoiceManagementService::class)->createDraft($tenant, directInvoicePayload(), $actor?->id);
}

function directInvoiceStripeSignature(array $event): array
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = time();

    return [$payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_directinvoice')];
}

test('landlord creates a tenant-scoped draft while Shopify and third-party lines fail closed', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);

    $this->actingAs($manager)->post(route('landlord.invoices.store', $tenant), directInvoicePayload())->assertForbidden();
    $this->actingAs($admin)->post(route('landlord.invoices.store', $tenant), directInvoicePayload(['tenant_id' => $other->id]))->assertRedirect();

    $invoice = TenantDirectInvoice::query()->sole();
    expect($invoice->tenant_id)->toBe($tenant->id)
        ->and($invoice->authorized_subtotal_cents)->toBe(25000)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->provider_invoice_id)->toBeNull()
        ->and($invoice->line_items[0]['tax_treatment'])->toBe('stripe_determined');

    $this->actingAs($admin)->post(route('landlord.invoices.store', $tenant), directInvoicePayload(['lines' => [['category' => 'shopify', 'description' => 'Shopify Basic', 'quantity' => 1, 'unit_amount' => '39.00']]]))->assertSessionHasErrors('lines.0.category');
    expect(TenantDirectInvoice::query()->count())->toBe(1);

    $this->actingAs($admin)->get(route('landlord.invoices.show', [$other, $invoice]))->assertNotFound();
    expect(LandlordOperatorAction::query()->where('action_type', 'tenant_billing.direct_invoice.create')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

test('sending uses only the stored snapshot and creates a Stripe hosted invoice with card and ACH', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $invoice = createDirectInvoice($tenant, $admin);

    Http::fake(function (Request $request) use ($invoice) {
        $data = $request->data();
        if (str_ends_with($request->url(), '/v1/customers')) {
            expect($data['email'])->toBe('billing@acme.example')
                ->and($data['metadata[tenant_id]'])->toBe((string) $invoice->tenant_id);

            return Http::response(['id' => 'cus_direct']);
        }
        if (str_ends_with($request->url(), '/v1/invoices')) {
            expect($data['collection_method'])->toBe('send_invoice')
                ->and($data['days_until_due'])->toBe(30)
                ->and($data['payment_settings[payment_method_types][0]'])->toBe('card')
                ->and($data['payment_settings[payment_method_types][1]'])->toBe('us_bank_account')
                ->and($data['metadata[direct_invoice_id]'])->toBe((string) $invoice->id)
                ->and($data['automatic_tax[enabled]'])->toBe('false')
                ->and(json_encode($data))->not->toContain('999999');

            return Http::response(['id' => 'in_direct']);
        }
        if (str_ends_with($request->url(), '/v1/invoiceitems')) {
            expect($data['invoice'])->toBe('in_direct')
                ->and($data['amount'])->toBe(25000)
                ->and($data['description'])->toBe('Shopify catalog implementation');

            return Http::response(['id' => 'ii_direct']);
        }
        if (str_ends_with($request->url(), '/finalize')) {
            return Http::response(['id' => 'in_direct', 'status' => 'open', 'number' => 'EB-0001', 'total' => 25000, 'amount_due' => 25000]);
        }
        if (str_ends_with($request->url(), '/send')) {
            return Http::response(['id' => 'in_direct', 'status' => 'open', 'number' => 'EB-0001', 'total' => 25000, 'amount_due' => 25000, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_direct', 'invoice_pdf' => 'https://invoice.stripe.test/in_direct.pdf']);
        }

        return Http::response(['error' => ['message' => 'Unexpected request']], 500);
    });

    $this->actingAs($admin)->post(route('landlord.invoices.send', [$tenant, $invoice]), ['tenant_id' => 999, 'lines' => [['unit_amount' => '9999.99']]])->assertRedirect()->assertSessionHas('status');
    $invoice->refresh();
    expect($invoice->status)->toBe('open')
        ->and($invoice->provider_customer_id)->toBe('cus_direct')
        ->and($invoice->provider_invoice_id)->toBe('in_direct')
        ->and($invoice->hosted_invoice_url)->toBe('https://invoice.stripe.test/in_direct')
        ->and($invoice->authorized_subtotal_cents)->toBe(25000);
});

test('invoice sending stays disabled without its separate flag and never calls Stripe', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $invoice = createDirectInvoice($tenant, $admin);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', false);
    Http::fake();

    $this->actingAs($admin)->post(route('landlord.invoices.send', [$tenant, $invoice]))->assertRedirect()->assertSessionHas('status_error');
    expect($invoice->fresh()->status)->toBe('draft');
    Http::assertNothingSent();
});

test('verified paid invoice webhook mirrors the receipt without changing plan or commercial billing state', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $invoice = createDirectInvoice($tenant);
    $invoice->forceFill(['status' => 'open', 'provider_customer_id' => 'cus_direct', 'provider_invoice_id' => 'in_direct', 'provider_payment_intent_id' => 'pi_direct', 'sent_at' => now()])->save();

    [$payload, $signature] = directInvoiceStripeSignature(['id' => 'evt_direct_paid', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_direct', 'object' => 'invoice', 'customer' => 'cus_direct', 'payment_intent' => 'pi_direct', 'status' => 'paid', 'number' => 'EB-0001', 'currency' => 'usd',
        'subtotal' => 25000, 'total' => 26750, 'amount_due' => 0, 'total_tax_amounts' => [['amount' => 1750]], 'created' => time(), 'status_transitions' => ['paid_at' => time()],
        'hosted_invoice_url' => 'https://invoice.stripe.test/in_direct', 'invoice_pdf' => 'https://invoice.stripe.test/in_direct.pdf',
        'metadata' => ['purpose' => 'direct_invoice', 'tenant_id' => (string) $tenant->id, 'direct_invoice_id' => (string) $invoice->id],
    ]]]);

    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->provider_tax_cents)->toBe(1750)
        ->and($invoice->fresh()->provider_total_cents)->toBe(26750)
        ->and(TenantBillingReceipt::query()->where('tenant_direct_invoice_id', $invoice->id)->count())->toBe(1)
        ->and(TenantBillingReceipt::query()->where('tenant_direct_invoice_id', $invoice->id)->value('tax_amount_cents'))->toBe(1750)
        ->and(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->value('plan_key'))->toBe('base')
        ->and(TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and(StripeWebhookEvent::query()->where('event_id', 'evt_direct_paid')->count())->toBe(1);
});

test('ACH-style invoice stays open until Stripe confirms paid and open invoices can be voided', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $invoice = createDirectInvoice($tenant);
    $invoice->forceFill(['status' => 'open', 'provider_customer_id' => 'cus_direct', 'provider_invoice_id' => 'in_direct', 'sent_at' => now()])->save();

    [$sentPayload, $sentSignature] = directInvoiceStripeSignature(['id' => 'evt_direct_sent', 'type' => 'invoice.sent', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'in_direct', 'object' => 'invoice', 'customer' => 'cus_direct', 'status' => 'open', 'currency' => 'usd', 'total' => 25000, 'amount_due' => 25000,
        'metadata' => ['purpose' => 'direct_invoice', 'tenant_id' => (string) $tenant->id, 'direct_invoice_id' => (string) $invoice->id],
    ]]]);
    $this->call('POST', '/webhooks/stripe/events', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sentSignature], $sentPayload)->assertOk();
    expect($invoice->fresh()->status)->toBe('open')->and($invoice->fresh()->paid_at)->toBeNull();

    Http::fake(fn (Request $request) => str_ends_with($request->url(), '/void')
        ? Http::response(['id' => 'in_direct', 'status' => 'void', 'total' => 0, 'amount_due' => 0])
        : Http::response([], 404));
    $result = app(\App\Services\Billing\DirectStripeInvoiceService::class)->void($invoice->fresh(), null);
    expect($result['ok'])->toBeTrue()->and($invoice->fresh()->status)->toBe('void')->and($invoice->fresh()->voided_at)->not->toBeNull();
});
