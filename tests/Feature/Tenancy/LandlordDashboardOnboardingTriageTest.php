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

test('landlord dashboard renders onboarding triage cards with links into filtered tenant directory', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenantNoTelemetry = Tenant::query()->create(['name' => 'No Telemetry', 'slug' => 'no-telemetry']);
    $tenantFirstOpen = Tenant::query()->create(['name' => 'First Open', 'slug' => 'first-open']);
    $tenantImportWaiting = Tenant::query()->create(['name' => 'Import Waiting', 'slug' => 'import-waiting']);
    $tenantImportProgressing = Tenant::query()->create(['name' => 'Import Progressing', 'slug' => 'import-progressing']);
    $tenantActivation = Tenant::query()->create(['name' => 'Activation', 'slug' => 'activation']);
    $tenantCompleted = Tenant::query()->create(['name' => 'Completed', 'slug' => 'completed']);

    $bpFirstOpen = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantFirstOpen->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpImportWaiting = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantImportWaiting->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpImportProgressing = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantImportProgressing->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpActivation = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantActivation->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);
    $bpCompleted = TenantOnboardingBlueprint::query()->create(['tenant_id' => $tenantCompleted->id, 'status' => 'final', 'account_mode' => 'demo', 'rail' => 'direct', 'payload' => []]);

    $base = CarbonImmutable::now()->subHours(6);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantFirstOpen->id,
        'final_blueprint_id' => $bpFirstOpen->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(10),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantImportWaiting->id,
        'final_blueprint_id' => $bpImportWaiting->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(12),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantImportProgressing->id,
        'final_blueprint_id' => $bpImportProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(13),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantImportProgressing->id,
        'final_blueprint_id' => $bpImportProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
        'occurred_at' => $base->addMinutes(14),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantActivation->id,
        'final_blueprint_id' => $bpActivation->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(20),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantActivation->id,
        'final_blueprint_id' => $bpActivation->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => $base->addMinutes(30),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(40),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => $base->addMinutes(45),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    TenantOnboardingJourneyEvent::query()->create([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        'occurred_at' => $base->addMinutes(50),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord")
        ->assertOk()
        ->assertSeeText('Onboarding triage')
        ->assertSeeTextInOrder(['5', 'with telemetry', '4', 'needing attention'])
        ->assertSee('onboarding_filter=no_telemetry', false)
        ->assertSee('onboarding_filter=waiting_for_first_open', false)
        ->assertSee('onboarding_filter=waiting_for_import', false)
        ->assertSee('onboarding_filter=waiting_for_activation', false)
        ->assertSee('onboarding_filter=completed_first_value', false);
});

