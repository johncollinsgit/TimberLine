<?php

use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Agreements\AgreementTerminationService;
use App\Services\Billing\AgreementBillingActivationGuard;
use App\Services\Billing\TenantBillingReceiptLedger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('evergrove.canonical_host', 'evergrove.test');
    config()->set('evergrove.hosts', ['evergrove.test', 'www.evergrove.test']);
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

function agreementTenant(string $slug = 'front-yard-foods'): Tenant
{
    return Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
}

/** @return array{agreement:Agreement,token:string,password:string,url:string} */
function sentAgreement(Tenant $tenant, string $password = 'ProposalPass123'): array
{
    $management = app(AgreementManagementService::class);
    $agreement = $management->prepareFrontYardFoods($tenant, null, 120000, 60000, 60000);
    $access = $management->send($agreement, null, $password);
    $token = basename(parse_url($access['url'], PHP_URL_PATH));

    return ['agreement' => $agreement->fresh(['currentVersion']), 'token' => $token, 'password' => $password, 'url' => $access['url']];
}

function acceptancePayload(array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

test('front yard agreement pricing stays separate configurable and idempotent', function (): void {
    $tenant = agreementTenant();
    $service = app(AgreementManagementService::class);
    $first = $service->prepareFrontYardFoods($tenant, null, 125000, 62500, 62500);
    $second = $service->prepareFrontYardFoods($tenant, null, 125000, 62500, 62500);

    $cards = collect($first->currentVersion->pricing_payload['cards'])->keyBy('key');
    expect($second->id)->toBe($first->id)
        ->and($first->versions()->count())->toBe(1)
        ->and($cards['shopify_basic_monthly']['amount_cents'])->toBe(3900)
        ->and($cards['everbranch_onboarding']['amount_cents'])->toBe(29900)
        ->and($cards['everbranch_launch_partner']['amount_cents'])->toBe(5900)
        ->and($cards['everbranch_standard']['amount_cents'])->toBe(14900)
        ->and($cards['shopify_implementation']['amount_cents'])->toBe(125000)
        ->and($cards['out_of_scope']['amount_cents'])->toBe(5000)
        ->and($first->currentVersion->subscription_payload['pricing_model'])->toBe('agreement_specific')
        ->and($first->currentVersion->subscription_payload['billing_lane'])->toBe('stripe_direct')
        ->and($first->currentVersion->subscription_payload['provider'])->toBe('stripe')
        ->and($first->currentVersion->rendered_content)->toContain('Shopify and Everbranch are never combined')
        ->and($first->currentVersion->rendered_content)->toContain('Shopify store expenses')
        ->and($first->currentVersion->rendered_content)->toContain('Third-party apps and services')
        ->and($first->currentVersion->rendered_content)->toContain('Everbranch setup and monthly service')
        ->and($first->currentVersion->rendered_content)->toContain('Evergrove implementation services')
        ->and($first->currentVersion->rendered_content)->toContain('Square, catalog, and inventory workflow')
        ->and($first->currentVersion->rendered_content)->toContain('Classes, consultations, and booking setup')
        ->and($first->currentVersion->rendered_content)->toContain('internal business management only')
        ->and($first->currentVersion->rendered_content)->toContain('does not include a separate Front Yard Foods App Store listing');

    $changed = $service->prepareFrontYardFoods($tenant, null, 125000, 62500, 62500, 'Import up to 75 currently active products.');
    expect($changed->versions()->count())->toBe(2)
        ->and($changed->currentVersion->scope_payload['additional_scope'])->toBe('Import up to 75 currently active products.')
        ->and($changed->currentVersion->rendered_content)->toContain('Import up to 75 currently active products.');
});

test('proposal access is evergrove host locked password protected and secret safe', function (): void {
    $sent = sentAgreement(agreementTenant());
    $agreement = $sent['agreement'];

    expect($agreement->public_token_hash)->toBe(hash('sha256', $sent['token']))
        ->and($agreement->public_token_hash)->not->toBe($sent['token'])
        ->and(Hash::check($sent['password'], $agreement->password_hash))->toBeTrue()
        ->and($agreement->password_hash)->not->toContain($sent['password']);

    $this->get('http://not-evergrove.test/proposals/'.$sent['token'])->assertNotFound();
    $this->get('http://evergrove.test/proposals/'.$sent['token'])->assertOk()->assertSeeText('Open secure proposal')->assertDontSeeText('Pricing and authorization');
    $this->post('http://evergrove.test/proposals/'.$sent['token'].'/unlock', ['password' => 'wrong-password'])->assertSessionHasErrors('password');
    $this->post('http://evergrove.test/proposals/'.$sent['token'].'/unlock', ['password' => $sent['password']])->assertRedirect();
    $this->get('http://evergrove.test/proposals/'.$sent['token'])->assertOk()->assertSeeText('Pricing and authorization')->assertSeeText('$50.00');
    $this->assertDatabaseHas('agreement_events', ['agreement_id' => $agreement->id, 'event_type' => 'password_failed']);
});

test('acceptance binds every confirmation and authorizes a billing order without creating a charge', function (): void {
    $sent = sentAgreement(agreementTenant());
    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']])->assertRedirect();
    $this->post($url.'/accept', acceptancePayload())->assertRedirect();

    $agreement = $sent['agreement']->fresh(['currentVersion', 'acceptance']);
    $authorization = SubscriptionAuthorization::query()->where('agreement_id', $agreement->id)->firstOrFail();
    expect($agreement->status)->toBe('active')
        ->and($agreement->acceptance)->not->toBeNull()
        ->and($agreement->acceptance->agreement_version_id)->toBe($agreement->current_version_id)
        ->and($authorization->status)->toBe('authorized_pending_provider')
        ->and($authorization->billing_lane)->toBe('stripe_direct')
        ->and($authorization->provider)->toBe('stripe')
        ->and($authorization->provider_subscription_id)->toBeNull()
        ->and($authorization->authorized_line_items)->toHaveCount(3)
        ->and(TenantBillingOrder::query()->where('agreement_id', $agreement->id)->value('status'))->toBe('authorized')
        ->and(TenantBillingOrder::query()->where('agreement_id', $agreement->id)->value('provider_checkout_session_id'))->toBeNull();
    Storage::disk('local')->assertExists((string) $agreement->acceptance->snapshot_path);
    expect(hash('sha256', Storage::disk('local')->get($agreement->acceptance->snapshot_path)))->toBe($agreement->acceptance->snapshot_hash);

    $this->post($url.'/accept', acceptancePayload())->assertRedirect();
    expect(AgreementAcceptance::query()->where('agreement_id', $agreement->id)->count())->toBe(1);
});

test('acceptance is rejected unless every checkbox and typed signature match', function (): void {
    $sent = sentAgreement(agreementTenant());
    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']]);
    $payload = acceptancePayload(['electronic_signature_value' => 'Someone Else']);
    unset($payload['accepted_hourly_rate']);
    $this->post($url.'/accept', $payload)->assertSessionHasErrors(['accepted_hourly_rate']);
    expect(AgreementAcceptance::query()->count())->toBe(0)->and(SubscriptionAuthorization::query()->count())->toBe(0);
});

test('expired and revoked proposal links fail closed', function (): void {
    $expired = sentAgreement(agreementTenant('expired-client'));
    $expired['agreement']->forceFill(['access_expires_at' => now()->subMinute()])->save();
    $this->get('http://evergrove.test/proposals/'.$expired['token'])->assertStatus(410);

    $revoked = sentAgreement(agreementTenant('revoked-client'));
    app(AgreementManagementService::class)->revoke($revoked['agreement'], null);
    $this->get('http://evergrove.test/proposals/'.$revoked['token'])->assertStatus(410);
});

test('accepted versions and evidence are immutable', function (): void {
    $sent = sentAgreement(agreementTenant());
    $version = $sent['agreement']->currentVersion;
    expect(fn () => $version->forceFill(['title' => 'Changed'])->save())->toThrow(RuntimeException::class);

    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']]);
    $this->post($url.'/accept', acceptancePayload());
    $acceptance = AgreementAcceptance::query()->firstOrFail();
    expect(fn () => $acceptance->forceFill(['signer_title' => 'Changed'])->save())->toThrow(RuntimeException::class);
});

test('tenant agreements are financially permissioned tenant scoped and hide internal notes', function (): void {
    $tenantA = agreementTenant('tenant-a');
    $tenantB = agreementTenant('tenant-b');
    $sent = sentAgreement($tenantA);
    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']]);
    $this->post($url.'/accept', acceptancePayload());
    $sent['agreement']->forceFill(['internal_notes' => 'PRIVATE LANDLORD NOTE'])->save();

    $ownerA = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $ownerA->tenants()->attach($tenantA->id, ['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $ownerB->tenants()->attach($tenantB->id, ['role' => 'owner']);

    $this->actingAs($ownerA)->get('http://tenant-a.theeverbranch.com/agreements?tenant=tenant-a')->assertOk()->assertSeeText('User Agreements')->assertDontSeeText('PRIVATE LANDLORD NOTE');
    $this->actingAs($ownerB)->get('http://tenant-b.theeverbranch.com/agreements/'.$sent['agreement']->id.'?tenant=tenant-b')->assertNotFound();
});

test('provider receipts are idempotent tax aware and cannot cross tenant boundaries', function (): void {
    $tenant = agreementTenant();
    $other = agreementTenant('other-client');
    $ledger = app(TenantBillingReceiptLedger::class);
    $receipt = ['provider_receipt_id' => 'shopify-bill-123', 'invoice_number' => 'B-123', 'status' => 'paid', 'subtotal_amount_cents' => 5900, 'tax_amount_cents' => 354, 'total_amount_cents' => 6254, 'receipt_url' => 'https://admin.shopify.com/store/example/settings/billing', 'billed_at' => now()];
    $ledger->recordVerifiedProviderReceipt($tenant, 'shopify', $receipt);
    $ledger->recordVerifiedProviderReceipt($tenant, 'shopify', $receipt);

    expect(TenantBillingReceipt::query()->count())->toBe(1)
        ->and(TenantBillingReceipt::query()->first()->provider_calculated_tax)->toBeTrue()
        ->and(fn () => $ledger->recordVerifiedProviderReceipt($other, 'shopify', $receipt))->toThrow(InvalidArgumentException::class);
});

test('billing activation remains blocked after signature until provider and fulfillment evidence exist', function (): void {
    $sent = sentAgreement(agreementTenant());
    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']]);
    $this->post($url.'/accept', acceptancePayload());
    $authorization = SubscriptionAuthorization::query()->firstOrFail();
    $result = app(AgreementBillingActivationGuard::class)->evaluate($authorization);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reasons'])->toContain('verified_provider_subscription_required')
        ->and($result['reasons'])->toContain('active_provider_ledger_subscription_required')
        ->and($result['reasons'])->toContain('settled_agreement_billing_order_required')
        ->and($result['reasons'])->toContain('configured_promotional_schedule_required')
        ->and($result['reasons'])->toContain('audited_entitlement_fulfillment_required');
});

test('termination tracks notice export window client ownership and child amendments', function (): void {
    $sent = sentAgreement(agreementTenant());
    $url = 'http://evergrove.test/proposals/'.$sent['token'];
    $this->post($url.'/unlock', ['password' => $sent['password']]);
    $this->post($url.'/accept', acceptancePayload());
    $agreement = $sent['agreement']->fresh(['currentVersion']);
    $effective = now()->addDays(30)->startOfDay();
    $termination = app(AgreementTerminationService::class)->request($agreement, null, 'Client request', $effective);
    app(AgreementTerminationService::class)->markExport($agreement->fresh(), null, 'requested', 'export-job-17');

    expect($agreement->fresh()->status)->toBe('termination_pending')
        ->and($termination->effective_at->toDateString())->toBe($effective->toDateString())
        ->and($termination->export_window_ends_at->toDateString())->toBe($effective->copy()->addDays(30)->toDateString())
        ->and($termination->fresh()->export_status)->toBe('requested')
        ->and($agreement->currentVersion->termination_payload['terms'])->toContain('Front Yard Foods keeps its Shopify store, Square account, domain, branding, content, and client-owned data.')
        ->and($agreement->currentVersion->termination_payload['terms'])->toContain('The shared Everbranch application remains in the App Store; termination does not remove it.');

    $amendment = app(AgreementManagementService::class)->createAmendment($agreement->fresh(), null, 50000, 'Add a final data mapping workshop.');
    expect($amendment->parent_agreement_id)->toBe($agreement->id)
        ->and($amendment->status)->toBe('draft')
        ->and($amendment->currentVersion->version_number)->toBe(1)
        ->and($amendment->currentVersion->rendered_content)->toContain('Add a final data mapping workshop.');
});

test('landlord agreement management is host locked and operator only', function (): void {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $this->actingAs($admin)->get('http://app.theeverbranch.com/landlord/agreements')->assertOk()->assertSeeText('Agreements');
    $this->actingAs($admin)->get('http://wrong.theeverbranch.com/landlord/agreements')->assertNotFound();
    $this->actingAs($manager)->get('http://app.theeverbranch.com/landlord/agreements')->assertForbidden();
});
