<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

function createJourneyEventOverview(array $overrides): TenantOnboardingJourneyEvent
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

test('diagnostics overview returns a clean empty state when no telemetry exists', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'No Telemetry Tenant',
        'slug' => 'no-telemetry',
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $overview = $service->overview($tenant->id);

    expect((bool) ($overview['has_telemetry'] ?? true))->toBeFalse();
    expect($overview['selected_blueprint_id'] ?? null)->toBeNull();
    expect((string) ($overview['status_sentence'] ?? ''))->toContain('No onboarding journey telemetry');
});

test('diagnostics overview selects latest linked blueprint id deterministically and reduces milestones', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Overview Tenant',
        'slug' => 'overview',
    ]);

    $olderBlueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $newerBlueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $base = CarbonImmutable::now()->subHours(5);

    createJourneyEventOverview([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $olderBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(10),
    ]);

    createJourneyEventOverview([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $newerBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(40),
    ]);

    createJourneyEventOverview([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $newerBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(55),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $overview = $service->overview($tenant->id);

    expect((bool) ($overview['has_telemetry'] ?? false))->toBeTrue();
    expect((int) ($overview['selected_blueprint_id'] ?? 0))->toBe((int) $newerBlueprint->id);
    expect((string) ($overview['latest_phase'] ?? ''))->toBe('ongoing_setup');
    expect((string) ($overview['stuck_point'] ?? ''))->toBe(OnboardingJourneyDiagnosticsService::STUCK_WAITING_FIRST_OPEN);

    $milestones = (array) ($overview['milestones'] ?? []);
    expect($milestones)->toHaveKey('handoff_viewed_at');
});

test('diagnostics overview reports unlinked telemetry without selecting a blueprint', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Unlinked Tenant',
        'slug' => 'unlinked',
    ]);

    createJourneyEventOverview([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $overview = $service->overview($tenant->id);

    expect((bool) ($overview['has_telemetry'] ?? false))->toBeTrue();
    expect($overview['selected_blueprint_id'] ?? null)->toBeNull();
    expect((string) ($overview['status_sentence'] ?? ''))->toContain('Unlinked onboarding telemetry');
});

