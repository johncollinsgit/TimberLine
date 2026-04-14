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

test('tenant directory renders onboarding summaries and deep links into onboarding journey when blueprint context exists', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenantA->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantB->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants")
        ->assertOk()
        ->assertSeeText('Onboarding')
        ->assertSee('name="onboarding_filter"', false)
        ->assertSeeText('Waiting for first open')
        ->assertSeeText('Unlinked onboarding telemetry present')
        ->assertSeeText('No onboarding telemetry yet')
        ->assertSee("final_blueprint_id={$blueprint->id}", false)
        ->assertSee('tab=onboarding_journey', false);
});

test('tenant directory supports lightweight onboarding stuck-point filtering', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenantA->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantB->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants?onboarding_filter=waiting_for_first_open")
        ->assertOk()
        ->assertSeeText('Tenant A')
        ->assertDontSeeText('Tenant B')
        ->assertDontSeeText('Tenant C');

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants?onboarding_filter=no_telemetry")
        ->assertOk()
        ->assertSeeText('Tenant C')
        ->assertDontSeeText('Tenant A')
        ->assertDontSeeText('Tenant B');
});

test('tenant directory waiting_for_import filter includes both waiting_for_import and progressing states', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.grovebud.com';

    $tenantWaiting = Tenant::query()->create(['name' => 'Tenant Waiting Import', 'slug' => 'tenant-waiting-import']);
    $tenantProgressing = Tenant::query()->create(['name' => 'Tenant Import Progressing', 'slug' => 'tenant-import-progressing']);
    $tenantCompleted = Tenant::query()->create(['name' => 'Tenant Completed', 'slug' => 'tenant-completed']);

    $bpWaiting = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantWaiting->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpProgressing = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantProgressing->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpCompleted = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantCompleted->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantWaiting->id,
        'final_blueprint_id' => $bpWaiting->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantProgressing->id,
        'final_blueprint_id' => $bpProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);
    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantProgressing->id,
        'final_blueprint_id' => $bpProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subHours(4),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);
    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);
    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/tenants?onboarding_filter=waiting_for_import")
        ->assertOk()
        ->assertSeeText('Tenant Waiting Import')
        ->assertSeeText('Tenant Import Progressing')
        ->assertDontSeeText('Tenant Completed');
});
