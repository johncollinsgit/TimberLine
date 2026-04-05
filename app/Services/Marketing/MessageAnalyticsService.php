<?php

namespace App\Services\Marketing;

use App\Models\MarketingDeliveryEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MessageAnalyticsService
{
    public function __construct(
        protected MessageLinkAggregationService $messageLinkAggregationService
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function normalizeFilters(array $input): array
    {
        $now = now()->toImmutable();
        $defaultFrom = $now->subDays(30)->startOfDay();
        $defaultTo = $now->endOfDay();

        $dateFrom = $this->parseDate(data_get($input, 'date_from'), $defaultFrom)?->startOfDay() ?? $defaultFrom;
        $dateTo = $this->parseDate(data_get($input, 'date_to'), $defaultTo)?->endOfDay() ?? $defaultTo;

        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        $channel = strtolower(trim((string) data_get($input, 'channel', 'all')));
        if (! in_array($channel, ['all', 'email', 'sms'], true)) {
            $channel = 'all';
        }

        $opened = strtolower(trim((string) data_get($input, 'opened', 'all')));
        if (! in_array($opened, ['all', 'opened', 'not_opened'], true)) {
            $opened = 'all';
        }

        $clicked = strtolower(trim((string) data_get($input, 'clicked', 'all')));
        if (! in_array($clicked, ['all', 'clicked', 'not_clicked'], true)) {
            $clicked = 'all';
        }

        $perPage = max(10, min(100, (int) data_get($input, 'per_page', 25)));
        $page = max(1, (int) data_get($input, 'page', 1));

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'channel' => $channel,
            'q' => $this->nullableString(data_get($input, 'q')),
            'opened' => $opened,
            'clicked' => $clicked,
            'has_orders' => $this->truthy(data_get($input, 'has_orders')),
            'url_search' => $this->nullableString(data_get($input, 'url_search')),
            'customer' => $this->nullableString(data_get($input, 'customer')),
            'message' => $this->nullableString(data_get($input, 'message')),
            'per_page' => $perPage,
            'page' => $page,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function index(?int $tenantId, ?string $storeKey, array $filters): array
    {
        $normalizedStoreKey = $this->nullableString($storeKey);
        if ($tenantId === null || $normalizedStoreKey === null) {
            $emptyPaginator = new LengthAwarePaginator([], 0, (int) ($filters['per_page'] ?? 25), (int) ($filters['page'] ?? 1), [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);

            return [
                'summary' => $this->emptySummary(),
                'messages' => $emptyPaginator,
                'chart' => $this->emptyChart((array) $filters),
                'history_outcomes' => $this->emptyHistoryOutcomes(),
                'diagnostics' => [
                    'reason' => 'tenant_or_store_missing',
                ],
                'raw' => [
                    'email_deliveries' => collect(),
                    'sms_deliveries' => collect(),
                    'engagement_events' => collect(),
                    'order_attributions' => collect(),
                ],
            ];
        }

        $dataset = $this->buildDataset($tenantId, $normalizedStoreKey, $filters);

        $rows = collect((array) ($dataset['rows'] ?? []));
        $filteredRows = $this->applyOperationalFilters($rows, $tenantId, (array) $filters);
        $sortedRows = $filteredRows
            ->sortByDesc(fn (array $row): string => (string) ($row['sent_at'] ?? ''))
            ->values();

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $total = $sortedRows->count();
        $offset = max(0, ($page - 1) * $perPage);
        $pageRows = $sortedRows->slice($offset, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $pageRows->all(),
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        return [
            'summary' => $this->summaryFromRows($sortedRows),
            'messages' => $paginator,
            'chart' => $this->chartFromDataset($dataset, $filters),
            'history_outcomes' => $this->historyOutcomes($tenantId, $normalizedStoreKey, $dataset, $filters),
            'diagnostics' => [
                'total_rows' => $rows->count(),
                'filtered_rows' => $sortedRows->count(),
            ],
            'raw' => [
                'email_deliveries' => $dataset['email_deliveries'] ?? collect(),
                'sms_deliveries' => $dataset['sms_deliveries'] ?? collect(),
                'engagement_events' => $dataset['engagement_events'] ?? collect(),
                'order_attributions' => $dataset['order_attributions'] ?? collect(),
            ],
        ];
    }

    public function detail(?int $tenantId, ?string $storeKey, string $messageKey): ?array
    {
        $normalizedStoreKey = $this->nullableString($storeKey);
        if ($tenantId === null || $normalizedStoreKey === null) {
            return null;
        }

        $parsed = $this->parseMessageKey($messageKey);
        if (! is_array($parsed)) {
            return null;
        }

        $channel = (string) $parsed['channel'];
        $batchKey = (string) $parsed['batch_key'];

        $deliveries = $channel === 'email'
            ? $this->emailDeliveriesForMessageKey($tenantId, $normalizedStoreKey, $batchKey)
            : $this->smsDeliveriesForMessageKey($tenantId, $normalizedStoreKey, $batchKey);

        if ($deliveries->isEmpty()) {
            return null;
        }

        $deliveryIds = $deliveries
            ->pluck('id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        $profileIds = $deliveries
            ->pluck('marketing_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $profileIds->all())
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);
        $profileMap = $profiles->keyBy('id');

        $sentAtValues = $deliveries
            ->map(fn ($row) => $row->sent_at ?? $row->created_at)
            ->filter();

        $firstDelivery = $deliveries->sortBy('id')->first();
        $messageName = $this->resolvedMessageNameFromDelivery($firstDelivery, $channel);

        $statusCounts = $deliveries
            ->groupBy(fn ($row): string => $channel === 'email'
                ? strtolower(trim((string) ($row->status ?? 'sent')))
                : strtolower(trim((string) ($row->send_status ?? 'sent'))))
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        $detail = [
            'message_key' => $messageKey,
            'channel' => $channel,
            'message_name' => $messageName,
            'batch_key' => $batchKey,
            'source_label' => $this->resolvedSourceLabel($firstDelivery),
            'status' => $this->resolvedStatus($statusCounts),
            'recipients_count' => $deliveries->count(),
            'sent_at' => optional($sentAtValues->min())->toIso8601String(),
            'last_sent_at' => optional($sentAtValues->max())->toIso8601String(),
            'metadata' => [
                'channel' => strtoupper($channel),
                'batch_id' => $this->nullableString($firstDelivery?->batch_id),
                'store_key' => $normalizedStoreKey,
                'subject' => $this->nullableString($firstDelivery?->message_subject),
                'source_label' => $this->resolvedSourceLabel($firstDelivery),
            ],
            'opens_timeline' => [],
            'links' => [],
            'orders' => [],
        ];

        $engagementEvents = Schema::hasTable('marketing_message_engagement_events')
            ? MarketingMessageEngagementEvent::query()
                ->forTenantId($tenantId)
                ->where('store_key', $normalizedStoreKey)
                ->whereIn('event_type', ['open', 'click'])
                ->when($channel === 'email', function (Builder $query) use ($deliveryIds): void {
                    $query->whereIn('marketing_email_delivery_id', $deliveryIds->all());
                }, function (Builder $query) use ($deliveryIds): void {
                    $query->whereIn('marketing_message_delivery_id', $deliveryIds->all());
                })
                ->get([
                    'id',
                    'marketing_email_delivery_id',
                    'marketing_message_delivery_id',
                    'marketing_profile_id',
                    'event_type',
                    'link_label',
                    'url',
                    'normalized_url',
                    'occurred_at',
                ])
            : collect();

        $openEvents = $engagementEvents
            ->filter(function (MarketingMessageEngagementEvent $row): bool {
                return strtolower(trim((string) ($row->event_type ?? ''))) === 'open';
            })
            ->values();
        $clickEvents = $engagementEvents
            ->filter(function (MarketingMessageEngagementEvent $row): bool {
                return strtolower(trim((string) ($row->event_type ?? ''))) === 'click';
            })
            ->values();
        $openTimeline = $openEvents
            ->groupBy(fn ($row): string => optional($row->occurred_at)->format('Y-m-d') ?: 'unknown')
            ->map(fn (Collection $group, string $date): array => [
                'date' => $date,
                'count' => $group->count(),
            ])
            ->sortBy('date')
            ->values()
            ->all();

        if ($openTimeline === [] && $channel === 'email') {
            $openTimeline = $deliveries
                ->filter(fn ($row): bool => $row->opened_at !== null)
                ->groupBy(fn ($row): string => optional($row->opened_at)->format('Y-m-d') ?: 'unknown')
                ->map(fn (Collection $group, string $date): array => [
                    'date' => $date,
                    'count' => $group->count(),
                ])
                ->sortBy('date')
                ->values()
                ->all();
        }

        $hasOrderAttributionTable = Schema::hasTable('marketing_message_order_attributions');
        $hasSmsDeliveryAttributionColumn = $hasOrderAttributionTable
            && Schema::hasColumn('marketing_message_order_attributions', 'marketing_message_delivery_id');
        $orderAttributions = $hasOrderAttributionTable
            ? MarketingMessageOrderAttribution::query()
                ->forTenantId($tenantId)
                ->where('store_key', $normalizedStoreKey)
                ->where(function (Builder $query) use ($channel, $deliveryIds, $hasSmsDeliveryAttributionColumn): void {
                    if ($channel === 'email') {
                        $query->whereIn('marketing_email_delivery_id', $deliveryIds->all());

                        return;
                    }

                    if (! $hasSmsDeliveryAttributionColumn) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->whereIn('marketing_message_delivery_id', $deliveryIds->all());
                })
                ->with([
                    'order:id,tenant_id,order_number,order_label,customer_name,total_price,ordered_at,created_at',
                    'profile:id,tenant_id,first_name,last_name,email,phone',
                ])
                ->orderByDesc('order_occurred_at')
                ->orderByDesc('id')
                ->get()
            : collect();

        $links = $this->messageLinkAggregationService->aggregate($clickEvents, $orderAttributions);

        $orderRows = $orderAttributions
            ->map(function (MarketingMessageOrderAttribution $attribution) use ($profileMap): array {
                $profile = $attribution->profile;
                $profileId = (int) ($attribution->marketing_profile_id ?? 0);
                if (! $profile instanceof MarketingProfile && $profileId > 0) {
                    $profile = $profileMap->get($profileId);
                }

                $order = $attribution->order;
                $profileName = $profile instanceof MarketingProfile
                    ? trim((string) $profile->first_name.' '.(string) $profile->last_name)
                    : '';

                return [
                    'order_id' => (int) ($attribution->order_id ?? 0),
                    'order_number' => $this->nullableString($order?->order_number) ?? $this->nullableString($order?->order_label),
                    'customer' => $profileName !== ''
                        ? $profileName
                        : ($this->nullableString($order?->customer_name) ?? 'Customer'),
                    'customer_email' => $this->nullableString($profile?->email),
                    'url' => $this->nullableString($attribution->attributed_url),
                    'click_at' => optional($attribution->click_occurred_at)->toIso8601String(),
                    'ordered_at' => optional($attribution->order_occurred_at)->toIso8601String(),
                    'revenue_cents' => (int) ($attribution->revenue_cents ?? 0),
                ];
            })
            ->take(120)
            ->values()
            ->all();

        $detail['delivered'] = $deliveries->filter(function ($row) use ($channel): bool {
            if ($channel === 'email') {
                $status = strtolower(trim((string) ($row->status ?? '')));

                return $row->delivered_at !== null || in_array($status, ['delivered', 'opened', 'clicked'], true);
            }

            $status = strtolower(trim((string) ($row->send_status ?? '')));

            return $row->delivered_at !== null || $status === 'delivered';
        })->count();

        $openCount = $openEvents->count();
        $clickCount = $clickEvents->count();
        $detail['opens'] = $openCount > 0
            ? $openCount
            : ($channel === 'email'
                ? $deliveries->filter(fn ($row): bool => $row->opened_at !== null)->count()
                : 0);
        $detail['unique_opens'] = $openCount > 0
            ? $openEvents
                ->pluck('marketing_profile_id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->unique()
                ->count()
            : ($channel === 'email'
                ? $deliveries
                    ->filter(fn ($row): bool => $row->opened_at !== null)
                    ->pluck('marketing_profile_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->count()
                : 0);
        $detail['clicks'] = $clickCount > 0
            ? $clickCount
            : ($channel === 'email'
                ? $deliveries->filter(fn ($row): bool => $row->clicked_at !== null)->count()
                : 0);
        $detail['unique_clicks'] = $clickCount > 0
            ? $clickEvents
                ->pluck('marketing_profile_id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->unique()
                ->count()
            : ($channel === 'email'
                ? $deliveries
                    ->filter(fn ($row): bool => $row->clicked_at !== null)
                    ->pluck('marketing_profile_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->count()
                : 0);
        $detail['attributed_orders'] = $orderAttributions
            ->pluck('order_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->count();
        $detail['attributed_revenue_cents'] = (int) $orderAttributions->sum('revenue_cents');
        $detail['opens_timeline'] = $openTimeline;
        $detail['links'] = $links;
        $detail['orders'] = $orderRows;

        return $detail;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function buildDataset(int $tenantId, string $storeKey, array $filters): array
    {
        $from = $filters['date_from'] instanceof CarbonImmutable
            ? $filters['date_from']
            : now()->toImmutable()->subDays(30)->startOfDay();
        $to = $filters['date_to'] instanceof CarbonImmutable
            ? $filters['date_to']
            : now()->toImmutable()->endOfDay();
        $channel = strtolower(trim((string) ($filters['channel'] ?? 'all')));
        $query = $this->nullableString($filters['q'] ?? null);

        $emailDeliveries = $channel === 'sms'
            ? collect()
            : $this->emailDeliveriesQuery($tenantId, $storeKey, $from, $to, $query)
                ->get();

        $smsDeliveries = $channel === 'email'
            ? collect()
            : $this->smsDeliveriesQuery($tenantId, $storeKey, $from, $to, $query)
                ->get();

        $rows = [];
        $deliveryKeyByEmailDeliveryId = [];
        $deliveryKeyBySmsDeliveryId = [];

        foreach ($emailDeliveries as $delivery) {
            $messageKey = $this->messageKey('email', $this->batchKey('email', $delivery->batch_id, (int) $delivery->id));
            if (! isset($rows[$messageKey])) {
                $rows[$messageKey] = $this->emptyMessageRow('email', $messageKey);
            }

            $rows[$messageKey]['delivery_ids'][] = (int) $delivery->id;
            $rows[$messageKey]['recipients_count']++;
            $rows[$messageKey]['message_name'] = $rows[$messageKey]['message_name'] !== ''
                ? $rows[$messageKey]['message_name']
                : $this->resolvedMessageNameFromDelivery($delivery, 'email');
            $rows[$messageKey]['source_label'] = $rows[$messageKey]['source_label'] !== ''
                ? $rows[$messageKey]['source_label']
                : $this->resolvedSourceLabel($delivery);
            $rows[$messageKey]['status_counts'][strtolower(trim((string) ($delivery->status ?? 'sent')))]
                = ((int) ($rows[$messageKey]['status_counts'][strtolower(trim((string) ($delivery->status ?? 'sent')))] ?? 0)) + 1;
            $rows[$messageKey]['delivered_count'] += $delivery->delivered_at !== null
                || in_array(strtolower(trim((string) ($delivery->status ?? ''))), ['delivered', 'opened', 'clicked'], true)
                ? 1
                : 0;
            $rows[$messageKey]['fallback_open_count'] += $delivery->opened_at !== null ? 1 : 0;
            $rows[$messageKey]['fallback_click_count'] += $delivery->clicked_at !== null ? 1 : 0;
            $rows[$messageKey]['sent_at'] = $this->maxDateString(
                $rows[$messageKey]['sent_at'],
                optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String()
            );
            $rows[$messageKey]['first_sent_at'] = $this->minDateString(
                $rows[$messageKey]['first_sent_at'],
                optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String()
            );

            $profileId = (int) ($delivery->marketing_profile_id ?? 0);
            if ($profileId > 0) {
                $rows[$messageKey]['profile_ids'][$profileId] = true;
            }

            if ($delivery->opened_at !== null && $profileId > 0) {
                $rows[$messageKey]['fallback_unique_open_profiles'][$profileId] = true;
            }

            if ($delivery->clicked_at !== null && $profileId > 0) {
                $rows[$messageKey]['fallback_unique_click_profiles'][$profileId] = true;
            }

            $deliveryKeyByEmailDeliveryId[(int) $delivery->id] = $messageKey;
        }

        foreach ($smsDeliveries as $delivery) {
            $messageKey = $this->messageKey('sms', $this->batchKey('sms', $delivery->batch_id, (int) $delivery->id));
            if (! isset($rows[$messageKey])) {
                $rows[$messageKey] = $this->emptyMessageRow('sms', $messageKey);
            }

            $rows[$messageKey]['delivery_ids'][] = (int) $delivery->id;
            $rows[$messageKey]['recipients_count']++;
            $rows[$messageKey]['message_name'] = $rows[$messageKey]['message_name'] !== ''
                ? $rows[$messageKey]['message_name']
                : $this->resolvedMessageNameFromDelivery($delivery, 'sms');
            $rows[$messageKey]['source_label'] = $rows[$messageKey]['source_label'] !== ''
                ? $rows[$messageKey]['source_label']
                : $this->resolvedSourceLabel($delivery);

            $status = strtolower(trim((string) ($delivery->send_status ?? 'sent')));
            $rows[$messageKey]['status_counts'][$status] = ((int) ($rows[$messageKey]['status_counts'][$status] ?? 0)) + 1;
            $rows[$messageKey]['delivered_count'] += $delivery->delivered_at !== null || $status === 'delivered'
                ? 1
                : 0;
            $rows[$messageKey]['sent_at'] = $this->maxDateString(
                $rows[$messageKey]['sent_at'],
                optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String()
            );
            $rows[$messageKey]['first_sent_at'] = $this->minDateString(
                $rows[$messageKey]['first_sent_at'],
                optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String()
            );

            $profileId = (int) ($delivery->marketing_profile_id ?? 0);
            if ($profileId > 0) {
                $rows[$messageKey]['profile_ids'][$profileId] = true;
            }

            $deliveryKeyBySmsDeliveryId[(int) $delivery->id] = $messageKey;
        }

        $emailDeliveryIds = array_values(array_map('intval', array_keys($deliveryKeyByEmailDeliveryId)));
        $smsDeliveryIds = array_values(array_map('intval', array_keys($deliveryKeyBySmsDeliveryId)));

        $engagementEvents = Schema::hasTable('marketing_message_engagement_events') && ($emailDeliveryIds !== [] || $smsDeliveryIds !== [])
            ? MarketingMessageEngagementEvent::query()
                ->forTenantId($tenantId)
                ->where('store_key', $storeKey)
                ->whereIn('event_type', ['open', 'click'])
                ->where(function (Builder $query) use ($emailDeliveryIds, $smsDeliveryIds): void {
                    if ($emailDeliveryIds !== []) {
                        $query->whereIn('marketing_email_delivery_id', $emailDeliveryIds);
                    }

                    if ($smsDeliveryIds !== []) {
                        if ($emailDeliveryIds !== []) {
                            $query->orWhereIn('marketing_message_delivery_id', $smsDeliveryIds);
                        } else {
                            $query->whereIn('marketing_message_delivery_id', $smsDeliveryIds);
                        }
                    }
                })
                ->get([
                    'id',
                    'marketing_email_delivery_id',
                    'marketing_message_delivery_id',
                    'marketing_profile_id',
                    'event_type',
                    'url',
                    'normalized_url',
                    'url_domain',
                    'occurred_at',
                ])
            : collect();

        foreach ($engagementEvents as $event) {
            $emailDeliveryId = (int) ($event->marketing_email_delivery_id ?? 0);
            $smsDeliveryId = (int) ($event->marketing_message_delivery_id ?? 0);
            $messageKey = $emailDeliveryId > 0
                ? ($deliveryKeyByEmailDeliveryId[$emailDeliveryId] ?? null)
                : ($deliveryKeyBySmsDeliveryId[$smsDeliveryId] ?? null);
            if (! is_string($messageKey) || ! isset($rows[$messageKey])) {
                continue;
            }

            $eventType = strtolower(trim((string) ($event->event_type ?? '')));
            $profileId = (int) ($event->marketing_profile_id ?? 0);
            $url = $this->nullableString($event->normalized_url)
                ?? $this->nullableString($event->url);

            if ($eventType === 'open') {
                $rows[$messageKey]['open_event_count']++;
                if ($profileId > 0) {
                    $rows[$messageKey]['unique_open_profiles'][$profileId] = true;
                }

                continue;
            }

            if ($eventType !== 'click') {
                continue;
            }

            $rows[$messageKey]['click_event_count']++;
            if ($profileId > 0) {
                $rows[$messageKey]['unique_click_profiles'][$profileId] = true;
            }
            if ($url !== null) {
                $rows[$messageKey]['clicked_urls'][] = $url;
                $rows[$messageKey]['top_click_counts'][$url] = ((int) ($rows[$messageKey]['top_click_counts'][$url] ?? 0)) + 1;
            }
        }

        $hasOrderAttributionTable = Schema::hasTable('marketing_message_order_attributions');
        $hasSmsDeliveryAttributionColumn = $hasOrderAttributionTable
            && Schema::hasColumn('marketing_message_order_attributions', 'marketing_message_delivery_id');
        $orderAttributionColumns = [
            'id',
            'order_id',
            'marketing_email_delivery_id',
            'revenue_cents',
            'attributed_url',
            'normalized_url',
            'order_occurred_at',
        ];
        if ($hasSmsDeliveryAttributionColumn) {
            $orderAttributionColumns[] = 'marketing_message_delivery_id';
        }
        $orderAttributions = $hasOrderAttributionTable
            && ($emailDeliveryIds !== [] || ($smsDeliveryIds !== [] && $hasSmsDeliveryAttributionColumn))
            ? MarketingMessageOrderAttribution::query()
                ->forTenantId($tenantId)
                ->where('store_key', $storeKey)
                ->where(function (Builder $query) use ($emailDeliveryIds, $smsDeliveryIds, $hasSmsDeliveryAttributionColumn): void {
                    if ($emailDeliveryIds !== []) {
                        $query->whereIn('marketing_email_delivery_id', $emailDeliveryIds);
                    }

                    if ($smsDeliveryIds !== [] && $hasSmsDeliveryAttributionColumn) {
                        if ($emailDeliveryIds !== []) {
                            $query->orWhereIn('marketing_message_delivery_id', $smsDeliveryIds);
                        } else {
                            $query->whereIn('marketing_message_delivery_id', $smsDeliveryIds);
                        }
                    }
                })
                ->get($orderAttributionColumns)
            : collect();

        foreach ($orderAttributions as $attribution) {
            $emailDeliveryId = (int) ($attribution->marketing_email_delivery_id ?? 0);
            $smsDeliveryId = (int) ($attribution->marketing_message_delivery_id ?? 0);
            $messageKey = $emailDeliveryId > 0
                ? ($deliveryKeyByEmailDeliveryId[$emailDeliveryId] ?? null)
                : ($deliveryKeyBySmsDeliveryId[$smsDeliveryId] ?? null);
            if (! is_string($messageKey) || ! isset($rows[$messageKey])) {
                continue;
            }

            $orderId = (int) ($attribution->order_id ?? 0);
            if ($orderId > 0) {
                $rows[$messageKey]['attributed_order_ids'][$orderId] = true;
            }
            $rows[$messageKey]['attributed_revenue_cents'] += (int) ($attribution->revenue_cents ?? 0);
        }

        foreach ($rows as $key => $row) {
            $eventOpenCount = (int) ($row['open_event_count'] ?? 0);
            $eventClickCount = (int) ($row['click_event_count'] ?? 0);

            $rows[$key]['opens'] = $eventOpenCount > 0
                ? $eventOpenCount
                : (int) ($row['fallback_open_count'] ?? 0);
            $rows[$key]['unique_opens'] = $eventOpenCount > 0
                ? count((array) ($row['unique_open_profiles'] ?? []))
                : count((array) ($row['fallback_unique_open_profiles'] ?? []));
            $rows[$key]['clicks'] = $eventClickCount > 0
                ? $eventClickCount
                : (int) ($row['fallback_click_count'] ?? 0);
            $rows[$key]['unique_clicks'] = $eventClickCount > 0
                ? count((array) ($row['unique_click_profiles'] ?? []))
                : count((array) ($row['fallback_unique_click_profiles'] ?? []));
            $rows[$key]['attributed_orders'] = count((array) ($row['attributed_order_ids'] ?? []));
            $rows[$key]['top_clicked_link'] = $this->topClickedLink((array) ($row['top_click_counts'] ?? []));
            $rows[$key]['open_rate'] = $rows[$key]['recipients_count'] > 0
                ? round(($rows[$key]['opens'] / max(1, (int) $rows[$key]['recipients_count'])) * 100, 2)
                : 0.0;
            $rows[$key]['click_rate'] = $rows[$key]['recipients_count'] > 0
                ? round(($rows[$key]['clicks'] / max(1, (int) $rows[$key]['recipients_count'])) * 100, 2)
                : 0.0;
            $rows[$key]['conversion_rate'] = $rows[$key]['clicks'] > 0
                ? round(($rows[$key]['attributed_orders'] / max(1, (int) $rows[$key]['clicks'])) * 100, 2)
                : 0.0;
            $rows[$key]['status'] = $this->resolvedStatus((array) ($row['status_counts'] ?? []));
            $rows[$key]['profile_ids'] = array_map('intval', array_keys((array) ($row['profile_ids'] ?? [])));
            $rows[$key]['delivery_ids'] = array_values(array_unique(array_map('intval', (array) ($row['delivery_ids'] ?? []))));
            $rows[$key]['clicked_urls'] = array_values(array_unique(array_map('strval', (array) ($row['clicked_urls'] ?? []))));
            $rows[$key]['attributed_revenue_cents'] = (int) ($row['attributed_revenue_cents'] ?? 0);
        }

        return [
            'rows' => array_values($rows),
            'email_deliveries' => $emailDeliveries,
            'sms_deliveries' => $smsDeliveries,
            'engagement_events' => $engagementEvents,
            'order_attributions' => $orderAttributions,
            'delivery_key_by_email_delivery_id' => $deliveryKeyByEmailDeliveryId,
            'delivery_key_by_sms_delivery_id' => $deliveryKeyBySmsDeliveryId,
        ];
    }

    protected function emailDeliveriesQuery(
        int $tenantId,
        string $storeKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $search = null
    ): Builder {
        return MarketingEmailDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('campaign_type', 'direct_message')
            ->where(function (Builder $query) use ($from, $to): void {
                $query->whereBetween('sent_at', [$from, $to])
                    ->orWhere(function (Builder $fallback) use ($from, $to): void {
                        $fallback->whereNull('sent_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($search !== null, function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('message_subject', 'like', '%'.$search.'%')
                        ->orWhere('batch_id', 'like', '%'.$search.'%')
                        ->orWhere('source_label', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->select([
                'id',
                'tenant_id',
                'store_key',
                'batch_id',
                'source_label',
                'message_subject',
                'campaign_type',
                'status',
                'sent_at',
                'delivered_at',
                'opened_at',
                'clicked_at',
                'created_at',
                'marketing_profile_id',
                'metadata',
                'raw_payload',
            ]);
    }

    protected function smsDeliveriesQuery(
        int $tenantId,
        string $storeKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $search = null
    ): Builder {
        return MarketingMessageDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('channel', 'sms')
            ->whereNull('campaign_id')
            ->where(function (Builder $query) use ($from, $to): void {
                $query->whereBetween('sent_at', [$from, $to])
                    ->orWhere(function (Builder $fallback) use ($from, $to): void {
                        $fallback->whereNull('sent_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($search !== null, function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('message_subject', 'like', '%'.$search.'%')
                        ->orWhere('batch_id', 'like', '%'.$search.'%')
                        ->orWhere('source_label', 'like', '%'.$search.'%')
                        ->orWhere('rendered_message', 'like', '%'.$search.'%')
                        ->orWhere('to_phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->select([
                'id',
                'tenant_id',
                'store_key',
                'batch_id',
                'source_label',
                'message_subject',
                'channel',
                'send_status',
                'sent_at',
                'delivered_at',
                'failed_at',
                'created_at',
                'marketing_profile_id',
                'rendered_message',
            ]);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    protected function applyOperationalFilters(Collection $rows, int $tenantId, array $filters): Collection
    {
        $openedFilter = strtolower(trim((string) ($filters['opened'] ?? 'all')));
        $clickedFilter = strtolower(trim((string) ($filters['clicked'] ?? 'all')));
        $hasOrders = (bool) ($filters['has_orders'] ?? false);
        $urlSearch = strtolower(trim((string) ($filters['url_search'] ?? '')));
        $customerSearch = strtolower(trim((string) ($filters['customer'] ?? '')));

        $rows = $rows
            ->filter(function (array $row) use ($openedFilter): bool {
                if ($openedFilter === 'opened') {
                    return (int) ($row['opens'] ?? 0) > 0;
                }

                if ($openedFilter === 'not_opened') {
                    return (int) ($row['opens'] ?? 0) === 0;
                }

                return true;
            })
            ->filter(function (array $row) use ($clickedFilter): bool {
                if ($clickedFilter === 'clicked') {
                    return (int) ($row['clicks'] ?? 0) > 0;
                }

                if ($clickedFilter === 'not_clicked') {
                    return (int) ($row['clicks'] ?? 0) === 0;
                }

                return true;
            })
            ->filter(fn (array $row): bool => ! $hasOrders || (int) ($row['attributed_orders'] ?? 0) > 0)
            ->values();

        if ($urlSearch !== '') {
            $rows = $rows
                ->filter(function (array $row) use ($urlSearch): bool {
                    $urls = array_map(
                        static fn ($url): string => strtolower(trim((string) $url)),
                        (array) ($row['clicked_urls'] ?? [])
                    );

                    if (str_contains(strtolower(trim((string) ($row['top_clicked_link'] ?? ''))), $urlSearch)) {
                        return true;
                    }

                    foreach ($urls as $url) {
                        if ($url !== '' && str_contains($url, $urlSearch)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        if ($customerSearch !== '') {
            $allProfileIds = $rows
                ->flatMap(fn (array $row): array => array_map('intval', (array) ($row['profile_ids'] ?? [])))
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            $matchingProfileIds = MarketingProfile::query()
                ->forTenantId($tenantId)
                ->whereIn('id', $allProfileIds->all())
                ->get(['id', 'first_name', 'last_name', 'email', 'phone'])
                ->filter(function (MarketingProfile $profile) use ($customerSearch): bool {
                    $haystack = strtolower(trim(implode(' ', [
                        (string) ($profile->first_name ?? ''),
                        (string) ($profile->last_name ?? ''),
                        (string) ($profile->email ?? ''),
                        (string) ($profile->phone ?? ''),
                    ])));

                    return $haystack !== '' && str_contains($haystack, $customerSearch);
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

            $rows = $rows
                ->filter(function (array $row) use ($matchingProfileIds): bool {
                    $profileIds = array_map('intval', (array) ($row['profile_ids'] ?? []));
                    foreach ($profileIds as $profileId) {
                        if ($matchingProfileIds->has($profileId)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        return $rows;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    protected function summaryFromRows(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return $this->emptySummary();
        }

        $messagesSent = (int) $rows->sum('recipients_count');
        $messagesDelivered = (int) $rows->sum('delivered_count');
        $messagesOpened = (int) $rows->sum('opens');
        $uniqueOpens = (int) $rows->sum('unique_opens');
        $totalClicks = (int) $rows->sum('clicks');
        $uniqueClicks = (int) $rows->sum('unique_clicks');
        $attributedOrders = (int) $rows->sum('attributed_orders');
        $attributedRevenueCents = (int) $rows->sum('attributed_revenue_cents');
        $conversionRate = $totalClicks > 0
            ? round(($attributedOrders / max(1, $totalClicks)) * 100, 2)
            : 0.0;

        return [
            'messages_sent' => $messagesSent,
            'messages_delivered' => $messagesDelivered,
            'messages_opened' => $messagesOpened,
            'unique_opens' => $uniqueOpens,
            'total_clicks' => $totalClicks,
            'unique_clicks' => $uniqueClicks,
            'attributed_orders' => $attributedOrders,
            'attributed_revenue_cents' => $attributedRevenueCents,
            'click_to_order_conversion_rate' => $conversionRate,
        ];
    }

    /**
     * @param  array<string,mixed>  $dataset
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function historyOutcomes(int $tenantId, string $storeKey, array $dataset, array $filters): array
    {
        /** @var Collection<int,MarketingEmailDelivery> $emailDeliveries */
        $emailDeliveries = $dataset['email_deliveries'] ?? collect();
        /** @var Collection<int,MarketingMessageDelivery> $smsDeliveries */
        $smsDeliveries = $dataset['sms_deliveries'] ?? collect();
        /** @var Collection<int,MarketingMessageEngagementEvent> $engagementEvents */
        $engagementEvents = $dataset['engagement_events'] ?? collect();
        /** @var Collection<int,MarketingMessageOrderAttribution> $orderAttributions */
        $orderAttributions = $dataset['order_attributions'] ?? collect();

        $deliveryKeyByEmailDeliveryId = [];
        foreach ((array) ($dataset['delivery_key_by_email_delivery_id'] ?? []) as $deliveryId => $messageKey) {
            $resolvedDeliveryId = (int) $deliveryId;
            $resolvedMessageKey = trim((string) $messageKey);
            if ($resolvedDeliveryId > 0 && $resolvedMessageKey !== '') {
                $deliveryKeyByEmailDeliveryId[$resolvedDeliveryId] = $resolvedMessageKey;
            }
        }

        $deliveryKeyBySmsDeliveryId = [];
        foreach ((array) ($dataset['delivery_key_by_sms_delivery_id'] ?? []) as $deliveryId => $messageKey) {
            $resolvedDeliveryId = (int) $deliveryId;
            $resolvedMessageKey = trim((string) $messageKey);
            if ($resolvedDeliveryId > 0 && $resolvedMessageKey !== '') {
                $deliveryKeyBySmsDeliveryId[$resolvedDeliveryId] = $resolvedMessageKey;
            }
        }

        $profileIds = $emailDeliveries
            ->pluck('marketing_profile_id')
            ->concat($smsDeliveries->pluck('marketing_profile_id'))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $profileMap = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $profileIds->all())
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->keyBy('id');

        $openCountByEmailDeliveryId = [];
        $clickCountByEmailDeliveryId = [];
        $openCountBySmsDeliveryId = [];
        $clickCountBySmsDeliveryId = [];
        $clickedUrlsByEmailDeliveryId = [];
        $clickedUrlsBySmsDeliveryId = [];

        foreach ($engagementEvents as $event) {
            $eventType = strtolower(trim((string) ($event->event_type ?? '')));
            if (! in_array($eventType, ['open', 'click'], true)) {
                continue;
            }

            $emailDeliveryId = (int) ($event->marketing_email_delivery_id ?? 0);
            $smsDeliveryId = (int) ($event->marketing_message_delivery_id ?? 0);
            $url = $this->nullableString($event->normalized_url)
                ?? $this->nullableString($event->url);

            if ($eventType === 'open') {
                if ($emailDeliveryId > 0) {
                    $openCountByEmailDeliveryId[$emailDeliveryId] = (int) ($openCountByEmailDeliveryId[$emailDeliveryId] ?? 0) + 1;
                }
                if ($smsDeliveryId > 0) {
                    $openCountBySmsDeliveryId[$smsDeliveryId] = (int) ($openCountBySmsDeliveryId[$smsDeliveryId] ?? 0) + 1;
                }

                continue;
            }

            if ($emailDeliveryId > 0) {
                $clickCountByEmailDeliveryId[$emailDeliveryId] = (int) ($clickCountByEmailDeliveryId[$emailDeliveryId] ?? 0) + 1;
                if ($url !== null) {
                    $clickedUrlsByEmailDeliveryId[$emailDeliveryId][] = $url;
                }
            }

            if ($smsDeliveryId > 0) {
                $clickCountBySmsDeliveryId[$smsDeliveryId] = (int) ($clickCountBySmsDeliveryId[$smsDeliveryId] ?? 0) + 1;
                if ($url !== null) {
                    $clickedUrlsBySmsDeliveryId[$smsDeliveryId][] = $url;
                }
            }
        }

        $orderSummaryByEmailDeliveryId = [];
        $orderSummaryBySmsDeliveryId = [];
        foreach ($orderAttributions as $attribution) {
            $orderId = (int) ($attribution->order_id ?? 0);
            $revenueCents = (int) ($attribution->revenue_cents ?? 0);
            $emailDeliveryId = (int) ($attribution->marketing_email_delivery_id ?? 0);
            $smsDeliveryId = (int) ($attribution->marketing_message_delivery_id ?? 0);

            if ($emailDeliveryId > 0) {
                if (! isset($orderSummaryByEmailDeliveryId[$emailDeliveryId])) {
                    $orderSummaryByEmailDeliveryId[$emailDeliveryId] = [
                        'order_ids' => [],
                        'revenue_cents' => 0,
                    ];
                }

                if ($orderId > 0) {
                    $orderSummaryByEmailDeliveryId[$emailDeliveryId]['order_ids'][$orderId] = true;
                }
                $orderSummaryByEmailDeliveryId[$emailDeliveryId]['revenue_cents'] += $revenueCents;
            }

            if ($smsDeliveryId > 0) {
                if (! isset($orderSummaryBySmsDeliveryId[$smsDeliveryId])) {
                    $orderSummaryBySmsDeliveryId[$smsDeliveryId] = [
                        'order_ids' => [],
                        'revenue_cents' => 0,
                    ];
                }

                if ($orderId > 0) {
                    $orderSummaryBySmsDeliveryId[$smsDeliveryId]['order_ids'][$orderId] = true;
                }
                $orderSummaryBySmsDeliveryId[$smsDeliveryId]['revenue_cents'] += $revenueCents;
            }
        }

        $smsRepliesByDelivery = $this->smsRepliesByDelivery($smsDeliveries);

        $rows = collect();

        foreach ($emailDeliveries as $delivery) {
            $sourceLabel = $this->resolvedSourceLabel($delivery);
            if (! $this->isEmbeddedMessagingSource($sourceLabel)) {
                continue;
            }

            $deliveryId = (int) ($delivery->id ?? 0);
            if ($deliveryId <= 0) {
                continue;
            }

            $profileId = (int) ($delivery->marketing_profile_id ?? 0);
            $profile = $profileId > 0 ? $profileMap->get($profileId) : null;
            $openCount = array_key_exists($deliveryId, $openCountByEmailDeliveryId)
                ? (int) $openCountByEmailDeliveryId[$deliveryId]
                : ($delivery->opened_at !== null ? 1 : 0);
            $clickCount = array_key_exists($deliveryId, $clickCountByEmailDeliveryId)
                ? (int) $clickCountByEmailDeliveryId[$deliveryId]
                : ($delivery->clicked_at !== null ? 1 : 0);
            $clickedUrls = array_values(array_unique(array_map(
                'strval',
                (array) ($clickedUrlsByEmailDeliveryId[$deliveryId] ?? [])
            )));
            $orderSummary = (array) ($orderSummaryByEmailDeliveryId[$deliveryId] ?? []);
            $attributedOrders = count((array) ($orderSummary['order_ids'] ?? []));
            $attributedRevenueCents = (int) ($orderSummary['revenue_cents'] ?? 0);
            $status = strtolower(trim((string) ($delivery->status ?? 'sent')));
            $sentAt = optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String();

            $rows->push([
                'history_key' => 'email:'.$deliveryId,
                'message_key' => $deliveryKeyByEmailDeliveryId[$deliveryId] ?? null,
                'delivery_id' => $deliveryId,
                'channel' => 'email',
                'message_name' => $this->resolvedMessageNameFromDelivery($delivery, 'email'),
                'source_label' => $sourceLabel,
                'status' => $status !== '' ? $status : 'sent',
                'recipient' => $this->nullableString($delivery->email) ?? $this->nullableString($profile?->email) ?? '—',
                'profile_id' => $profileId > 0 ? $profileId : null,
                'profile_name' => $this->profileDisplayName($profile),
                'message_preview' => Str::limit((string) ($delivery->message_subject ?? ''), 120),
                'sent_at' => $sentAt,
                'opened' => $openCount > 0,
                'opens' => $openCount,
                'clicked' => $clickCount > 0,
                'clicks' => $clickCount,
                'clicked_urls' => $clickedUrls,
                'attributed_orders' => $attributedOrders,
                'attributed_revenue_cents' => $attributedRevenueCents,
                'responded' => false,
                'responded_at' => null,
                'response_preview' => null,
                'outcome' => $this->historyOutcome($attributedOrders, false, $clickCount, $openCount),
            ]);
        }

        foreach ($smsDeliveries as $delivery) {
            $sourceLabel = $this->resolvedSourceLabel($delivery);
            if (! $this->isEmbeddedMessagingSource($sourceLabel)) {
                continue;
            }

            $deliveryId = (int) ($delivery->id ?? 0);
            if ($deliveryId <= 0) {
                continue;
            }

            $profileId = (int) ($delivery->marketing_profile_id ?? 0);
            $profile = $profileId > 0 ? $profileMap->get($profileId) : null;
            $openCount = (int) ($openCountBySmsDeliveryId[$deliveryId] ?? 0);
            $clickCount = (int) ($clickCountBySmsDeliveryId[$deliveryId] ?? 0);
            $clickedUrls = array_values(array_unique(array_map(
                'strval',
                (array) ($clickedUrlsBySmsDeliveryId[$deliveryId] ?? [])
            )));
            $orderSummary = (array) ($orderSummaryBySmsDeliveryId[$deliveryId] ?? []);
            $attributedOrders = count((array) ($orderSummary['order_ids'] ?? []));
            $attributedRevenueCents = (int) ($orderSummary['revenue_cents'] ?? 0);
            $reply = (array) ($smsRepliesByDelivery[$deliveryId] ?? []);
            $responded = (bool) ($reply['responded'] ?? false);
            $status = strtolower(trim((string) ($delivery->send_status ?? 'sent')));
            $sentAt = optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String();

            $rows->push([
                'history_key' => 'sms:'.$deliveryId,
                'message_key' => $deliveryKeyBySmsDeliveryId[$deliveryId] ?? null,
                'delivery_id' => $deliveryId,
                'channel' => 'sms',
                'message_name' => $this->resolvedMessageNameFromDelivery($delivery, 'sms'),
                'source_label' => $sourceLabel,
                'status' => $status !== '' ? $status : 'sent',
                'recipient' => $this->nullableString($delivery->to_phone) ?? $this->nullableString($profile?->phone) ?? '—',
                'profile_id' => $profileId > 0 ? $profileId : null,
                'profile_name' => $this->profileDisplayName($profile),
                'message_preview' => Str::limit((string) ($delivery->rendered_message ?? ''), 120),
                'sent_at' => $sentAt,
                'opened' => $openCount > 0,
                'opens' => $openCount,
                'clicked' => $clickCount > 0,
                'clicks' => $clickCount,
                'clicked_urls' => $clickedUrls,
                'attributed_orders' => $attributedOrders,
                'attributed_revenue_cents' => $attributedRevenueCents,
                'responded' => $responded,
                'responded_at' => $this->nullableString($reply['responded_at'] ?? null),
                'response_preview' => $this->nullableString($reply['response_preview'] ?? null),
                'outcome' => $this->historyOutcome($attributedOrders, $responded, $clickCount, $openCount),
            ]);
        }

        $filteredRows = $this->applyHistoryOutcomeFilters($rows, $filters)
            ->sortByDesc(fn (array $row): string => (string) ($row['sent_at'] ?? ''))
            ->values();

        $maxRows = max(40, min(200, ((int) ($filters['per_page'] ?? 25)) * 3));
        $limitedRows = $filteredRows->take($maxRows)->values();

        return [
            'rows' => $limitedRows->all(),
            'summary' => [
                'total_rows' => $filteredRows->count(),
                'opened_rows' => $filteredRows->where('opened', true)->count(),
                'clicked_rows' => $filteredRows->where('clicked', true)->count(),
                'responded_rows' => $filteredRows->where('responded', true)->count(),
                'attributed_orders' => (int) $filteredRows->sum('attributed_orders'),
                'attributed_revenue_cents' => (int) $filteredRows->sum('attributed_revenue_cents'),
            ],
            'empty' => $filteredRows->isEmpty(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    protected function applyHistoryOutcomeFilters(Collection $rows, array $filters): Collection
    {
        $channelFilter = strtolower(trim((string) ($filters['channel'] ?? 'all')));
        $openedFilter = strtolower(trim((string) ($filters['opened'] ?? 'all')));
        $clickedFilter = strtolower(trim((string) ($filters['clicked'] ?? 'all')));
        $hasOrders = (bool) ($filters['has_orders'] ?? false);
        $messageSearch = strtolower(trim((string) ($filters['message'] ?? '')));
        $urlSearch = strtolower(trim((string) ($filters['url_search'] ?? '')));
        $customerSearch = strtolower(trim((string) ($filters['customer'] ?? '')));

        return $rows
            ->filter(function (array $row) use ($channelFilter): bool {
                if (! in_array($channelFilter, ['email', 'sms'], true)) {
                    return true;
                }

                return strtolower(trim((string) ($row['channel'] ?? ''))) === $channelFilter;
            })
            ->filter(function (array $row) use ($openedFilter): bool {
                if ($openedFilter === 'opened') {
                    return (bool) ($row['opened'] ?? false);
                }

                if ($openedFilter === 'not_opened') {
                    return ! (bool) ($row['opened'] ?? false);
                }

                return true;
            })
            ->filter(function (array $row) use ($clickedFilter): bool {
                if ($clickedFilter === 'clicked') {
                    return (bool) ($row['clicked'] ?? false);
                }

                if ($clickedFilter === 'not_clicked') {
                    return ! (bool) ($row['clicked'] ?? false);
                }

                return true;
            })
            ->filter(fn (array $row): bool => ! $hasOrders || (int) ($row['attributed_orders'] ?? 0) > 0)
            ->filter(function (array $row) use ($messageSearch): bool {
                if ($messageSearch === '') {
                    return true;
                }

                $haystack = strtolower(trim(implode(' ', [
                    (string) ($row['message_name'] ?? ''),
                    (string) ($row['source_label'] ?? ''),
                    (string) ($row['message_preview'] ?? ''),
                ])));

                return $haystack !== '' && str_contains($haystack, $messageSearch);
            })
            ->filter(function (array $row) use ($urlSearch): bool {
                if ($urlSearch === '') {
                    return true;
                }

                $urls = array_map(
                    static fn ($url): string => strtolower(trim((string) $url)),
                    (array) ($row['clicked_urls'] ?? [])
                );

                foreach ($urls as $url) {
                    if ($url !== '' && str_contains($url, $urlSearch)) {
                        return true;
                    }
                }

                return false;
            })
            ->filter(function (array $row) use ($customerSearch): bool {
                if ($customerSearch === '') {
                    return true;
                }

                $haystack = strtolower(trim(implode(' ', [
                    (string) ($row['profile_name'] ?? ''),
                    (string) ($row['recipient'] ?? ''),
                ])));

                return $haystack !== '' && str_contains($haystack, $customerSearch);
            })
            ->values();
    }

    /**
     * @param  Collection<int,MarketingMessageDelivery>  $smsDeliveries
     * @return array<int,array{responded:bool,responded_at:?string,response_preview:?string}>
     */
    protected function smsRepliesByDelivery(Collection $smsDeliveries): array
    {
        if (! Schema::hasTable('marketing_delivery_events') || $smsDeliveries->isEmpty()) {
            return [];
        }

        $deliverySnapshots = $smsDeliveries
            ->map(function (MarketingMessageDelivery $delivery): ?array {
                $deliveryId = (int) ($delivery->id ?? 0);
                if ($deliveryId <= 0) {
                    return null;
                }

                $recipient = $this->normalizedPhone($delivery->to_phone);
                if ($recipient === null) {
                    return null;
                }

                $sender = $this->normalizedPhone($delivery->from_identifier);
                $sentAt = $this->dateOrNull($delivery->sent_at ?? $delivery->created_at);
                if (! $sentAt instanceof CarbonImmutable) {
                    return null;
                }

                return [
                    'delivery_id' => $deliveryId,
                    'recipient' => $recipient,
                    'sender' => $sender,
                    'sent_at' => $sentAt,
                ];
            })
            ->filter(fn (?array $snapshot): bool => is_array($snapshot))
            ->values();

        if ($deliverySnapshots->isEmpty()) {
            return [];
        }

        $replyWindowDays = max(1, (int) config('marketing.message_analytics.attribution_window_days', 7));
        $startAt = $deliverySnapshots
            ->map(fn (array $snapshot) => $snapshot['sent_at'] ?? null)
            ->filter(fn ($date): bool => $date instanceof CarbonImmutable)
            ->min();
        $endAt = $deliverySnapshots
            ->map(fn (array $snapshot) => $snapshot['sent_at'] ?? null)
            ->filter(fn ($date): bool => $date instanceof CarbonImmutable)
            ->max();

        if (! $startAt instanceof CarbonImmutable || ! $endAt instanceof CarbonImmutable) {
            return [];
        }

        $events = MarketingDeliveryEvent::query()
            ->where('provider', 'twilio')
            ->where('event_type', 'webhook_received')
            ->whereNotNull('occurred_at')
            ->whereBetween('occurred_at', [$startAt, $endAt->addDays($replyWindowDays)])
            ->orderBy('occurred_at')
            ->get([
                'id',
                'event_status',
                'payload',
                'occurred_at',
            ]);

        if ($events->isEmpty()) {
            return [];
        }

        $deliveriesByRecipient = [];
        $deliveriesByPair = [];

        foreach ($deliverySnapshots as $snapshot) {
            $recipient = (string) $snapshot['recipient'];
            $sender = $snapshot['sender'] ?? null;
            $deliveriesByRecipient[$recipient][] = $snapshot;
            if (is_string($sender) && $sender !== '') {
                $deliveriesByPair[$recipient.'|'.$sender][] = $snapshot;
            }
        }

        foreach ($deliveriesByRecipient as $recipient => $entries) {
            usort($entries, fn (array $left, array $right): int => ($right['sent_at']?->timestamp ?? 0) <=> ($left['sent_at']?->timestamp ?? 0));
            $deliveriesByRecipient[$recipient] = $entries;
        }

        foreach ($deliveriesByPair as $pair => $entries) {
            usort($entries, fn (array $left, array $right): int => ($right['sent_at']?->timestamp ?? 0) <=> ($left['sent_at']?->timestamp ?? 0));
            $deliveriesByPair[$pair] = $entries;
        }

        $responses = [];

        foreach ($events as $event) {
            if (! $this->isInboundSmsWebhookEvent($event)) {
                continue;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $fromPhone = $this->normalizedPhone($payload['From'] ?? $payload['from'] ?? null);
            if ($fromPhone === null) {
                continue;
            }

            $toPhone = $this->normalizedPhone($payload['To'] ?? $payload['to'] ?? null);
            $occurredAt = $this->dateOrNull($event->occurred_at);
            if (! $occurredAt instanceof CarbonImmutable) {
                continue;
            }

            $candidateEntries = [];
            if ($toPhone !== null) {
                $candidateEntries = array_merge($candidateEntries, (array) ($deliveriesByPair[$fromPhone.'|'.$toPhone] ?? []));
            }
            $candidateEntries = array_merge($candidateEntries, (array) ($deliveriesByRecipient[$fromPhone] ?? []));

            if ($candidateEntries === []) {
                continue;
            }

            $dedupedCandidates = [];
            foreach ($candidateEntries as $candidate) {
                $candidateDeliveryId = (int) ($candidate['delivery_id'] ?? 0);
                if ($candidateDeliveryId > 0) {
                    $dedupedCandidates[$candidateDeliveryId] = $candidate;
                }
            }

            $matched = null;
            foreach ($dedupedCandidates as $candidate) {
                $sentAt = $candidate['sent_at'] ?? null;
                if (! $sentAt instanceof CarbonImmutable || $sentAt->greaterThan($occurredAt)) {
                    continue;
                }

                $ageInDays = $sentAt->diffInDays($occurredAt);
                if ($ageInDays > $replyWindowDays) {
                    continue;
                }

                if ($matched === null || ($candidate['sent_at']?->timestamp ?? 0) > ($matched['sent_at']?->timestamp ?? 0)) {
                    $matched = $candidate;
                }
            }

            if (! is_array($matched)) {
                continue;
            }

            $deliveryId = (int) ($matched['delivery_id'] ?? 0);
            if ($deliveryId <= 0) {
                continue;
            }

            $existing = (array) ($responses[$deliveryId] ?? []);
            $existingAt = $this->dateOrNull($existing['responded_at'] ?? null);
            if ($existingAt instanceof CarbonImmutable && $existingAt->greaterThanOrEqualTo($occurredAt)) {
                continue;
            }

            $responsePreview = $this->nullableString($payload['Body'] ?? $payload['body'] ?? null);

            $responses[$deliveryId] = [
                'responded' => true,
                'responded_at' => $occurredAt->toIso8601String(),
                'response_preview' => $responsePreview !== null ? Str::limit($responsePreview, 140) : null,
            ];
        }

        return $responses;
    }

    protected function isInboundSmsWebhookEvent(MarketingDeliveryEvent $event): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $body = $this->nullableString($payload['Body'] ?? $payload['body'] ?? null);
        $status = strtolower(trim((string) (
            $payload['MessageStatus']
            ?? $payload['SmsStatus']
            ?? $event->event_status
            ?? ''
        )));

        if ($body !== null) {
            return true;
        }

        return in_array($status, ['received', 'inbound', 'reply', 'replied'], true);
    }

    protected function historyOutcome(int $attributedOrders, bool $responded, int $clicks, int $opens): string
    {
        if ($attributedOrders > 0) {
            return 'sale';
        }

        if ($responded) {
            return 'responded';
        }

        if ($clicks > 0) {
            return 'clicked';
        }

        if ($opens > 0) {
            return 'opened';
        }

        return 'sent';
    }

    protected function profileDisplayName(?MarketingProfile $profile): string
    {
        if (! $profile instanceof MarketingProfile) {
            return 'Customer';
        }

        $name = trim((string) $profile->first_name.' '.(string) $profile->last_name);

        return $name !== '' ? $name : 'Customer';
    }

    protected function isEmbeddedMessagingSource(?string $sourceLabel): bool
    {
        $source = strtolower(trim((string) $sourceLabel));

        return $source === 'shopify_embedded_messaging'
            || str_starts_with($source, 'shopify_embedded_messaging_');
    }

    /**
     * @param  array<string,mixed>  $dataset
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function chartFromDataset(array $dataset, array $filters): array
    {
        /** @var Collection<int,MarketingEmailDelivery> $emailDeliveries */
        $emailDeliveries = $dataset['email_deliveries'] ?? collect();
        /** @var Collection<int,MarketingMessageDelivery> $smsDeliveries */
        $smsDeliveries = $dataset['sms_deliveries'] ?? collect();
        /** @var Collection<int,MarketingMessageEngagementEvent> $engagementEvents */
        $engagementEvents = $dataset['engagement_events'] ?? collect();
        /** @var Collection<int,MarketingMessageOrderAttribution> $orderAttributions */
        $orderAttributions = $dataset['order_attributions'] ?? collect();

        return $this->buildChartData($filters, $emailDeliveries, $smsDeliveries, $engagementEvents, $orderAttributions);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  Collection<int,MarketingEmailDelivery>  $emailDeliveries
     * @param  Collection<int,MarketingMessageDelivery>  $smsDeliveries
     * @param  Collection<int,MarketingMessageEngagementEvent>  $engagementEvents
     * @param  Collection<int,MarketingMessageOrderAttribution>  $orderAttributions
     * @return array<string,mixed>
     */
    protected function buildChartData(
        array $filters,
        Collection $emailDeliveries,
        Collection $smsDeliveries,
        Collection $engagementEvents,
        Collection $orderAttributions
    ): array {
        $from = $filters['date_from'] instanceof CarbonImmutable
            ? $filters['date_from']
            : now()->toImmutable()->subDays(30)->startOfDay();
        $to = $filters['date_to'] instanceof CarbonImmutable
            ? $filters['date_to']
            : now()->toImmutable()->endOfDay();

        $dateKeys = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $dateKeys[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        if ($dateKeys === []) {
            $dateKeys[] = now()->toDateString();
        }

        $emailSentByDate = $this->countByDate(
            $emailDeliveries,
            fn (MarketingEmailDelivery $row): ?CarbonImmutable => $this->dateOrNull($row->sent_at ?? $row->created_at)
        );
        $smsSentByDate = $this->countByDate(
            $smsDeliveries,
            fn (MarketingMessageDelivery $row): ?CarbonImmutable => $this->dateOrNull($row->sent_at ?? $row->created_at)
        );
        $smsDeliveredByDate = $this->countByDate(
            $smsDeliveries->filter(function (MarketingMessageDelivery $row): bool {
                $status = strtolower(trim((string) ($row->send_status ?? '')));

                return $row->delivered_at !== null || $status === 'delivered';
            }),
            fn (MarketingMessageDelivery $row): ?CarbonImmutable => $this->dateOrNull($row->delivered_at ?? $row->sent_at ?? $row->created_at)
        );

        $openEvents = $engagementEvents
            ->filter(function (MarketingMessageEngagementEvent $row): bool {
                if (strtolower(trim((string) ($row->event_type ?? ''))) !== 'open') {
                    return false;
                }

                return strtolower(trim((string) ($row->channel ?? ''))) === 'email'
                    || (int) ($row->marketing_email_delivery_id ?? 0) > 0;
            })
            ->values();
        $clickEvents = $engagementEvents
            ->filter(function (MarketingMessageEngagementEvent $row): bool {
                if (strtolower(trim((string) ($row->event_type ?? ''))) !== 'click') {
                    return false;
                }

                return strtolower(trim((string) ($row->channel ?? ''))) === 'email'
                    || (int) ($row->marketing_email_delivery_id ?? 0) > 0;
            })
            ->values();
        $smsClickEvents = $engagementEvents
            ->filter(function (MarketingMessageEngagementEvent $row): bool {
                if (strtolower(trim((string) ($row->event_type ?? ''))) !== 'click') {
                    return false;
                }

                return strtolower(trim((string) ($row->channel ?? ''))) === 'sms'
                    || (int) ($row->marketing_message_delivery_id ?? 0) > 0;
            })
            ->values();

        $emailOpenByDate = $this->countByDate(
            $openEvents,
            fn (MarketingMessageEngagementEvent $row): ?CarbonImmutable => $this->dateOrNull($row->occurred_at)
        );
        $emailClickByDate = $this->countByDate(
            $clickEvents,
            fn (MarketingMessageEngagementEvent $row): ?CarbonImmutable => $this->dateOrNull($row->occurred_at)
        );
        $smsClickByDate = $this->countByDate(
            $smsClickEvents,
            fn (MarketingMessageEngagementEvent $row): ?CarbonImmutable => $this->dateOrNull($row->occurred_at)
        );

        if ($emailOpenByDate === [] && $emailDeliveries->isNotEmpty()) {
            $emailOpenByDate = $this->countByDate(
                $emailDeliveries->filter(fn (MarketingEmailDelivery $row): bool => $row->opened_at !== null),
                fn (MarketingEmailDelivery $row): ?CarbonImmutable => $this->dateOrNull($row->opened_at)
            );
        }

        if ($emailClickByDate === [] && $emailDeliveries->isNotEmpty()) {
            $emailClickByDate = $this->countByDate(
                $emailDeliveries->filter(fn (MarketingEmailDelivery $row): bool => $row->clicked_at !== null),
                fn (MarketingEmailDelivery $row): ?CarbonImmutable => $this->dateOrNull($row->clicked_at)
            );
        }

        $attributedOrdersByDate = $this->countByDate(
            $orderAttributions,
            fn (MarketingMessageOrderAttribution $row): ?CarbonImmutable => $this->dateOrNull($row->order_occurred_at)
        );
        $smsResponseByDate = [];
        foreach ($this->smsRepliesByDelivery($smsDeliveries) as $reply) {
            $respondedAt = $this->dateOrNull($reply['responded_at'] ?? null);
            if (! $respondedAt instanceof CarbonImmutable) {
                continue;
            }

            $dateKey = $respondedAt->format('Y-m-d');
            $smsResponseByDate[$dateKey] = (int) ($smsResponseByDate[$dateKey] ?? 0) + 1;
        }

        $seriesOptions = [
            ['key' => 'email_sent', 'label' => 'Email sent', 'color' => '#0f766e', 'selected' => true],
            ['key' => 'email_opened', 'label' => 'Email opened', 'color' => '#2563eb', 'selected' => true],
            ['key' => 'email_clicked', 'label' => 'Email clicked', 'color' => '#0369a1', 'selected' => true],
            ['key' => 'sms_sent', 'label' => 'Text sent', 'color' => '#9a3412', 'selected' => false],
            ['key' => 'sms_delivered', 'label' => 'Text delivered', 'color' => '#ea580c', 'selected' => false],
            ['key' => 'sms_clicked', 'label' => 'Text clicked', 'color' => '#7c2d12', 'selected' => false],
            ['key' => 'sms_responded', 'label' => 'Text responded', 'color' => '#b45309', 'selected' => true],
            ['key' => 'attributed_orders', 'label' => 'Attributed orders', 'color' => '#4f46e5', 'selected' => true],
        ];

        $series = collect($seriesOptions)
            ->map(function (array $option) use (
                $dateKeys,
                $emailSentByDate,
                $emailOpenByDate,
                $emailClickByDate,
                $smsSentByDate,
                $smsDeliveredByDate,
                $smsClickByDate,
                $smsResponseByDate,
                $attributedOrdersByDate
            ): array {
                $key = (string) ($option['key'] ?? 'metric');

                $values = array_map(function (string $dateKey) use (
                    $key,
                    $emailSentByDate,
                    $emailOpenByDate,
                    $emailClickByDate,
                    $smsSentByDate,
                    $smsDeliveredByDate,
                    $smsClickByDate,
                    $smsResponseByDate,
                    $attributedOrdersByDate
                ): int {
                    return match ($key) {
                        'email_sent' => (int) ($emailSentByDate[$dateKey] ?? 0),
                        'email_opened' => (int) ($emailOpenByDate[$dateKey] ?? 0),
                        'email_clicked' => (int) ($emailClickByDate[$dateKey] ?? 0),
                        'sms_sent' => (int) ($smsSentByDate[$dateKey] ?? 0),
                        'sms_delivered' => (int) ($smsDeliveredByDate[$dateKey] ?? 0),
                        'sms_clicked' => (int) ($smsClickByDate[$dateKey] ?? 0),
                        'sms_responded' => (int) ($smsResponseByDate[$dateKey] ?? 0),
                        'attributed_orders' => (int) ($attributedOrdersByDate[$dateKey] ?? 0),
                        default => 0,
                    };
                }, $dateKeys);

                return [
                    'key' => $key,
                    'name' => (string) ($option['label'] ?? Str::headline($key)),
                    'color' => (string) ($option['color'] ?? '#0f172a'),
                    'selected' => (bool) ($option['selected'] ?? false),
                    'data' => $values,
                ];
            })
            ->values()
            ->all();

        return [
            'labels' => $dateKeys,
            'series' => $series,
            'series_options' => $seriesOptions,
            'empty' => $emailDeliveries->isEmpty() && $smsDeliveries->isEmpty() && $engagementEvents->isEmpty(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function emptyChart(array $filters): array
    {
        $from = $filters['date_from'] instanceof CarbonImmutable
            ? $filters['date_from']
            : now()->toImmutable()->subDays(30)->startOfDay();
        $to = $filters['date_to'] instanceof CarbonImmutable
            ? $filters['date_to']
            : now()->toImmutable()->endOfDay();

        return [
            'labels' => [$from->toDateString(), $to->toDateString()],
            'series' => [],
            'series_options' => [],
            'empty' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptySummary(): array
    {
        return [
            'messages_sent' => 0,
            'messages_delivered' => 0,
            'messages_opened' => 0,
            'unique_opens' => 0,
            'total_clicks' => 0,
            'unique_clicks' => 0,
            'attributed_orders' => 0,
            'attributed_revenue_cents' => 0,
            'click_to_order_conversion_rate' => 0.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyHistoryOutcomes(): array
    {
        return [
            'rows' => [],
            'summary' => [
                'total_rows' => 0,
                'opened_rows' => 0,
                'clicked_rows' => 0,
                'responded_rows' => 0,
                'attributed_orders' => 0,
                'attributed_revenue_cents' => 0,
            ],
            'empty' => true,
        ];
    }

    /**
     * @return array{channel:string,batch_key:string}|null
     */
    protected function parseMessageKey(string $messageKey): ?array
    {
        $parts = explode(':', $messageKey, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $channel = strtolower(trim((string) ($parts[0] ?? '')));
        $batchKey = trim((string) ($parts[1] ?? ''));

        if (! in_array($channel, ['email', 'sms'], true) || $batchKey === '') {
            return null;
        }

        return [
            'channel' => $channel,
            'batch_key' => $batchKey,
        ];
    }

    /**
     * @return Collection<int,MarketingEmailDelivery>
     */
    protected function emailDeliveriesForMessageKey(int $tenantId, string $storeKey, string $batchKey): Collection
    {
        $query = MarketingEmailDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('campaign_type', 'direct_message');

        $legacyId = $this->legacyIdFromBatchKey('email', $batchKey);
        if ($legacyId !== null) {
            return $query
                ->whereNull('batch_id')
                ->whereKey($legacyId)
                ->get();
        }

        return $query
            ->where('batch_id', $batchKey)
            ->get();
    }

    /**
     * @return Collection<int,MarketingMessageDelivery>
     */
    protected function smsDeliveriesForMessageKey(int $tenantId, string $storeKey, string $batchKey): Collection
    {
        $query = MarketingMessageDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('channel', 'sms')
            ->whereNull('campaign_id');

        $legacyId = $this->legacyIdFromBatchKey('sms', $batchKey);
        if ($legacyId !== null) {
            return $query
                ->whereNull('batch_id')
                ->whereKey($legacyId)
                ->get();
        }

        return $query
            ->where('batch_id', $batchKey)
            ->get();
    }

    protected function legacyIdFromBatchKey(string $channel, string $batchKey): ?int
    {
        $prefix = strtolower($channel).'-';
        if (! str_starts_with(strtolower($batchKey), $prefix)) {
            return null;
        }

        $suffix = trim((string) Str::after($batchKey, $prefix));

        return ctype_digit($suffix) ? (int) $suffix : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyMessageRow(string $channel, string $messageKey): array
    {
        return [
            'message_key' => $messageKey,
            'channel' => $channel,
            'message_name' => '',
            'source_label' => '',
            'sent_at' => null,
            'first_sent_at' => null,
            'recipients_count' => 0,
            'delivered_count' => 0,
            'fallback_open_count' => 0,
            'fallback_click_count' => 0,
            'open_event_count' => 0,
            'click_event_count' => 0,
            'unique_open_profiles' => [],
            'unique_click_profiles' => [],
            'fallback_unique_open_profiles' => [],
            'fallback_unique_click_profiles' => [],
            'profile_ids' => [],
            'clicked_urls' => [],
            'top_click_counts' => [],
            'status_counts' => [],
            'delivery_ids' => [],
            'attributed_order_ids' => [],
            'attributed_revenue_cents' => 0,
            'opens' => 0,
            'unique_opens' => 0,
            'clicks' => 0,
            'unique_clicks' => 0,
            'attributed_orders' => 0,
            'open_rate' => 0.0,
            'click_rate' => 0.0,
            'conversion_rate' => 0.0,
            'top_clicked_link' => null,
            'status' => 'sent',
        ];
    }

    protected function topClickedLink(array $clickCounts): ?string
    {
        if ($clickCounts === []) {
            return null;
        }

        arsort($clickCounts);
        $top = trim((string) array_key_first($clickCounts));

        return $top !== '' ? $top : null;
    }

    protected function resolvedStatus(array $statusCounts): string
    {
        if ($statusCounts === []) {
            return 'sent';
        }

        $rank = [
            'queued' => 10,
            'sending' => 20,
            'sent' => 30,
            'delivered' => 40,
            'opened' => 50,
            'clicked' => 60,
            'failed' => 70,
            'undelivered' => 70,
            'canceled' => 70,
        ];

        $resolved = 'sent';
        $resolvedRank = 0;

        foreach ($statusCounts as $status => $count) {
            if ((int) $count <= 0) {
                continue;
            }

            $normalized = strtolower(trim((string) $status));
            $candidateRank = (int) ($rank[$normalized] ?? 0);
            if ($candidateRank >= $resolvedRank) {
                $resolved = $normalized;
                $resolvedRank = $candidateRank;
            }
        }

        return $resolved;
    }

    protected function resolvedMessageNameFromDelivery(mixed $delivery, string $channel): string
    {
        if ($delivery === null) {
            return $channel === 'email' ? 'Email message' : 'SMS message';
        }

        $subject = $this->nullableString(data_get($delivery, 'message_subject'));
        if ($subject !== null) {
            return Str::limit($subject, 120);
        }

        if ($channel === 'email') {
            $metadataSubject = $this->nullableString(data_get($delivery, 'metadata.subject'));
            if ($metadataSubject !== null) {
                return Str::limit($metadataSubject, 120);
            }

            return 'Email message';
        }

        $message = $this->nullableString(data_get($delivery, 'rendered_message'));

        return $message !== null ? Str::limit($message, 120) : 'SMS message';
    }

    protected function resolvedSourceLabel(mixed $delivery): string
    {
        $source = $this->nullableString(data_get($delivery, 'source_label'))
            ?? $this->nullableString(data_get($delivery, 'metadata.source_label'))
            ?? $this->nullableString(data_get($delivery, 'provider_payload.source_label'));

        return $source ?? 'shopify_embedded_messaging';
    }

    protected function batchKey(string $channel, mixed $batchId, int $id): string
    {
        $batch = $this->nullableString($batchId);

        return $batch ?? (strtolower($channel).'-'.$id);
    }

    protected function messageKey(string $channel, string $batchKey): string
    {
        return strtolower($channel).':'.$batchKey;
    }

    /**
     * @param  Collection<int,mixed>  $collection
     * @param  callable(mixed):?CarbonImmutable  $resolver
     * @return array<string,int>
     */
    protected function countByDate(Collection $collection, callable $resolver): array
    {
        $counts = [];

        foreach ($collection as $item) {
            $date = $resolver($item);
            if (! $date instanceof CarbonImmutable) {
                continue;
            }

            $key = $date->format('Y-m-d');
            $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    protected function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function maxDateString(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        $leftDate = $this->dateOrNull($left);
        $rightDate = $this->dateOrNull($right);

        if (! $leftDate instanceof CarbonImmutable) {
            return $right;
        }

        if (! $rightDate instanceof CarbonImmutable) {
            return $left;
        }

        return $rightDate->greaterThan($leftDate) ? $right : $left;
    }

    protected function minDateString(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        $leftDate = $this->dateOrNull($left);
        $rightDate = $this->dateOrNull($right);

        if (! $leftDate instanceof CarbonImmutable) {
            return $right;
        }

        if (! $rightDate instanceof CarbonImmutable) {
            return $left;
        }

        return $rightDate->lessThan($leftDate) ? $right : $left;
    }

    protected function parseDate(mixed $value, CarbonImmutable $fallback): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = trim((string) $value);
        if ($string === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    protected function normalizedPhone(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
