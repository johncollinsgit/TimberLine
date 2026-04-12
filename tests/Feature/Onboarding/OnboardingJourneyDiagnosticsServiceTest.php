<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

function createJourneyEvent(array $overrides): TenantOnboardingJourneyEvent
{
    $defaults = [
        'tenant_id' => 1,
        'final_blueprint_id' => null,
        'event_key' => 'onboarding.handoff_viewed',
        'occurred_at' => now(),
        'dedupe_key' => bin2hex(random_bytes(16)),
        'payload' => [],
    ];

    /** @var TenantOnboardingJourneyEvent $event */
    $event = TenantOnboardingJourneyEvent::query()->create(array_merge($defaults, $overrides));

    return $event;
}

test('diagnostics reduces events into milestone timestamps and latest phase', function (): void {
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

    $base = CarbonImmutable::now()->subHours(6);

    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(5),
        'payload' => ['to' => 'handoff'],
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(10),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(20),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(30),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
        'occurred_at' => $base->addMinutes(40),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
        'occurred_at' => $base->addMinutes(50),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        'occurred_at' => $base->addMinutes(55),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(56),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $result = $service->summarize([
        'from' => CarbonImmutable::now()->subDays(1)->toDateString(),
        'to' => CarbonImmutable::now()->addDay()->toDateString(),
    ]);

    $rows = (array) ($result['rows'] ?? []);
    expect($rows)->toHaveCount(1);

    $row = (array) $rows[0];
    expect($row['tenant_id'])->toBe($tenant->id);
    expect($row['final_blueprint_id'])->toBe($blueprint->id);
    expect($row['latest_phase'])->toBe('ongoing_setup');
    expect($row['stuck_point'])->toBe(OnboardingJourneyDiagnosticsService::STUCK_COMPLETED_FIRST_VALUE);

    // Earliest handoff event wins despite duplicates.
    expect((string) $row['handoff_viewed_at'])->toContain($base->addMinutes(10)->format('Y-m-d'));
});

test('diagnostics classifies stuck points from missing milestones', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Stuck Tenant',
        'slug' => 'stuck',
    ]);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);

    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
    ]);

    $row = (array) ($service->summarize()['rows'][0] ?? []);
    expect($row['stuck_point'])->toBe(OnboardingJourneyDiagnosticsService::STUCK_WAITING_FIRST_OPEN);

    TenantOnboardingJourneyEvent::query()->delete();

    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
    ]);

    $row = (array) ($service->summarize()['rows'][0] ?? []);
    expect($row['stuck_point'])->toBe(OnboardingJourneyDiagnosticsService::STUCK_WAITING_IMPORT);

    TenantOnboardingJourneyEvent::query()->delete();

    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
    ]);
    createJourneyEvent([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
    ]);

    $row = (array) ($service->summarize()['rows'][0] ?? []);
    expect($row['stuck_point'])->toBe(OnboardingJourneyDiagnosticsService::STUCK_PROGRESSING);
});

