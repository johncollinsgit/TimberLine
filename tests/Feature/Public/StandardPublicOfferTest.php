<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
});

test('public acquisition surfaces agree on the current offer and conditional usage fees', function (string $path): void {
    $this->get($path)->assertOk()
        ->assertSeeText('$499 one-time setup + $299/month. Additional text, email, and AI usage fees may apply.')
        ->assertDontSeeText('$89/mo')
        ->assertDontSeeText('$149/mo')
        ->assertDontSeeText('$249/mo')
        ->assertDontSeeText('Starter includes everything');
})->with([
    '/platform/promo',
    '/platform/plans',
    '/platform/start',
    '/explore/modules',
    'https://evergrovesoftware.com/',
]);

test('requesting the standard offer records interest without activating a workspace or billing', function (): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production',
        'name' => 'Quality Test Owner',
        'email' => 'standard-offer@example.com',
        'company' => 'Fictional Quality Workshop',
        'preferred_plan_key' => 'standard',
        'branches_interest' => ['customers', 'messaging'],
    ])->assertRedirect(route('platform.request-submitted', ['intent' => 'production'], absolute: false));

    $request = CustomerAccessRequest::query()->where('email', 'standard-offer@example.com')->firstOrFail();
    expect($request->status)->toBe('pending')
        ->and(data_get($request->metadata, 'preferred_plan_key'))->toBe('standard')
        ->and(data_get($request->metadata, 'branches_interest'))->toBe(['customers', 'messaging'])
        ->and($request->tenant_id)->toBeNull()
        ->and(Tenant::query()->count())->toBe(0)
        ->and(TenantAccessProfile::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('public branch choices include only configured safe core branches and keep premium software separate', function (): void {
    $service = app(TenantCommercialExperienceService::class);
    $payload = $service->publicPlansPayload();
    $keys = array_column($payload['branch_cards'], 'key');
    expect($keys)->toContain('messaging', 'sms')
        ->not->toContain('ai_branch', 'future_niche_modules', 'team_work', 'trajectory', 'markets_makers');
    expect($payload['addon_cards'])->toBe([])
        ->and(array_column($payload['premium_products'], 'name'))->toBe(['Teams', 'Trajectory', 'Markets & Makers']);

    config()->set('module_catalog.modules.sms.visibility.public_site', false);
    $hidden = $service->publicPlansPayload();
    expect(array_column($hidden['branch_cards'], 'key'))->not->toContain('sms');
    foreach (['/platform/start', '/platform/plans'] as $path) {
        $this->get($path)->assertOk()->assertDontSeeText('AI Branch Pro')->assertDontSeeText('Future Niche Modules');
    }
});

test('public requests reject premium or hidden branch selections and retired paid add-ons', function (array $selection, string $error): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production', 'name' => 'Fictional Owner', 'email' => 'invalid-branch@example.com',
        ...$selection,
    ])->assertSessionHasErrors($error);
    expect(CustomerAccessRequest::query()->count())->toBe(0);
})->with([
    [['branches_interest' => ['team_work']], 'branches_interest.0'],
    [['branches_interest' => ['trajectory']], 'branches_interest.0'],
    [['branches_interest' => ['future_niche_modules']], 'branches_interest.0'],
    [['addons_interest' => ['sms']], 'addons_interest'],
    [['preferred_plan_key' => 'launch_partner'], 'preferred_plan_key'],
]);

test('approved standard offer interest survives the merchant handoff without enabling hosted checkout', function (bool $checkoutActive, string $stripeSecret, string $expectedMode, string $expectedReason): void {
    config()->set('commercial.billing_readiness.checkout_active', $checkoutActive);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    config()->set('services.stripe.secret', $stripeSecret);
    $tenant = Tenant::query()->create(['name' => 'Fictional Standard Workshop', 'slug' => 'quality-standard']);
    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Quality Test Owner',
        'email' => 'approved-standard@example.com',
        'tenant_id' => $tenant->id,
        'metadata' => ['preferred_plan_key' => 'standard'],
    ]);

    $payload = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect(data_get($payload, 'billing_interest.preferred_plan_key'))->toBe('standard')
        ->and(data_get($payload, 'billing_interest.addons_interest'))->toBe([])
        ->and(data_get($payload, 'billing_next_step.mode'))->toBe($expectedMode)
        ->and(data_get($payload, 'billing_next_step.reason'))->toBe($expectedReason)
        ->and(data_get($payload, 'billing_next_step.cta_route'))->toBeNull()
        ->and(data_get($payload, 'billing_next_step.cta_url'))->toBe(route('platform.contact', ['intent' => 'billing']))
        ->and(data_get($payload, 'billing_next_step.readiness.checkout_active'))->toBe($checkoutActive)
        ->and(data_get($payload, 'billing_next_step.readiness.stripe_secret_configured'))->toBe($stripeSecret !== '')
        ->and(config('module_catalog.plans.standard'))->toBeNull()
        ->and(CustomerAccessRequest::query()->sole()->metadata['preferred_plan_key'])->toBe('standard')
        ->and(TenantAccessProfile::query()->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'checkout enabled and provider configured' => [true, 'sk_test_fictional_standard_offer_fixture', 'unavailable', 'unknown_plan_key'],
    'checkout disabled and provider configured' => [false, 'sk_test_fictional_standard_offer_fixture', 'landlord_follow_up', 'hosted_billing_disabled'],
    'checkout enabled without provider configuration' => [true, '', 'landlord_follow_up', 'stripe_not_configured'],
    'checkout disabled without provider configuration' => [false, '', 'landlord_follow_up', 'stripe_not_configured'],
]);
