<?php

namespace App\Services\Team\Search;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Services\Team\Operations\EventSchedule;
use Carbon\CarbonImmutable;
use DateTimeZone;

/** Date preference supplements relevance; it never grants access or invents selling days. */
class EventSearchOrder
{
    public const VERSION = 1;

    public function context(Tenant $tenant): array
    {
        $metadata = TenantAccessProfile::where('tenant_id', $tenant->id)->value('metadata');
        $zone = $this->zone(data_get($metadata, 'ai_branch.timezone'), $this->zone(config('app.timezone'), 'UTC'));
        $context = ['version' => self::VERSION, 'timezone' => $zone, 'today' => CarbonImmutable::now($zone)->toDateString(), 'areas' => []];
        foreach (TeamArea::where('tenant_id', $tenant->id)->where('profile', 'events')->get(['id', 'settings']) as $area) {
            $zone = $this->zone($area->settings['timezone'] ?? null, $context['timezone']);
            $context['areas'][$area->id] = ['timezone' => $zone, 'today' => CarbonImmutable::now($zone)->toDateString()];
        }
        ksort($context['areas']);

        return $context;
    }

    private function zone(mixed $zone, string $fallback): string
    {
        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? $zone : $fallback;
    }

    private function day(mixed $day): ?string
    {
        return is_string($day) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $day : null;
    }

    public function dates(TeamOperationRecord $record, ?array $data = null): array
    {
        $calendar = app(EventSchedule::class)->calendar($data ?? $record->data ?? [], $record->starts_on?->toDateString(), $record->ends_on?->toDateString());
        $evidence = collect($calendar['session_evidence'] ?? [])->keyBy('session_id');
        $days = [];
        foreach ($calendar['sessions'] as $session) {
            $proof = $evidence[$session['id']] ?? [];
            if (empty($proof['stale']) && in_array($proof['status'] ?? '', ['source_verified_at_review', 'staff_attested', 'staff_attested_external'], true) && ($day = $this->day($session['date'] ?? null))) {
                $days[] = $day;
            }
        }
        $basis = $days ? 'reviewed_sessions' : 'recorded_bounds';
        // A partial setup review cannot erase the recorded end of the occurrence.
        $recordedEnd = $this->day($record->ends_on?->toDateString());
        if ($days && ! $calendar['schedule_complete'] && $recordedEnd && $recordedEnd > max($days)) {
            $days[] = $recordedEnd;
            $basis = 'reviewed_sessions_and_recorded_end';
        }
        if (! $days) {
            $days = array_filter([$this->day($record->starts_on?->toDateString()), $this->day($record->ends_on?->toDateString())]);
        }
        sort($days);

        return ['starts_on' => $days[0] ?? null, 'ends_on' => $days ? $days[count($days) - 1] : null, 'dates' => array_values(array_unique($days)), 'basis' => $days ? $basis : 'unknown'];
    }

    public function decorate(array $item, array $context): array
    {
        if ($item['type'] !== 'event' || ! isset($item['event_occurrence'])) {
            return $item;
        }
        $local = $context['areas'][$item['target']['area'] ?? ''] ?? $context;
        $occurrence = $item['event_occurrence'];
        $today = $local['today'];
        $next = collect($occurrence['dates'])->first(fn ($day) => $day >= $today);
        $status = ! $occurrence['ends_on'] ? 'unknown' : ($occurrence['ends_on'] < $today ? 'past' : ($occurrence['starts_on'] > $today ? 'upcoming' : 'ongoing'));
        $item['event_occurrence'] = [...$occurrence, 'status' => $status, 'next_on' => $next, 'today' => $today, 'timezone' => $local['timezone']];

        return $item;
    }

    public function rank(array $base, array $item, string $query): array
    {
        $occurrence = $item['event_occurrence'] ?? null;
        $bucket = $date = 0;
        if ($item['type'] === 'event' && $occurrence) {
            preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $query, $years);
            if ($years[0]) {
                $matchedYear = array_intersect($years[0], array_map(fn ($day) => substr($day, 0, 4), $occurrence['dates']));
                $bucket = $matchedYear ? 0 : 1;
            } else {
                $bucket = ['ongoing' => 0, 'upcoming' => 1, 'unknown' => 2, 'past' => 3][$occurrence['status']];
                $date = $occurrence['status'] === 'upcoming' ? (int) str_replace('-', '', $occurrence['next_on'] ?? $occurrence['starts_on']) : ($occurrence['status'] === 'past' ? -(int) str_replace('-', '', $occurrence['ends_on']) : 0);
            }
        }

        return [$base[0], $base[1], $bucket, $date, ...array_slice($base, 2)];
    }
}
