<?php

namespace App\Services\Team;

use DateTimeImmutable;
use DateTimeZone;

/** Operates only on the graph's permission-filtered task projection. No independent task store. */
class TeamTaskFeed
{
    public function day(array $task, string $timezone): ?string
    {
        return $task['due_on'] ?? (isset($task['due_at']) ? (new DateTimeImmutable($task['due_at']))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d') : null);
    }

    public function matches(array $task, string $filter, DateTimeImmutable $now, string $timezone): bool
    {
        $local = $now->setTimezone(new DateTimeZone($timezone));
        $today = $local->format('Y-m-d');
        $day = $this->day($task, $timezone);

        return match ($filter) {
            'today' => $day === $today,
            'tomorrow' => $day === $local->modify('+1 day')->format('Y-m-d'),
            'overdue' => $day !== null && ($task['completed_at'] ?? null) === null && $day < $today,
            'week' => $day !== null && $day >= $local->modify('monday this week')->format('Y-m-d') && $day <= $local->modify('sunday this week')->format('Y-m-d'),
            'undated' => $day === null,
            default => true,
        };
    }

    public function home(array $tasks, string $userId, DateTimeImmutable $now, string $timezone): array
    {
        $mine = array_values(array_filter($tasks, fn ($task) => $task['assignee_id'] === $userId && $task['completed_at'] === null));
        $counts = ['all' => count($mine)];
        foreach (['today', 'tomorrow', 'overdue', 'week', 'undated'] as $filter) {
            $counts[$filter] = count(array_filter($mine, fn ($task) => $this->matches($task, $filter, $now, $timezone)));
        }

        return ['counts' => $counts, 'today' => $now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d'), 'timezone' => $timezone,
            'coverage' => 'all_accessible_assigned_incomplete_tasks', 'week' => 'ISO Monday through Sunday; counts may overlap'];
    }

    public function sort(array $tasks, string $sort, string $timezone): array
    {
        $priority = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        $key = fn ($task) => match ($sort) {
            'due' => [$this->day($task, $timezone) ?? '9999-12-31', isset($task['due_at']) ? (new DateTimeImmutable($task['due_at']))->getTimestamp() : 0, $task['id']],
            'start' => [$task['start_on'] ?? (isset($task['start_at']) ? (new DateTimeImmutable($task['start_at']))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d') : '9999-12-31'), $task['id']],
            'priority' => [$priority[$task['priority']], $this->day($task, $timezone) ?? '9999-12-31', $task['id']],
            'created' => [$task['created_at'], $task['id']],
            default => [strtolower($task['title']), $task['id']],
        };
        usort($tasks, fn ($a, $b) => $key($a) <=> $key($b));

        return $tasks;
    }
}
