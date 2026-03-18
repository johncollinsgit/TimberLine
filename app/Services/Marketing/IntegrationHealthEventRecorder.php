<?php

namespace App\Services\Marketing;

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class IntegrationHealthEventRecorder
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes, int $dedupeWindowMinutes = 30): IntegrationHealthEvent
    {
        $provider = $this->normalizeToken($attributes['provider'] ?? null) ?? 'shopify';
        $eventType = $this->normalizeToken($attributes['event_type'] ?? null) ?? 'unspecified_event';
        $severity = $this->normalizeSeverity($attributes['severity'] ?? null);
        $status = $this->normalizeStatus($attributes['status'] ?? null);
        $storeKey = $this->normalizeStoreKey($attributes['store_key'] ?? null);
        $tenantId = $this->positiveInt($attributes['tenant_id'] ?? null);
        $shopifyStoreId = $this->positiveInt($attributes['shopify_store_id'] ?? null);
        $context = $this->normalizeContext($attributes['context'] ?? []);
        $occurredAt = $this->coerceDate($attributes['occurred_at'] ?? null) ?? now()->toImmutable();
        $relatedModelType = $this->nullableString($attributes['related_model_type'] ?? null);
        $relatedModelId = $this->positiveInt($attributes['related_model_id'] ?? null);

        [$tenantId, $shopifyStoreId, $storeKey] = $this->resolveStoreContext($tenantId, $shopifyStoreId, $storeKey);
        $dedupeKey = $this->normalizeDedupeKey($attributes['dedupe_key'] ?? null) ?? $this->buildDedupeKey(
            provider: $provider,
            eventType: $eventType,
            storeKey: $storeKey,
            relatedModelType: $relatedModelType,
            relatedModelId: $relatedModelId,
            context: $context
        );

        $windowStart = now()->subMinutes(max(1, $dedupeWindowMinutes));

        $existing = IntegrationHealthEvent::query()
            ->where('provider', $provider)
            ->where('event_type', $eventType)
            ->where('status', 'open')
            ->where('dedupe_key', $dedupeKey)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($tenantId === null, fn (Builder $query) => $query->whereNull('tenant_id'))
            ->when($storeKey !== null, fn (Builder $query) => $query->where('store_key', $storeKey))
            ->when($storeKey === null, fn (Builder $query) => $query->whereNull('store_key'))
            ->where('occurred_at', '>=', $windowStart)
            ->latest('occurred_at')
            ->first();

        if ($existing) {
            $mergedContext = $this->mergeContext(
                is_array($existing->context) ? $existing->context : [],
                $context
            );
            $mergedContext['_occurrences'] = (int) data_get($existing->context, '_occurrences', 1) + 1;
            $mergedContext['_last_recorded_at'] = now()->toIso8601String();

            $existing->forceFill([
                'severity' => $this->strongerSeverity((string) $existing->severity, $severity),
                'status' => $status,
                'tenant_id' => $tenantId,
                'shopify_store_id' => $shopifyStoreId,
                'store_key' => $storeKey,
                'related_model_type' => $relatedModelType ?: $existing->related_model_type,
                'related_model_id' => $relatedModelId ?: $existing->related_model_id,
                'context' => $mergedContext !== [] ? $mergedContext : null,
                'occurred_at' => $occurredAt,
                'resolved_at' => $status === 'resolved' ? ($existing->resolved_at ?: $occurredAt) : null,
            ])->save();

            return $existing->fresh() ?? $existing;
        }

        $event = IntegrationHealthEvent::query()->create([
            'tenant_id' => $tenantId,
            'shopify_store_id' => $shopifyStoreId,
            'store_key' => $storeKey,
            'provider' => $provider,
            'event_type' => $eventType,
            'severity' => $severity,
            'status' => $status,
            'dedupe_key' => $dedupeKey,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
            'context' => array_merge(
                ['_occurrences' => 1, '_last_recorded_at' => now()->toIso8601String()],
                $context
            ),
            'occurred_at' => $occurredAt,
            'resolved_at' => $status === 'resolved' ? $occurredAt : null,
        ]);

        return $event;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function resolve(array $filters): int
    {
        $provider = $this->normalizeToken($filters['provider'] ?? null);
        $eventTypes = collect((array) ($filters['event_type'] ?? $filters['event_types'] ?? []))
            ->map(fn (mixed $value): ?string => $this->normalizeToken($value))
            ->filter()
            ->values()
            ->all();
        if ($eventTypes === []) {
            $singleType = $this->normalizeToken($filters['event_type'] ?? null);
            if ($singleType !== null) {
                $eventTypes[] = $singleType;
            }
        }

        $tenantId = $this->positiveInt($filters['tenant_id'] ?? null);
        $shopifyStoreId = $this->positiveInt($filters['shopify_store_id'] ?? null);
        $storeKey = $this->normalizeStoreKey($filters['store_key'] ?? null);
        $dedupeKey = $this->normalizeDedupeKey($filters['dedupe_key'] ?? null);

        $query = IntegrationHealthEvent::query()
            ->where('status', 'open');

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        if ($eventTypes !== []) {
            $query->whereIn('event_type', $eventTypes);
        }

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($shopifyStoreId !== null) {
            $query->where('shopify_store_id', $shopifyStoreId);
        }

        if ($storeKey !== null) {
            $query->where('store_key', $storeKey);
        }

        if ($dedupeKey !== null) {
            $query->where('dedupe_key', $dedupeKey);
        }

        return (int) $query->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function normalizeContext(mixed $context): array
    {
        if (! is_array($context)) {
            return [];
        }

        return collect($context)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): mixed => is_scalar($value) || is_array($value) ? $value : (string) $value)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $incoming
     * @return array<string,mixed>
     */
    protected function mergeContext(array $existing, array $incoming): array
    {
        if ($incoming === []) {
            return $existing;
        }

        return array_merge($existing, $incoming);
    }

    protected function strongerSeverity(string $left, string $right): string
    {
        $rank = ['info' => 1, 'warning' => 2, 'error' => 3];
        $leftRank = $rank[$left] ?? 1;
        $rightRank = $rank[$right] ?? 1;

        return $rightRank >= $leftRank ? $right : $left;
    }

    protected function normalizeSeverity(mixed $value): string
    {
        $severity = $this->normalizeToken($value) ?? 'info';

        return in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'info';
    }

    protected function normalizeStatus(mixed $value): string
    {
        $status = $this->normalizeToken($value) ?? 'open';

        return in_array($status, ['open', 'resolved'], true) ? $status : 'open';
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        return $this->normalizeToken($value);
    }

    protected function normalizeDedupeKey(mixed $value): ?string
    {
        $key = trim((string) $value);

        return $key !== '' ? Str::lower($key) : null;
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = Str::lower(trim((string) $value));

        return $token !== '' ? $token : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cast = (int) $value;

        return $cast > 0 ? $cast : null;
    }

    protected function coerceDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function buildDedupeKey(
        string $provider,
        string $eventType,
        ?string $storeKey,
        ?string $relatedModelType,
        ?int $relatedModelId,
        array $context
    ): string {
        $scopeParts = [
            'provider' => $provider,
            'event_type' => $eventType,
            'store_key' => $storeKey,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
            'topic' => $this->normalizeToken($context['topic'] ?? null),
            'reason' => $this->normalizeToken($context['reason'] ?? null),
            'source_id' => $this->nullableString($context['source_id'] ?? null),
        ];

        return sha1(json_encode($scopeParts));
    }

    /**
     * @return array{0:?int,1:?int,2:?string}
     */
    protected function resolveStoreContext(?int $tenantId, ?int $shopifyStoreId, ?string $storeKey): array
    {
        if ($shopifyStoreId !== null) {
            $store = ShopifyStore::query()->find($shopifyStoreId);
            if ($store) {
                return [
                    $tenantId ?: ($store->tenant_id ? (int) $store->tenant_id : null),
                    (int) $store->id,
                    $storeKey ?: $this->normalizeStoreKey($store->store_key),
                ];
            }
        }

        if ($storeKey !== null) {
            $store = ShopifyStore::query()
                ->where('store_key', $storeKey)
                ->first();
            if ($store) {
                return [
                    $tenantId ?: ($store->tenant_id ? (int) $store->tenant_id : null),
                    $shopifyStoreId ?: (int) $store->id,
                    $storeKey,
                ];
            }
        }

        return [$tenantId, $shopifyStoreId, $storeKey];
    }
}

