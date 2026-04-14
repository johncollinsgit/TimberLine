<?php

use App\Models\LandlordOperatorAction;
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

test('tenant activity tab includes onboarding journey telemetry with deep link when blueprint id exists', function (): void {
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
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => CarbonImmutable::now()->subMinutes(10),
        'actor_user_id' => null,
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['from' => 'handoff', 'to' => 'ongoing_setup', 'payload_type' => 'merchant_journey'],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=activity")
        ->assertOk()
        ->assertSeeText('Onboarding phase changed')
        ->assertSeeText('Blueprint #'.$blueprint->id)
        ->assertSeeText('Open onboarding journey')
        ->assertSee('tab=onboarding_journey', false)
        ->assertSee('final_blueprint_id='.$blueprint->id, false);
});

test('tenant activity tab includes unlinked onboarding telemetry without a broken deep link', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Unlinked Tenant',
        'slug' => 'unlinked',
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subMinutes(15),
        'actor_user_id' => null,
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=activity")
        ->assertOk()
        ->assertSeeText('Onboarding handoff viewed')
        ->assertDontSeeText('Open onboarding journey');
});

test('tenant activity chronology remains unified across onboarding and operator actions', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenant = Tenant::query()->create([
        'name' => 'Chronology Tenant',
        'slug' => 'chronology',
    ]);

    $operator = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    LandlordOperatorAction::query()->create([
        'tenant_id' => $tenant->id,
        'actor_user_id' => $operator->id,
        'action_type' => 'assign_plan',
        'status' => 'success',
        'target_type' => 'tenant',
        'target_id' => $tenant->id,
        'context' => [],
        'created_at' => CarbonImmutable::now()->subHours(2),
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subMinutes(5),
        'actor_user_id' => null,
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => ['payload_anchor' => 'start_here'],
    ]);

    $this->actingAs($operator)
        ->get("http://{$landlordHost}/landlord/tenants/{$tenant->id}?tab=activity")
        ->assertOk()
        ->assertSeeInOrder([
            'Onboarding first open acknowledged',
            'Assign Plan',
        ]);
});

