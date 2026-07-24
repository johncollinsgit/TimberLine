<?php

namespace App\Services\Automation\V2\Operations;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\Providers\CommerceOrderSourceClient;
use Carbon\CarbonImmutable;

abstract class AbstractCommerceOrderTriggerOperation implements TriggerOperation
{
    protected string $provider;

    public function __construct(
        protected CommerceOrderSourceClient $orders,
        protected PayloadFingerprint $fingerprints,
    ) {}

    public function poll(TriggerOperationContext $context): TriggerPollResult
    {
        $modifiedSince = $this->modifiedSince($context);
        $pollLimit = min(100, max(1, (int) ($context->config['poll_limit'] ?? 100)));
        $maxOrders = min($context->limit, max(1, (int) ($context->config['max_items_per_poll'] ?? $context->limit)));
        $scanLimit = min(5_000, max(1_000, $maxOrders * 10));
        $configuredLocations = (array) ($context->config['location_ids'] ?? []);
        if ($configuredLocations === [] && filled($context->config['location_id'] ?? null)) {
            $configuredLocations = [$context->config['location_id']];
        }
        $locationIds = array_values(array_filter(array_map('strval', $configuredLocations)));
        $poll = $this->orders->fetch(
            provider: $this->provider,
            tenantId: $context->tenantId,
            connectionId: $context->connectionId,
            modifiedSince: $modifiedSince,
            pollLimit: $pollLimit,
            maxOrders: $scanLimit,
            locationIds: $locationIds,
        );
        if ($poll['truncated']) {
            throw new AutomationWorkflowException(
                ucfirst($this->provider).' returned too many overlapping orders to advance the cursor safely.'
            );
        }
        [$cursorDate, $cursorSourceId] = $this->watermark($context->cursor);
        $orders = collect($poll['orders'])
            ->filter(function (array $order) use ($cursorDate, $cursorSourceId): bool {
                $sourceId = trim((string) ($order['source_id'] ?? ''));
                $updatedAt = $this->date((string) ($order['updated_at'] ?? ''));

                return $sourceId !== ''
                    && $updatedAt !== null
                    && $this->afterWatermark($updatedAt, $sourceId, $cursorDate, $cursorSourceId);
            })
            ->sort(function (array $left, array $right): int {
                $leftDate = $this->date((string) ($left['updated_at'] ?? ''));
                $rightDate = $this->date((string) ($right['updated_at'] ?? ''));
                if ($leftDate && $rightDate && ! $leftDate->equalTo($rightDate)) {
                    return $leftDate->lt($rightDate) ? -1 : 1;
                }

                return strcmp((string) ($left['source_id'] ?? ''), (string) ($right['source_id'] ?? ''));
            })
            ->values();
        $hasMore = $orders->count() > $maxOrders;
        $orders = $orders->take($maxOrders)->all();

        $events = [];
        $nextCursor = $context->cursor;
        foreach ($orders as $order) {
            $sourceId = trim((string) ($order['source_id'] ?? ''));
            if ($sourceId === '') {
                continue;
            }
            $scheduleSource = strtolower(trim((string) (
                $context->config['schedule_source']
                ?? 'fulfillment'
            )));
            $order['fulfillment_at'] = $scheduleSource === 'created_at'
                ? ($order['created_at'] ?? data_get($order, 'schedule.order_created'))
                : (
                    data_get($order, 'schedule.fulfillment')
                    ?? data_get($order, 'schedule.delivery')
                    ?? data_get($order, 'schedule.pickup')
                    ?? $order['created_at']
                    ?? data_get($order, 'schedule.order_created')
                );
            $fingerprint = $this->fingerprints->hash($order);
            $updatedAt = $this->date((string) ($order['updated_at'] ?? ''));
            if ($updatedAt !== null) {
                $nextCursor = $this->formatWatermark($updatedAt, $sourceId);
            }
            $events[] = new TriggerEvent(
                eventKey: $this->provider.'.order:'.hash('sha256', $sourceId.'|'.$fingerprint),
                sourceSystem: $this->provider.'_order',
                sourceId: $sourceId,
                sourceFingerprint: $fingerprint,
                payload: $order,
                occurredAt: $updatedAt,
            );
        }

        return new TriggerPollResult(
            events: $events,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            summary: ['scanned' => count($poll['orders']), 'fetched' => count($orders), 'emitted' => count($events)],
        );
    }

    protected function modifiedSince(TriggerOperationContext $context): CarbonImmutable
    {
        $lookbackDays = max(1, min(90, (int) ($context->config['bootstrap_lookback_days'] ?? 14)));
        $overlapMinutes = max(0, min(60, (int) ($context->config['modified_overlap_minutes'] ?? 5)));

        try {
            [$cursor] = $this->watermark($context->cursor);
            $cursor ??= CarbonImmutable::now()->subDays($lookbackDays);
        } catch (\Throwable) {
            $cursor = CarbonImmutable::now()->subDays($lookbackDays);
        }

        return $overlapMinutes > 0 ? $cursor->subMinutes($overlapMinutes) : $cursor;
    }

    protected function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0:?CarbonImmutable,1:?string} */
    protected function watermark(?string $cursor): array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') {
            return [null, null];
        }

        [$dateValue, $sourceId] = array_pad(explode('|', $cursor, 2), 2, null);

        return [$this->date((string) $dateValue), filled($sourceId) ? (string) $sourceId : null];
    }

    protected function afterWatermark(
        CarbonImmutable $updatedAt,
        string $sourceId,
        ?CarbonImmutable $cursorDate,
        ?string $cursorSourceId,
    ): bool {
        if ($cursorDate === null) {
            return true;
        }
        if ($updatedAt->gt($cursorDate)) {
            return true;
        }
        if (! $updatedAt->equalTo($cursorDate)) {
            return false;
        }
        if ($cursorSourceId === null) {
            return true;
        }

        return strcmp($sourceId, $cursorSourceId) > 0;
    }

    protected function formatWatermark(CarbonImmutable $updatedAt, string $sourceId): string
    {
        return $updatedAt->toIso8601String().'|'.$sourceId;
    }
}
