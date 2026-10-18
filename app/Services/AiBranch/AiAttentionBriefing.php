<?php

namespace App\Services\AiBranch;

use Carbon\CarbonImmutable;

class AiAttentionBriefing
{
    public const QUESTION = 'What needs my attention?';

    public function rank(array $source, string $timezone): int
    {
        $now = CarbonImmutable::now($timezone);
        $due = empty($source['due_at']) ? null : CarbonImmutable::parse($source['due_at'])->setTimezone($timezone);
        if (in_array($source['type'], ['task', 'followup'], true) && $due && $due->lt($now)) {
            return 0;
        }
        if ($source['type'] === 'job' && ($source['status'] ?? '') === 'blocked') {
            return 1;
        }
        if (in_array($source['type'], ['job', 'task', 'class', 'followup'], true) && $due) {
            if ($due->isSameDay($now)) {
                return 2;
            }
            if ($due->gt($now) && $due->lte($now->addDays(7))) {
                return 3;
            }
        }
        if ($source['type'] === 'connection' && in_array($source['status'], ['error', 'disconnected', 'expired'], true)) {
            return 4;
        }

        return 5;
    }

    public function ordered(array $sources, string $timezone): array
    {
        usort($sources, fn ($a, $b) => [$this->rank($a, $timezone), $this->dueOrder($a), (int) $a['id'], $a['ref']]
            <=> [$this->rank($b, $timezone), $this->dueOrder($b), (int) $b['id'], $b['ref']]);

        return $sources;
    }

    private function dueOrder(array $source): int
    {
        return empty($source['due_at']) ? PHP_INT_MAX : CarbonImmutable::parse($source['due_at'])->getTimestamp();
    }

    public function candidates(array $sources, string $timezone): array
    {
        return array_values(array_filter($this->ordered($sources, $timezone), fn ($source) => $this->rank($source, $timezone) < 5));
    }
}
