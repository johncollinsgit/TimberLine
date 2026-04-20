<?php

namespace App\Services\Marketing;

use App\Models\MarketingDeliveryEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MessageAnalyticsService
{
    public function __construct(
        protected MessageLinkAggregationService $messageLinkAggregationService,
        protected MessageAnalyticsShopifyOrderSignalService $messageAnalyticsShopifyOrderSignalService,
        protected AiBudgetReadinessService $aiBudgetReadinessService
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

        $scope = $this->normalizedMessageScope(data_get($input, 'scope', 'all'));

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
            'scope' => $scope,
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
    public function index(?int $tenantId, ?string $storeKey, array $filters, array $options = []): array
    {
        $includeMessages = (bool) ($options['include_messages'] ?? true);
        $includeHistoryOutcomes = (bool) ($options['include_history_outcomes'] ?? true);
        $includeSalesSuccess = (bool) ($options['include_sales_success'] ?? false);
        $includeDecisionPanels = (bool) ($options['include_decision_panels'] ?? true);
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
                'sales_success' => $this->emptySalesSuccess(),
                'decision_panels' => $this->emptyDecisionPanels(),
                'diagnostics' => [
                    'reason' => 'tenant_or_store_missing',
                    'include_messages' => $includeMessages,
                    'include_history_outcomes' => $includeHistoryOutcomes,
                    'include_sales_success' => $includeSalesSuccess,
                    'include_decision_panels' => $includeDecisionPanels,
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
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $sortedRows = $filteredRows
            ->sortByDesc(fn (array $row): string => (string) ($row['sent_at'] ?? ''))
            ->values();
        $paginator = new LengthAwarePaginator([], $sortedRows->count(), $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        if ($includeMessages) {
            $offset = max(0, ($page - 1) * $perPage);
            $pageRows = $sortedRows->slice($offset, $perPage)->values();
            $paginator = new LengthAwarePaginator(
                $pageRows->all(),
                $sortedRows->count(),
                $perPage,
                $page,
                [
                    'path' => request()->url(),
                    'query' => request()->query(),
                ]
            );
        }

        return [
            'summary' => $this->summaryFromRows($filteredRows),
            'messages' => $paginator,
            'chart' => $this->chartFromDataset($dataset, $filters),
            'history_outcomes' => $includeHistoryOutcomes
                ? $this->historyOutcomes($tenantId, $normalizedStoreKey, $dataset, $filters)
                : $this->emptyHistoryOutcomes(),
            'sales_success' => $includeSalesSuccess
                ? $this->salesSuccess($tenantId, $dataset, $filteredRows)
                : $this->emptySalesSuccess(),
            'decision_panels' => $includeDecisionPanels
                ? $this->decisionPanels($tenantId, $normalizedStoreKey, $filters)
                : $this->emptyDecisionPanels(),
            'diagnostics' => [
                'total_rows' => $rows->count(),
                'filtered_rows' => $filteredRows->count(),
                'include_messages' => $includeMessages,
                'include_history_outcomes' => $includeHistoryOutcomes,
                'include_sales_success' => $includeSalesSuccess,
                'include_decision_panels' => $includeDecisionPanels,
            ],
            'raw' => [
                'email_deliveries' => $dataset['email_deliveries'] ?? collect(),
                'sms_deliveries' => $dataset['sms_deliveries'] ?? collect(),
                'engagement_events' => $dataset['engagement_events'] ?? collect(),
                'order_attributions' => $dataset['order_attributions'] ?? collect(),
            ],
        ];
    }

    public function detail(?int $tenantId, ?string $storeKey, string $messageKey, string $scope = 'all'): ?array
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
        $normalizedScope = $this->normalizedMessageScope($scope);

        $deliveries = $channel === 'email'
            ? $this->emailDeliveriesForMessageKey($tenantId, $normalizedStoreKey, $batchKey, $normalizedScope)
            : $this->smsDeliveriesForMessageKey($tenantId, $normalizedStoreKey, $batchKey, $normalizedScope);

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
        $batchIds = $deliveries
            ->pluck('batch_id')
            ->map(fn ($value): ?string => $this->nullableString($value))
            ->filter()
            ->unique()
            ->values();
        $isLogicalSmsRun = $channel === 'sms' && $this->parseSmsRunBatchKey($batchKey) !== null;

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
                'batch_id' => $isLogicalSmsRun ? null : $this->nullableString($firstDelivery?->batch_id),
                'batch_scope' => $isLogicalSmsRun ? 'logical_run' : 'batch',
                'batch_count' => $batchIds->count(),
                'batch_ids' => $batchIds->take(12)->all(),
                'store_key' => $normalizedStoreKey,
                'subject' => $this->nullableString($firstDelivery?->message_subject),
                'source_label' => $this->resolvedSourceLabel($firstDelivery),
            ],
            'opens_timeline' => [],
            'links' => [],
            'orders' => [],
            'funnel' => $this->emptyFunnelDetail(),
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
                    'order:id,tenant_id,order_number,order_label,customer_name,total_price,ordered_at,created_at,attribution_meta,shopify_store_key,shopify_store,shopify_order_id',
                    'profile:id,tenant_id,first_name,last_name,email,phone',
                ])
                ->orderByDesc('order_occurred_at')
                ->orderByDesc('id')
                ->get()
            : collect();

        $links = $this->messageLinkAggregationService->aggregate($clickEvents, $orderAttributions);
        $orderSignalMetaByOrderId = $this->messageAnalyticsShopifyOrderSignalService->refreshForOrders(
            $orderAttributions
                ->pluck('order')
                ->filter(fn ($order): bool => $order instanceof Order)
                ->values()
                ->all()
        );

        $orderRows = $orderAttributions
            ->map(function (MarketingMessageOrderAttribution $attribution) use ($profileMap, $orderSignalMetaByOrderId): array {
                $profile = $attribution->profile;
                $profileId = (int) ($attribution->marketing_profile_id ?? 0);
                if (! $profile instanceof MarketingProfile && $profileId > 0) {
                    $profile = $profileMap->get($profileId);
                }

                $order = $attribution->order;
                $orderId = (int) ($attribution->order_id ?? 0);
                $sourceMeta = $order instanceof Order
                    ? ($orderSignalMetaByOrderId[$orderId] ?? (is_array($order->attribution_meta ?? null) ? $order->attribution_meta : []))
                    : [];
                $profileName = $profile instanceof MarketingProfile
                    ? trim((string) $profile->first_name.' '.(string) $profile->last_name)
                    : '';

                return [
                    'order_id' => $orderId,
                    'order_number' => $this->nullableString($order?->order_number) ?? $this->nullableString($order?->order_label),
                    'customer' => $profileName !== ''
                        ? $profileName
                        : ($this->nullableString($order?->customer_name) ?? 'Customer'),
                    'customer_email' => $this->nullableString($profile?->email),
                    'url' => $this->nullableString($attribution->attributed_url),
                    'click_at' => optional($attribution->click_occurred_at)->toIso8601String(),
                    'ordered_at' => optional($attribution->order_occurred_at)->toIso8601String(),
                    'revenue_cents' => (int) ($attribution->revenue_cents ?? 0),
                    'landing_page' => $this->orderLandingPage($sourceMeta),
                    'referrer' => $this->orderReferrer($sourceMeta),
                    'source_summary' => $this->orderSourceSummary($sourceMeta),
                    'attribution_method' => $this->attributionRuleLabel(
                        $this->nullableString(data_get($attribution->metadata, 'attribution_rule'))
                    ),
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
        $detail['funnel'] = $this->storefrontFunnelDetail(
            tenantId: $tenantId,
            storeKey: $normalizedStoreKey,
            channel: $channel,
            deliveries: $deliveries,
            detail: $detail,
            orderRows: $orderRows
        );

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
        $scope = $this->normalizedMessageScope($filters['scope'] ?? 'all');
        $query = $this->nullableString($filters['q'] ?? null);

        $emailDeliveries = $channel === 'sms'
            ? collect()
            : $this->emailDeliveriesQuery($tenantId, $storeKey, $from, $to, $scope, $query)
                ->get();

        $smsDeliveries = $channel === 'email'
            ? collect()
            : $this->smsDeliveriesQuery($tenantId, $storeKey, $from, $to, $scope, $query)
                ->get();
        $smsMessageKeyByDeliveryId = $this->smsMessageKeys($smsDeliveries);

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
            $messageKey = $smsMessageKeyByDeliveryId[(int) $delivery->id]
                ?? $this->messageKey('sms', $this->batchKey('sms', $delivery->batch_id, (int) $delivery->id));
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
            $batchId = $this->nullableString($delivery->batch_id) ?? ('sms-'.(int) $delivery->id);
            $rows[$messageKey]['batch_ids'][$batchId] = true;

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
            $url = $this->nullableString($attribution->normalized_url)
                ?? $this->nullableString($attribution->attributed_url);
            if ($url !== null) {
                $rows[$messageKey]['clicked_urls'][] = $url;
                $rows[$messageKey]['attributed_url_counts'][$url] = ((int) ($rows[$messageKey]['attributed_url_counts'][$url] ?? 0)) + 1;
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
            $rows[$key]['top_clicked_link'] = $this->topClickedLink((array) ($row['top_click_counts'] ?? []))
                ?? $this->topClickedLink((array) ($row['attributed_url_counts'] ?? []));
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
            $rows[$key]['batch_count'] = count((array) ($row['batch_ids'] ?? []));
            $rows[$key]['aggregation_scope'] = $rows[$key]['channel'] === 'sms'
                && $this->parseSmsRunBatchKey((string) Str::after((string) $rows[$key]['message_key'], 'sms:')) !== null
                    ? 'logical_run'
                    : 'batch';
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
        string $scope = 'all',
        ?string $search = null
    ): Builder {
        $query = MarketingEmailDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
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

        return $this->applyEmailMessageScope($query, $scope);
    }

    protected function smsDeliveriesQuery(
        int $tenantId,
        string $storeKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $scope = 'all',
        ?string $search = null
    ): Builder {
        $query = MarketingMessageDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('channel', 'sms')
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
                'to_phone',
                'from_identifier',
            ]);

        return $this->applySmsMessageScope($query, $scope);
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
     * @param  array<string,mixed>  $dataset
     * @param  Collection<int,array<string,mixed>>  $filteredRows
     * @return array<string,mixed>
     */
    protected function salesSuccess(int $tenantId, array $dataset, Collection $filteredRows): array
    {
        /** @var Collection<int,MarketingMessageOrderAttribution> $orderAttributions */
        $orderAttributions = $dataset['order_attributions'] ?? collect();
        if ($orderAttributions->isEmpty() || $filteredRows->isEmpty()) {
            return $this->emptySalesSuccess();
        }

        $rowByMessageKey = $filteredRows
            ->filter(fn (array $row): bool => $this->nullableString($row['message_key'] ?? null) !== null)
            ->keyBy(fn (array $row): string => (string) ($row['message_key'] ?? ''));

        if ($rowByMessageKey->isEmpty()) {
            return $this->emptySalesSuccess();
        }

        $allowedMessageKeys = $rowByMessageKey->keys()->flip();

        $deliveryKeyByEmailDeliveryId = [];
        foreach ((array) ($dataset['delivery_key_by_email_delivery_id'] ?? []) as $deliveryId => $messageKey) {
            $resolvedDeliveryId = (int) $deliveryId;
            $resolvedMessageKey = $this->nullableString($messageKey);
            if ($resolvedDeliveryId > 0 && $resolvedMessageKey !== null) {
                $deliveryKeyByEmailDeliveryId[$resolvedDeliveryId] = $resolvedMessageKey;
            }
        }

        $deliveryKeyBySmsDeliveryId = [];
        foreach ((array) ($dataset['delivery_key_by_sms_delivery_id'] ?? []) as $deliveryId => $messageKey) {
            $resolvedDeliveryId = (int) $deliveryId;
            $resolvedMessageKey = $this->nullableString($messageKey);
            if ($resolvedDeliveryId > 0 && $resolvedMessageKey !== null) {
                $deliveryKeyBySmsDeliveryId[$resolvedDeliveryId] = $resolvedMessageKey;
            }
        }

        $messageKeyForAttribution = function (MarketingMessageOrderAttribution $attribution) use (
            $deliveryKeyByEmailDeliveryId,
            $deliveryKeyBySmsDeliveryId
        ): ?string {
            $emailDeliveryId = (int) ($attribution->marketing_email_delivery_id ?? 0);
            if ($emailDeliveryId > 0) {
                return $deliveryKeyByEmailDeliveryId[$emailDeliveryId] ?? null;
            }

            $smsDeliveryId = (int) ($attribution->marketing_message_delivery_id ?? 0);
            if ($smsDeliveryId > 0) {
                return $deliveryKeyBySmsDeliveryId[$smsDeliveryId] ?? null;
            }

            return null;
        };

        $filteredAttributions = $orderAttributions
            ->filter(function (MarketingMessageOrderAttribution $attribution) use ($allowedMessageKeys, $messageKeyForAttribution): bool {
                $messageKey = $messageKeyForAttribution($attribution);
                if ($messageKey === null) {
                    return false;
                }

                return $allowedMessageKeys->has($messageKey);
            })
            ->values();

        if ($filteredAttributions->isEmpty()) {
            return $this->emptySalesSuccess();
        }

        $orderIds = $filteredAttributions
            ->pluck('order_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
        $profileIds = $filteredAttributions
            ->pluck('marketing_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $orders = Order::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $orderIds->all())
            ->get(['id', 'order_number', 'order_label', 'customer_name', 'total_price', 'ordered_at', 'created_at', 'attribution_meta'])
            ->keyBy('id');
        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $profileIds->all())
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->keyBy('id');

        $orderSignalMetaByOrderId = $this->messageAnalyticsShopifyOrderSignalService->refreshForOrders(
            $orders->values()->all()
        );

        $rows = $filteredAttributions
            ->groupBy(fn (MarketingMessageOrderAttribution $attribution): int => (int) ($attribution->order_id ?? 0))
            ->map(function (Collection $group, int $orderId) use (
                $orders,
                $profiles,
                $rowByMessageKey,
                $messageKeyForAttribution,
                $orderSignalMetaByOrderId
            ): ?array {
                if ($orderId <= 0) {
                    return null;
                }

                $order = $orders->get($orderId);
                if (! $order instanceof Order) {
                    return null;
                }

                /** @var MarketingMessageOrderAttribution|null $primary */
                $primary = $group
                    ->sortByDesc(fn (MarketingMessageOrderAttribution $row): string => optional($row->order_occurred_at)->toIso8601String() ?? '')
                    ->first();
                if (! $primary instanceof MarketingMessageOrderAttribution) {
                    return null;
                }

                $messageKey = $messageKeyForAttribution($primary);
                $messageRow = $messageKey !== null ? (array) ($rowByMessageKey->get($messageKey) ?? []) : [];
                $rawChannel = strtolower(trim((string) (
                    $messageRow['channel']
                    ?? $primary->channel
                    ?? (((int) ($primary->marketing_message_delivery_id ?? 0)) > 0 ? 'sms' : 'email')
                )));

                $profileId = (int) ($primary->marketing_profile_id ?? 0);
                $profile = $profileId > 0 ? $profiles->get($profileId) : null;
                $profileName = $profile instanceof MarketingProfile
                    ? trim((string) $profile->first_name.' '.(string) $profile->last_name)
                    : '';

                $clickedPages = $group
                    ->map(function (MarketingMessageOrderAttribution $attribution): ?string {
                        return $this->nullableString($attribution->normalized_url)
                            ?? $this->nullableString($attribution->attributed_url);
                    })
                    ->filter(fn (?string $value): bool => $value !== null)
                    ->unique()
                    ->values()
                    ->all();

                $sourceMeta = $orderSignalMetaByOrderId[$orderId] ?? (is_array($order->attribution_meta ?? null) ? $order->attribution_meta : []);
                $landingPage = $this->orderLandingPage($sourceMeta);
                $sourceUrl = $this->nullableString($sourceMeta['source_url'] ?? null);
                $purchaseAt = optional($primary->order_occurred_at)->toIso8601String()
                    ?? optional($order->ordered_at ?? $order->created_at)->toIso8601String();
                $orderTotalCents = (int) round((float) ($order->total_price ?? 0) * 100);
                $attributedRevenueCents = (int) $group->sum('revenue_cents');
                $valueCents = $orderTotalCents > 0 ? $orderTotalCents : $attributedRevenueCents;

                return [
                    'order_id' => $orderId,
                    'order_number' => $this->nullableString($order->order_number)
                        ?? $this->nullableString($order->order_label)
                        ?? ('#'.$orderId),
                    'customer' => $profileName !== '' ? $profileName : ($this->nullableString($order->customer_name) ?? 'Customer'),
                    'customer_email' => $this->nullableString($profile?->email),
                    'channel' => $rawChannel,
                    'channel_label' => $this->salesChannelLabel($rawChannel),
                    'message_name' => $this->nullableString($messageRow['message_name'] ?? null) ?? ($rawChannel === 'sms' ? 'Text message' : 'Email message'),
                    'purchase_at' => $purchaseAt,
                    'value_cents' => $valueCents,
                    'attributed_revenue_cents' => $attributedRevenueCents,
                    'pages_followed' => $this->salesJourneySummary($clickedPages, $landingPage, $sourceUrl),
                ];
            })
            ->filter(fn (?array $row): bool => is_array($row))
            ->sortByDesc(fn (array $row): string => (string) ($row['purchase_at'] ?? ''))
            ->take(200)
            ->values()
            ->all();

        $rowsCollection = collect($rows);

        return [
            'rows' => $rows,
            'summary' => [
                'total_orders' => $rowsCollection->count(),
                'email_orders' => $rowsCollection->where('channel', 'email')->count(),
                'text_orders' => $rowsCollection->where('channel', 'sms')->count(),
                'total_value_cents' => (int) $rowsCollection->sum('value_cents'),
            ],
            'empty' => $rowsCollection->isEmpty(),
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
    protected function emptySalesSuccess(): array
    {
        return [
            'rows' => [],
            'summary' => [
                'total_orders' => 0,
                'email_orders' => 0,
                'text_orders' => 0,
                'total_value_cents' => 0,
            ],
            'empty' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function decisionPanels(int $tenantId, string $storeKey, array $filters): array
    {
        $from = $filters['date_from'] instanceof CarbonImmutable
            ? $filters['date_from']
            : now()->toImmutable()->subDays(30)->startOfDay();
        $to = $filters['date_to'] instanceof CarbonImmutable
            ? $filters['date_to']
            : now()->toImmutable()->endOfDay();

        $attributionQuality = $this->attributionQualityPanel($tenantId, $storeKey, $from, $to);
        $acquisitionFunnel = $this->acquisitionFunnelPanel($tenantId, $storeKey, $from, $to);
        $retention = $this->retentionPanel($tenantId, $storeKey, $from, $to);

        return [
            'attribution_quality' => $attributionQuality,
            'acquisition_funnel' => $acquisitionFunnel,
            'retention' => $retention,
            'action_queue' => $this->actionQueuePanel($attributionQuality, $acquisitionFunnel, $retention),
            'ai_budget_readiness' => $this->aiBudgetReadinessService->evaluate(
                tenantId: $tenantId,
                storeKey: $storeKey,
                from: $from,
                to: $to,
                attributionQuality: $attributionQuality,
                acquisitionFunnel: $acquisitionFunnel,
                retention: $retention
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyDecisionPanels(): array
    {
        return [
            'attribution_quality' => [
                'totals' => [
                    'purchases' => 0,
                    'utm_coverage_rate' => 0.0,
                    'self_referral_rate' => 0.0,
                    'unattributed_purchase_rate' => 0.0,
                    'purchase_linkage_match_rate' => 0.0,
                    'meta_relevant_purchases' => 0,
                    'meta_continuity_rate' => 0.0,
                ],
                'linkage_confidence' => [
                    'high' => 0,
                    'medium' => 0,
                    'low' => 0,
                    'unlinked' => 0,
                ],
                'meta_signal_coverage' => [
                    'fbclid_rate' => 0.0,
                    'fbc_rate' => 0.0,
                    'fbp_rate' => 0.0,
                ],
                'empty' => true,
            ],
            'acquisition_funnel' => [
                'steps' => [],
                'totals' => [
                    'sessions' => 0,
                    'landing_page_views' => 0,
                    'product_views' => 0,
                    'add_to_cart' => 0,
                    'checkout_started' => 0,
                    'purchases' => 0,
                    'session_to_purchase_rate' => 0.0,
                    'checkout_to_purchase_rate' => 0.0,
                ],
                'source_breakdown' => [],
                'empty' => true,
            ],
            'retention' => [
                'totals' => [
                    'orders' => 0,
                    'identifiable_orders' => 0,
                    'first_time_orders' => 0,
                    'returning_orders' => 0,
                    'unknown_orders' => 0,
                    'first_time_revenue_cents' => 0,
                    'returning_revenue_cents' => 0,
                    'unknown_revenue_cents' => 0,
                    'repeat_order_share_pct' => 0.0,
                    'returning_revenue_share_pct' => 0.0,
                ],
                'time_to_second_purchase' => [
                    'eligible_customers' => 0,
                    'converted_customers' => 0,
                    'conversion_rate_pct' => 0.0,
                    'median_days' => null,
                    'p75_days' => null,
                ],
                'cohorts' => [],
                'empty' => true,
            ],
            'action_queue' => [
                'items' => [],
                'empty' => true,
            ],
            'ai_budget_readiness' => [
                'score' => 0.0,
                'tier' => 'blocked',
                'window' => [
                    'date_from' => null,
                    'date_to' => null,
                ],
                'metrics' => [],
                'blockers' => [],
                'warnings' => [],
                'next_fixes' => [],
                'spend' => [
                    'rows_count' => 0,
                    'days_with_rows' => 0,
                    'expected_days' => 0,
                    'completeness_rate' => 0.0,
                    'campaigns_count' => 0,
                    'compliant_campaigns_count' => 0,
                    'campaign_naming_compliance_rate' => 0.0,
                    'last_synced_at' => null,
                    'freshness_lag_hours' => null,
                    'latest_purchase_at' => null,
                    'latest_funnel_event_at' => null,
                    'funnel_match_coverage_rate' => 0.0,
                    'campaign_performance' => [],
                ],
                'policy' => [
                    'mode' => 'advisory_only',
                    'actions' => [
                        'advisory_budget_recommendations' => [
                            'allowed' => false,
                            'reason' => 'Readiness is below advisory-ready.',
                        ],
                        'audience_recommendations' => [
                            'allowed' => true,
                            'reason' => 'Advisory-only recommendations.',
                        ],
                        'creative_copy_suggestions' => [
                            'allowed' => true,
                            'reason' => 'Advisory-only recommendations.',
                        ],
                        'automatic_budget_mutation' => [
                            'allowed' => false,
                            'reason' => 'Autonomous budget mutation is blocked.',
                        ],
                        'automatic_campaign_pausing' => [
                            'allowed' => false,
                            'reason' => 'Autonomous campaign pausing is blocked.',
                        ],
                        'automatic_channel_reallocation' => [
                            'allowed' => false,
                            'reason' => 'Autonomous channel reallocation is blocked.',
                        ],
                    ],
                    'blocked_reasons' => [],
                    'future_automation_guardrails' => [
                        'max_daily_budget_shift_pct' => 0,
                        'max_weekly_budget_shift_pct' => 0,
                        'rollback_window_hours' => 0,
                        'anomaly_trigger_roas_drop_pct' => 0,
                        'human_approval_required' => true,
                        'audit_log_required' => true,
                        'automation_enabled' => false,
                    ],
                ],
                'recommendations' => [],
                'empty' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function attributionQualityPanel(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::query()
            ->forTenantId($tenantId)
            ->where(function (Builder $query) use ($storeKey): void {
                $query->where('shopify_store_key', $storeKey)
                    ->orWhere('shopify_store', $storeKey);
            })
            ->whereBetween('ordered_at', [$from, $to])
            ->orderByDesc('ordered_at')
            ->get([
                'id',
                'ordered_at',
                'attribution_meta',
                'storefront_linked_event_id',
                'storefront_link_confidence',
                'storefront_checkout_token',
                'storefront_cart_token',
                'storefront_session_key',
                'storefront_client_id',
            ]);

        if ($orders->isEmpty()) {
            return $this->emptyDecisionPanels()['attribution_quality'];
        }

        $totals = [
            'purchases' => (int) $orders->count(),
            'utm_complete' => 0,
            'self_referrals' => 0,
            'unattributed_purchases' => 0,
            'linked_purchases' => 0,
            'meta_relevant_purchases' => 0,
            'meta_continuity_purchases' => 0,
            'with_fbclid' => 0,
            'with_fbc' => 0,
            'with_fbp' => 0,
        ];
        $linkageConfidence = [
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'unlinked' => 0,
        ];

        foreach ($orders as $order) {
            $meta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];

            $utmComplete = $this->nullableString($meta['utm_source'] ?? null) !== null
                && $this->nullableString($meta['utm_medium'] ?? null) !== null
                && $this->nullableString($meta['utm_campaign'] ?? null) !== null;
            if ($utmComplete) {
                $totals['utm_complete']++;
            }

            $selfReferral = $this->isOrderSelfReferral($meta);
            if ($selfReferral) {
                $totals['self_referrals']++;
            }

            if (! $this->orderHasAttributionSignals($meta, $selfReferral)) {
                $totals['unattributed_purchases']++;
            }

            $confidence = $this->orderLinkConfidence($order, $meta);
            if ($confidence === null) {
                $linkageConfidence['unlinked']++;
            } elseif ($confidence >= 0.80) {
                $linkageConfidence['high']++;
            } elseif ($confidence >= 0.50) {
                $linkageConfidence['medium']++;
            } else {
                $linkageConfidence['low']++;
            }

            if ($this->orderHasDurableLinkage($order, $meta, $confidence)) {
                $totals['linked_purchases']++;
            }

            $hasFbclid = $this->nullableString($meta['fbclid'] ?? null) !== null;
            $hasFbc = $this->nullableString($meta['fbc'] ?? null) !== null;
            $hasFbp = $this->nullableString($meta['fbp'] ?? null) !== null;

            if ($this->isMetaRelevantOrder($meta, $hasFbclid, $hasFbc, $hasFbp)) {
                $totals['meta_relevant_purchases']++;
                if ($hasFbclid || $hasFbc || $hasFbp) {
                    $totals['meta_continuity_purchases']++;
                }
                if ($hasFbclid) {
                    $totals['with_fbclid']++;
                }
                if ($hasFbc) {
                    $totals['with_fbc']++;
                }
                if ($hasFbp) {
                    $totals['with_fbp']++;
                }
            }
        }

        $purchaseCount = max(1, (int) $totals['purchases']);
        $metaRelevant = max(1, (int) $totals['meta_relevant_purchases']);

        return [
            'totals' => [
                'purchases' => (int) $totals['purchases'],
                'utm_coverage_rate' => round(((int) $totals['utm_complete'] / $purchaseCount) * 100, 1),
                'self_referral_rate' => round(((int) $totals['self_referrals'] / $purchaseCount) * 100, 1),
                'unattributed_purchase_rate' => round(((int) $totals['unattributed_purchases'] / $purchaseCount) * 100, 1),
                'purchase_linkage_match_rate' => round(((int) $totals['linked_purchases'] / $purchaseCount) * 100, 1),
                'meta_relevant_purchases' => (int) $totals['meta_relevant_purchases'],
                'meta_continuity_rate' => (int) $totals['meta_relevant_purchases'] > 0
                    ? round(((int) $totals['meta_continuity_purchases'] / $metaRelevant) * 100, 1)
                    : 0.0,
            ],
            'linkage_confidence' => $linkageConfidence,
            'meta_signal_coverage' => [
                'fbclid_rate' => (int) $totals['meta_relevant_purchases'] > 0
                    ? round(((int) $totals['with_fbclid'] / $metaRelevant) * 100, 1)
                    : 0.0,
                'fbc_rate' => (int) $totals['meta_relevant_purchases'] > 0
                    ? round(((int) $totals['with_fbc'] / $metaRelevant) * 100, 1)
                    : 0.0,
                'fbp_rate' => (int) $totals['meta_relevant_purchases'] > 0
                    ? round(((int) $totals['with_fbp'] / $metaRelevant) * 100, 1)
                    : 0.0,
            ],
            'empty' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function acquisitionFunnelPanel(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return $this->emptyDecisionPanels()['acquisition_funnel'];
        }

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereIn('event_type', [
                'session_started',
                'landing_page_viewed',
                'product_viewed',
                'add_to_cart',
                'checkout_started',
                'purchase',
            ])
            ->where(function (Builder $query): void {
                $query->where('source_type', 'shopify_storefront_funnel')
                    ->orWhere('source_type', 'shopify_storefront_purchase');
            })
            ->get(['id', 'event_type', 'source_type', 'meta', 'occurred_at'])
            ->filter(function (MarketingStorefrontEvent $event) use ($storeKey): bool {
                $meta = is_array($event->meta ?? null) ? $event->meta : [];
                $eventStoreKey = $this->nullableString($meta['store_key'] ?? null);

                return $eventStoreKey === null || $eventStoreKey === $storeKey;
            })
            ->values();

        if ($events->isEmpty()) {
            return $this->emptyDecisionPanels()['acquisition_funnel'];
        }

        $totals = [
            'sessions' => (int) $events->where('event_type', 'session_started')->count(),
            'landing_page_views' => (int) $events->where('event_type', 'landing_page_viewed')->count(),
            'product_views' => (int) $events->where('event_type', 'product_viewed')->count(),
            'add_to_cart' => (int) $events->where('event_type', 'add_to_cart')->count(),
            'checkout_started' => (int) $events->where('event_type', 'checkout_started')->count(),
            'purchases' => (int) $events->where('event_type', 'purchase')->count(),
        ];

        $steps = [
            [
                'key' => 'sessions',
                'label' => 'Sessions',
                'count' => (int) $totals['sessions'],
                'conversion_from_previous_rate' => null,
            ],
            [
                'key' => 'landing_page_views',
                'label' => 'Landing page views',
                'count' => (int) $totals['landing_page_views'],
                'conversion_from_previous_rate' => $this->ratio((int) $totals['landing_page_views'], (int) $totals['sessions']),
            ],
            [
                'key' => 'product_views',
                'label' => 'Product views',
                'count' => (int) $totals['product_views'],
                'conversion_from_previous_rate' => $this->ratio((int) $totals['product_views'], (int) $totals['landing_page_views']),
            ],
            [
                'key' => 'add_to_cart',
                'label' => 'Add to cart',
                'count' => (int) $totals['add_to_cart'],
                'conversion_from_previous_rate' => $this->ratio((int) $totals['add_to_cart'], (int) $totals['product_views']),
            ],
            [
                'key' => 'checkout_started',
                'label' => 'Checkout started',
                'count' => (int) $totals['checkout_started'],
                'conversion_from_previous_rate' => $this->ratio((int) $totals['checkout_started'], (int) $totals['add_to_cart']),
            ],
            [
                'key' => 'purchases',
                'label' => 'Purchases',
                'count' => (int) $totals['purchases'],
                'conversion_from_previous_rate' => $this->ratio((int) $totals['purchases'], (int) $totals['checkout_started']),
            ],
        ];

        $sourceRows = [];
        foreach ($events as $event) {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];
            $source = $this->eventSourceValue($meta);
            $medium = $this->eventMediumValue($meta);
            $campaign = $this->eventCampaignValue($meta);
            $groupKey = strtolower($source.'|'.$medium.'|'.$campaign);

            if (! isset($sourceRows[$groupKey])) {
                $sourceRows[$groupKey] = [
                    'source' => $source,
                    'medium' => $medium,
                    'campaign' => $campaign,
                    'sessions' => 0,
                    'landing_page_views' => 0,
                    'product_views' => 0,
                    'add_to_cart' => 0,
                    'checkout_started' => 0,
                    'purchases' => 0,
                ];
            }

            if ($event->event_type === 'session_started') {
                $sourceRows[$groupKey]['sessions']++;
            } elseif ($event->event_type === 'landing_page_viewed') {
                $sourceRows[$groupKey]['landing_page_views']++;
            } elseif ($event->event_type === 'product_viewed') {
                $sourceRows[$groupKey]['product_views']++;
            } elseif ($event->event_type === 'add_to_cart') {
                $sourceRows[$groupKey]['add_to_cart']++;
            } elseif ($event->event_type === 'checkout_started') {
                $sourceRows[$groupKey]['checkout_started']++;
            } elseif ($event->event_type === 'purchase') {
                $sourceRows[$groupKey]['purchases']++;
            }
        }

        $sourceBreakdown = collect($sourceRows)
            ->map(function (array $row): array {
                $row['session_to_purchase_rate'] = $this->ratio((int) $row['purchases'], (int) $row['sessions']);
                $row['checkout_to_purchase_rate'] = $this->ratio((int) $row['purchases'], (int) $row['checkout_started']);

                return $row;
            })
            ->sortByDesc(fn (array $row): int => ((int) $row['purchases'] * 10000) + (int) $row['sessions'])
            ->take(8)
            ->values()
            ->all();

        return [
            'steps' => $steps,
            'totals' => [
                ...$totals,
                'session_to_purchase_rate' => $this->ratio((int) $totals['purchases'], (int) $totals['sessions']),
                'checkout_to_purchase_rate' => $this->ratio((int) $totals['purchases'], (int) $totals['checkout_started']),
            ],
            'source_breakdown' => $sourceBreakdown,
            'empty' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function retentionPanel(int $tenantId, string $storeKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::query()
            ->forTenantId($tenantId)
            ->where(function (Builder $query) use ($storeKey): void {
                $query->where('shopify_store_key', $storeKey)
                    ->orWhere('shopify_store', $storeKey);
            })
            ->where(function (Builder $query) use ($to): void {
                $query->where(function (Builder $ordered) use ($to): void {
                    $ordered->whereNotNull('ordered_at')
                        ->where('ordered_at', '<=', $to);
                })->orWhere(function (Builder $fallback) use ($to): void {
                    $fallback->whereNull('ordered_at')
                        ->where('created_at', '<=', $to);
                });
            })
            ->orderBy('ordered_at')
            ->orderBy('id')
            ->get([
                'id',
                'ordered_at',
                'created_at',
                'total_price',
                'shopify_customer_id',
                'email',
                'customer_email',
                'shipping_email',
                'billing_email',
            ]);

        if ($orders->isEmpty()) {
            return $this->emptyDecisionPanels()['retention'];
        }

        $orderIds = $orders->pluck('id')
            ->map(fn ($value): string => (string) (int) $value)
            ->filter()
            ->values()
            ->all();

        $profileByOrderId = Schema::hasTable('marketing_profile_links')
            ? MarketingProfileLink::query()
                ->forTenantId($tenantId)
                ->where('source_type', 'order')
                ->whereIn('source_id', $orderIds)
                ->get(['source_id', 'marketing_profile_id'])
                ->mapWithKeys(function (MarketingProfileLink $link): array {
                    $orderId = (int) $link->source_id;

                    return $orderId > 0
                        ? [$orderId => (int) $link->marketing_profile_id]
                        : [];
                })
                ->all()
            : [];

        $identityOrders = [];
        $firstTimeRevenueCents = 0;
        $returningRevenueCents = 0;
        $unknownRevenueCents = 0;
        $firstTimeOrders = 0;
        $returningOrders = 0;
        $unknownOrders = 0;
        $windowOrders = 0;
        $identifiableWindowOrders = 0;

        foreach ($orders as $order) {
            $orderAt = $this->dateOrNull($order->ordered_at) ?? $this->dateOrNull($order->created_at);
            if (! $orderAt instanceof CarbonImmutable) {
                continue;
            }

            $profileId = (int) ($profileByOrderId[(int) $order->id] ?? 0);
            $identity = $this->orderIdentityKey($order, $profileId > 0 ? $profileId : null);
            if ($identity === null) {
                $identity = 'order:'.(string) $order->id;
            }

            if (! isset($identityOrders[$identity])) {
                $identityOrders[$identity] = [];
            }
            $identityOrders[$identity][] = [
                'order_id' => (int) $order->id,
                'occurred_at' => $orderAt,
            ];

            if ($orderAt->lt($from) || $orderAt->gt($to)) {
                continue;
            }

            $windowOrders++;
            $revenueCents = (int) round((float) ($order->total_price ?? 0) * 100);
            $orderSequence = count($identityOrders[$identity]);

            if (str_starts_with($identity, 'order:')) {
                $unknownOrders++;
                $unknownRevenueCents += $revenueCents;

                continue;
            }

            $identifiableWindowOrders++;
            if ($orderSequence <= 1) {
                $firstTimeOrders++;
                $firstTimeRevenueCents += $revenueCents;
            } else {
                $returningOrders++;
                $returningRevenueCents += $revenueCents;
            }
        }

        $timeToSecondDays = [];
        $cohorts = [];
        $cohortStart = $to->subMonths(5)->startOfMonth();
        $eligibleFirstOrderCustomers = 0;

        foreach ($identityOrders as $identity => $events) {
            if (str_starts_with($identity, 'order:')) {
                continue;
            }

            usort($events, fn (array $left, array $right): int => $left['occurred_at']->greaterThan($right['occurred_at']) ? 1 : -1);
            $firstAt = $events[0]['occurred_at'] ?? null;
            $secondAt = $events[1]['occurred_at'] ?? null;
            if (! $firstAt instanceof CarbonImmutable) {
                continue;
            }

            if ($firstAt->betweenIncluded($from, $to) && $secondAt instanceof CarbonImmutable) {
                $timeToSecondDays[] = $firstAt->diffInDays($secondAt);
            }
            if ($firstAt->betweenIncluded($from, $to)) {
                $eligibleFirstOrderCustomers++;
            }

            if ($firstAt->lt($cohortStart) || $firstAt->gt($to)) {
                continue;
            }

            $cohortKey = $firstAt->format('Y-m');
            if (! isset($cohorts[$cohortKey])) {
                $cohorts[$cohortKey] = [
                    'cohort' => $cohortKey,
                    'new_customers' => 0,
                    'repeat_30d' => 0,
                    'repeat_60d' => 0,
                ];
            }

            $cohorts[$cohortKey]['new_customers']++;

            if ($secondAt instanceof CarbonImmutable) {
                $days = $firstAt->diffInDays($secondAt);
                if ($days <= 30) {
                    $cohorts[$cohortKey]['repeat_30d']++;
                }
                if ($days <= 60) {
                    $cohorts[$cohortKey]['repeat_60d']++;
                }
            }
        }

        $cohortRows = collect($cohorts)
            ->sortKeys()
            ->map(function (array $row): array {
                $base = max(1, (int) $row['new_customers']);
                $row['repeat_30d_rate'] = round(((int) $row['repeat_30d'] / $base) * 100, 1);
                $row['repeat_60d_rate'] = round(((int) $row['repeat_60d'] / $base) * 100, 1);

                return $row;
            })
            ->values()
            ->all();

        sort($timeToSecondDays);
        $timeToSecondCount = count($timeToSecondDays);

        return [
            'totals' => [
                'orders' => $windowOrders,
                'identifiable_orders' => $identifiableWindowOrders,
                'first_time_orders' => $firstTimeOrders,
                'returning_orders' => $returningOrders,
                'unknown_orders' => $unknownOrders,
                'first_time_revenue_cents' => $firstTimeRevenueCents,
                'returning_revenue_cents' => $returningRevenueCents,
                'unknown_revenue_cents' => $unknownRevenueCents,
                'repeat_order_share_pct' => $this->ratio($returningOrders, $firstTimeOrders + $returningOrders),
                'returning_revenue_share_pct' => $this->ratio($returningRevenueCents, $firstTimeRevenueCents + $returningRevenueCents),
            ],
            'time_to_second_purchase' => [
                'eligible_customers' => $eligibleFirstOrderCustomers,
                'converted_customers' => $timeToSecondCount,
                'conversion_rate_pct' => $this->ratio($timeToSecondCount, $eligibleFirstOrderCustomers),
                'median_days' => $timeToSecondCount > 0 ? $this->percentile($timeToSecondDays, 0.5) : null,
                'p75_days' => $timeToSecondCount > 0 ? $this->percentile($timeToSecondDays, 0.75) : null,
            ],
            'cohorts' => $cohortRows,
            'empty' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $attributionQuality
     * @param  array<string,mixed>  $acquisitionFunnel
     * @param  array<string,mixed>  $retention
     * @return array<string,mixed>
     */
    protected function actionQueuePanel(array $attributionQuality, array $acquisitionFunnel, array $retention): array
    {
        $items = [];

        $utmCoverage = (float) data_get($attributionQuality, 'totals.utm_coverage_rate', 0.0);
        if ($utmCoverage < 70.0) {
            $items[] = [
                'priority' => 'high',
                'title' => 'Fix UTM discipline on outbound links',
                'reason' => 'UTM coverage is '.$utmCoverage.'% of purchases.',
                'owner' => 'marketing',
                'action' => 'Enforce tagged links for every email, SMS, and paid-social destination URL.',
            ];
        }

        $selfReferralRate = (float) data_get($attributionQuality, 'totals.self_referral_rate', 0.0);
        if ($selfReferralRate > 8.0) {
            $items[] = [
                'priority' => 'high',
                'title' => 'Clean up self-referrals before channel decisions',
                'reason' => 'Self-referrals are '.$selfReferralRate.'% of purchases.',
                'owner' => 'engineering',
                'action' => 'Normalize referrer capture and prevent first-party domains from overriding acquisition source.',
            ];
        }

        $unattributedRate = (float) data_get($attributionQuality, 'totals.unattributed_purchase_rate', 0.0);
        if ($unattributedRate > 20.0) {
            $items[] = [
                'priority' => 'high',
                'title' => 'Reduce unattributed purchases',
                'reason' => 'Unattributed purchases are '.$unattributedRate.'% in this window.',
                'owner' => 'engineering',
                'action' => 'Audit order ingest attribution_meta hydration for missing landing/referrer/UTM context.',
            ];
        }

        $linkageRate = (float) data_get($attributionQuality, 'totals.purchase_linkage_match_rate', 0.0);
        if ($linkageRate < 85.0) {
            $items[] = [
                'priority' => 'high',
                'title' => 'Increase checkout to purchase linkage reliability',
                'reason' => 'Only '.$linkageRate.'% of purchases have durable linkage.',
                'owner' => 'engineering',
                'action' => 'Prioritize checkout_token/cart_token/session token persistence through order ingest.',
            ];
        }

        $metaRelevant = (int) data_get($attributionQuality, 'totals.meta_relevant_purchases', 0);
        $metaContinuityRate = (float) data_get($attributionQuality, 'totals.meta_continuity_rate', 0.0);
        if ($metaRelevant >= 10 && $metaContinuityRate < 70.0) {
            $items[] = [
                'priority' => 'medium',
                'title' => 'Improve Meta signal continuity',
                'reason' => 'Only '.$metaContinuityRate.'% of Meta-relevant purchases carry fbclid/fbc/fbp.',
                'owner' => 'engineering',
                'action' => 'Verify fbclid/fbc/fbp handoff from landing through checkout and order ingestion.',
            ];
        }

        $checkoutToPurchaseRate = (float) data_get($acquisitionFunnel, 'totals.checkout_to_purchase_rate', 0.0);
        if ($checkoutToPurchaseRate > 0 && $checkoutToPurchaseRate < 45.0) {
            $items[] = [
                'priority' => 'medium',
                'title' => 'Investigate checkout drop-off',
                'reason' => 'Checkout to purchase conversion is '.$checkoutToPurchaseRate.'%.',
                'owner' => 'operator',
                'action' => 'Review checkout UX, shipping thresholds, and offer timing before scaling paid traffic.',
            ];
        }

        $returningRevenueShare = (float) data_get($retention, 'totals.returning_revenue_share_pct', 0.0);
        if ($returningRevenueShare >= 55.0) {
            $items[] = [
                'priority' => 'medium',
                'title' => 'Lean into retention-led revenue',
                'reason' => 'Returning customers drive '.$returningRevenueShare.'% of identified revenue.',
                'owner' => 'marketing',
                'action' => 'Prioritize post-purchase, winback, and repeat-buyer message sequencing in Phase 4.',
            ];
        }

        if ($items === []) {
            $items[] = [
                'priority' => 'low',
                'title' => 'Baseline is stable for Phase 4 workflow rollout',
                'reason' => 'No critical attribution or funnel integrity alarms triggered in this window.',
                'owner' => 'operator',
                'action' => 'Proceed with lifecycle workflow rollout using this baseline as control.',
            ];
        }

        $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($items, function (array $left, array $right) use ($priorityOrder): int {
            $leftRank = $priorityOrder[$left['priority'] ?? 'low'] ?? 99;
            $rightRank = $priorityOrder[$right['priority'] ?? 'low'] ?? 99;

            return $leftRank <=> $rightRank;
        });

        return [
            'items' => $items,
            'empty' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function orderHasAttributionSignals(array $meta, bool $selfReferral = false): bool
    {
        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'source_name',
            'source_type',
            'source_identifier',
            'fbclid',
            'fbc',
            'fbp',
        ] as $field) {
            if ($this->nullableString($meta[$field] ?? null) !== null) {
                return true;
            }
        }

        if ($selfReferral) {
            return false;
        }

        foreach ([
            'referrer',
            'referring_site',
            'landing_site',
            'landing_page',
        ] as $field) {
            if ($this->nullableString($meta[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function isOrderSelfReferral(array $meta): bool
    {
        $referrerHost = $this->normalizedHost(
            $this->hostFromUrl(
                $this->nullableString($meta['referrer'] ?? null)
                ?? $this->nullableString($meta['referring_site'] ?? null)
                ?? $this->nullableString($meta['referrer_url'] ?? null)
            )
        );
        if ($referrerHost === null) {
            return false;
        }

        $landingHost = $this->normalizedHost(
            $this->hostFromUrl(
                $this->nullableString($meta['landing_site'] ?? null)
                ?? $this->nullableString($meta['landing_page'] ?? null)
                ?? $this->nullableString($meta['shop_domain'] ?? null)
            )
        );
        if ($landingHost !== null && $referrerHost === $landingHost) {
            return true;
        }

        $sourceHost = $this->normalizedHost($this->hostFromUrl($this->nullableString($meta['source_url'] ?? null)));

        return $sourceHost !== null && $sourceHost === $referrerHost;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function orderLinkConfidence(Order $order, array $meta): ?float
    {
        $confidence = $order->storefront_link_confidence !== null
            ? (float) $order->storefront_link_confidence
            : null;
        if ($confidence !== null) {
            return $confidence;
        }

        $metaConfidence = data_get($meta, 'storefront_link.confidence');
        if (is_numeric($metaConfidence)) {
            return (float) $metaConfidence;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function orderHasDurableLinkage(Order $order, array $meta, ?float $confidence): bool
    {
        if ((int) ($order->storefront_linked_event_id ?? 0) > 0) {
            return true;
        }

        if ((bool) data_get($meta, 'storefront_link.linked', false)) {
            return true;
        }

        if ($confidence !== null && $confidence > 0.0) {
            return true;
        }

        return $this->nullableString($order->storefront_checkout_token ?? null) !== null
            || $this->nullableString($order->storefront_cart_token ?? null) !== null
            || $this->nullableString($order->storefront_session_key ?? null) !== null
            || $this->nullableString($order->storefront_client_id ?? null) !== null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function isMetaRelevantOrder(array $meta, bool $hasFbclid, bool $hasFbc, bool $hasFbp): bool
    {
        if ($hasFbclid || $hasFbc || $hasFbp) {
            return true;
        }

        $signals = [
            $this->nullableString($meta['utm_source'] ?? null),
            $this->nullableString($meta['utm_medium'] ?? null),
            $this->nullableString($meta['utm_campaign'] ?? null),
            $this->nullableString($meta['source_name'] ?? null),
            $this->nullableString($meta['source_type'] ?? null),
            $this->nullableString($meta['referrer'] ?? null),
            $this->nullableString($meta['referring_site'] ?? null),
        ];

        foreach ($signals as $signal) {
            $value = strtolower(trim((string) $signal));
            if ($value === '') {
                continue;
            }
            if (str_contains($value, 'facebook')
                || str_contains($value, 'instagram')
                || str_contains($value, 'meta')
                || str_contains($value, 'fb')
                || str_contains($value, 'ig')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function eventSourceValue(array $meta): string
    {
        $source = $this->nullableString($meta['utm_source'] ?? null)
            ?? $this->nullableString($meta['source_name'] ?? null)
            ?? $this->normalizedHost(
                $this->hostFromUrl(
                    $this->nullableString($meta['referrer'] ?? null)
                    ?? $this->nullableString($meta['referring_site'] ?? null)
                )
            );

        return $source ?? '(unattributed)';
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function eventMediumValue(array $meta): string
    {
        $medium = $this->nullableString($meta['utm_medium'] ?? null)
            ?? $this->nullableString($meta['source_type'] ?? null);

        return $medium ?? '(unattributed)';
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function eventCampaignValue(array $meta): string
    {
        return $this->nullableString($meta['utm_campaign'] ?? null) ?? '(none)';
    }

    protected function ratio(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator <= 0.0) {
            return 0.0;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 1);
    }

    /**
     * @param  array<int,int>  $values
     */
    protected function percentile(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        $index = (int) ceil(($percentile * count($values)) - 1);
        $boundedIndex = max(0, min(count($values) - 1, $index));

        return (int) $values[$boundedIndex];
    }

    /**
     * @param  mixed  $order
     */
    protected function orderIdentityKey($order, ?int $profileId): ?string
    {
        if ($profileId !== null && $profileId > 0) {
            return 'profile:'.$profileId;
        }

        $shopifyCustomerId = $this->nullableString($order->shopify_customer_id ?? null);
        if ($shopifyCustomerId !== null) {
            return 'shopify:'.$shopifyCustomerId;
        }

        $email = $this->nullableString(
            $order->customer_email
            ?? $order->email
            ?? $order->shipping_email
            ?? $order->billing_email
            ?? null
        );
        if ($email !== null) {
            return 'email:'.strtolower($email);
        }

        return null;
    }

    protected function hostFromUrl(?string $url): ?string
    {
        $value = $this->nullableString($url);
        if ($value === null) {
            return null;
        }

        $candidate = str_contains($value, '://') ? $value : ('https://'.$value);
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return trim($host);
    }

    protected function normalizedHost(?string $value): ?string
    {
        $host = $this->nullableString($value);
        if ($host === null) {
            return null;
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : null;
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
    protected function emailDeliveriesForMessageKey(int $tenantId, string $storeKey, string $batchKey, string $scope = 'all'): Collection
    {
        $query = MarketingEmailDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey);
        $query = $this->applyEmailMessageScope($query, $scope);

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
    protected function smsDeliveriesForMessageKey(int $tenantId, string $storeKey, string $batchKey, string $scope = 'all'): Collection
    {
        $query = MarketingMessageDelivery::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('channel', 'sms');
        $query = $this->applySmsMessageScope($query, $scope);

        $run = $this->parseSmsRunBatchKey($batchKey);
        if (is_array($run)) {
            $startAt = $run['start_at'];
            $endAt = $run['end_at'];
            $fingerprint = (string) ($run['fingerprint'] ?? '');

            return $query
                ->where(function (Builder $query) use ($startAt, $endAt): void {
                    $query->whereBetween('sent_at', [$startAt, $endAt])
                        ->orWhere(function (Builder $fallback) use ($startAt, $endAt): void {
                            $fallback->whereNull('sent_at')
                                ->whereBetween('created_at', [$startAt, $endAt]);
                        });
                })
                ->get()
                ->filter(function (MarketingMessageDelivery $delivery) use ($fingerprint, $startAt, $endAt): bool {
                    $timestamp = $this->smsRunTimestamp($delivery);
                    if (! $timestamp instanceof CarbonImmutable) {
                        return false;
                    }

                    return $this->smsRunFingerprint($delivery) === $fingerprint
                        && $timestamp->greaterThanOrEqualTo($startAt)
                        && $timestamp->lessThanOrEqualTo($endAt);
                })
                ->sortBy(fn (MarketingMessageDelivery $delivery): string => $this->smsRunSortKey($delivery))
                ->values();
        }

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
            'batch_ids' => [],
            'batch_count' => 0,
            'aggregation_scope' => 'batch',
            'top_click_counts' => [],
            'attributed_url_counts' => [],
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

    /**
     * @param  Collection<int,MarketingMessageDelivery>  $smsDeliveries
     * @return array<int,string>
     */
    protected function smsMessageKeys(Collection $smsDeliveries): array
    {
        if ($smsDeliveries->isEmpty()) {
            return [];
        }

        $keys = [];
        $gapSeconds = max(60, $this->smsRunGapMinutes() * 60);

        $groups = $smsDeliveries
            ->filter(fn (MarketingMessageDelivery $delivery): bool => (int) ($delivery->id ?? 0) > 0)
            ->groupBy(fn (MarketingMessageDelivery $delivery): string => $this->smsRunFingerprint($delivery));

        foreach ($groups as $fingerprint => $group) {
            $sorted = $group
                ->sortBy(fn (MarketingMessageDelivery $delivery): string => $this->smsRunSortKey($delivery))
                ->values();

            $currentDeliveryIds = [];
            $currentStart = null;
            $currentEnd = null;

            $flush = function () use (&$keys, &$currentDeliveryIds, &$currentStart, &$currentEnd, $fingerprint): void {
                if ($currentDeliveryIds === [] || ! $currentStart instanceof CarbonImmutable || ! $currentEnd instanceof CarbonImmutable) {
                    $currentDeliveryIds = [];
                    $currentStart = null;
                    $currentEnd = null;

                    return;
                }

                $messageKey = $this->messageKey('sms', $this->smsRunBatchKey((string) $fingerprint, $currentStart, $currentEnd));
                foreach ($currentDeliveryIds as $deliveryId) {
                    $keys[$deliveryId] = $messageKey;
                }

                $currentDeliveryIds = [];
                $currentStart = null;
                $currentEnd = null;
            };

            foreach ($sorted as $delivery) {
                $deliveryId = (int) ($delivery->id ?? 0);
                $timestamp = $this->smsRunTimestamp($delivery);

                if ($deliveryId <= 0 || ! $timestamp instanceof CarbonImmutable) {
                    continue;
                }

                if (! $currentStart instanceof CarbonImmutable || ! $currentEnd instanceof CarbonImmutable) {
                    $currentDeliveryIds = [$deliveryId];
                    $currentStart = $timestamp;
                    $currentEnd = $timestamp;

                    continue;
                }

                if ($currentEnd->diffInSeconds($timestamp) > $gapSeconds) {
                    $flush();
                    $currentDeliveryIds = [$deliveryId];
                    $currentStart = $timestamp;
                    $currentEnd = $timestamp;

                    continue;
                }

                $currentDeliveryIds[] = $deliveryId;
                if ($timestamp->lessThan($currentStart)) {
                    $currentStart = $timestamp;
                }
                if ($timestamp->greaterThan($currentEnd)) {
                    $currentEnd = $timestamp;
                }
            }

            $flush();
        }

        foreach ($smsDeliveries as $delivery) {
            $deliveryId = (int) ($delivery->id ?? 0);
            if ($deliveryId <= 0 || isset($keys[$deliveryId])) {
                continue;
            }

            $keys[$deliveryId] = $this->messageKey('sms', $this->batchKey('sms', $delivery->batch_id, $deliveryId));
        }

        return $keys;
    }

    protected function smsRunGapMinutes(): int
    {
        return max(1, (int) config('marketing.message_analytics.sms_run_gap_minutes', 5));
    }

    protected function smsRunFingerprint(MarketingMessageDelivery $delivery): string
    {
        return sha1(implode('|', [
            $this->fingerprintValue($this->resolvedSourceLabel($delivery)),
            $this->fingerprintValue($delivery->message_subject),
            $this->fingerprintValue($delivery->rendered_message),
            $this->fingerprintValue($delivery->from_identifier),
        ]));
    }

    protected function smsRunTimestamp(MarketingMessageDelivery $delivery): ?CarbonImmutable
    {
        return $this->dateOrNull($delivery->sent_at ?? $delivery->created_at);
    }

    protected function smsRunSortKey(MarketingMessageDelivery $delivery): string
    {
        $timestamp = $this->smsRunTimestamp($delivery)?->utc()->timestamp ?? 0;

        return sprintf('%012d:%010d', $timestamp, (int) ($delivery->id ?? 0));
    }

    protected function smsRunBatchKey(string $fingerprint, CarbonImmutable $startAt, CarbonImmutable $endAt): string
    {
        return sprintf('run|%s|%d|%d', $fingerprint, $startAt->utc()->timestamp, $endAt->utc()->timestamp);
    }

    /**
     * @return array{fingerprint:string,start_at:CarbonImmutable,end_at:CarbonImmutable}|null
     */
    protected function parseSmsRunBatchKey(string $batchKey): ?array
    {
        $parts = explode('|', $batchKey);
        if (count($parts) !== 4 || strtolower(trim((string) ($parts[0] ?? ''))) !== 'run') {
            return null;
        }

        $fingerprint = strtolower(trim((string) ($parts[1] ?? '')));
        $startTimestamp = trim((string) ($parts[2] ?? ''));
        $endTimestamp = trim((string) ($parts[3] ?? ''));

        if (! preg_match('/^[a-f0-9]{40}$/', $fingerprint)
            || ! ctype_digit($startTimestamp)
            || ! ctype_digit($endTimestamp)) {
            return null;
        }

        $startAt = CarbonImmutable::createFromTimestampUTC((int) $startTimestamp);
        $endAt = CarbonImmutable::createFromTimestampUTC((int) $endTimestamp);

        if ($endAt->lessThan($startAt)) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }

        return [
            'fingerprint' => $fingerprint,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    protected function messageKey(string $channel, string $batchKey): string
    {
        return strtolower($channel).':'.$batchKey;
    }

    protected function fingerprintValue(mixed $value): string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return '';
        }

        return Str::of($string)
            ->lower()
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->value();
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

    protected function emptyFunnelDetail(): array
    {
        return [
            'summary' => [
                'sessions_started' => 0,
                'landing_page_views' => 0,
                'product_views' => 0,
                'wishlist_adds' => 0,
                'add_to_cart' => 0,
                'checkout_started' => 0,
                'checkout_completed' => 0,
                'purchases' => 0,
                'checkout_abandoned_candidates' => 0,
            ],
            'products' => [],
            'events' => [],
        ];
    }

    /**
     * @param  Collection<int,mixed>  $deliveries
     * @param  array<string,mixed>  $detail
     * @param  array<int,array<string,mixed>>  $orderRows
     * @return array<string,mixed>
     */
    protected function storefrontFunnelDetail(
        int $tenantId,
        string $storeKey,
        string $channel,
        Collection $deliveries,
        array $detail,
        array $orderRows
    ): array {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return $this->emptyFunnelDetail();
        }

        $deliveryIds = $deliveries
            ->pluck('id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
        $campaignIds = $deliveries
            ->map(fn ($row): int => (int) data_get($row, 'raw_payload.campaign_id', 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
        $profileIds = $deliveries
            ->pluck('marketing_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        if ($deliveryIds === [] && $campaignIds === []) {
            return $this->emptyFunnelDetail();
        }

        $sentAt = $this->dateOrNull($detail['sent_at'] ?? null) ?? now()->toImmutable()->subDay();
        $lastSentAt = $this->dateOrNull($detail['last_sent_at'] ?? null) ?? $sentAt;
        $windowDays = max(1, (int) config('marketing.messaging.attribution_window_days', 7));

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->whereIn('event_type', [
                'session_started',
                'landing_page_viewed',
                'product_viewed',
                'wishlist_added',
                'add_to_cart',
                'checkout_started',
                'checkout_completed',
                'purchase',
            ])
            ->whereBetween('occurred_at', [$sentAt->subHour(), $lastSentAt->addDays($windowDays)])
            ->orderByDesc('occurred_at')
            ->get([
                'id',
                'event_type',
                'marketing_profile_id',
                'source_surface',
                'meta',
                'occurred_at',
            ])
            ->filter(function (MarketingStorefrontEvent $event) use ($storeKey, $deliveryIds, $campaignIds, $profileIds, $channel): bool {
                return $this->storefrontEventMatchesMessage($event, $storeKey, $deliveryIds, $campaignIds, $profileIds, $channel);
            })
            ->values();

        if ($events->isEmpty()) {
            return $this->emptyFunnelDetail();
        }

        $summary = [
            'sessions_started' => (int) $events->where('event_type', 'session_started')->count(),
            'landing_page_views' => (int) $events->where('event_type', 'landing_page_viewed')->count(),
            'product_views' => (int) $events->where('event_type', 'product_viewed')->count(),
            'wishlist_adds' => (int) $events->where('event_type', 'wishlist_added')->count(),
            'add_to_cart' => (int) $events->where('event_type', 'add_to_cart')->count(),
            'checkout_started' => (int) $events->where('event_type', 'checkout_started')->count(),
            'checkout_completed' => (int) $events->where('event_type', 'checkout_completed')->count(),
            'purchases' => (int) $events->where('event_type', 'purchase')->count(),
            'checkout_abandoned_candidates' => 0,
        ];

        $checkoutStartedKeys = $events
            ->where('event_type', 'checkout_started')
            ->map(fn (MarketingStorefrontEvent $event): ?string => $this->storefrontJourneyKey((array) ($event->meta ?? [])))
            ->filter()
            ->unique()
            ->values();
        $checkoutCompletedKeys = $events
            ->filter(fn (MarketingStorefrontEvent $event): bool => in_array((string) $event->event_type, ['checkout_completed', 'purchase'], true))
            ->map(fn (MarketingStorefrontEvent $event): ?string => $this->storefrontJourneyKey((array) ($event->meta ?? [])))
            ->filter()
            ->unique()
            ->values();

        $summary['checkout_abandoned_candidates'] = max(
            0,
            $checkoutStartedKeys->diff($checkoutCompletedKeys)->count()
        );

        $products = $events
            ->filter(function (MarketingStorefrontEvent $event): bool {
                $meta = is_array($event->meta ?? null) ? $event->meta : [];

                return $this->nullableString($meta['product_id'] ?? null) !== null
                    || $this->nullableString($meta['product_title'] ?? null) !== null
                    || $this->nullableString($meta['mf_product_id'] ?? null) !== null;
            })
            ->groupBy(function (MarketingStorefrontEvent $event): string {
                $meta = is_array($event->meta ?? null) ? $event->meta : [];

                return $this->nullableString($meta['product_id'] ?? null)
                    ?? $this->nullableString($meta['mf_product_id'] ?? null)
                    ?? $this->nullableString($meta['product_title'] ?? null)
                    ?? 'product';
            })
            ->map(function (Collection $group, string $productKey): array {
                /** @var MarketingStorefrontEvent|null $sample */
                $sample = $group->first();
                $meta = is_array($sample?->meta ?? null) ? $sample->meta : [];
                $views = (int) $group->where('event_type', 'product_viewed')->count();
                $wishlistAdds = (int) $group->where('event_type', 'wishlist_added')->count();
                $addToCart = (int) $group->where('event_type', 'add_to_cart')->count();

                return [
                    'product_key' => $productKey,
                    'product_id' => $this->nullableString($meta['product_id'] ?? null) ?? $this->nullableString($meta['mf_product_id'] ?? null),
                    'product_title' => $this->nullableString($meta['product_title'] ?? null) ?? 'Product',
                    'product_handle' => $this->nullableString($meta['product_handle'] ?? null),
                    'product_views' => $views,
                    'wishlist_adds' => $wishlistAdds,
                    'add_to_cart' => $addToCart,
                    'score' => ($addToCart * 100) + ($wishlistAdds * 10) + $views,
                ];
            })
            ->sortByDesc('score')
            ->take(8)
            ->map(function (array $row): array {
                unset($row['score']);

                return $row;
            })
            ->values()
            ->all();

        $recentEvents = $events
            ->take(20)
            ->map(function (MarketingStorefrontEvent $event): array {
                $meta = is_array($event->meta ?? null) ? $event->meta : [];

                return [
                    'event_type' => (string) $event->event_type,
                    'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                    'product_title' => $this->nullableString($meta['product_title'] ?? null),
                    'product_id' => $this->nullableString($meta['product_id'] ?? null) ?? $this->nullableString($meta['mf_product_id'] ?? null),
                    'page_path' => $this->nullableString($meta['page_path'] ?? null) ?? $this->nullableString($meta['landing_path'] ?? null),
                ];
            })
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'products' => $products,
            'events' => $recentEvents,
            'orders_count' => count($orderRows),
        ];
    }

    /**
     * @param  array<int,int>  $deliveryIds
     * @param  array<int,int>  $campaignIds
     * @param  array<int,int>  $profileIds
     */
    protected function storefrontEventMatchesMessage(
        MarketingStorefrontEvent $event,
        string $storeKey,
        array $deliveryIds,
        array $campaignIds,
        array $profileIds,
        string $channel
    ): bool {
        $meta = is_array($event->meta ?? null) ? $event->meta : [];
        $eventStoreKey = $this->nullableString($meta['store_key'] ?? null);
        if ($eventStoreKey !== null && $eventStoreKey !== $storeKey) {
            return false;
        }

        $eventChannel = strtolower(trim((string) ($meta['mf_channel'] ?? '')));
        if ($eventChannel !== '' && $eventChannel !== strtolower($channel)) {
            return false;
        }

        $deliveryId = $this->positiveInt($meta['mf_delivery_id'] ?? null);
        if ($deliveryId !== null && in_array($deliveryId, $deliveryIds, true)) {
            return true;
        }

        $campaignId = $this->positiveInt($meta['mf_campaign_id'] ?? null);
        $profileId = $this->positiveInt($meta['mf_profile_id'] ?? null) ?? $this->positiveInt($event->marketing_profile_id ?? null);
        if ($campaignId !== null && in_array($campaignId, $campaignIds, true)) {
            return $profileId === null || in_array($profileId, $profileIds, true);
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function storefrontJourneyKey(array $meta): ?string
    {
        return $this->nullableString($meta['checkout_token'] ?? null)
            ?? $this->nullableString($meta['session_key'] ?? null)
            ?? ($this->positiveInt($meta['mf_delivery_id'] ?? null) !== null
                ? 'delivery:'.(string) $this->positiveInt($meta['mf_delivery_id'] ?? null)
                : null);
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

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    protected function orderLandingPage(array $sourceMeta): ?string
    {
        return $this->nullableString($sourceMeta['landing_page'] ?? null)
            ?? $this->nullableString($sourceMeta['landing_site'] ?? null)
            ?? $this->nullableString($sourceMeta['source_url'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    protected function orderReferrer(array $sourceMeta): ?string
    {
        return $this->nullableString($sourceMeta['referrer'] ?? null)
            ?? $this->nullableString($sourceMeta['referring_site'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    protected function orderSourceSummary(array $sourceMeta): ?string
    {
        $parts = collect([
            $this->nullableString($sourceMeta['source_name'] ?? null),
            $this->utmSummary($sourceMeta),
        ])
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    protected function utmSummary(array $sourceMeta): ?string
    {
        $source = $this->nullableString($sourceMeta['utm_source'] ?? null);
        $medium = $this->nullableString($sourceMeta['utm_medium'] ?? null);

        if ($source === null && $medium === null) {
            return null;
        }

        return trim(implode(' / ', array_filter([$source, $medium], fn (?string $value): bool => $value !== null)));
    }

    protected function attributionRuleLabel(?string $rule): string
    {
        return match ($rule) {
            'last_click_within_window' => 'Tracked click',
            'coupon_signal_message_match_without_click' => 'Coupon signal',
            'landing_signal_message_url_match_without_click' => 'Landing-page signal',
            default => $rule !== null
                ? Str::of($rule)->replace('_', ' ')->title()->value()
                : 'Attributed order',
        };
    }

    protected function salesChannelLabel(?string $channel): string
    {
        return match (strtolower(trim((string) $channel))) {
            'email' => 'Email',
            'sms' => 'Text',
            default => 'Message',
        };
    }

    /**
     * @param  array<int,string>  $clickedUrls
     */
    protected function salesJourneySummary(array $clickedUrls, ?string $landingPage, ?string $sourceUrl): string
    {
        $steps = collect($clickedUrls)
            ->map(fn (string $value): ?string => $this->pageLabel($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->values()
            ->all();

        foreach ([$landingPage, $sourceUrl] as $candidate) {
            $label = $this->pageLabel($candidate);
            if ($label === null || in_array($label, $steps, true)) {
                continue;
            }
            $steps[] = $label;
        }

        if ($steps === []) {
            return 'Page path before purchase was not captured for this order yet.';
        }

        if (count($steps) === 1) {
            return 'Visited '.$steps[0].' and then purchased.';
        }

        return 'Moved through '.implode(' -> ', $steps).' and then purchased.';
    }

    protected function pageLabel(mixed $url): ?string
    {
        $value = $this->nullableString($url);
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return Str::limit($value, 80);
        }

        $path = trim((string) ($parts['path'] ?? ''));
        $query = trim((string) ($parts['query'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        $display = $path !== '' ? $path : ($host !== '' ? $host : $value);
        if ($query !== '') {
            $display .= '?'.$query;
        }

        return Str::limit($display, 80);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function normalizedMessageScope(mixed $value): string
    {
        $scope = strtolower(trim((string) $value));

        return in_array($scope, ['all', 'direct', 'campaign'], true)
            ? $scope
            : 'all';
    }

    protected function applySmsMessageScope(Builder $query, string $scope): Builder
    {
        return match ($this->normalizedMessageScope($scope)) {
            'direct' => $query->whereNull('campaign_id'),
            'campaign' => $query->whereNotNull('campaign_id'),
            default => $query,
        };
    }

    protected function applyEmailMessageScope(Builder $query, string $scope): Builder
    {
        return match ($this->normalizedMessageScope($scope)) {
            'direct' => $query
                ->where('campaign_type', 'direct_message')
                ->whereNull('marketing_campaign_recipient_id'),
            'campaign' => $query->whereNotNull('marketing_campaign_recipient_id'),
            default => $query->where(function (Builder $scopeQuery): void {
                $scopeQuery
                    ->where('campaign_type', 'direct_message')
                    ->orWhereNotNull('marketing_campaign_recipient_id');
            }),
        };
    }

    protected function positiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
