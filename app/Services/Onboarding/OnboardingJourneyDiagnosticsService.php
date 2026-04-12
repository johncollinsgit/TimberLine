<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Models\TenantOnboardingJourneyEvent;
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
}
