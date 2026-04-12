<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

function createJourneyEventDirectory(array $overrides): TenantOnboardingJourneyEvent
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

test('directory summaries pick latest linked blueprint per tenant and fall back for unlinked/no telemetry', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);

    $olderBlueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenantA->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $newerBlueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenantA->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    $base = CarbonImmutable::now()->subHours(6);

    createJourneyEventDirectory([
        'tenant_id' => $tenantA->id,
        'final_blueprint_id' => $olderBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(10),
    ]);

    createJourneyEventDirectory([
        'tenant_id' => $tenantA->id,
        'final_blueprint_id' => $newerBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(40),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    createJourneyEventDirectory([
        'tenant_id' => $tenantB->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(20),
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $summaries = $service->directorySummaries([$tenantA->id, $tenantB->id, $tenantC->id]);

    expect($summaries)->toHaveKeys([$tenantA->id, $tenantB->id, $tenantC->id]);

    $a = (array) $summaries[$tenantA->id];
    expect((bool) ($a['has_telemetry'] ?? false))->toBeTrue();
    expect((int) ($a['selected_blueprint_id'] ?? 0))->toBe((int) $newerBlueprint->id);
    expect((string) ($a['latest_phase'] ?? ''))->toBe('ongoing_setup');
    expect((string) ($a['stuck_point'] ?? ''))->toBe(OnboardingJourneyDiagnosticsService::STUCK_WAITING_FIRST_OPEN);

    $b = (array) $summaries[$tenantB->id];
    expect((bool) ($b['has_telemetry'] ?? false))->toBeTrue();
    expect($b['selected_blueprint_id'] ?? null)->toBeNull();
    expect((string) ($b['status_sentence'] ?? ''))->toContain('Unlinked');

    $c = (array) $summaries[$tenantC->id];
    expect((bool) ($c['has_telemetry'] ?? true))->toBeFalse();
    expect((string) ($c['status_sentence'] ?? ''))->toContain('No onboarding telemetry');
});

