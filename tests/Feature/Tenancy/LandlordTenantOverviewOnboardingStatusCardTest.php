<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Models\User;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('overview tab renders an onboarding status card with a blueprint deep link when linked telemetry exists', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

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
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=overview")
        ->assertOk()
        ->assertSeeText('Onboarding status')
        ->assertSeeText('Waiting for first open')
        ->assertSee("final_blueprint_id={$blueprint->id}", false);
});

test('overview tab renders a clean empty state when no telemetry exists', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenant = Tenant::query()->create([
        'name' => 'No Telemetry Tenant',
        'slug' => 'no-telemetry',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=overview")
        ->assertOk()
        ->assertSeeText('Onboarding status')
        ->assertSeeText('No onboarding journey telemetry yet')
        ->assertDontSee('final_blueprint_id=', false);
});

test('overview tab shows unlinked telemetry badge and a non-blueprint onboarding deep link when only unlinked events exist', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenant = Tenant::query()->create([
        'name' => 'Unlinked Tenant',
        'slug' => 'unlinked',
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(1),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['payload_type' => 'merchant_journey'],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=overview")
        ->assertOk()
        ->assertSeeText('Onboarding status')
        ->assertSeeText('Unlinked telemetry')
        ->assertSeeText('Open onboarding journey')
        ->assertSee('tab=onboarding_journey', false)
        ->assertDontSee('final_blueprint_id=', false);
});

