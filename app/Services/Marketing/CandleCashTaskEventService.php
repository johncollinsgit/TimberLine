<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\MarketingProfile;

class CandleCashTaskEventService
{
    public function record(
        CandleCashTask $task,
        ?MarketingProfile $profile,
        array $payload = []
    ): array {
        $eventKey = trim((string) ($payload['source_event_key'] ?? ''));
        if ($eventKey === '') {
            $eventKey = trim((string) ($payload['source_id'] ?? ''));
        }

        if ($eventKey === '') {
            $eventKey = trim((string) ($payload['request_key'] ?? ''));
        }

        if ($eventKey === '') {
            $eventKey = strtolower($task->handle . ':profile:' . (int) ($profile?->id ?? 0) . ':fallback');
        }

        /** @var CandleCashTaskEvent|null $existing */
        $existing = CandleCashTaskEvent::query()
            ->where('candle_cash_task_id', $task->id)
            ->where('source_event_key', $eventKey)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'duplicate_hits' => (int) $existing->duplicate_hits + 1,
                'duplicate_last_seen_at' => now(),
                'metadata' => array_merge((array) $existing->metadata, [
                    'last_duplicate_payload' => (array) ($payload['metadata'] ?? []),
                ]),
            ])->save();

            return ['event' => $existing->fresh(), 'duplicate' => true];
        }

        $event = CandleCashTaskEvent::query()->create([
            'candle_cash_task_id' => $task->id,
            'marketing_profile_id' => $profile?->id,
            'verification_mode' => trim((string) ($payload['verification_mode'] ?? $task->verification_mode ?: 'manual_review_fallback')),
            'source_type' => trim((string) ($payload['source_type'] ?? 'task_event')) ?: null,
            'source_id' => trim((string) ($payload['source_id'] ?? '')) ?: null,
            'source_event_key' => $eventKey,
            'status' => trim((string) ($payload['status'] ?? 'received')) ?: 'received',
            'reward_awarded' => false,
            'blocked_reason' => trim((string) ($payload['blocked_reason'] ?? '')) ?: null,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
        ]);

        return ['event' => $event, 'duplicate' => false];
    }

    public function markBlocked(CandleCashTaskEvent $event, string $reason, ?array $metadata = null): CandleCashTaskEvent
    {
        $event->forceFill([
            'status' => 'blocked',
            'blocked_reason' => $reason,
            'processed_at' => now(),
            'metadata' => $metadata !== null ? array_merge((array) $event->metadata, $metadata) : $event->metadata,
        ])->save();

        return $event->fresh();
    }

    public function markPending(CandleCashTaskEvent $event, ?CandleCashTaskCompletion $completion = null, ?array $metadata = null): CandleCashTaskEvent
    {
        $event->forceFill([
            'status' => 'pending',
            'candle_cash_task_completion_id' => $completion?->id ?: $event->candle_cash_task_completion_id,
            'processed_at' => now(),
            'metadata' => $metadata !== null ? array_merge((array) $event->metadata, $metadata) : $event->metadata,
        ])->save();

        return $event->fresh();
    }

    public function markAwarded(CandleCashTaskEvent $event, ?CandleCashTaskCompletion $completion = null, ?array $metadata = null): CandleCashTaskEvent
    {
        $event->forceFill([
            'status' => 'awarded',
            'reward_awarded' => true,
            'candle_cash_task_completion_id' => $completion?->id ?: $event->candle_cash_task_completion_id,
            'processed_at' => now(),
            'awarded_at' => now(),
            'metadata' => $metadata !== null ? array_merge((array) $event->metadata, $metadata) : $event->metadata,
        ])->save();

        return $event->fresh();
    }
}
