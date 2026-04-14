<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Models\User;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord tenant workspace onboarding tab renders reduced milestones and raw events', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['payload_type' => 'merchant_journey'],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id={$blueprint->id}")
        ->assertOk()
        ->assertSeeText('Onboarding journey')
        ->assertSeeText('Raw blueprint events')
        ->assertSeeText(OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED)
        ->assertDontSee('name="final_blueprint_id"', false);
});

test('landlord tenant workspace onboarding tab returns not found when blueprint pair has no events', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id=999999")
        ->assertNotFound();
});

test('onboarding journey tab renders a blueprint selector only when multiple blueprint ids exist', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Selector Tenant',
        'slug' => 'selector',
    ]);

    $blueprintA = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $blueprintB = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprintA->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['to' => 'handoff'],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprintB->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey")
        ->assertOk()
        ->assertSeeText('Blueprint context')
        ->assertSee('name="final_blueprint_id"', false);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id={$blueprintA->id}")
        ->assertOk()
        ->assertSeeText('Phase: handoff');

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id={$blueprintB->id}")
        ->assertOk()
        ->assertSeeText('Phase: ongoing setup');
});

test('onboarding journey tab renders raw event filters and payload expander content', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Filter Tenant',
        'slug' => 'filter',
    ]);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['payload_type' => 'merchant_journey', 'phase' => 'handoff'],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['from' => 'handoff', 'to' => 'ongoing_setup', 'payload_type' => 'merchant_journey'],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id={$blueprint->id}")
        ->assertOk()
        ->assertSeeText('Milestones')
        ->assertSeeText('Phase')
        ->assertSeeText('Import')
        ->assertSeeText('View payload')
        ->assertSeeText('Linked events');

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=onboarding_journey&final_blueprint_id={$blueprint->id}&event_filter=phase")
        ->assertOk()
        ->assertSeeText(OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED)
        ->assertDontSeeText(OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED);
});
