<?php

use App\Models\Tenant;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantOnboardingJourneyEvent;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use Carbon\CarbonImmutable;

function createJourneyEventDetail(array $overrides): TenantOnboardingJourneyEvent
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

test('diagnostics detail returns milestone reduction and raw events in reverse chronological order', function (): void {
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

    $base = CarbonImmutable::now()->subHours(4);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(10),
        'payload' => ['to' => 'handoff'],
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(55),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(20),
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(30),
    ]);

    $latest = createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
        'occurred_at' => $base->addMinutes(70),
        'payload' => ['payload_anchor' => 'merchant_journey'],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $detail = $service->detail($tenant->id, $blueprint->id, 50);

    expect((int) ($detail['final_blueprint_id'] ?? 0))->toBe($blueprint->id);
    expect((array) ($detail['available_blueprint_ids'] ?? []))->toBe([$blueprint->id]);
    expect((bool) ($detail['auto_selected_latest'] ?? false))->toBeFalse();
    expect((string) ($detail['latest_phase'] ?? ''))->toBe('ongoing_setup');
    expect((array) ($detail['raw_events'] ?? []))->not->toBeEmpty();

    $raw = (array) ($detail['raw_events'] ?? []);
    expect((int) ($raw[0]['id'] ?? 0))->toBe((int) $latest->id);
    expect((string) ($raw[0]['category'] ?? ''))->toBe('milestone');
    expect((array) ($raw[0]['context_summary_items'] ?? []))->not->toBeEmpty();

    $unlinked = (array) ($detail['unlinked_events'] ?? []);
    expect($unlinked)->toHaveCount(1);
});

test('diagnostics detail auto-selects latest blueprint id from telemetry and excludes unlinked ids from options', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Selector Tenant',
        'slug' => 'selector',
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

    $base = CarbonImmutable::now()->subHours(3);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => null,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => $base->addMinutes(5),
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $olderBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(10),
        'payload' => ['to' => 'handoff'],
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $newerBlueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => $base->addMinutes(20),
        'payload' => ['to' => 'ongoing_setup'],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $detail = $service->detail($tenant->id, null, 50);

    expect((array) ($detail['available_blueprint_ids'] ?? []))->toBe([$newerBlueprint->id, $olderBlueprint->id]);
    expect((int) ($detail['selected_blueprint_id'] ?? 0))->toBe($newerBlueprint->id);
    expect((bool) ($detail['auto_selected_latest'] ?? false))->toBeTrue();
});

test('diagnostics detail event rows expose stable categories and fallback context summaries', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Category Tenant',
        'slug' => 'category',
    ]);

    $blueprint = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => $tenant->id,
        'status' => 'final',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'payload' => [],
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
        'occurred_at' => CarbonImmutable::now()->subHours(2),
        'payload' => ['to' => 'handoff'],
    ]);

    createJourneyEventDetail([
        'tenant_id' => $tenant->id,
        'final_blueprint_id' => $blueprint->id,
        'event_key' => OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
        'payload' => [],
    ]);

    $service = app(OnboardingJourneyDiagnosticsService::class);
    $detail = $service->detail($tenant->id, $blueprint->id, 50);

    $events = (array) ($detail['raw_events'] ?? []);
    $categories = array_map(static fn (array $row): string => (string) ($row['category'] ?? ''), $events);
    expect($categories)->toContain('phase_change');
    expect($categories)->toContain('milestone');

    $handoffRow = collect($events)->firstWhere('event_key', OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED);
    expect($handoffRow)->not->toBeNull();
    expect((array) ($handoffRow['context_summary_items'] ?? []))->toContain(['label' => 'note', 'value' => 'No additional context']);
});
