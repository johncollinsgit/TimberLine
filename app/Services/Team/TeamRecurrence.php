<?php

namespace App\Services\Team;

use App\Services\AiBranch\WorkspaceWallTime;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Calendar recurrence only. Nothing registers a scheduler, dispatches a job or calls a provider. */
class TeamRecurrence
{
    public function configure(array $task, array $input): array
    {
        if (array_diff(array_keys($input), ['frequency', 'interval', 'timezone', 'overlap', 'until_on', 'max_occurrences'])
            || ! in_array($input['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true)
            || ! is_int($input['interval'] ?? null) || $input['interval'] < 1 || $input['interval'] > 12
            || ! in_array($input['timezone'] ?? '', DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            || ! in_array($input['overlap'] ?? '', ['reject', 'earlier', 'later'], true)) {
            throw new DomainException('invalid_recurrence');
        }
        if (($task['due_on'] ?? $task['due_at'] ?? null) === null) {
            throw new DomainException('recurrence_requires_due_date');
        }
        $until = $input['until_on'] ?? null;
        $parsed = is_string($until) ? DateTimeImmutable::createFromFormat('!Y-m-d', $until) : false;
        if ($until !== null && (! $parsed || $parsed->format('Y-m-d') !== $until)) {
            throw new DomainException('invalid_recurrence_end');
        }
        $limit = $input['max_occurrences'] ?? null;
        if ($limit !== null && (! is_int($limit) || $limit < 1 || $limit > 1000)) {
            throw new DomainException('invalid_recurrence_end');
        }
        $rule = $input + ['until_on' => null, 'max_occurrences' => null];
        foreach (['start', 'due'] as $prefix) {
            $value = $task[$prefix.'_on'] ?? $task[$prefix.'_at'] ?? null;
            if ($value !== null) {
                $local = isset($task[$prefix.'_on']) ? new DateTimeImmutable($value.'T00:00:00Z') : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone($input['timezone']));
                if ($local->format('s') !== '00' || (int) $local->format('Y') < 2000 || (int) $local->format('Y') > 2099) {
                    throw new DomainException('recurrence_requires_minute_precision_2000_2099');
                }
                $rule[$prefix.'_anchor_day'] = (int) $local->format('d');
            }
        }

        return $rule;
    }

    public function next(array $task): ?array
    {
        $rule = $task['recurrence'];
        $index = (int) ($task['recurrence_index'] ?? 1) + 1;
        if (($rule['max_occurrences'] ?? null) !== null && $index > $rule['max_occurrences']) {
            return null;
        }
        $dates = [];
        foreach (['start', 'due'] as $prefix) {
            $on = $task[$prefix.'_on'] ?? null;
            $at = $task[$prefix.'_at'] ?? null;
            $dates[$prefix.'_on'] = $dates[$prefix.'_at'] = null;
            if ($on === null && $at === null) {
                continue;
            }
            $local = $on !== null ? new DateTimeImmutable($on.'T00:00:00Z') : (new DateTimeImmutable($at))->setTimezone(new DateTimeZone($rule['timezone']));
            // Do arithmetic on a naive UTC calendar; PHP must not silently normalize a DST gap.
            $calendar = new DateTimeImmutable($local->format('Y-m-d\TH:i:00').'Z');
            if ($rule['frequency'] === 'monthly') {
                $first = $calendar->modify('first day of this month')->modify('+'.$rule['interval'].' months');
                $next = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min($rule[$prefix.'_anchor_day'], (int) $first->format('t')));
            } else {
                $next = $calendar->modify('+'.($rule['interval'] * ($rule['frequency'] === 'weekly' ? 7 : 1)).' days');
            }
            if ((int) $next->format('Y') > 2099) {
                throw new DomainException('recurrence_requires_minute_precision_2000_2099');
            }
            if ($prefix === 'due' && ($rule['until_on'] ?? null) !== null && $next->format('Y-m-d') > $rule['until_on']) {
                return null;
            }
            $dates[$prefix.'_on'] = $on !== null ? $next->format('Y-m-d') : null;
            $dates[$prefix.'_at'] = $at !== null ? $this->wallTime($next, $rule) : null;
        }

        return $dates;
    }

    private function wallTime(DateTimeImmutable $calendar, array $rule): string
    {
        $zone = new DateTimeZone($rule['timezone']);
        $offsets = array_unique(array_column($zone->getTransitions($calendar->getTimestamp() - 172800, $calendar->getTimestamp() + 172800), 'offset'));
        $choices = [];
        foreach ($offsets as $seconds) {
            $offset = ($seconds < 0 ? '-' : '+').sprintf('%02d:%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
            try {
                $choices[] = (new WorkspaceWallTime)->resolve($calendar->format('Y-m-d\TH:i'), $rule['timezone'], $offset)['utc'];
            } catch (\Illuminate\Validation\ValidationException) {
                // An offset candidate may not apply. The shared resolver verifies actual wall time.
            }
        }
        sort($choices);
        if ($choices === []) {
            throw new DomainException('recurrence_dst_gap_review_required');
        }
        if (count($choices) > 1 && $rule['overlap'] === 'reject') {
            throw new DomainException('recurrence_dst_overlap_review_required');
        }

        return $rule['overlap'] === 'later' ? $choices[array_key_last($choices)] : $choices[0];
    }

    public static function stableId(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = '8';

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
