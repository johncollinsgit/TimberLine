<?php

use App\Models\User;
use App\Services\Readiness\EverbranchSelfServiceReadinessService;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', false);
});

function selfServiceReadinessAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

test('landlord admin can view self service readiness dashboard', function (): void {
    $this->actingAs(selfServiceReadinessAdmin())
        ->get('http://app.theeverbranch.com/landlord/readiness')
        ->assertOk()
        ->assertSeeText('Self-Service Readiness Dashboard')
        ->assertSeeText('No. Everbranch cannot safely onboard fully self-service tenants today.')
        ->assertSeeText('Tenant Onboarding Readiness')
        ->assertSeeText('Intake Queue Readiness')
        ->assertSeeText('Module App Store Readiness')
        ->assertSeeText('Custom Module Request Readiness')
        ->assertSeeText('Commercial Intent Readiness')
        ->assertSeeText('Billing Readiness')
        ->assertSeeText('Shopify App Readiness')
        ->assertSeeText('Privacy Webhook Readiness')
        ->assertSeeText('Shopify External Evidence Readiness')
        ->assertSeeText('Mobile Readiness')
        ->assertSeeText('Launch Readiness Summary')
        ->assertSeeText('docs/operations/evidence/shopify/2026-05-21/README.md')
        ->assertSeeText('docs/operations/shopify-scope-branding-decision-record.md');
});

test('non landlord cannot view self service readiness dashboard', function (): void {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('http://app.theeverbranch.com/landlord/readiness')
        ->assertForbidden();
});

test('dashboard keeps billing disabled external evidence pending and mobile not started', function (): void {
    $this->actingAs(selfServiceReadinessAdmin())
        ->get('http://app.theeverbranch.com/landlord/readiness')
        ->assertOk()
        ->assertSeeText('disabled')
        ->assertSeeText('Billing and checkout remain intentionally disabled.')
        ->assertSeeText('pending_external')
        ->assertSeeText('Partner Dashboard screenshots are pending.')
        ->assertSeeText('Live privacy webhook delivery evidence remains pending.')
        ->assertSeeText('not_started')
        ->assertSeeText('A generic Everbranch Android/iOS app and API are not built.')
        ->assertSeeText('This dashboard is a status/control surface only.')
        ->assertDontSee('/billing/checkout', false)
        ->assertDontSee('/commercial/billing/stripe', false)
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now')
        ->assertDontSeeText('Create subscription')
        ->assertDontSeeText('Charge tenant')
        ->assertDontSeeText('Install module')
        ->assertDontSeeText('Activate entitlements');
});

test('readiness service returns expected status keys and conservative launch answer', function (): void {
    $readiness = app(EverbranchSelfServiceReadinessService::class)->evaluate();

    $sections = collect((array) $readiness['sections'])->keyBy('key');

    expect(array_keys((array) $readiness['summary']))->toBe([
        'ready',
        'partial',
        'blocked',
        'pending_external',
        'disabled',
        'not_started',
    ])
        ->and((string) data_get($readiness, 'overall.status'))->toBe('blocked')
        ->and((string) data_get($readiness, 'overall.answer'))->toContain('No.')
        ->and($sections->keys()->all())->toContain(
            'tenant_onboarding',
            'intake_queue',
            'module_app_store',
            'custom_module_requests',
            'commercial_intent',
            'billing',
            'shopify_app',
            'privacy_webhooks',
            'shopify_external_evidence',
            'mobile',
            'launch_readiness',
        )
        ->and(data_get($sections->get('billing'), 'status'))->toBe('disabled')
        ->and(data_get($sections->get('shopify_external_evidence'), 'status'))->toBe('pending_external')
        ->and(data_get($sections->get('mobile'), 'status'))->toBe('not_started')
        ->and(data_get($sections->get('launch_readiness'), 'status'))->toBe('blocked');
});

test('readiness route and related landlord links exist', function (): void {
    expect(Route::has('landlord.readiness'))->toBeTrue()
        ->and(Route::has('landlord.onboarding.intake'))->toBeTrue()
        ->and(Route::has('landlord.commercial-intent.index'))->toBeTrue()
        ->and(Route::has('landlord.custom-module-requests.index'))->toBeTrue();
});
