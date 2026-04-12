<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingJourneyEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

class OnboardingJourneyDiagnosticsService
{
    public const STUCK_WAITING_FIRST_OPEN = 'waiting_for_first_open';
    public const STUCK_WAITING_IMPORT = 'waiting_for_import';
    public const STUCK_WAITING_ACTIVATION = 'waiting_for_activation';
    public const STUCK_PROGRESSING = 'progressing';
    public const STUCK_COMPLETED_FIRST_VALUE = 'completed_first_value';

    /**
     * @param  array{
     *   tenant_id?:int|null,
     *   final_blueprint_id?:int|null,
     *   from?:\DateTimeInterface|string|null,
     *   to?:\DateTimeInterface|string|null,
     *   stuck_point?:string|null,
     *   phase?:string|null,
     *   limit?:int|null
     * }  $filters
     * @return array{
     *   rows:array<int,array<string,mixed>>,
     *   meta:array<string,mixed>
     * }
     */
    public function summarize(array $filters = []): array
    {
        $tenantId = isset($filters['tenant_id']) && is_numeric($filters['tenant_id'])
            ? (int) $filters['tenant_id']
            : null;
        $finalBlueprintId = isset($filters['final_blueprint_id']) && is_numeric($filters['final_blueprint_id'])
            ? (int) $filters['final_blueprint_id']
            : null;
        $stuckFilter = isset($filters['stuck_point']) ? strtolower(trim((string) $filters['stuck_point'])) : null;
        $phaseFilter = isset($filters['phase']) ? strtolower(trim((string) $filters['phase'])) : null;
        $limit = isset($filters['limit']) && is_numeric($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 75;

        $fromRaw = $filters['from'] ?? null;
        $toRaw = $filters['to'] ?? null;

        $from = $this->parseDate($fromRaw) ?? CarbonImmutable::now()->subDays(30);
        $to = $this->parseDate($toRaw) ?? CarbonImmutable::now();

        if (is_string($fromRaw) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', trim($fromRaw))) {
            $from = $from->startOfDay();
        }

        if (is_string($toRaw) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', trim($toRaw))) {
            $to = $to->endOfDay();
        }

        $eventKeys = [
            OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
            OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        ];

        $query = TenantOnboardingJourneyEvent::query()
            ->select(['tenant_id', 'final_blueprint_id', 'event_key', 'occurred_at', 'payload'])
            ->whereIn('event_key', $eventKeys)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('occurred_at')
            ->limit(10000);

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        if ($finalBlueprintId !== null && $finalBlueprintId > 0) {
            $query->where('final_blueprint_id', $finalBlueprintId);
        }

        /** @var array<int,array<string,mixed>> $events */
        $events = $query->get()->map(static fn (TenantOnboardingJourneyEvent $event): array => [
            'tenant_id' => (int) $event->tenant_id,
            'final_blueprint_id' => is_numeric($event->final_blueprint_id) ? (int) $event->final_blueprint_id : null,
            'event_key' => (string) $event->event_key,
            'occurred_at' => $event->occurred_at?->toImmutable(),
            'payload' => is_array($event->payload ?? null) ? (array) $event->payload : [],
        ])->all();

        $groups = [];
        foreach ($events as $event) {
            $groupKey = (string) ($event['tenant_id'] ?? 0) . ':' . (string) ($event['final_blueprint_id'] ?? 0);
            $groups[$groupKey][] = $event;
        }

        $tenantIds = array_values(array_unique(array_map(
            static fn (string $key): int => (int) explode(':', $key)[0],
            array_keys($groups)
        )));

        $tenantLookup = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $rows = [];

        foreach ($groups as $key => $groupEvents) {
            $tenantIdPart = (int) explode(':', $key)[0];
            $blueprintIdPart = (int) explode(':', $key)[1];
            $finalId = $blueprintIdPart > 0 ? $blueprintIdPart : null;

            $milestones = $this->reduceMilestones($groupEvents);
            $stuckPoint = $this->stuckPoint($milestones);

            if ($stuckFilter !== null && $stuckFilter !== '' && $stuckPoint !== $stuckFilter) {
                continue;
            }

            $latestPhase = (string) ($milestones['latest_phase'] ?? '');
            if ($phaseFilter !== null && $phaseFilter !== '' && $latestPhase !== $phaseFilter) {
                continue;
            }

            $tenant = $tenantLookup->get($tenantIdPart);

            $rows[] = [
                'tenant_id' => $tenantIdPart,
                'tenant_name' => $tenant instanceof Tenant ? (string) $tenant->name : 'Tenant #' . $tenantIdPart,
                'tenant_slug' => $tenant instanceof Tenant ? (string) $tenant->slug : null,
                'final_blueprint_id' => $finalId,
                'latest_phase' => $latestPhase !== '' ? $latestPhase : null,
                'latest_phase_changed_at' => $this->iso($milestones['latest_phase_changed_at'] ?? null),
                'handoff_viewed_at' => $this->iso($milestones['handoff_viewed_at'] ?? null),
                'first_open_acknowledged_at' => $this->iso($milestones['first_open_acknowledged_at'] ?? null),
                'import_started_at' => $this->iso($milestones['import_started_at'] ?? null),
                'import_completed_at' => $this->iso($milestones['import_completed_at'] ?? null),
                'first_active_module_reached_at' => $this->iso($milestones['first_active_module_reached_at'] ?? null),
                'latest_event_at' => $this->iso($milestones['latest_event_at'] ?? null),
                'durations' => [
                    'handoff_to_first_open_seconds' => $this->durationSeconds(
                        $milestones['handoff_viewed_at'] ?? null,
                        $milestones['first_open_acknowledged_at'] ?? null
                    ),
                    'first_open_to_import_complete_seconds' => $this->durationSeconds(
                        $milestones['first_open_acknowledged_at'] ?? null,
                        $milestones['import_completed_at'] ?? null
                    ),
                    'import_complete_to_first_active_module_seconds' => $this->durationSeconds(
                        $milestones['import_completed_at'] ?? null,
                        $milestones['first_active_module_reached_at'] ?? null
                    ),
                ],
                'stuck_point' => $stuckPoint,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) ($right['latest_event_at'] ?? ''),
            (string) ($left['latest_event_at'] ?? '')
        ));

        $rows = array_slice($rows, 0, $limit);

        return [
            'rows' => $rows,
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'limit' => $limit,
                'result_count' => count($rows),
                'source_event_count' => count($events),
            ],
        ];
    }

    public function __construct(
        protected OnboardingJourneyEventPresenter $presenter
    ) {
    }

    /**
     * Compact read-only overview for landlord/operator surfaces (Overview tab).
     *
     * This does not introduce a new onboarding state model. It reduces the same canonical
     * append-only telemetry already used by the onboarding_journey tab and selects the
     * most-recent linked blueprint id (by last seen telemetry) when available.
     *
     * @return array{
     *   has_telemetry:bool,
     *   selected_blueprint_id:int|null,
     *   auto_selected_latest:bool,
     *   latest_phase:string|null,
     *   stuck_point:string|null,
     *   status_sentence:string,
     *   milestones:array{
     *     handoff_viewed_at:string|null,
     *     first_open_acknowledged_at:string|null,
     *     import_completed_at:string|null,
     *     first_active_module_reached_at:string|null
     *   }|null,
     *   meta:array<string,mixed>
     * }
     */
    public function overview(int $tenantId): array
    {
        $availableBlueprintIds = $this->linkedBlueprintIdsForTenant($tenantId);
        $selectedBlueprintId = $availableBlueprintIds[0] ?? null;

        $hasTelemetry = $selectedBlueprintId !== null;

        if (! $hasTelemetry) {
            try {
                $hasTelemetry = TenantOnboardingJourneyEvent::query()
                    ->where('tenant_id', $tenantId)
                    ->exists();
            } catch (\Throwable) {
                $hasTelemetry = false;
            }
        }

        if (! $hasTelemetry) {
            return [
                'has_telemetry' => false,
                'selected_blueprint_id' => null,
                'auto_selected_latest' => false,
                'latest_phase' => null,
                'stuck_point' => null,
                'status_sentence' => 'No onboarding journey telemetry yet',
                'milestones' => null,
                'meta' => [
                    'available_blueprint_ids' => $availableBlueprintIds,
                    'source' => 'telemetry',
                ],
            ];
        }

        if ($selectedBlueprintId === null) {
            return [
                'has_telemetry' => true,
                'selected_blueprint_id' => null,
                'auto_selected_latest' => false,
                'latest_phase' => null,
                'stuck_point' => null,
                'status_sentence' => 'Unlinked onboarding telemetry present',
                'milestones' => null,
                'meta' => [
                    'available_blueprint_ids' => $availableBlueprintIds,
                    'source' => 'telemetry',
                ],
            ];
        }

        $eventKeys = [
            OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
            OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        ];

        /** @var array<int,array<string,mixed>> $events */
        $events = TenantOnboardingJourneyEvent::query()
            ->select(['id', 'tenant_id', 'final_blueprint_id', 'event_key', 'occurred_at', 'actor_user_id', 'payload'])
            ->where('tenant_id', $tenantId)
            ->where('final_blueprint_id', $selectedBlueprintId)
            ->whereIn('event_key', $eventKeys)
            ->orderByDesc('occurred_at')
            ->limit(5000)
            ->get()
            ->map(fn (TenantOnboardingJourneyEvent $event): array => $this->presentEventRow($event))
            ->all();

        $milestonesReduced = $this->reduceMilestones($events);
        $stuckPoint = $this->stuckPoint($milestonesReduced);
        $latestPhase = (string) ($milestonesReduced['latest_phase'] ?? '');
        $latestPhase = $latestPhase !== '' ? $latestPhase : null;

        return [
            'has_telemetry' => true,
            'selected_blueprint_id' => $selectedBlueprintId,
            'auto_selected_latest' => true,
            'latest_phase' => $latestPhase,
            'stuck_point' => $stuckPoint,
            'status_sentence' => $this->statusSentence($stuckPoint),
            'milestones' => [
                'handoff_viewed_at' => $this->iso($milestonesReduced['handoff_viewed_at'] ?? null),
                'first_open_acknowledged_at' => $this->iso($milestonesReduced['first_open_acknowledged_at'] ?? null),
                'import_completed_at' => $this->iso($milestonesReduced['import_completed_at'] ?? null),
                'first_active_module_reached_at' => $this->iso($milestonesReduced['first_active_module_reached_at'] ?? null),
            ],
            'meta' => [
                'available_blueprint_ids' => $availableBlueprintIds,
                'source' => 'telemetry',
                'reduced_event_count' => count($events),
            ],
        ];
    }

    /**
     * Batch directory-level scan summaries for many tenants.
     *
     * This is intentionally compact and derived strictly from append-only journey telemetry:
     * - picks the latest linked blueprint id per tenant (by last-seen telemetry)
     * - reduces milestones for that blueprint only (linked)
     * - falls back to "unlinked telemetry" / "no telemetry" states when appropriate
     *
     * @param  array<int,int|string>  $tenantIds
     * @return array<int,array{
     *   has_telemetry:bool,
     *   selected_blueprint_id:int|null,
     *   latest_phase:string|null,
     *   stuck_point:string|null,
     *   status_sentence:string
     * }>
     */
    public function directorySummaries(array $tenantIds): array
    {
        $ids = [];
        foreach ($tenantIds as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $eventKeys = [
            OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
            OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        ];

        $telemetryTenantIds = [];
        $unlinkedTenantIds = [];
        $latestBlueprintByTenant = [];

        try {
            $telemetryTenantIds = TenantOnboardingJourneyEvent::query()
                ->whereIn('tenant_id', $ids)
                ->distinct()
                ->pluck('tenant_id')
                ->map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)
                ->filter(static fn (int $value): bool => $value > 0)
                ->values()
                ->all();
        } catch (\Throwable) {
            $telemetryTenantIds = [];
        }

        try {
            $unlinkedTenantIds = TenantOnboardingJourneyEvent::query()
                ->whereIn('tenant_id', $ids)
                ->whereNull('final_blueprint_id')
                ->distinct()
                ->pluck('tenant_id')
                ->map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)
                ->filter(static fn (int $value): bool => $value > 0)
                ->values()
                ->all();
        } catch (\Throwable) {
            $unlinkedTenantIds = [];
        }

        // Determine the most recently seen linked blueprint per tenant.
        try {
            $rows = TenantOnboardingJourneyEvent::query()
                ->selectRaw('tenant_id, final_blueprint_id, max(occurred_at) as last_seen_at')
                ->whereIn('tenant_id', $ids)
                ->whereNotNull('final_blueprint_id')
                ->groupBy('tenant_id', 'final_blueprint_id')
                ->orderByDesc('last_seen_at')
                ->limit(50000)
                ->get();

            foreach ($rows as $row) {
                $tenantId = is_numeric($row->tenant_id) ? (int) $row->tenant_id : null;
                $blueprintId = is_numeric($row->final_blueprint_id) ? (int) $row->final_blueprint_id : null;

                if (! is_int($tenantId) || $tenantId <= 0) {
                    continue;
                }

                if (! is_int($blueprintId) || $blueprintId <= 0) {
                    continue;
                }

                if (! array_key_exists($tenantId, $latestBlueprintByTenant)) {
                    $latestBlueprintByTenant[$tenantId] = $blueprintId;
                }
            }
        } catch (\Throwable) {
            $latestBlueprintByTenant = [];
        }

        $selectedBlueprintIds = array_values(array_unique(array_values($latestBlueprintByTenant)));

        $eventsByTenant = [];
        if ($selectedBlueprintIds !== []) {
            try {
                $rows = TenantOnboardingJourneyEvent::query()
                    ->select(['tenant_id', 'final_blueprint_id', 'event_key', 'occurred_at', 'payload'])
                    ->whereIn('tenant_id', $ids)
                    ->whereIn('final_blueprint_id', $selectedBlueprintIds)
                    ->whereIn('event_key', $eventKeys)
                    ->orderByDesc('occurred_at')
                    ->limit(200000)
                    ->get();

                foreach ($rows as $event) {
                    $tenantId = is_numeric($event->tenant_id) ? (int) $event->tenant_id : null;
                    if (! is_int($tenantId) || $tenantId <= 0) {
                        continue;
                    }

                    $eventsByTenant[$tenantId][] = [
                        'event_key' => (string) $event->event_key,
                        'occurred_at' => $event->occurred_at?->toImmutable(),
                        'payload' => is_array($event->payload ?? null) ? (array) $event->payload : [],
                    ];
                }
            } catch (\Throwable) {
                $eventsByTenant = [];
            }
        }

        $telemetryTenantLookup = array_fill_keys($telemetryTenantIds, true);
        $unlinkedTenantLookup = array_fill_keys($unlinkedTenantIds, true);

        $result = [];

        foreach ($ids as $tenantId) {
            $hasTelemetry = isset($telemetryTenantLookup[$tenantId]);
            $selectedBlueprintId = $latestBlueprintByTenant[$tenantId] ?? null;

            if (! $hasTelemetry) {
                $result[$tenantId] = [
                    'has_telemetry' => false,
                    'selected_blueprint_id' => null,
                    'latest_phase' => null,
                    'stuck_point' => null,
                    'status_sentence' => 'No onboarding telemetry yet',
                ];
                continue;
            }

            if ($selectedBlueprintId === null) {
                $result[$tenantId] = [
                    'has_telemetry' => true,
                    'selected_blueprint_id' => null,
                    'latest_phase' => null,
                    'stuck_point' => null,
                    'status_sentence' => isset($unlinkedTenantLookup[$tenantId])
                        ? 'Unlinked onboarding telemetry present'
                        : 'Onboarding telemetry present',
                ];
                continue;
            }

            $events = $eventsByTenant[$tenantId] ?? [];
            $milestonesReduced = $this->reduceMilestones($events);
            $stuckPoint = $this->stuckPoint($milestonesReduced);
            $latestPhase = (string) ($milestonesReduced['latest_phase'] ?? '');

            $result[$tenantId] = [
                'has_telemetry' => true,
                'selected_blueprint_id' => $selectedBlueprintId,
                'latest_phase' => $latestPhase !== '' ? $latestPhase : null,
                'stuck_point' => $stuckPoint,
                'status_sentence' => $this->statusSentence($stuckPoint),
            ];
        }

        return $result;
    }

    /**
     * Read-only counts for landlord dashboard onboarding triage cards.
     *
     * Counts are derived from the same deterministic stuck-point reduction used elsewhere.
     * Import bucket intentionally includes both "waiting_for_import" and "progressing" so
     * operators can triage "import not yet complete" in one click.
     *
     * @param  array<int,int|string>  $tenantIds
     * @return array{
     *   tenants_with_telemetry:int,
     *   tenants_needing_onboarding_attention:int,
     *   counts:array{
     *     no_telemetry:int,
     *     waiting_for_first_open:int,
     *     waiting_for_import:int,
     *     waiting_for_activation:int,
     *     completed_first_value:int
     *   },
     *   meta:array<string,mixed>
     * }
     */
    public function dashboardTriageSummary(array $tenantIds): array
    {
        $summaries = $this->directorySummaries($tenantIds);

        $counts = [
            'no_telemetry' => 0,
            self::STUCK_WAITING_FIRST_OPEN => 0,
            self::STUCK_WAITING_IMPORT => 0,
            self::STUCK_PROGRESSING => 0,
            self::STUCK_WAITING_ACTIVATION => 0,
            self::STUCK_COMPLETED_FIRST_VALUE => 0,
        ];

        $tenantsWithTelemetry = 0;
        $unlinkedTelemetry = 0;

        foreach ($summaries as $tenantId => $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $hasTelemetry = (bool) ($summary['has_telemetry'] ?? false);
            if (! $hasTelemetry) {
                $counts['no_telemetry']++;
                continue;
            }

            $tenantsWithTelemetry++;

            if (! is_numeric($summary['selected_blueprint_id'] ?? null)) {
                $unlinkedTelemetry++;
            }

            $stuck = strtolower(trim((string) ($summary['stuck_point'] ?? '')));
            if ($stuck !== '' && array_key_exists($stuck, $counts)) {
                $counts[$stuck]++;
            }
        }

        $waitingForImportBucket = (int) $counts[self::STUCK_WAITING_IMPORT] + (int) $counts[self::STUCK_PROGRESSING];
        $needingAttention = $tenantsWithTelemetry - (int) $counts[self::STUCK_COMPLETED_FIRST_VALUE];

        return [
            'tenants_with_telemetry' => $tenantsWithTelemetry,
            'tenants_needing_onboarding_attention' => max(0, $needingAttention),
            'counts' => [
                'no_telemetry' => (int) $counts['no_telemetry'],
                'waiting_for_first_open' => (int) $counts[self::STUCK_WAITING_FIRST_OPEN],
                'waiting_for_import' => $waitingForImportBucket,
                'waiting_for_activation' => (int) $counts[self::STUCK_WAITING_ACTIVATION],
                'completed_first_value' => (int) $counts[self::STUCK_COMPLETED_FIRST_VALUE],
            ],
            'meta' => [
                'unlinked_telemetry_tenant_count' => $unlinkedTelemetry,
                'import_progressing_count' => (int) $counts[self::STUCK_PROGRESSING],
            ],
        ];
    }

    /**
     * Read-only drill-in for one tenant + finalized blueprint id.
     *
     * Milestones are reduced from blueprint-scoped events only. Unlinked events (null blueprint id)
     * are returned separately so operators can see telemetry that could not be attributed.
     *
     * @return array{
     *   tenant:array<string,mixed>,
     *   final_blueprint_id:int|null,
     *   milestones:array<string,mixed>|null,
     *   stuck_point:string|null,
     *   latest_phase:string|null,
     *   raw_events:array<int,array<string,mixed>>,
     *   unlinked_events:array<int,array<string,mixed>>,
     *   actor_lookup:array<int,array<string,mixed>>,
     *   meta:array<string,mixed>
     * }
     */
    public function detail(int $tenantId, ?int $finalBlueprintId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $tenant = Tenant::query()->find($tenantId);

        $availableBlueprintIds = $this->linkedBlueprintIdsForTenant($tenantId);

        $resolvedBlueprintId = $finalBlueprintId;
        if ($resolvedBlueprintId === null || $resolvedBlueprintId <= 0) {
            $resolvedBlueprintId = $availableBlueprintIds[0] ?? null;
        }

        $blueprintEvents = [];
        if ($resolvedBlueprintId !== null) {
            $blueprintEvents = $this->eventRowsForTenantBlueprint($tenantId, $resolvedBlueprintId, $limit);
        }

        $unlinkedEvents = $this->eventRowsForTenantUnlinked($tenantId, min(100, $limit));

        $milestones = $resolvedBlueprintId !== null ? $this->reduceMilestones($blueprintEvents) : null;
        $stuckPoint = $milestones !== null ? $this->stuckPoint($milestones) : null;
        $latestPhase = $milestones !== null ? (string) ($milestones['latest_phase'] ?? '') : '';
        $latestPhase = $latestPhase !== '' ? $latestPhase : null;

        $actorLookup = $this->actorLookupForEvents([...$blueprintEvents, ...$unlinkedEvents]);

        $autoSelectedLatest = ($finalBlueprintId === null || $finalBlueprintId <= 0)
            && $resolvedBlueprintId !== null;

        return [
            'tenant' => [
                'tenant_id' => $tenant ? (int) $tenant->id : $tenantId,
                'tenant_name' => $tenant ? (string) $tenant->name : ('Tenant #'.$tenantId),
                'tenant_slug' => $tenant ? (string) $tenant->slug : null,
            ],
            'available_blueprint_ids' => $availableBlueprintIds,
            'final_blueprint_id' => $resolvedBlueprintId,
            'selected_blueprint_id' => $resolvedBlueprintId,
            'auto_selected_latest' => $autoSelectedLatest,
            'milestones' => $milestones !== null ? [
                'latest_phase' => $latestPhase,
                'latest_phase_changed_at' => $this->iso($milestones['latest_phase_changed_at'] ?? null),
                'handoff_viewed_at' => $this->iso($milestones['handoff_viewed_at'] ?? null),
                'first_open_acknowledged_at' => $this->iso($milestones['first_open_acknowledged_at'] ?? null),
                'import_started_at' => $this->iso($milestones['import_started_at'] ?? null),
                'import_completed_at' => $this->iso($milestones['import_completed_at'] ?? null),
                'first_active_module_reached_at' => $this->iso($milestones['first_active_module_reached_at'] ?? null),
                'latest_event_at' => $this->iso($milestones['latest_event_at'] ?? null),
                'durations' => [
                    'handoff_to_first_open_seconds' => $this->durationSeconds(
                        $milestones['handoff_viewed_at'] ?? null,
                        $milestones['first_open_acknowledged_at'] ?? null
                    ),
                    'first_open_to_import_complete_seconds' => $this->durationSeconds(
                        $milestones['first_open_acknowledged_at'] ?? null,
                        $milestones['import_completed_at'] ?? null
                    ),
                    'import_complete_to_first_active_module_seconds' => $this->durationSeconds(
                        $milestones['import_completed_at'] ?? null,
                        $milestones['first_active_module_reached_at'] ?? null
                    ),
                ],
            ] : null,
            'stuck_point' => $stuckPoint,
            'latest_phase' => $latestPhase,
            'raw_events' => $blueprintEvents,
            'unlinked_events' => $unlinkedEvents,
            'actor_lookup' => $actorLookup,
            'meta' => [
                'requested_final_blueprint_id' => $finalBlueprintId,
                'resolved_final_blueprint_id' => $resolvedBlueprintId,
                'auto_selected_latest' => $autoSelectedLatest,
                'raw_event_count' => count($blueprintEvents),
                'unlinked_event_count' => count($unlinkedEvents),
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    protected function reduceMilestones(array $events): array
    {
        $milestones = [
            'handoff_viewed_at' => null,
            'first_open_acknowledged_at' => null,
            'import_started_at' => null,
            'import_completed_at' => null,
            'first_active_module_reached_at' => null,
            'latest_event_at' => null,
            'latest_phase' => null,
            'latest_phase_changed_at' => null,
        ];

        foreach ($events as $event) {
            $occurredAt = $event['occurred_at'] ?? null;
            if (! $occurredAt instanceof CarbonImmutable) {
                continue;
            }

            if (! $milestones['latest_event_at'] instanceof CarbonImmutable || $occurredAt->greaterThan($milestones['latest_event_at'])) {
                $milestones['latest_event_at'] = $occurredAt;
            }

            $eventKey = (string) ($event['event_key'] ?? '');

            $milestoneKey = match ($eventKey) {
                OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED => 'handoff_viewed_at',
                OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK => 'first_open_acknowledged_at',
                OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED => 'import_started_at',
                OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED => 'import_completed_at',
                OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE => 'first_active_module_reached_at',
                default => null,
            };

            if ($milestoneKey !== null) {
                if (! $milestones[$milestoneKey] instanceof CarbonImmutable || $occurredAt->lessThan($milestones[$milestoneKey])) {
                    $milestones[$milestoneKey] = $occurredAt;
                }
            }

            if ($eventKey === OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED) {
                if (! $milestones['latest_phase_changed_at'] instanceof CarbonImmutable || $occurredAt->greaterThan($milestones['latest_phase_changed_at'])) {
                    $payload = is_array($event['payload'] ?? null) ? (array) $event['payload'] : [];
                    $to = strtolower(trim((string) ($payload['to'] ?? '')));
                    $milestones['latest_phase'] = $to !== '' ? $to : null;
                    $milestones['latest_phase_changed_at'] = $occurredAt;
                }
            }
        }

        return $milestones;
    }

    /**
     * @param  array<string,mixed>  $milestones
     */
    protected function stuckPoint(array $milestones): string
    {
        $firstOpen = $milestones['first_open_acknowledged_at'] ?? null;
        $importStarted = $milestones['import_started_at'] ?? null;
        $importComplete = $milestones['import_completed_at'] ?? null;
        $firstActive = $milestones['first_active_module_reached_at'] ?? null;

        if (! $firstOpen instanceof CarbonImmutable) {
            return self::STUCK_WAITING_FIRST_OPEN;
        }

        if (! $importComplete instanceof CarbonImmutable) {
            if ($importStarted instanceof CarbonImmutable) {
                return self::STUCK_PROGRESSING;
            }

            return self::STUCK_WAITING_IMPORT;
        }

        if (! $firstActive instanceof CarbonImmutable) {
            return self::STUCK_WAITING_ACTIVATION;
        }

        return self::STUCK_COMPLETED_FIRST_VALUE;
    }

    protected function durationSeconds(?CarbonImmutable $from, ?CarbonImmutable $to): ?int
    {
        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable) {
            return null;
        }

        return max(0, $from->diffInSeconds($to, false));
    }

    protected function statusSentence(string $stuckPoint): string
    {
        return match ($stuckPoint) {
            self::STUCK_WAITING_FIRST_OPEN => 'Waiting for first open',
            self::STUCK_WAITING_IMPORT => 'Waiting for import',
            self::STUCK_WAITING_ACTIVATION => 'Waiting for first active module',
            self::STUCK_PROGRESSING => 'Import in progress',
            self::STUCK_COMPLETED_FIRST_VALUE => 'Reached first value',
            default => 'Onboarding activity detected',
        };
    }

    protected function iso(?CarbonImmutable $value): ?string
    {
        return $value instanceof CarbonImmutable ? $value->toIso8601String() : null;
    }

    protected function parseDate(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        $token = trim((string) $raw);
        if ($token === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($token);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function eventRowsForTenantBlueprint(int $tenantId, int $finalBlueprintId, int $limit): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = TenantOnboardingJourneyEvent::query()
            ->select(['id', 'tenant_id', 'final_blueprint_id', 'event_key', 'occurred_at', 'actor_user_id', 'payload'])
            ->where('tenant_id', $tenantId)
            ->where('final_blueprint_id', $finalBlueprintId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (TenantOnboardingJourneyEvent $event): array => $this->presentEventRow($event))
            ->all();

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function eventRowsForTenantUnlinked(int $tenantId, int $limit): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = TenantOnboardingJourneyEvent::query()
            ->select(['id', 'tenant_id', 'final_blueprint_id', 'event_key', 'occurred_at', 'actor_user_id', 'payload'])
            ->where('tenant_id', $tenantId)
            ->whereNull('final_blueprint_id')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (TenantOnboardingJourneyEvent $event): array => $this->presentEventRow($event))
            ->all();

        return $rows;
    }

    /**
     * @return array<int,int>
     */
    protected function linkedBlueprintIdsForTenant(int $tenantId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $rows = TenantOnboardingJourneyEvent::query()
                ->selectRaw('final_blueprint_id, max(occurred_at) as last_seen_at')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('final_blueprint_id')
                ->groupBy('final_blueprint_id')
                ->orderByDesc('last_seen_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows
            ->pluck('final_blueprint_id')
            ->map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)
            ->filter(static fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentEventRow(TenantOnboardingJourneyEvent $event): array
    {
        $payload = is_array($event->payload ?? null) ? (array) $event->payload : [];
        $eventKey = (string) $event->event_key;
        $payloadSummary = $this->presenter->payloadSummary($eventKey, $payload);

        return [
            'id' => (int) $event->id,
            'tenant_id' => (int) $event->tenant_id,
            'final_blueprint_id' => is_numeric($event->final_blueprint_id) ? (int) $event->final_blueprint_id : null,
            'event_key' => $eventKey,
            'category' => $this->presenter->categoryForEventKey($eventKey),
            'occurred_at' => $event->occurred_at?->toImmutable(),
            'occurred_at_iso' => $event->occurred_at?->toIso8601String(),
            'actor_user_id' => is_numeric($event->actor_user_id) ? (int) $event->actor_user_id : null,
            'payload' => $payload,
            'payload_summary' => $payloadSummary,
            'context_summary_items' => $this->presenter->contextSummaryItems($eventKey, $payloadSummary),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<int,array<string,mixed>>
     */
    protected function actorLookupForEvents(array $events): array
    {
        $actorIds = [];
        foreach ($events as $event) {
            $actorId = isset($event['actor_user_id']) && is_numeric($event['actor_user_id']) ? (int) $event['actor_user_id'] : null;
            if ($actorId !== null && $actorId > 0) {
                $actorIds[] = $actorId;
            }
        }

        $actorIds = array_values(array_unique($actorIds));
        if ($actorIds === []) {
            return [];
        }

        try {
            $users = User::query()
                ->whereIn('id', $actorIds)
                ->get(['id', 'name', 'email', 'role'])
                ->keyBy('id');
        } catch (\Throwable) {
            return [];
        }

        $lookup = [];
        foreach ($actorIds as $actorId) {
            $user = $users->get($actorId);
            if (! $user instanceof User) {
                continue;
            }

            $lookup[$actorId] = [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
                'role' => (string) ($user->role ?? ''),
            ];
        }

        return $lookup;
    }

}
