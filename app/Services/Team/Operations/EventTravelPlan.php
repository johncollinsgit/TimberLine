<?php

namespace App\Services\Team\Operations;

use Carbon\CarbonImmutable;

/** A reviewed driving estimate, never an inferred road speed or live traffic claim. */
class EventTravelPlan
{
    public function homeBase(array $areaSettings, array $tenantMetadata = []): array
    {
        $value = data_get($areaSettings, 'travel.home_base', data_get($tenantMetadata, 'teams.home_base', []));
        $address = is_array($value) && is_string($value['address'] ?? null) ? trim($value['address']) : null;
        $timezone = is_array($value) && in_array($value['timezone'] ?? null, timezone_identifiers_list(), true) ? $value['timezone'] : null;

        return ['address' => $address ?: null, 'timezone' => $timezone];
    }

    public function calculate(array $home, ?string $address, array $schedule, array $review = [], ?string $sourceVersion = null, bool $sourceCurrent = true): array
    {
        $missing = [];
        if (! $home['address']) {
            $missing[] = 'Add your home-base address.';
        }
        if (! $home['timezone']) {
            $missing[] = 'Choose the home-base timezone.';
        }
        if (! $address) {
            $missing[] = 'Add the event address.';
        }
        $reviewed = ! empty($review['reviewed_at']) && ! empty($review['reviewed_by']);
        $matches = $sourceCurrent && $reviewed && ($review['origin_address'] ?? null) === $home['address'] && ($review['origin_timezone'] ?? null) === $home['timezone']
            && ($review['destination_address'] ?? null) === $address && ($review['source_version'] ?? null) === $sourceVersion;
        if (! $sourceCurrent) {
            $missing[] = 'The event details changed or could not be checked. Refresh connected data before reviewing this trip.';
        }
        if ($reviewed && ! $matches) {
            $missing[] = 'The trip details changed. Review the driving times again.';
        }
        $outbound = $matches && is_int($review['outbound_minutes'] ?? null) && $review['outbound_minutes'] > 0 ? $review['outbound_minutes'] : null;
        $return = $matches && is_int($review['return_minutes'] ?? null) && $review['return_minutes'] > 0 ? $review['return_minutes'] : null;
        if ($outbound === null) {
            $missing[] = 'Review the drive time to the event.';
        }
        if ($return === null) {
            $missing[] = 'Review the drive time home.';
        }
        $timezone = in_array($schedule['timezone'] ?? null, timezone_identifiers_list(), true) ? $schedule['timezone'] : null;
        $evidence = collect($schedule['session_evidence'] ?? [])->keyBy('session_id');
        $allSessions = collect($schedule['sessions'] ?? []);
        $sessions = $allSessions->filter(fn ($s) => ! ($evidence[$s['id'] ?? '']['stale'] ?? false)
            && in_array($evidence[$s['id'] ?? '']['status'] ?? '', ['source_verified_at_review', 'staff_attested', 'staff_attested_external'], true)
            && in_array($s['kind'] ?? '', ['setup', 'selling'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s['date'] ?? ''))->values();
        $setup = $sessions->where('kind', 'setup')->filter(fn ($s) => ! empty($s['opens']));
        $arrivalSession = ($setup->isNotEmpty() ? $setup : $sessions->where('kind', 'selling'))->sortBy(fn ($s) => $s['date'].' '.($s['opens'] ?? '00:00'))->first();
        $lastSession = $sessions->where('kind', 'selling')->sortByDesc(fn ($s) => $s['date'].' '.($s['closes'] ?? '23:59'))->first();
        $arrival = $arrivalSession && $timezone ? $this->local($arrivalSession['date'], $arrivalSession['opens'] ?? null, $timezone) : null;
        $allSelling = $allSessions->where('kind', 'selling');
        $latestDate = $allSelling->pluck('date')->filter()->max();
        $unknownLastDay = $allSelling->contains(fn ($s) => empty($s['date'])) || ($lastSession && $latestDate > $lastSession['date'])
            || ($lastSession && ($schedule['legacy_ends_on'] ?? $lastSession['date']) > $lastSession['date']);
        $closes = $lastSession && $timezone && ! $unknownLastDay ? $this->local($lastSession['date'], $lastSession['closes'] ?? null, $timezone) : null;
        $hoursNeedConfirmation = ($arrivalSession && ($evidence[$arrivalSession['id']]['hours_verified'] ?? null) === false)
            || ($lastSession && ($evidence[$lastSession['id']]['hours_verified'] ?? null) === false);
        if (! $arrival) {
            $missing[] = 'Confirm the setup date and arrival time, or the first show opening time.';
        }
        if (! $closes) {
            $missing[] = 'Confirm the final show closing time.';
        }
        $teardown = is_int($review['teardown_minutes'] ?? null) && $review['teardown_minutes'] >= 0 && $review['teardown_minutes'] <= 1440 ? $review['teardown_minutes'] : 60;
        $buffer = is_int($review['arrival_buffer_minutes'] ?? null) && $review['arrival_buffer_minutes'] >= 0 && $review['arrival_buffer_minutes'] <= 1440 ? $review['arrival_buffer_minutes'] : 0;
        $arrivalBy = $arrival?->utc()->subMinutes($buffer)->setTimezone($timezone);
        $departure = $arrival && $outbound !== null && $home['address'] && $home['timezone'] && $address
            ? $arrival->utc()->subMinutes($outbound + $buffer)->setTimezone($home['timezone'])->toIso8601String() : null;
        $back = $closes && $return !== null && $home['address'] && $home['timezone'] && $address
            ? $closes->utc()->addMinutes($teardown + $return)->setTimezone($home['timezone'])->toIso8601String() : null;

        return ['home_base' => $home, 'outbound_minutes' => $outbound, 'return_minutes' => $return, 'departure_at' => $departure, 'return_at' => $back,
            'arrival_at' => $arrivalBy?->toIso8601String(), 'session_starts_at' => $arrival?->toIso8601String(), 'arrival_basis' => $arrivalSession['kind'] ?? null, 'event_closes_at' => $closes?->toIso8601String(), 'teardown_minutes' => $teardown, 'arrival_buffer_minutes' => $buffer,
            'outbound_directions_url' => $this->directions($home['address'], $address), 'return_directions_url' => $this->directions($address, $home['address']),
            'basis' => $matches ? 'reviewed_manual' : ($reviewed ? 'needs_review' : 'unavailable'),
            'route_review' => $matches ? ['reviewed_at' => $review['reviewed_at'], 'reason' => $review['reason'] ?? null] : null,
            'schedule_notice' => $hoursNeedConfirmation ? 'Confirm the recorded show hours before travelling.' : null,
            'notice' => 'Planning estimate using reviewed drive times; traffic and stops can change the journey. Return includes '.$teardown.' minutes for booth teardown.',
            'missing' => $missing];
    }

    public function sourceCurrent(\App\Models\TeamOperationRecord $event, ?string $admittedVersion): bool
    {
        $source = $event->connection_id !== null || isset($event->data['source_id']) || ($event->data['source_authority'] ?? null) === 'asana';

        return ! $source || (is_string($admittedVersion) && is_string($event->data['source_version'] ?? null) && hash_equals($event->data['source_version'], $admittedVersion));
    }

    private function directions(?string $from, ?string $to): ?string
    {
        return $from && $to ? 'https://www.google.com/maps/dir/?'.http_build_query(['api' => 1, 'origin' => $from, 'destination' => $to, 'travelmode' => 'driving'], '', '&', PHP_QUERY_RFC3986) : null;
    }

    /** Reject missing, nonexistent and ambiguous local hours before doing UTC arithmetic. */
    private function local(string $date, ?string $hour, string $timezone): ?CarbonImmutable
    {
        if (! $hour || ! preg_match('/^\d{2}:\d{2}$/D', $hour)) {
            return null;
        }
        try {
            $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$hour, 'UTC');
            if ($wall->format('Y-m-d H:i') !== $date.' '.$hour) {
                return null;
            }
            $transitions = (new \DateTimeZone($timezone))->getTransitions($wall->timestamp - 86400, $wall->timestamp + 86400);
            $offsets = array_unique(array_column($transitions, 'offset'));
            $matches = [];
            foreach ($offsets as $offset) {
                $candidate = CarbonImmutable::createFromTimestampUTC($wall->timestamp - $offset)->setTimezone($timezone);
                if ($candidate->format('Y-m-d H:i') === $date.' '.$hour) {
                    $matches[] = $candidate;
                }
            }

            return count($matches) === 1 ? $matches[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
