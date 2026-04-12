<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

function createJourneyEventTriage(array $overrides): TenantOnboardingJourneyEvent
{
    $defaults = [
        'tenant_id' => 1,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => now(),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ];

    /** @var TenantOnboardingJourneyEvent $event */
    $event = TenantOnboardingJourneyEvent::query()->create(array_merge($defaults, $overrides));

    return $event;
}

test('dashboard triage summary derives counts from canonical telemetry and buckets progressing into waiting_for_import', function (): void {
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

    createJourneyEventTriage([
        'tenant_id' => $tenantFirstOpen->id,
        'final_blueprint_id' => $bpFirstOpen->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(10),
    ]);

    createJourneyEventTriage([
        'tenant_id' => $tenantImportWaiting->id,
        'final_blueprint_id' => $bpImportWaiting->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(12),
    ]);

    createJourneyEventTriage([
        'tenant_id' => $tenantImportProgressing->id,
        'final_blueprint_id' => $bpImportProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(13),
    ]);
    createJourneyEventTriage([
        'tenant_id' => $tenantImportProgressing->id,
        'final_blueprint_id' => $bpImportProgressing->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
        'occurred_at' => $base->addMinutes(14),
    ]);

    createJourneyEventTriage([
        'tenant_id' => $tenantActivation->id,
        'final_blueprint_id' => $bpActivation->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(20),
    ]);
    createJourneyEventTriage([
        'tenant_id' => $tenantActivation->id,
        'final_blueprint_id' => $bpActivation->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => $base->addMinutes(30),
    ]);

    createJourneyEventTriage([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(40),
    ]);
    createJourneyEventTriage([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => $base->addMinutes(45),
    ]);
    createJourneyEventTriage([
        'tenant_id' => $tenantCompleted->id,
        'final_blueprint_id' => $bpCompleted->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        'occurred_at' => $base->addMinutes(50),
    ]);

    $tenantIds = [
        $tenantNoTelemetry->id,
        $tenantFirstOpen->id,
        $tenantImportWaiting->id,
        $tenantImportProgressing->id,
        $tenantActivation->id,
        $tenantCompleted->id,
    ];

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $summary = $service->dashboardTriageSummary($tenantIds);

    expect((int) ($summary['tenants_with_telemetry'] ?? 0))->toBe(5);
    expect((int) ($summary['tenants_needing_onboarding_attention'] ?? 0))->toBe(4);

    $counts = (array) ($summary['counts'] ?? []);
    expect((int) ($counts['no_telemetry'] ?? 0))->toBe(1);
    expect((int) ($counts['waiting_for_first_open'] ?? 0))->toBe(1);
    expect((int) ($counts['waiting_for_import'] ?? 0))->toBe(2);
    expect((int) ($counts['waiting_for_activation'] ?? 0))->toBe(1);
    expect((int) ($counts['completed_first_value'] ?? 0))->toBe(1);
});

