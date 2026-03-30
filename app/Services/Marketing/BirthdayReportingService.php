<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\BirthdayMessageEvent;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BirthdayReportingService
{
    public function __construct(
        protected BirthdayEmailDeliveryStatusNormalizer $deliveryStatusNormalizer,
        protected MarketingEmailDeliveryProviderContext $providerContextResolver
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   total:int,
     *   sent:int,
     *   failed:int,
     *   by_provider:array<int,array{provider:string,total:int,sent:int,failed:int}>
     * }
     */
    public function canonicalBirthdayEmailDeliverySummary(array $filters = []): array
    {
        $query = $this->canonicalBirthdayEmailDeliveryQuery($filters);

        $total = (int) (clone $query)->count();
        $sent = (int) (clone $query)->where('status', 'sent')->count();
        $failed = (int) (clone $query)->where('status', 'failed')->count();

        $byProvider = (clone $query)
            ->selectRaw(
                "coalesce(provider, 'unknown') as provider, count(*) as total, "
                . "sum(case when status = 'sent' then 1 else 0 end) as sent, "
                . "sum(case when status = 'failed' then 1 else 0 end) as failed"
            )
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->map(fn ($row): array => [
                'provider' => (string) ($row->provider ?? 'unknown'),
                'total' => (int) ($row->total ?? 0),
                'sent' => (int) ($row->sent ?? 0),
                'failed' => (int) ($row->failed ?? 0),
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function canonicalBirthdayEmailDeliveryQuery(array $filters = []): Builder
    {
        $query = MarketingEmailDelivery::query()->where('campaign_type', 'birthday');

        $tenantId = isset($filters['tenant_id']) && is_numeric($filters['tenant_id']) && (int) $filters['tenant_id'] > 0
            ? (int) $filters['tenant_id']
            : null;
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $provider = strtolower(trim((string) ($filters['provider'] ?? '')));
        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        $resolutionSource = strtolower(trim((string) ($filters['provider_resolution_source'] ?? '')));
        if ($resolutionSource !== '') {
            if ($resolutionSource === 'unknown') {
                $query->where(function (Builder $builder): void {
                    $builder
                        ->whereNull('metadata')
                        ->orWhereNull('metadata->provider_resolution_source')
                        ->orWhere('metadata->provider_resolution_source', '');
                });
            } else {
                $query->where('metadata->provider_resolution_source', $resolutionSource);
            }
        }

        $readinessStatus = strtolower(trim((string) ($filters['provider_readiness_status'] ?? '')));
        if ($readinessStatus !== '') {
            if ($readinessStatus === 'unknown') {
                $query->where(function (Builder $builder): void {
                    $builder
                        ->whereNull('metadata')
                        ->orWhereNull('metadata->provider_readiness_status')
                        ->orWhere('metadata->provider_readiness_status', '');
                });
            } else {
                $query->where('metadata->provider_readiness_status', $readinessStatus);
            }
        }

        $templateKey = trim((string) ($filters['template_key'] ?? ''));
        if ($templateKey !== '') {
            $query->where('template_key', $templateKey);
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['sending', 'sent', 'delivered', 'opened', 'clicked', 'failed'], true)) {
            $query->where('status', $status);
        }

        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom->startOfDay());
        }

        $dateTo = $this->parseDate($filters['date_to'] ?? null);
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo->endOfDay());
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   filters:array{
     *     tenant_id:?int,
     *     date_from:string,
     *     date_to:string,
     *     provider:?string,
     *     provider_resolution_source:?string,
     *     provider_readiness_status:?string,
     *     template_key:?string,
     *     status:string,
     *     comparison_mode:'template'|'provider'|'period',
     *     period_view:'raw'|'per_day',
     *     compare_from:?string,
     *     compare_to:?string
     *   },
     *   options:array{
     *     providers:array<int,string>,
     *     provider_resolution_sources:array<int,string>,
     *     provider_readiness_statuses:array<int,string>,
     *     template_keys:array<int,string>,
     *     statuses:array<int,string>,
     *     comparison_modes:array<int,string>,
     *     period_views:array<int,string>
     *   },
     *   metrics:array{
     *     rewards_issued:int,
     *     birthday_emails_attempted:int,
     *     birthday_emails_sent_successfully:int,
     *     birthday_emails_failed:int,
     *     delivered_count:int,
     *     opened_count:int,
     *     clicked_count:int,
     *     coupons_redeemed:int,
     *     redemption_rate:float,
     *     attributed_revenue:float,
     *     revenue_per_issued_reward:float,
     *     revenue_per_successfully_sent_birthday_email:float
     *   },
     *   funnel:array<int,array{key:string,label:string,value:int|float}>,
     *   status_breakdown:array<int,array{status:string,count:int}>,
     *   provider_breakdown:array<int,array{
     *     provider:string,
     *     attempted:int,
     *     sent:int,
     *     delivered:int,
     *     opened:int,
     *     clicked:int,
     *     failed:int,
     *     bounced:int,
     *     unsupported:int
     *   }>,
     *   provider_resolution_breakdown:array<int,array{
     *     provider_resolution_source:string,
     *     provider_resolution_source_label:string,
     *     attempted:int,
     *     sent:int,
     *     failed:int,
     *     unsupported:int,
     *     providers:array<int,string>,
     *     legacy_context_missing_count:int
     *   }>,
     *   provider_readiness_breakdown:array<int,array{
     *     provider_readiness_status:string,
     *     provider_readiness_status_label:string,
     *     attempted:int,
     *     sent:int,
     *     failed:int,
     *     unsupported:int
     *   }>,
     *   top_failure_reasons:array<int,array{reason:string,count:int}>,
     *   top_failure_reasons_by_resolution_source:array<int,array{
     *     provider_resolution_source:string,
     *     provider_resolution_source_label:string,
     *     reason:string,
     *     count:int
     *   }>,
     *   attribution:array{
     *     delivery_links:array{
     *       linked_count:int,
     *       linked_issuance_count:int,
     *       unlinked_count:int,
     *       link_paths:array<int,string>
     *     },
     *     joined_redeemed_count:int,
     *     joined_attributed_revenue:float
     *   },
     *   notes:array<int,string>,
     *   empty:bool
     * }
     */
    public function birthdayAnalytics(array $filters = []): array
    {
        $tenantId = $this->positiveInt($filters['tenant_id'] ?? null);
        $provider = $this->nullableString($filters['provider'] ?? null);
        $providerResolutionSource = $this->normalizedProviderResolutionSourceFilter($filters['provider_resolution_source'] ?? null);
        $providerReadinessStatus = $this->normalizedProviderReadinessStatusFilter($filters['provider_readiness_status'] ?? null);
        $templateKey = $this->nullableString($filters['template_key'] ?? null);
        $statusFilter = $this->normalizedStatusFilter((string) ($filters['status'] ?? 'all'));
        $comparisonMode = $this->normalizedComparisonMode($filters['comparison_mode'] ?? null);
        $periodView = $this->normalizedPeriodView($filters['period_view'] ?? null);
        $compareFrom = $this->parseDate($filters['compare_from'] ?? null);
        $compareTo = $this->parseDate($filters['compare_to'] ?? null);

        $dateFrom = $this->parseDate($filters['date_from'] ?? null) ?: now()->subDays(29)->toImmutable();
        $dateTo = $this->parseDate($filters['date_to'] ?? null) ?: now()->toImmutable();
        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $dateFrom = $dateFrom->startOfDay();
        $dateTo = $dateTo->endOfDay();
        if ($compareFrom && $compareTo && $compareFrom->greaterThan($compareTo)) {
            [$compareFrom, $compareTo] = [$compareTo, $compareFrom];
        }
        $compareFrom = $compareFrom?->startOfDay();
        $compareTo = $compareTo?->endOfDay();

        $issuanceQuery = BirthdayRewardIssuance::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($tenantId !== null) {
            $issuanceQuery->whereHas('marketingProfile', fn (Builder $query) => $query->where('tenant_id', $tenantId));
        }

        $issuances = $issuanceQuery->get();
        $issuancesById = $issuances->keyBy(fn (BirthdayRewardIssuance $issuance): int => (int) $issuance->id);
        $issuanceIdsByCode = $issuances
            ->filter(fn (BirthdayRewardIssuance $issuance): bool => trim((string) ($issuance->reward_code ?? '')) !== '')
            ->mapWithKeys(fn (BirthdayRewardIssuance $issuance): array => [
                strtoupper(trim((string) $issuance->reward_code)) => (int) $issuance->id,
            ])
            ->all();

        $deliveryBaseQuery = $this->canonicalBirthdayEmailDeliveryQuery([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'provider_resolution_source' => $providerResolutionSource,
            'provider_readiness_status' => $providerReadinessStatus,
            'template_key' => $templateKey,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $deliveryRows = (clone $deliveryBaseQuery)
            ->orderBy('created_at')
            ->get()
            ->map(function (MarketingEmailDelivery $delivery) use ($issuanceIdsByCode): array {
                $metadata = is_array($delivery->metadata) ? $delivery->metadata : [];
                $couponCode = strtoupper(trim((string) ($metadata['coupon_code'] ?? '')));
                $issuanceId = $this->positiveInt($metadata['birthday_reward_issuance_id'] ?? null)
                    ?? ($couponCode !== '' ? $this->positiveInt($issuanceIdsByCode[$couponCode] ?? null) : null);
                $providerContext = $this->providerContextResolver->resolveFromDelivery($delivery);

                return [
                    'delivery' => $delivery,
                    'provider_context' => $providerContext,
                    'normalized' => $this->deliveryStatusNormalizer->normalize($delivery),
                    'issuance_id' => $issuanceId,
                ];
            })
            ->filter(fn (array $row): bool => $this->deliveryStatusNormalizer->matchesStatusFilter($statusFilter, (array) $row['normalized']))
            ->values();

        $attempted = (int) $deliveryRows->count();
        $sentSuccessfully = (int) $deliveryRows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.sent', false))->count();
        $failed = (int) $deliveryRows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))->count();
        $delivered = (int) $deliveryRows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.delivered', false))->count();
        $opened = (int) $deliveryRows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.opened', false))->count();
        $clicked = (int) $deliveryRows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.clicked', false))->count();

        $statusBreakdown = collect([
            'attempted',
            'sent',
            'delivered',
            'opened',
            'clicked',
            'failed',
            'bounced',
            'unsupported',
        ])->map(function (string $status) use ($deliveryRows): array {
            if ($status === 'attempted') {
                return ['status' => $status, 'count' => (int) $deliveryRows->count()];
            }

            return [
                'status' => $status,
                'count' => (int) $deliveryRows->filter(
                    fn (array $row): bool => (string) data_get($row, 'normalized.normalized_status', 'attempted') === $status
                )->count(),
            ];
        })->all();

        $providerBreakdown = $deliveryRows
            ->groupBy(function (array $row): string {
                $provider = strtolower(trim((string) data_get($row, 'delivery.provider', '')));

                return $provider !== '' ? $provider : 'unknown';
            })
            ->map(function (Collection $rows, string $provider): array {
                return [
                    'provider' => $provider,
                    'attempted' => (int) $rows->count(),
                    'sent' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.sent', false))->count(),
                    'delivered' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.delivered', false))->count(),
                    'opened' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.opened', false))->count(),
                    'clicked' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.clicked', false))->count(),
                    'failed' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))->count(),
                    'bounced' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.bounced', false))->count(),
                    'unsupported' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.unsupported', false))->count(),
                ];
            })
            ->sortBy('provider')
            ->values()
            ->all();

        $providerResolutionBreakdown = $deliveryRows
            ->groupBy(fn (array $row): string => (string) data_get($row, 'provider_context.provider_resolution_source', 'unknown'))
            ->map(function (Collection $rows, string $source): array {
                return [
                    'provider_resolution_source' => $source,
                    'provider_resolution_source_label' => $this->providerContextResolver->resolutionSourceLabel($source),
                    'attempted' => (int) $rows->count(),
                    'sent' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.sent', false))->count(),
                    'failed' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))->count(),
                    'unsupported' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.unsupported', false))->count(),
                    'providers' => $rows
                        ->map(fn (array $row): string => (string) data_get($row, 'provider_context.provider', 'unknown'))
                        ->filter(fn (string $provider): bool => $provider !== '')
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                    'legacy_context_missing_count' => (int) $rows
                        ->filter(fn (array $row): bool => (bool) data_get($row, 'provider_context.legacy_context_missing', false))
                        ->count(),
                ];
            })
            ->sortBy(function (array $row): int {
                return match ((string) ($row['provider_resolution_source'] ?? 'unknown')) {
                    'tenant' => 1,
                    'fallback' => 2,
                    'none' => 3,
                    default => 4,
                };
            })
            ->values()
            ->all();

        $providerReadinessBreakdown = $deliveryRows
            ->groupBy(fn (array $row): string => (string) data_get($row, 'provider_context.provider_readiness_status', 'unknown'))
            ->map(function (Collection $rows, string $status): array {
                return [
                    'provider_readiness_status' => $status,
                    'provider_readiness_status_label' => $this->providerContextResolver->readinessStatusLabel($status),
                    'attempted' => (int) $rows->count(),
                    'sent' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.sent', false))->count(),
                    'failed' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))->count(),
                    'unsupported' => (int) $rows->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.unsupported', false))->count(),
                ];
            })
            ->sortBy(function (array $row): int {
                return match ((string) ($row['provider_readiness_status'] ?? 'unknown')) {
                    'ready' => 1,
                    'unsupported' => 2,
                    'incomplete' => 3,
                    'not_configured' => 4,
                    'error' => 5,
                    default => 6,
                };
            })
            ->values()
            ->all();

        $failureReasons = $deliveryRows
            ->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))
            ->map(function (array $row): string {
                $reason = trim((string) data_get($row, 'normalized.failure_reason', 'unknown_failure'));

                return $reason !== '' ? $reason : 'unknown_failure';
            })
            ->countBy()
            ->sortDesc()
            ->map(fn (int $count, string $reason): array => [
                'reason' => mb_substr($reason, 0, 120),
                'count' => $count,
            ])
            ->values()
            ->take(8)
            ->all();

        $failureReasonsByResolutionSource = $deliveryRows
            ->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))
            ->map(function (array $row): array {
                $reason = trim((string) data_get($row, 'normalized.failure_reason', 'unknown_failure'));
                $source = (string) data_get($row, 'provider_context.provider_resolution_source', 'unknown');

                return [
                    'provider_resolution_source' => $source !== '' ? $source : 'unknown',
                    'provider_resolution_source_label' => $this->providerContextResolver->resolutionSourceLabel($source !== '' ? $source : 'unknown'),
                    'reason' => $reason !== '' ? mb_substr($reason, 0, 120) : 'unknown_failure',
                ];
            })
            ->groupBy(fn (array $row): string => (string) ($row['provider_resolution_source'] ?? 'unknown') . '||' . (string) ($row['reason'] ?? 'unknown_failure'))
            ->map(function (Collection $rows): array {
                $sample = (array) $rows->first();

                return [
                    'provider_resolution_source' => (string) ($sample['provider_resolution_source'] ?? 'unknown'),
                    'provider_resolution_source_label' => (string) ($sample['provider_resolution_source_label'] ?? 'Legacy / unavailable'),
                    'reason' => (string) ($sample['reason'] ?? 'unknown_failure'),
                    'count' => (int) $rows->count(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->take(12)
            ->all();

        $linkedIssuanceIds = $deliveryRows
            ->map(fn (array $row): ?int => $this->positiveInt($row['issuance_id'] ?? null))
            ->filter()
            ->unique()
            ->values();
        $linkedDeliveryCount = (int) $deliveryRows
            ->filter(fn (array $row): bool => $this->positiveInt($row['issuance_id'] ?? null) !== null)
            ->count();
        $linkedIssuances = $linkedIssuanceIds
            ->map(fn (int $id): ?BirthdayRewardIssuance => $issuancesById->get($id))
            ->filter();
        $hasDeliveryCohortFilter = $provider !== null
            || $providerResolutionSource !== null
            || $providerReadinessStatus !== null
            || $templateKey !== null
            || $statusFilter !== 'all';
        $issuanceCohort = $hasDeliveryCohortFilter ? $linkedIssuances : $issuances;
        $redeemedIssuances = $issuanceCohort->filter(
            fn (BirthdayRewardIssuance $issuance): bool => (string) $issuance->status === 'redeemed'
        );
        $couponsRedeemed = (int) $redeemedIssuances->count();
        $attributedRevenue = round((float) $redeemedIssuances->sum(function (BirthdayRewardIssuance $issuance): float {
            if ($issuance->attributed_revenue !== null) {
                return (float) $issuance->attributed_revenue;
            }

            return (float) ($issuance->order_total ?? 0);
        }), 2);
        $rewardsIssued = (int) $issuanceCohort->count();
        $redemptionRate = $rewardsIssued > 0
            ? round(($couponsRedeemed / $rewardsIssued) * 100, 2)
            : 0.0;
        $revenuePerIssuedReward = $rewardsIssued > 0
            ? round($attributedRevenue / $rewardsIssued, 2)
            : 0.0;
        $revenuePerSuccessfulSend = $sentSuccessfully > 0
            ? round($attributedRevenue / $sentSuccessfully, 2)
            : 0.0;
        $joinedRedeemedCount = (int) $linkedIssuances->filter(fn (BirthdayRewardIssuance $issuance): bool => (string) $issuance->status === 'redeemed')->count();
        $joinedAttributedRevenue = round((float) $linkedIssuances
            ->filter(fn (BirthdayRewardIssuance $issuance): bool => (string) $issuance->status === 'redeemed')
            ->sum(fn (BirthdayRewardIssuance $issuance): float => $issuance->attributed_revenue !== null
                ? (float) $issuance->attributed_revenue
                : (float) ($issuance->order_total ?? 0)), 2);
        $linkedCount = $linkedDeliveryCount;
        $unlinkedCount = max(0, $attempted - $linkedDeliveryCount);

        $optionDeliveries = $this->canonicalBirthdayEmailDeliveryQuery([
            'tenant_id' => $tenantId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ])->get(['provider', 'template_key', 'metadata']);

        $providerOptions = $optionDeliveries
            ->map(fn (MarketingEmailDelivery $delivery): string => strtolower(trim((string) ($delivery->provider ?? ''))))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $providerResolutionOptions = $optionDeliveries
            ->map(fn (MarketingEmailDelivery $delivery): string => (string) $this->providerContextResolver->resolveFromDelivery($delivery)['provider_resolution_source'])
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $providerReadinessOptions = $optionDeliveries
            ->map(fn (MarketingEmailDelivery $delivery): string => (string) $this->providerContextResolver->resolveFromDelivery($delivery)['provider_readiness_status'])
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $templateOptions = $optionDeliveries
            ->map(fn (MarketingEmailDelivery $delivery): string => trim((string) ($delivery->template_key ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $notes = [];
        if ($attempted === 0 && $rewardsIssued === 0) {
            $notes[] = 'No birthday issuance or delivery activity was found for the selected filters.';
        }
        if ($delivered === 0 && $opened === 0 && $clicked === 0 && $attempted > 0) {
            $notes[] = 'Delivery/open/click engagement events are unavailable or not yet received for this filtered cohort.';
        }
        if ($hasDeliveryCohortFilter) {
            $notes[] = 'Issuance/redemption metrics are scoped to issuances linked to the currently filtered delivery cohort.';
        }
        if ((int) collect($statusBreakdown)->firstWhere('status', 'unsupported')['count'] > 0) {
            $notes[] = 'Unsupported provider selections are recorded as failed/unsupported attempts so they remain visible in reporting.';
        }
        if ($unlinkedCount > 0) {
            $notes[] = $unlinkedCount . ' delivery row(s) could not be linked to a birthday reward issuance via persisted issuance ID or coupon code metadata.';
        }
        $legacyContextCount = (int) $deliveryRows
            ->filter(fn (array $row): bool => (bool) data_get($row, 'provider_context.legacy_context_missing', false))
            ->count();
        if ($legacyContextCount > 0) {
            $notes[] = $legacyContextCount . ' delivery row(s) are legacy and do not include provider-resolution metadata; they are labeled as unknown in context breakdowns.';
        }

        $trend = $this->buildDailyTrend(
            $dateFrom,
            $dateTo,
            $issuanceCohort,
            $deliveryRows,
            $redeemedIssuances
        );
        $comparison = $comparisonMode === 'period'
            ? $this->buildPeriodComparison(
                filters: [
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'provider_resolution_source' => $providerResolutionSource,
                    'provider_readiness_status' => $providerReadinessStatus,
                    'template_key' => $templateKey,
                    'status' => $statusFilter,
                ],
                currentPeriodFrom: $dateFrom,
                currentPeriodTo: $dateTo,
                compareFrom: $compareFrom,
                compareTo: $compareTo,
                periodView: $periodView,
                currentSnapshot: $this->periodSnapshotFromAnalytics([
                    'filters' => [
                        'date_from' => $dateFrom->toDateString(),
                        'date_to' => $dateTo->toDateString(),
                    ],
                    'metrics' => [
                        'rewards_issued' => $rewardsIssued,
                        'birthday_emails_attempted' => $attempted,
                        'birthday_emails_sent_successfully' => $sentSuccessfully,
                        'delivered_count' => $delivered,
                        'opened_count' => $opened,
                        'clicked_count' => $clicked,
                        'birthday_emails_failed' => $failed,
                        'coupons_redeemed' => $couponsRedeemed,
                        'redemption_rate' => $redemptionRate,
                        'attributed_revenue' => $attributedRevenue,
                        'revenue_per_issued_reward' => $revenuePerIssuedReward,
                        'revenue_per_successfully_sent_birthday_email' => $revenuePerSuccessfulSend,
                    ],
                    'status_breakdown' => $statusBreakdown,
                    'attribution' => [
                        'delivery_links' => [
                            'unlinked_count' => $unlinkedCount,
                        ],
                    ],
                    'notes' => $notes,
                ])
            )
            : $this->buildComparison(
                $comparisonMode,
                $deliveryRows,
                $issuancesById
            );

        return [
            'filters' => [
                'tenant_id' => $tenantId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'provider' => $provider,
                'provider_resolution_source' => $providerResolutionSource,
                'provider_readiness_status' => $providerReadinessStatus,
                'template_key' => $templateKey,
                'status' => $statusFilter,
                'comparison_mode' => $comparisonMode,
                'period_view' => $periodView,
                'compare_from' => $compareFrom?->toDateString(),
                'compare_to' => $compareTo?->toDateString(),
            ],
            'options' => [
                'providers' => $providerOptions,
                'provider_resolution_sources' => $providerResolutionOptions,
                'provider_readiness_statuses' => $providerReadinessOptions,
                'template_keys' => $templateOptions,
                'statuses' => ['all', 'attempted', 'sent', 'delivered', 'opened', 'clicked', 'failed', 'bounced', 'unsupported'],
                'comparison_modes' => ['template', 'provider', 'period'],
                'period_views' => ['raw', 'per_day'],
            ],
            'metrics' => [
                'rewards_issued' => $rewardsIssued,
                'birthday_emails_attempted' => $attempted,
                'birthday_emails_sent_successfully' => $sentSuccessfully,
                'birthday_emails_failed' => $failed,
                'delivered_count' => $delivered,
                'opened_count' => $opened,
                'clicked_count' => $clicked,
                'coupons_redeemed' => $couponsRedeemed,
                'redemption_rate' => $redemptionRate,
                'attributed_revenue' => $attributedRevenue,
                'revenue_per_issued_reward' => $revenuePerIssuedReward,
                'revenue_per_successfully_sent_birthday_email' => $revenuePerSuccessfulSend,
            ],
            'funnel' => [
                ['key' => 'issued', 'label' => 'Issued', 'value' => $rewardsIssued],
                ['key' => 'attempted', 'label' => 'Attempted', 'value' => $attempted],
                ['key' => 'sent', 'label' => 'Sent', 'value' => $sentSuccessfully],
                ['key' => 'delivered', 'label' => 'Delivered', 'value' => $delivered],
                ['key' => 'opened', 'label' => 'Opened', 'value' => $opened],
                ['key' => 'clicked', 'label' => 'Clicked', 'value' => $clicked],
                ['key' => 'redeemed', 'label' => 'Redeemed', 'value' => $couponsRedeemed],
            ],
            'status_breakdown' => $statusBreakdown,
            'provider_breakdown' => $providerBreakdown,
            'provider_resolution_breakdown' => $providerResolutionBreakdown,
            'provider_readiness_breakdown' => $providerReadinessBreakdown,
            'top_failure_reasons' => $failureReasons,
            'top_failure_reasons_by_resolution_source' => $failureReasonsByResolutionSource,
            'attribution' => [
                'delivery_links' => [
                    'linked_count' => $linkedCount,
                    'linked_issuance_count' => (int) $linkedIssuanceIds->count(),
                    'unlinked_count' => $unlinkedCount,
                    'link_paths' => [
                        'marketing_email_deliveries.metadata.birthday_reward_issuance_id',
                        'marketing_email_deliveries.metadata.coupon_code => birthday_reward_issuances.reward_code',
                    ],
                ],
                'joined_redeemed_count' => $joinedRedeemedCount,
                'joined_attributed_revenue' => $joinedAttributedRevenue,
            ],
            'trend' => $trend,
            'comparison' => $comparison,
            'notes' => $notes,
            'empty' => $attempted === 0 && $rewardsIssued === 0,
        ];
    }

    /**
     * @param array<string,mixed> $analytics
     * @return array<int,string>
     */
    public function birthdayAnalyticsExportColumns(array $analytics = []): array
    {
        return [
            'record_type',
            'date',
            'key',
            'value',
            'comparison_mode',
            'period_view',
            'comparison_group',
            'comparison_group_label',
            'comparison_attempt_share_pct',
            'comparison_revenue_share_pct',
            'comparison_low_sample',
            'comparison_notes',
            'comparison_rank',
            'recommendation_status',
            'recommendation_message',
            'comparison_period',
            'comparison_period_view_mode',
            'metric_scope',
            'normalized_per_day',
            'current_value',
            'prior_value',
            'absolute_delta',
            'percent_delta',
            'delta_direction',
            'insufficient_baseline',
            'provider',
            'provider_resolution_source',
            'provider_resolution_source_label',
            'provider_readiness_status',
            'provider_readiness_status_label',
            'provider_config_status',
            'provider_using_fallback_config',
            'provider_runtime_path',
            'template_key',
            'status',
            'rewards_issued',
            'birthday_emails_attempted',
            'birthday_emails_sent_successfully',
            'delivered_count',
            'opened_count',
            'clicked_count',
            'birthday_emails_failed',
            'coupons_redeemed',
            'attributed_revenue',
            'provider_attempted',
            'provider_sent',
            'provider_delivered',
            'provider_opened',
            'provider_clicked',
            'provider_failed',
            'provider_bounced',
            'provider_unsupported',
            'count',
            'note',
        ];
    }

    /**
     * @param array<string,mixed> $analytics
     * @return array<int,array<string,mixed>>
     */
    public function birthdayAnalyticsExportRows(array $analytics): array
    {
        $rows = [];

        foreach ((array) ($analytics['filters'] ?? []) as $key => $value) {
            $rows[] = [
                'record_type' => 'filter',
                'key' => (string) $key,
                'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
            ];
        }

        foreach ((array) ($analytics['metrics'] ?? []) as $key => $value) {
            $rows[] = [
                'record_type' => 'summary_metric',
                'key' => (string) $key,
                'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
            ];
        }

        $comparisonMode = (string) data_get($analytics, 'comparison.mode', data_get($analytics, 'filters.comparison_mode', 'template'));
        $comparisonRows = (array) data_get($analytics, 'comparison.rows', []);
        foreach ($comparisonRows as $index => $row) {
            $rows[] = [
                'record_type' => 'comparison_row',
                'comparison_mode' => $comparisonMode,
                'comparison_group' => (string) ($row['group_key'] ?? ''),
                'comparison_group_label' => (string) ($row['group_label'] ?? ''),
                'comparison_attempt_share_pct' => round((float) ($row['attempt_share_pct'] ?? 0), 2),
                'comparison_revenue_share_pct' => round((float) ($row['revenue_share_pct'] ?? 0), 2),
                'comparison_low_sample' => (bool) ($row['low_sample_size'] ?? false),
                'comparison_notes' => implode(' | ', array_map('strval', (array) ($row['notes'] ?? []))),
                'comparison_rank' => $index + 1,
                'rewards_issued' => (int) ($row['rewards_issued'] ?? 0),
                'birthday_emails_attempted' => (int) ($row['birthday_emails_attempted'] ?? 0),
                'birthday_emails_sent_successfully' => (int) ($row['birthday_emails_sent_successfully'] ?? 0),
                'delivered_count' => (int) ($row['delivered_count'] ?? 0),
                'opened_count' => (int) ($row['opened_count'] ?? 0),
                'clicked_count' => (int) ($row['clicked_count'] ?? 0),
                'birthday_emails_failed' => (int) ($row['birthday_emails_failed'] ?? 0),
                'coupons_redeemed' => (int) ($row['coupons_redeemed'] ?? 0),
                'attributed_revenue' => round((float) ($row['attributed_revenue'] ?? 0), 2),
            ];
        }

        $recommendation = (array) data_get($analytics, 'comparison.recommendation', []);
        if ($recommendation !== []) {
            $rows[] = [
                'record_type' => 'comparison_recommendation',
                'comparison_mode' => $comparisonMode,
                'recommendation_status' => (string) ($recommendation['status'] ?? ''),
                'recommendation_message' => (string) ($recommendation['message'] ?? ''),
                'comparison_group' => (string) ($recommendation['winner_group_key'] ?? ''),
                'comparison_group_label' => (string) ($recommendation['winner_group_label'] ?? ''),
            ];
        }

        if ($comparisonMode === 'period') {
            $currentPeriod = (array) data_get($analytics, 'comparison.current_period', []);
            $priorPeriod = (array) data_get($analytics, 'comparison.prior_period', []);
            $metricDeltas = (array) data_get($analytics, 'comparison.metric_deltas', []);
            $metricDeltasNormalized = (array) data_get($analytics, 'comparison.metric_deltas_normalized', []);
            $rangeDiagnostics = (array) data_get($analytics, 'comparison.range_diagnostics', []);
            $viewMode = (string) data_get($analytics, 'comparison.view_mode', 'raw');
            $currentNormalizedMetrics = (array) data_get($analytics, 'comparison.current_period.normalized_metrics', []);
            $priorNormalizedMetrics = (array) data_get($analytics, 'comparison.prior_period.normalized_metrics', []);
            $normalizationNotes = (array) data_get($analytics, 'comparison.normalization_notes', []);

            $rows[] = [
                'record_type' => 'comparison_meta',
                'comparison_mode' => $comparisonMode,
                'key' => 'period_resolution_mode',
                'value' => (string) data_get($analytics, 'comparison.period_resolution_mode', 'auto_prior_period'),
            ];
            $rows[] = [
                'record_type' => 'comparison_meta',
                'comparison_mode' => $comparisonMode,
                'key' => 'custom_range_override',
                'value' => (bool) data_get($analytics, 'comparison.custom_range_override', false) ? 'true' : 'false',
            ];
            $rows[] = [
                'record_type' => 'comparison_meta',
                'comparison_mode' => $comparisonMode,
                'key' => 'period_view_mode',
                'value' => $viewMode,
            ];
            foreach ($rangeDiagnostics as $diagnosticKey => $diagnosticValue) {
                $rows[] = [
                    'record_type' => 'comparison_diagnostic',
                    'comparison_mode' => $comparisonMode,
                    'key' => (string) $diagnosticKey,
                    'value' => is_scalar($diagnosticValue) || $diagnosticValue === null
                        ? (string) ($diagnosticValue ?? '')
                        : json_encode($diagnosticValue),
                ];
            }
            foreach ($normalizationNotes as $note) {
                $rows[] = [
                    'record_type' => 'comparison_diagnostic',
                    'comparison_mode' => $comparisonMode,
                    'key' => 'normalization_note',
                    'value' => (string) $note,
                ];
            }

            foreach ([
                'current' => $currentPeriod,
                'prior' => $priorPeriod,
            ] as $periodKey => $period) {
                foreach ((array) ($period['metrics'] ?? []) as $metricKey => $value) {
                    $rows[] = [
                        'record_type' => 'comparison_period_metric',
                        'comparison_mode' => $comparisonMode,
                        'comparison_period_view_mode' => $viewMode,
                        'metric_scope' => 'raw',
                        'normalized_per_day' => false,
                        'comparison_period' => $periodKey,
                        'date' => (string) ($period['label'] ?? ''),
                        'key' => (string) $metricKey,
                        'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
                    ];
                }

                if ($viewMode === 'per_day') {
                    $normalizedMetrics = $periodKey === 'current' ? $currentNormalizedMetrics : $priorNormalizedMetrics;
                    foreach ($normalizedMetrics as $metricKey => $value) {
                        $rows[] = [
                            'record_type' => 'comparison_period_metric_normalized',
                            'comparison_mode' => $comparisonMode,
                            'comparison_period_view_mode' => $viewMode,
                            'metric_scope' => 'per_day',
                            'normalized_per_day' => (bool) data_get($metricDeltasNormalized, $metricKey . '.normalized_per_day', false),
                            'comparison_period' => $periodKey,
                            'date' => (string) ($period['label'] ?? ''),
                            'key' => (string) $metricKey,
                            'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
                        ];
                    }
                }
            }

            foreach ($metricDeltas as $metricKey => $delta) {
                $rows[] = [
                    'record_type' => 'comparison_delta',
                    'comparison_mode' => $comparisonMode,
                    'comparison_period_view_mode' => $viewMode,
                    'metric_scope' => 'raw',
                    'normalized_per_day' => false,
                    'key' => (string) $metricKey,
                    'current_value' => round((float) ($delta['current_value'] ?? 0), 2),
                    'prior_value' => round((float) ($delta['prior_value'] ?? 0), 2),
                    'absolute_delta' => round((float) ($delta['absolute_delta'] ?? 0), 2),
                    'percent_delta' => $delta['percent_delta'] !== null
                        ? round((float) $delta['percent_delta'], 2)
                        : '',
                    'delta_direction' => (string) ($delta['direction'] ?? ''),
                    'insufficient_baseline' => (bool) ($delta['insufficient_baseline'] ?? false),
                ];
            }

            if ($viewMode === 'per_day') {
                foreach ($metricDeltasNormalized as $metricKey => $delta) {
                    $rows[] = [
                        'record_type' => 'comparison_delta_normalized',
                        'comparison_mode' => $comparisonMode,
                        'comparison_period_view_mode' => $viewMode,
                        'metric_scope' => 'per_day',
                        'normalized_per_day' => (bool) ($delta['normalized_per_day'] ?? false),
                        'key' => (string) $metricKey,
                        'current_value' => round((float) ($delta['current_value'] ?? 0), 2),
                        'prior_value' => round((float) ($delta['prior_value'] ?? 0), 2),
                        'absolute_delta' => round((float) ($delta['absolute_delta'] ?? 0), 2),
                        'percent_delta' => $delta['percent_delta'] !== null
                            ? round((float) $delta['percent_delta'], 2)
                            : '',
                        'delta_direction' => (string) ($delta['direction'] ?? ''),
                        'insufficient_baseline' => (bool) ($delta['insufficient_baseline'] ?? false),
                    ];
                }
            }
        }

        foreach ((array) data_get($analytics, 'trend.daily', []) as $row) {
            $rows[] = [
                'record_type' => 'daily',
                'date' => (string) ($row['date'] ?? ''),
                'rewards_issued' => (int) ($row['rewards_issued'] ?? 0),
                'birthday_emails_attempted' => (int) ($row['birthday_emails_attempted'] ?? 0),
                'birthday_emails_sent_successfully' => (int) ($row['birthday_emails_sent_successfully'] ?? 0),
                'delivered_count' => (int) ($row['delivered_count'] ?? 0),
                'opened_count' => (int) ($row['opened_count'] ?? 0),
                'clicked_count' => (int) ($row['clicked_count'] ?? 0),
                'birthday_emails_failed' => (int) ($row['birthday_emails_failed'] ?? 0),
                'coupons_redeemed' => (int) ($row['coupons_redeemed'] ?? 0),
                'attributed_revenue' => round((float) ($row['attributed_revenue'] ?? 0), 2),
            ];
        }

        foreach ((array) ($analytics['provider_breakdown'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'provider_breakdown',
                'provider' => (string) ($row['provider'] ?? ''),
                'provider_attempted' => (int) ($row['attempted'] ?? 0),
                'provider_sent' => (int) ($row['sent'] ?? 0),
                'provider_delivered' => (int) ($row['delivered'] ?? 0),
                'provider_opened' => (int) ($row['opened'] ?? 0),
                'provider_clicked' => (int) ($row['clicked'] ?? 0),
                'provider_failed' => (int) ($row['failed'] ?? 0),
                'provider_bounced' => (int) ($row['bounced'] ?? 0),
                'provider_unsupported' => (int) ($row['unsupported'] ?? 0),
            ];
        }

        foreach ((array) ($analytics['provider_resolution_breakdown'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'provider_resolution_breakdown',
                'provider_resolution_source' => (string) ($row['provider_resolution_source'] ?? ''),
                'provider_resolution_source_label' => (string) ($row['provider_resolution_source_label'] ?? ''),
                'provider_attempted' => (int) ($row['attempted'] ?? 0),
                'provider_sent' => (int) ($row['sent'] ?? 0),
                'provider_failed' => (int) ($row['failed'] ?? 0),
                'provider_unsupported' => (int) ($row['unsupported'] ?? 0),
                'count' => (int) ($row['legacy_context_missing_count'] ?? 0),
                'note' => implode(' | ', array_map('strval', (array) ($row['providers'] ?? []))),
            ];
        }

        foreach ((array) ($analytics['provider_readiness_breakdown'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'provider_readiness_breakdown',
                'provider_readiness_status' => (string) ($row['provider_readiness_status'] ?? ''),
                'provider_readiness_status_label' => (string) ($row['provider_readiness_status_label'] ?? ''),
                'provider_attempted' => (int) ($row['attempted'] ?? 0),
                'provider_sent' => (int) ($row['sent'] ?? 0),
                'provider_failed' => (int) ($row['failed'] ?? 0),
                'provider_unsupported' => (int) ($row['unsupported'] ?? 0),
            ];
        }

        foreach ((array) ($analytics['status_breakdown'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'status_breakdown',
                'status' => (string) ($row['status'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        foreach ((array) ($analytics['top_failure_reasons'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'failure_reason',
                'key' => (string) ($row['reason'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        foreach ((array) ($analytics['top_failure_reasons_by_resolution_source'] ?? []) as $row) {
            $rows[] = [
                'record_type' => 'failure_reason_by_resolution_source',
                'provider_resolution_source' => (string) ($row['provider_resolution_source'] ?? ''),
                'provider_resolution_source_label' => (string) ($row['provider_resolution_source_label'] ?? ''),
                'key' => (string) ($row['reason'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        foreach ((array) ($analytics['notes'] ?? []) as $note) {
            $rows[] = [
                'record_type' => 'note',
                'note' => (string) $note,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(int $tenantId, ?CarbonInterface $asOf = null): array
    {
        $this->requireTenantId($tenantId);
        $asOf = $asOf ?: now();
        $year = (int) $asOf->year;

        $totalProfiles = (int) MarketingProfile::query()->forTenantId($tenantId)->count();
        $withBirthday = (int) $this->baseBirthdayQuery($tenantId)->count();
        $missingBirthday = max(0, $totalProfiles - $withBirthday);
        $emailSubscribed = (int) CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('email_subscribed', true)
            ->count();
        $smsSubscribed = (int) CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('sms_subscribed', true)
            ->count();
        $shopifyMatched = (int) CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->whereHas('marketingProfile.links', fn (Builder $query) => $query->where('source_type', 'shopify_customer'))
            ->count();
        $nonShopify = max(0, $withBirthday - $shopifyMatched);

        $weekDates = collect();
        $start = $asOf->copy()->startOfWeek();
        $end = $asOf->copy()->endOfWeek();
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor = $cursor->copy()->addDay()) {
            $weekDates->push([(int) $cursor->month, (int) $cursor->day]);
        }

        $tomorrow = $asOf->copy()->addDay();

        $birthdaysToday = (int) $this->baseBirthdayQuery($tenantId)
            ->where('birth_month', (int) $asOf->month)
            ->where('birth_day', (int) $asOf->day)
            ->count();

        $birthdaysTomorrow = (int) $this->baseBirthdayQuery($tenantId)
            ->where('birth_month', (int) $tomorrow->month)
            ->where('birth_day', (int) $tomorrow->day)
            ->count();

        $birthdaysThisWeek = (int) $this->baseBirthdayQuery($tenantId)
            ->where(function (Builder $query) use ($weekDates): void {
                foreach ($weekDates as [$month, $day]) {
                    $query->orWhere(function (Builder $dayQuery) use ($month, $day): void {
                        $dayQuery->where('birth_month', $month)->where('birth_day', $day);
                    });
                }
            })
            ->count();

        $birthdaysThisMonth = (int) $this->baseBirthdayQuery($tenantId)
            ->where('birth_month', (int) $asOf->month)
            ->count();

        $issuedThisYear = (int) BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('cycle_year', $year)
            ->count();

        $activatedThisYear = (int) BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('cycle_year', $year)
            ->whereIn('status', ['claimed', 'redeemed'])
            ->count();

        $redeemedThisYear = (int) BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('cycle_year', $year)
            ->where('status', 'redeemed')
            ->count();

        $attributedRevenue = (float) (BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('cycle_year', $year)
            ->where('status', 'redeemed')
            ->sum('attributed_revenue') ?? 0);

        $averageOrderValue = $redeemedThisYear > 0
            ? round($attributedRevenue / $redeemedThisYear, 2)
            : 0.0;

        $syncFailures = (int) BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('cycle_year', $year)
            ->where('discount_sync_status', 'failed')
            ->count();

        $emailEventsThisYear = BirthdayMessageEvent::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('channel', 'email')
            ->whereYear('created_at', $year);

        $emailsSent = (int) (clone $emailEventsThisYear)->whereNotNull('sent_at')->count();
        $emailsOpened = (int) (clone $emailEventsThisYear)->whereNotNull('opened_at')->count();
        $emailsClicked = (int) (clone $emailEventsThisYear)->whereNotNull('clicked_at')->count();

        $segmentsByMonth = CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->selectRaw('birth_month, count(*) as total')
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day')
            ->groupBy('birth_month')
            ->orderBy('birth_month')
            ->get()
            ->map(fn ($row): array => [
                'month' => (int) $row->birth_month,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $signupSources = CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->selectRaw('signup_source, count(*) as total')
            ->whereNotNull('signup_source')
            ->groupBy('signup_source')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->signup_source,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $recentTrend = $this->recentTrend($tenantId, $asOf);

        return [
            'total_profiles' => $totalProfiles,
            'with_birthday' => $withBirthday,
            'missing_birthday' => $missingBirthday,
            'capture_rate' => $totalProfiles > 0 ? round(($withBirthday / $totalProfiles) * 100, 2) : 0.0,
            'birthdays_today' => $birthdaysToday,
            'birthdays_tomorrow' => $birthdaysTomorrow,
            'birthdays_this_week' => $birthdaysThisWeek,
            'birthdays_this_month' => $birthdaysThisMonth,
            'email_subscribed' => $emailSubscribed,
            'sms_subscribed' => $smsSubscribed,
            'shopify_matched' => $shopifyMatched,
            'non_shopify' => $nonShopify,
            'rewards_issued_this_year' => $issuedThisYear,
            'rewards_activated_this_year' => $activatedThisYear,
            'rewards_redeemed_this_year' => $redeemedThisYear,
            'reward_activation_rate' => $issuedThisYear > 0 ? round(($activatedThisYear / $issuedThisYear) * 100, 2) : 0.0,
            'reward_redemption_rate' => $activatedThisYear > 0 ? round(($redeemedThisYear / $activatedThisYear) * 100, 2) : 0.0,
            'attributed_revenue' => round($attributedRevenue, 2),
            'reward_average_order_value' => $averageOrderValue,
            'discount_sync_failures' => $syncFailures,
            'emails_sent_this_year' => $emailsSent,
            'emails_opened_this_year' => $emailsOpened,
            'emails_clicked_this_year' => $emailsClicked,
            'email_open_rate' => $emailsSent > 0 ? round(($emailsOpened / $emailsSent) * 100, 2) : 0.0,
            'email_click_rate' => $emailsSent > 0 ? round(($emailsClicked / $emailsSent) * 100, 2) : 0.0,
            'segments_by_month' => $segmentsByMonth,
            'signup_sources' => $signupSources,
            'recent_trend' => $recentTrend,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function campaignSummary(int $tenantId, ?CarbonInterface $asOf = null): array
    {
        $this->requireTenantId($tenantId);
        $asOf = $asOf ?: now();
        $start = $asOf->copy()->startOfMonth();

        $events = BirthdayMessageEvent::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('created_at', '>=', $start)
            ->get();

        $grouped = collect(['birthday_email', 'birthday_sms', 'followup_email', 'followup_sms'])
            ->mapWithKeys(function (string $campaignType) use ($events): array {
                $rows = $events->where('campaign_type', $campaignType);

                return [$campaignType => [
                    'sent' => $rows->whereNotNull('sent_at')->count(),
                    'opened' => $rows->whereNotNull('opened_at')->count(),
                    'clicked' => $rows->whereNotNull('clicked_at')->count(),
                    'converted' => $rows->whereNotNull('conversion_at')->count(),
                ]];
            })
            ->all();

        return $grouped;
    }

    /**
     * @return array<string,mixed>
     */
    public function rewardSummary(int $tenantId): array
    {
        $this->requireTenantId($tenantId);

        $issuedQuery = BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId));

        $issued = (int) $issuedQuery->count();
        $activated = (int) (clone $issuedQuery)->whereIn('status', ['claimed', 'redeemed'])->count();
        $redeemed = (int) (clone $issuedQuery)->where('status', 'redeemed')->count();
        $revenue = (float) (BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('status', 'redeemed')
            ->sum('attributed_revenue') ?? 0);

        return [
            'available' => (int) (clone $issuedQuery)->where('status', 'issued')->count(),
            'activated' => $activated,
            'redeemed' => $redeemed,
            'expired' => (int) (clone $issuedQuery)->where('status', 'expired')->count(),
            'sync_failures' => (int) (clone $issuedQuery)->where('discount_sync_status', 'failed')->count(),
            'activation_rate' => $issued > 0 ? round(($activated / $issued) * 100, 2) : 0.0,
            'redemption_rate' => $activated > 0 ? round(($redeemed / $activated) * 100, 2) : 0.0,
            'attributed_revenue' => round($revenue, 2),
            'average_order_value' => $redeemed > 0 ? round($revenue / $redeemed, 2) : 0.0,
            'latest' => BirthdayRewardIssuance::query()
                ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->with('marketingProfile:id,first_name,last_name,email')
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @return Collection<int,array{date:string,signups:int,sends:int,opens:int,clicks:int,issued:int,redeemed:int}>
     */
    protected function recentTrend(int $tenantId, CarbonInterface $asOf): Collection
    {
        $this->requireTenantId($tenantId);
        $start = $asOf->copy()->subDays(29)->startOfDay();
        $days = collect();

        for ($cursor = $start->copy(); $cursor->lte($asOf); $cursor = $cursor->copy()->addDay()) {
            $dateKey = $cursor->toDateString();
            $days->push([
                'date' => $dateKey,
                'signups' => (int) CustomerBirthdayProfile::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('created_at', $dateKey)
                    ->count(),
                'sends' => (int) BirthdayMessageEvent::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('sent_at', $dateKey)
                    ->count(),
                'opens' => (int) BirthdayMessageEvent::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('opened_at', $dateKey)
                    ->count(),
                'clicks' => (int) BirthdayMessageEvent::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('clicked_at', $dateKey)
                    ->count(),
                'issued' => (int) BirthdayRewardIssuance::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('issued_at', $dateKey)
                    ->count(),
                'redeemed' => (int) BirthdayRewardIssuance::query()
                    ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->whereDate('redeemed_at', $dateKey)
                    ->count(),
            ]);
        }

        return $days;
    }

    protected function baseBirthdayQuery(int $tenantId): Builder
    {
        return CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day');
    }

    /**
     * @param Collection<int,BirthdayRewardIssuance> $issuanceCohort
     * @param Collection<int,array{delivery:MarketingEmailDelivery,normalized:array<string,mixed>,issuance_id:?int}> $deliveryRows
     * @param Collection<int,BirthdayRewardIssuance> $redeemedIssuances
     * @return array{
     *   bucket:string,
     *   timezone:string,
     *   labels:array<int,string>,
     *   daily:array<int,array{
     *     date:string,
     *     rewards_issued:int,
     *     birthday_emails_attempted:int,
     *     birthday_emails_sent_successfully:int,
     *     delivered_count:int,
     *     opened_count:int,
     *     clicked_count:int,
     *     birthday_emails_failed:int,
     *     coupons_redeemed:int,
     *     attributed_revenue:float
     *   }>,
     *   series:array{
     *     sends:array{
     *       attempted:array<int,int>,
     *       sent:array<int,int>,
     *       failed:array<int,int>
     *     },
     *     engagement:array{
     *       delivered:array<int,int>,
     *       opened:array<int,int>,
     *       clicked:array<int,int>
     *     },
     *     redemption:array{
     *       coupons_redeemed:array<int,int>,
     *       attributed_revenue:array<int,float>
     *     }
     *   },
     *   bucket_rules:array<string,string>,
     *   availability:array{
     *     engagement_events_present:bool,
     *     engagement_events_limited:bool,
     *     unsupported_or_non_sendgrid_attempts:int,
     *     notes:array<int,string>
     *   }
     * }
     */
    protected function buildDailyTrend(
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        Collection $issuanceCohort,
        Collection $deliveryRows,
        Collection $redeemedIssuances
    ): array {
        $bucketDates = $this->dateBucketKeys($dateFrom, $dateTo);
        $dailyRows = [];

        foreach ($bucketDates as $date) {
            $dailyRows[$date] = [
                'date' => $date,
                'rewards_issued' => 0,
                'birthday_emails_attempted' => 0,
                'birthday_emails_sent_successfully' => 0,
                'delivered_count' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
                'birthday_emails_failed' => 0,
                'coupons_redeemed' => 0,
                'attributed_revenue' => 0.0,
            ];
        }

        foreach ($issuanceCohort as $issuance) {
            $date = $this->modelDateValue($issuance->created_at);
            $this->incrementTrendMetric($dailyRows, $date, 'rewards_issued', 1);
        }

        foreach ($deliveryRows as $row) {
            $delivery = $row['delivery'];
            $normalized = (array) ($row['normalized'] ?? []);

            $attemptedDate = $this->deliveryBucketDate($delivery, ['created_at']);
            $this->incrementTrendMetric($dailyRows, $attemptedDate, 'birthday_emails_attempted', 1);

            if ((bool) ($normalized['sent'] ?? false)) {
                $sentDate = $this->deliveryBucketDate($delivery, ['sent_at', 'created_at']);
                $this->incrementTrendMetric($dailyRows, $sentDate, 'birthday_emails_sent_successfully', 1);
            }

            if ((bool) ($normalized['failed'] ?? false)) {
                $failedDate = $this->deliveryBucketDate($delivery, ['failed_at', 'created_at']);
                $this->incrementTrendMetric($dailyRows, $failedDate, 'birthday_emails_failed', 1);
            }

            if ((bool) ($normalized['delivered'] ?? false)) {
                $deliveredDate = $this->deliveryBucketDate($delivery, ['delivered_at', 'opened_at', 'clicked_at', 'sent_at', 'created_at']);
                $this->incrementTrendMetric($dailyRows, $deliveredDate, 'delivered_count', 1);
            }

            if ((bool) ($normalized['opened'] ?? false)) {
                $openedDate = $this->deliveryBucketDate($delivery, ['opened_at', 'clicked_at', 'delivered_at', 'sent_at', 'created_at']);
                $this->incrementTrendMetric($dailyRows, $openedDate, 'opened_count', 1);
            }

            if ((bool) ($normalized['clicked'] ?? false)) {
                $clickedDate = $this->deliveryBucketDate($delivery, ['clicked_at', 'opened_at', 'delivered_at', 'sent_at', 'created_at']);
                $this->incrementTrendMetric($dailyRows, $clickedDate, 'clicked_count', 1);
            }
        }

        foreach ($redeemedIssuances as $issuance) {
            $redeemedDate = $this->modelDateValue($issuance->redeemed_at)
                ?? $this->modelDateValue($issuance->updated_at)
                ?? $this->modelDateValue($issuance->created_at);

            $revenue = $issuance->attributed_revenue !== null
                ? (float) $issuance->attributed_revenue
                : (float) ($issuance->order_total ?? 0);

            $this->incrementTrendMetric($dailyRows, $redeemedDate, 'coupons_redeemed', 1);
            $this->incrementTrendMetric($dailyRows, $redeemedDate, 'attributed_revenue', $revenue);
        }

        $daily = array_values(array_map(function (array $row): array {
            $row['rewards_issued'] = (int) round((float) ($row['rewards_issued'] ?? 0));
            $row['birthday_emails_attempted'] = (int) round((float) ($row['birthday_emails_attempted'] ?? 0));
            $row['birthday_emails_sent_successfully'] = (int) round((float) ($row['birthday_emails_sent_successfully'] ?? 0));
            $row['delivered_count'] = (int) round((float) ($row['delivered_count'] ?? 0));
            $row['opened_count'] = (int) round((float) ($row['opened_count'] ?? 0));
            $row['clicked_count'] = (int) round((float) ($row['clicked_count'] ?? 0));
            $row['birthday_emails_failed'] = (int) round((float) ($row['birthday_emails_failed'] ?? 0));
            $row['coupons_redeemed'] = (int) round((float) ($row['coupons_redeemed'] ?? 0));
            $row['attributed_revenue'] = round((float) ($row['attributed_revenue'] ?? 0), 2);

            return $row;
        }, $dailyRows));

        $labels = array_map(fn (array $row): string => (string) $row['date'], $daily);

        $attemptedSeries = array_map(fn (array $row): int => (int) ($row['birthday_emails_attempted'] ?? 0), $daily);
        $sentSeries = array_map(fn (array $row): int => (int) ($row['birthday_emails_sent_successfully'] ?? 0), $daily);
        $failedSeries = array_map(fn (array $row): int => (int) ($row['birthday_emails_failed'] ?? 0), $daily);
        $deliveredSeries = array_map(fn (array $row): int => (int) ($row['delivered_count'] ?? 0), $daily);
        $openedSeries = array_map(fn (array $row): int => (int) ($row['opened_count'] ?? 0), $daily);
        $clickedSeries = array_map(fn (array $row): int => (int) ($row['clicked_count'] ?? 0), $daily);
        $redeemedSeries = array_map(fn (array $row): int => (int) ($row['coupons_redeemed'] ?? 0), $daily);
        $revenueSeries = array_map(fn (array $row): float => round((float) ($row['attributed_revenue'] ?? 0), 2), $daily);

        $engagementEventsPresent = array_sum($deliveredSeries) > 0
            || array_sum($openedSeries) > 0
            || array_sum($clickedSeries) > 0;
        $engagementEventsLimited = array_sum($attemptedSeries) > 0 && ! $engagementEventsPresent;
        $nonSendGridAttempts = (int) $deliveryRows->filter(function (array $row): bool {
            $provider = strtolower(trim((string) data_get($row, 'delivery.provider', '')));

            return $provider !== '' && $provider !== 'sendgrid';
        })->count();

        $availabilityNotes = [];
        if ($engagementEventsLimited) {
            $availabilityNotes[] = 'Engagement trend metrics rely on canonical delivered/opened/clicked timestamps. Some providers may not emit these events yet.';
        }
        if ($nonSendGridAttempts > 0) {
            $availabilityNotes[] = 'Non-SendGrid or unsupported provider attempts are included and may have limited engagement event visibility.';
        }

        return [
            'bucket' => 'day',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'labels' => $labels,
            'daily' => $daily,
            'series' => [
                'sends' => [
                    'attempted' => $attemptedSeries,
                    'sent' => $sentSeries,
                    'failed' => $failedSeries,
                ],
                'engagement' => [
                    'delivered' => $deliveredSeries,
                    'opened' => $openedSeries,
                    'clicked' => $clickedSeries,
                ],
                'redemption' => [
                    'coupons_redeemed' => $redeemedSeries,
                    'attributed_revenue' => $revenueSeries,
                ],
            ],
            'bucket_rules' => [
                'rewards_issued' => 'birthday_reward_issuances.created_at',
                'birthday_emails_attempted' => 'marketing_email_deliveries.created_at',
                'birthday_emails_sent_successfully' => 'marketing_email_deliveries.sent_at (fallback created_at)',
                'delivered_count' => 'marketing_email_deliveries.delivered_at (fallback opened_at, clicked_at, sent_at, created_at)',
                'opened_count' => 'marketing_email_deliveries.opened_at (fallback clicked_at, delivered_at, sent_at, created_at)',
                'clicked_count' => 'marketing_email_deliveries.clicked_at (fallback opened_at, delivered_at, sent_at, created_at)',
                'birthday_emails_failed' => 'marketing_email_deliveries.failed_at (fallback created_at)',
                'coupons_redeemed' => 'birthday_reward_issuances.redeemed_at (fallback updated_at, created_at)',
                'attributed_revenue' => 'birthday_reward_issuances.redeemed_at bucket; value uses attributed_revenue (fallback order_total)',
            ],
            'availability' => [
                'engagement_events_present' => $engagementEventsPresent,
                'engagement_events_limited' => $engagementEventsLimited,
                'unsupported_or_non_sendgrid_attempts' => $nonSendGridAttempts,
                'notes' => $availabilityNotes,
            ],
        ];
    }

    /**
     * @param Collection<int,array{delivery:MarketingEmailDelivery,normalized:array<string,mixed>,issuance_id:?int}> $deliveryRows
     * @param Collection<int,BirthdayRewardIssuance> $issuancesById
     * @return array{
     *   mode:'template'|'provider'|'period',
     *   mode_label:string,
     *   available_modes:array<int,string>,
     *   guardrails:array{minimum_attempted_for_ranking:int,minimum_issued_for_ranking:int},
     *   summary:array{
     *     group_count:int,
     *     total_attempted:int,
     *     total_sent:int,
     *     total_failed:int,
     *     total_redeemed:int,
     *     total_attributed_revenue:float
     *   },
     *   rows:array<int,array<string,mixed>>,
     *   recommendation:array<string,mixed>,
     *   notes:array<int,string>,
     *   empty:bool
     * }
     */
    protected function buildComparison(
        string $comparisonMode,
        Collection $deliveryRows,
        Collection $issuancesById
    ): array {
        $mode = $this->normalizedComparisonMode($comparisonMode);
        $minimumAttemptedForRanking = 10;
        $minimumIssuedForRanking = 10;

        if ($deliveryRows->isEmpty()) {
            return [
                'mode' => $mode,
                'mode_label' => $this->comparisonModeLabel($mode),
                'available_modes' => ['template', 'provider', 'period'],
                'guardrails' => [
                    'minimum_attempted_for_ranking' => $minimumAttemptedForRanking,
                    'minimum_issued_for_ranking' => $minimumIssuedForRanking,
                ],
                'summary' => [
                    'group_count' => 0,
                    'total_attempted' => 0,
                    'total_sent' => 0,
                    'total_failed' => 0,
                    'total_redeemed' => 0,
                    'total_attributed_revenue' => 0.0,
                ],
                'rows' => [],
                'recommendation' => [
                    'status' => 'insufficient_data',
                    'ranking_metric' => 'redemption_rate',
                    'qualified_group_count' => 0,
                    'minimum_attempted' => $minimumAttemptedForRanking,
                    'minimum_rewards_issued' => $minimumIssuedForRanking,
                    'message' => 'No birthday delivery rows are available for comparison in the selected filter range.',
                ],
                'notes' => [
                    'Comparison groups are built from canonical birthday delivery rows and linked reward issuances.',
                ],
                'empty' => true,
            ];
        }

        $grouped = $deliveryRows->groupBy(function (array $row) use ($mode): string {
            return $this->comparisonGroupKey($mode, $row);
        });

        $rows = [];

        foreach ($grouped as $groupKey => $rowsInGroup) {
            $attempted = (int) $rowsInGroup->count();
            $sentSuccessfully = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.sent', false))->count();
            $failed = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.failed', false))->count();
            $delivered = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.delivered', false))->count();
            $opened = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.opened', false))->count();
            $clicked = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.clicked', false))->count();
            $unsupported = (int) $rowsInGroup->filter(fn (array $row): bool => (bool) data_get($row, 'normalized.unsupported', false))->count();

            $linkedIssuanceIds = $rowsInGroup
                ->map(fn (array $row): ?int => $this->positiveInt($row['issuance_id'] ?? null))
                ->filter()
                ->unique()
                ->values();
            $linkedIssuances = $linkedIssuanceIds
                ->map(fn (int $id): ?BirthdayRewardIssuance => $issuancesById->get($id))
                ->filter();
            $redeemedIssuances = $linkedIssuances
                ->filter(fn (BirthdayRewardIssuance $issuance): bool => (string) $issuance->status === 'redeemed');

            $rewardsIssued = (int) $linkedIssuances->count();
            $couponsRedeemed = (int) $redeemedIssuances->count();
            $attributedRevenue = round((float) $redeemedIssuances->sum(
                fn (BirthdayRewardIssuance $issuance): float => $issuance->attributed_revenue !== null
                    ? (float) $issuance->attributed_revenue
                    : (float) ($issuance->order_total ?? 0)
            ), 2);
            $redemptionRate = $rewardsIssued > 0
                ? round(($couponsRedeemed / $rewardsIssued) * 100, 2)
                : 0.0;
            $revenuePerIssued = $rewardsIssued > 0
                ? round($attributedRevenue / $rewardsIssued, 2)
                : 0.0;
            $revenuePerSent = $sentSuccessfully > 0
                ? round($attributedRevenue / $sentSuccessfully, 2)
                : 0.0;
            $unlinkedDeliveryRows = max(0, $attempted - (int) $linkedIssuanceIds->count());

            $lowSampleSize = $attempted < $minimumAttemptedForRanking || $rewardsIssued < $minimumIssuedForRanking;
            $qualityFlags = [];
            if ($attempted < $minimumAttemptedForRanking) {
                $qualityFlags[] = 'low_attempt_volume';
            }
            if ($rewardsIssued < $minimumIssuedForRanking) {
                $qualityFlags[] = 'low_issuance_volume';
            }
            if ($unlinkedDeliveryRows > 0) {
                $qualityFlags[] = 'has_unlinked_deliveries';
            }
            if ($unsupported > 0) {
                $qualityFlags[] = 'contains_unsupported_attempts';
            }

            $rowNotes = [];
            if ($lowSampleSize) {
                $rowNotes[] = 'Low sample size; compare cautiously.';
            }
            if ($unlinkedDeliveryRows > 0) {
                $rowNotes[] = $unlinkedDeliveryRows . ' row(s) could not be linked to a reward issuance.';
            }
            if ($unsupported > 0) {
                $rowNotes[] = 'Includes unsupported provider attempts.';
            }

            $rows[] = [
                'group_key' => (string) $groupKey,
                'group_label' => $this->comparisonGroupLabel($mode, (string) $groupKey),
                'rewards_issued' => $rewardsIssued,
                'birthday_emails_attempted' => $attempted,
                'birthday_emails_sent_successfully' => $sentSuccessfully,
                'delivered_count' => $delivered,
                'opened_count' => $opened,
                'clicked_count' => $clicked,
                'birthday_emails_failed' => $failed,
                'unsupported_count' => $unsupported,
                'coupons_redeemed' => $couponsRedeemed,
                'redemption_rate' => $redemptionRate,
                'attributed_revenue' => $attributedRevenue,
                'revenue_per_issued_reward' => $revenuePerIssued,
                'revenue_per_successfully_sent_birthday_email' => $revenuePerSent,
                'sample_size' => [
                    'attempted' => $attempted,
                    'linked_issuances' => $rewardsIssued,
                ],
                'low_sample_size' => $lowSampleSize,
                'data_quality_flags' => $qualityFlags,
                'unlinked_delivery_rows' => $unlinkedDeliveryRows,
                'notes' => $rowNotes,
            ];
        }

        $rows = collect($rows)
            ->sortByDesc('birthday_emails_attempted')
            ->values();

        $totalAttempted = (int) $rows->sum('birthday_emails_attempted');
        $totalSent = (int) $rows->sum('birthday_emails_sent_successfully');
        $totalFailed = (int) $rows->sum('birthday_emails_failed');
        $totalRedeemed = (int) $rows->sum('coupons_redeemed');
        $totalRevenue = round((float) $rows->sum('attributed_revenue'), 2);

        $rows = $rows->map(function (array $row) use ($totalAttempted, $totalRevenue): array {
            $attemptShare = $totalAttempted > 0
                ? round(((int) ($row['birthday_emails_attempted'] ?? 0) / $totalAttempted) * 100, 2)
                : 0.0;
            $revenueShare = $totalRevenue > 0
                ? round(((float) ($row['attributed_revenue'] ?? 0) / $totalRevenue) * 100, 2)
                : 0.0;

            $row['attempt_share_pct'] = $attemptShare;
            $row['revenue_share_pct'] = $revenueShare;

            return $row;
        })->values()->all();

        $recommendation = $this->buildComparisonRecommendation(
            $rows,
            $mode,
            $minimumAttemptedForRanking,
            $minimumIssuedForRanking
        );

        $notes = [
            'Comparison rows are grouped from canonical marketing_email_deliveries and attributed to birthday_reward_issuances via persisted linkage.',
        ];
        if (collect($rows)->contains(fn (array $row): bool => (int) ($row['unsupported_count'] ?? 0) > 0)) {
            $notes[] = 'Unsupported provider attempts remain visible in comparison totals.';
        }
        if (collect($rows)->contains(fn (array $row): bool => (int) ($row['unlinked_delivery_rows'] ?? 0) > 0)) {
            $notes[] = 'Some deliveries are unlinked to issuances, which can limit redemption and revenue attribution precision for those groups.';
        }

        return [
            'mode' => $mode,
            'mode_label' => $this->comparisonModeLabel($mode),
            'available_modes' => ['template', 'provider', 'period'],
            'guardrails' => [
                'minimum_attempted_for_ranking' => $minimumAttemptedForRanking,
                'minimum_issued_for_ranking' => $minimumIssuedForRanking,
            ],
            'summary' => [
                'group_count' => count($rows),
                'total_attempted' => $totalAttempted,
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'total_redeemed' => $totalRedeemed,
                'total_attributed_revenue' => $totalRevenue,
            ],
            'rows' => $rows,
            'recommendation' => $recommendation,
            'notes' => $notes,
            'empty' => count($rows) === 0,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    protected function buildComparisonRecommendation(
        array $rows,
        string $mode,
        int $minimumAttemptedForRanking,
        int $minimumIssuedForRanking
    ): array {
        $rowsCollection = collect($rows);
        $qualified = $rowsCollection
            ->filter(function (array $row) use ($minimumAttemptedForRanking, $minimumIssuedForRanking): bool {
                return (int) ($row['birthday_emails_attempted'] ?? 0) >= $minimumAttemptedForRanking
                    && (int) ($row['rewards_issued'] ?? 0) >= $minimumIssuedForRanking
                    && (int) ($row['birthday_emails_sent_successfully'] ?? 0) > 0;
            })
            ->values();

        if ($qualified->count() < 2) {
            return [
                'status' => 'insufficient_data',
                'ranking_metric' => 'redemption_rate',
                'qualified_group_count' => (int) $qualified->count(),
                'minimum_attempted' => $minimumAttemptedForRanking,
                'minimum_rewards_issued' => $minimumIssuedForRanking,
                'message' => sprintf(
                    'Not enough groups meet the minimum sample size (%d attempts and %d linked issued rewards) for a reliable %s comparison.',
                    $minimumAttemptedForRanking,
                    $minimumIssuedForRanking,
                    $this->comparisonModeLabel($mode)
                ),
            ];
        }

        $ranked = $qualified->sort(function (array $left, array $right): int {
            foreach ([
                'redemption_rate',
                'revenue_per_successfully_sent_birthday_email',
                'birthday_emails_attempted',
            ] as $metric) {
                $leftValue = (float) ($left[$metric] ?? 0);
                $rightValue = (float) ($right[$metric] ?? 0);
                if ($leftValue === $rightValue) {
                    continue;
                }

                return $leftValue < $rightValue ? 1 : -1;
            }

            return 0;
        })->values();

        $winner = (array) ($ranked->get(0) ?? []);
        $runnerUp = (array) ($ranked->get(1) ?? []);

        if ($winner === [] || ((float) ($winner['redemption_rate'] ?? 0)) <= 0) {
            return [
                'status' => 'insufficient_data',
                'ranking_metric' => 'redemption_rate',
                'qualified_group_count' => (int) $qualified->count(),
                'minimum_attempted' => $minimumAttemptedForRanking,
                'minimum_rewards_issued' => $minimumIssuedForRanking,
                'message' => 'No comparison group has enough positive performance signal to recommend a leader confidently.',
            ];
        }

        $redemptionDelta = abs(
            (float) ($winner['redemption_rate'] ?? 0) - (float) ($runnerUp['redemption_rate'] ?? 0)
        );
        if ($runnerUp !== [] && $redemptionDelta < 1.0) {
            return [
                'status' => 'insufficient_data',
                'ranking_metric' => 'redemption_rate',
                'qualified_group_count' => (int) $qualified->count(),
                'minimum_attempted' => $minimumAttemptedForRanking,
                'minimum_rewards_issued' => $minimumIssuedForRanking,
                'message' => 'Top comparison groups are too close on redemption rate to call a reliable leader.',
            ];
        }

        return [
            'status' => 'ranked',
            'ranking_metric' => 'redemption_rate',
            'qualified_group_count' => (int) $qualified->count(),
            'minimum_attempted' => $minimumAttemptedForRanking,
            'minimum_rewards_issued' => $minimumIssuedForRanking,
            'winner_group_key' => (string) ($winner['group_key'] ?? ''),
            'winner_group_label' => (string) ($winner['group_label'] ?? ''),
            'winner_redemption_rate' => round((float) ($winner['redemption_rate'] ?? 0), 2),
            'winner_revenue_per_successful_send' => round((float) ($winner['revenue_per_successfully_sent_birthday_email'] ?? 0), 2),
            'message' => sprintf(
                'Conservative leader by redemption rate: %s (%.2f%% redemption, %d attempted sends).',
                (string) ($winner['group_label'] ?? 'Unknown'),
                (float) ($winner['redemption_rate'] ?? 0),
                (int) ($winner['birthday_emails_attempted'] ?? 0)
            ),
        ];
    }

    /**
     * @param array{delivery:MarketingEmailDelivery,normalized:array<string,mixed>,issuance_id:?int} $row
     */
    protected function comparisonGroupKey(string $mode, array $row): string
    {
        $delivery = $row['delivery'];

        if ($mode === 'provider') {
            $provider = strtolower(trim((string) ($delivery->provider ?? '')));

            return $provider !== '' ? $provider : 'unknown';
        }

        $template = trim((string) ($delivery->template_key ?? ''));

        return $template !== '' ? $template : 'unknown';
    }

    protected function comparisonGroupLabel(string $mode, string $groupKey): string
    {
        if ($groupKey === 'unknown') {
            return $mode === 'provider' ? 'Unknown provider' : 'Unknown template';
        }

        return $groupKey;
    }

    protected function comparisonModeLabel(string $mode): string
    {
        return match ($mode) {
            'provider' => 'Provider',
            'period' => 'Period',
            default => 'Template',
        };
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $currentSnapshot
     * @return array<string,mixed>
     */
    protected function buildPeriodComparison(
        array $filters,
        CarbonImmutable $currentPeriodFrom,
        CarbonImmutable $currentPeriodTo,
        ?CarbonImmutable $compareFrom,
        ?CarbonImmutable $compareTo,
        string $periodView,
        array $currentSnapshot
    ): array {
        $viewMode = $this->normalizedPeriodView($periodView);
        $minimumAttemptedForComparison = 10;
        $minimumIssuedForComparison = 10;
        $minimumPriorBaseline = 3;

        $periods = $this->resolveComparisonPeriodRange(
            currentFrom: $currentPeriodFrom,
            currentTo: $currentPeriodTo,
            compareFrom: $compareFrom,
            compareTo: $compareTo
        );
        $comparisonAnalytics = $this->birthdayAnalytics([
            ...$filters,
            'date_from' => $periods['comparison']['date_from'],
            'date_to' => $periods['comparison']['date_to'],
            'comparison_mode' => 'template',
            'period_view' => 'raw',
        ]);
        $comparisonSnapshot = $this->periodSnapshotFromAnalytics($comparisonAnalytics);

        $currentLowSample = $this->isLowSamplePeriod(
            $currentSnapshot,
            $minimumAttemptedForComparison,
            $minimumIssuedForComparison
        );
        $comparisonLowSample = $this->isLowSamplePeriod(
            $comparisonSnapshot,
            $minimumAttemptedForComparison,
            $minimumIssuedForComparison
        );

        $rangeDiagnostics = (array) ($periods['range_diagnostics'] ?? []);
        $rangeLengthMismatch = (bool) ($rangeDiagnostics['range_length_mismatch'] ?? false);
        $currentPeriodDays = (int) ($rangeDiagnostics['current_period_days'] ?? 0);
        $comparisonPeriodDays = (int) ($rangeDiagnostics['comparison_period_days'] ?? 0);
        $periodDayCountsValid = $currentPeriodDays > 0 && $comparisonPeriodDays > 0;
        $daysDifference = abs($currentPeriodDays - $comparisonPeriodDays);
        $materialRangeLengthMismatch = $rangeLengthMismatch
            && $daysDifference >= max(2, (int) ceil(max(1, $currentPeriodDays) * 0.25));

        $metricConfig = $this->periodComparisonMetricConfig();

        $metricDeltas = [];
        $summaryRows = [];
        $metricDeltasNormalized = [];
        $summaryRowsNormalized = [];
        $currentNormalizedMetrics = [];
        $comparisonNormalizedMetrics = [];
        $ratioMetricsNotReNormalized = [];
        $normalizationSupportedMetrics = [];

        foreach ($metricConfig as $metricKey => $config) {
            $currentValue = (float) data_get($currentSnapshot, 'metrics.' . $metricKey, 0);
            $comparisonValue = (float) data_get($comparisonSnapshot, 'metrics.' . $metricKey, 0);
            $rawDelta = $this->buildPeriodMetricDelta(
                metricKey: $metricKey,
                config: $config,
                currentValue: $currentValue,
                comparisonValue: $comparisonValue,
                currentLowSample: $currentLowSample,
                comparisonLowSample: $comparisonLowSample,
                rangeLengthMismatch: $rangeLengthMismatch,
                normalizedPerDay: false
            );

            $metricDeltas[$metricKey] = $rawDelta;
            $summaryRows[] = ['key' => $metricKey, ...$rawDelta];

            $normalizablePerDay = (bool) ($config['normalizable_per_day'] ?? false);
            if ($normalizablePerDay) {
                $normalizationSupportedMetrics[] = $metricKey;
                $currentNormalized = $this->normalizedPerDayValue($currentValue, $currentPeriodDays);
                $comparisonNormalized = $this->normalizedPerDayValue($comparisonValue, $comparisonPeriodDays);
            } else {
                $ratioMetricsNotReNormalized[] = $metricKey;
                $currentNormalized = $currentValue;
                $comparisonNormalized = $comparisonValue;
            }

            $currentNormalizedMetrics[$metricKey] = $normalizablePerDay
                ? ($periodDayCountsValid ? round((float) ($currentNormalized ?? 0), 4) : null)
                : round((float) $currentNormalized, 4);
            $comparisonNormalizedMetrics[$metricKey] = $normalizablePerDay
                ? ($periodDayCountsValid ? round((float) ($comparisonNormalized ?? 0), 4) : null)
                : round((float) $comparisonNormalized, 4);

            $normalizedDelta = $this->buildPeriodMetricDelta(
                metricKey: $metricKey,
                config: $config,
                currentValue: $currentNormalized,
                comparisonValue: $comparisonNormalized,
                currentLowSample: $currentLowSample,
                comparisonLowSample: $comparisonLowSample,
                rangeLengthMismatch: $rangeLengthMismatch,
                normalizedPerDay: $normalizablePerDay,
                ratioMetricNotReNormalized: ! $normalizablePerDay
            );
            if ($normalizablePerDay && ! $periodDayCountsValid) {
                $normalizedDelta['direction'] = 'insufficient_data';
                $normalizedDelta['insufficient_baseline'] = true;
            }

            $metricDeltasNormalized[$metricKey] = $normalizedDelta;
            $summaryRowsNormalized[] = ['key' => $metricKey, ...$normalizedDelta];
        }

        $recommendationStatus = 'insufficient_data';
        $recommendationMessage = sprintf(
            'Not enough stable baseline data to compare periods confidently. Minimum guardrails are %d attempted sends and %d linked issued rewards per period.',
            $minimumAttemptedForComparison,
            $minimumIssuedForComparison
        );
        $insufficientBaseline = false;
        $dataQualityFlags = [];

        if (! $currentLowSample && ! $comparisonLowSample && $periodDayCountsValid) {
            $priorAttempted = (int) data_get($comparisonSnapshot, 'metrics.birthday_emails_attempted', 0);
            $priorIssued = (int) data_get($comparisonSnapshot, 'metrics.rewards_issued', 0);
            if ($priorAttempted < $minimumPriorBaseline || $priorIssued < $minimumPriorBaseline || $materialRangeLengthMismatch) {
                $insufficientBaseline = true;
                $recommendationMessage = $materialRangeLengthMismatch
                    ? 'Comparison range length differs materially from current period; directional interpretation is limited.'
                    : 'Prior-period baseline volume is too small for meaningful directional interpretation.';
            } else {
                $redemptionDelta = (float) data_get($metricDeltas, 'redemption_rate.absolute_delta', 0);
                $revenuePerSendDelta = (float) data_get($metricDeltas, 'revenue_per_successfully_sent_birthday_email.absolute_delta', 0);

                if (abs($redemptionDelta) < 1.0 && abs($revenuePerSendDelta) < 1.0) {
                    $recommendationStatus = 'flat';
                    $recommendationMessage = 'Current and prior birthday performance appear directionally flat across redemption and revenue efficiency.';
                } elseif ($redemptionDelta > 1.0 && $revenuePerSendDelta >= 0) {
                    $recommendationStatus = 'up';
                    $recommendationMessage = 'Current period is directionally stronger than prior period based on redemption rate and revenue per successful send.';
                } elseif ($redemptionDelta < -1.0 && $revenuePerSendDelta <= 0) {
                    $recommendationStatus = 'down';
                    $recommendationMessage = 'Current period is directionally weaker than prior period based on redemption rate and revenue per successful send.';
                } else {
                    $recommendationStatus = 'mixed';
                    $recommendationMessage = 'Period-over-period signals are mixed; review individual metric deltas before changing campaign settings.';
                }
            }
        } elseif (! $periodDayCountsValid) {
            $insufficientBaseline = true;
            $recommendationMessage = 'Current/comparison day counts are invalid, so period normalization and directional interpretation are unavailable.';
        }
        if ($rangeLengthMismatch) {
            $dataQualityFlags[] = 'range_length_mismatch';
        }
        if ($materialRangeLengthMismatch) {
            $dataQualityFlags[] = 'material_range_length_mismatch';
        }
        if ($currentLowSample) {
            $dataQualityFlags[] = 'current_period_low_sample';
        }
        if ($comparisonLowSample) {
            $dataQualityFlags[] = 'comparison_period_low_sample';
        }
        if ($insufficientBaseline) {
            $dataQualityFlags[] = 'insufficient_baseline';
        }
        if (! $periodDayCountsValid) {
            $dataQualityFlags[] = 'invalid_period_day_count';
        }
        if ((int) ($currentSnapshot['unsupported_attempts'] ?? 0) > 0 || (int) ($comparisonSnapshot['unsupported_attempts'] ?? 0) > 0) {
            $dataQualityFlags[] = 'contains_unsupported_attempts';
        }
        if ((int) ($currentSnapshot['unlinked_delivery_rows'] ?? 0) > 0 || (int) ($comparisonSnapshot['unlinked_delivery_rows'] ?? 0) > 0) {
            $dataQualityFlags[] = 'has_unlinked_deliveries';
        }

        $notes = [
            ((string) ($periods['period_resolution_mode'] ?? 'auto_prior_period')) === 'custom_comparison_period'
                ? 'Custom comparison period override is active for this analysis.'
                : 'Comparison period uses the immediately preceding equal-length inclusive range by default.',
            'All active non-date filters are applied to both current and comparison periods.',
        ];
        if ($rangeLengthMismatch) {
            $notes[] = sprintf(
                'Current period (%d day%s) and comparison period (%d day%s) have different lengths.',
                $currentPeriodDays,
                $currentPeriodDays === 1 ? '' : 's',
                $comparisonPeriodDays,
                $comparisonPeriodDays === 1 ? '' : 's'
            );
        }
        foreach ((array) ($currentSnapshot['notes'] ?? []) as $note) {
            $notes[] = 'Current period: ' . (string) $note;
        }
        foreach ((array) ($comparisonSnapshot['notes'] ?? []) as $note) {
            $notes[] = 'Comparison period: ' . (string) $note;
        }
        if ((int) ($currentSnapshot['unsupported_attempts'] ?? 0) > 0 || (int) ($comparisonSnapshot['unsupported_attempts'] ?? 0) > 0) {
            $notes[] = 'Unsupported provider attempts are included in both period summaries and failure totals.';
        }
        if ($viewMode === 'per_day') {
            $notes[] = 'Per-day normalized view is active. Raw totals are still available via Period View = Raw totals.';
        }
        if (! $periodDayCountsValid) {
            $notes[] = 'Per-day normalization could not be computed because one or both period day counts were invalid.';
        }

        $rangeDiagnostics['period_day_counts_valid'] = $periodDayCountsValid;
        $rangeDiagnostics['normalized_per_day_view_active'] = $viewMode === 'per_day';
        $rangeDiagnostics['raw_totals_still_available'] = true;
        $rangeDiagnostics['ratio_metrics_not_re_normalized'] = array_values($ratioMetricsNotReNormalized);
        if ($rangeLengthMismatch) {
            $rangeDiagnostics['range_length_mismatch_interpretation_note'] = 'Range lengths differ; per-day normalization can help interpret intensity but does not remove sample-quality guardrails.';
        }

        $normalizationNotes = [
            $viewMode === 'per_day'
                ? 'Per-day normalized period view is active for count/revenue metrics.'
                : 'Raw totals period view is active.',
            'Raw totals remain available and unchanged.',
            'Ratio metrics are not re-normalized per day: redemption_rate, revenue_per_issued_reward, revenue_per_successfully_sent_birthday_email.',
        ];
        if ($rangeLengthMismatch) {
            $normalizationNotes[] = 'Date ranges differ in length; per-day view can improve comparability but does not remove baseline and sample-size guardrails.';
        }
        if (! $periodDayCountsValid) {
            $normalizationNotes[] = 'Invalid period day counts prevented per-day normalization math.';
        }

        return [
            'mode' => 'period',
            'mode_label' => 'Period',
            'available_modes' => ['template', 'provider', 'period'],
            'view_mode' => $viewMode,
            'period_resolution_mode' => (string) ($periods['period_resolution_mode'] ?? 'auto_prior_period'),
            'custom_range_override' => (bool) ($periods['custom_range_override'] ?? false),
            'range_diagnostics' => $rangeDiagnostics,
            'guardrails' => [
                'minimum_attempted_for_comparison' => $minimumAttemptedForComparison,
                'minimum_issued_for_comparison' => $minimumIssuedForComparison,
                'minimum_prior_baseline' => $minimumPriorBaseline,
            ],
            'current_period' => [
                ...$currentSnapshot,
                'date_from' => $periods['current']['date_from'],
                'date_to' => $periods['current']['date_to'],
                'label' => $periods['current']['label'],
                'low_sample_size' => $currentLowSample,
                'normalized_metrics' => $currentNormalizedMetrics,
            ],
            'prior_period' => [
                ...$comparisonSnapshot,
                'date_from' => $periods['comparison']['date_from'],
                'date_to' => $periods['comparison']['date_to'],
                'label' => $periods['comparison']['label'],
                'low_sample_size' => $comparisonLowSample,
                'normalized_metrics' => $comparisonNormalizedMetrics,
            ],
            'comparison_period' => [
                ...$comparisonSnapshot,
                'date_from' => $periods['comparison']['date_from'],
                'date_to' => $periods['comparison']['date_to'],
                'label' => $periods['comparison']['label'],
                'low_sample_size' => $comparisonLowSample,
                'normalized_metrics' => $comparisonNormalizedMetrics,
            ],
            'metric_deltas' => $metricDeltas,
            'metric_deltas_normalized' => $metricDeltasNormalized,
            'summary_rows' => $summaryRows,
            'summary_rows_normalized' => $summaryRowsNormalized,
            'normalization_supported_metrics' => array_values($normalizationSupportedMetrics),
            'normalization_notes' => array_values(array_unique($normalizationNotes)),
            'recommendation' => [
                'status' => $recommendationStatus,
                'insufficient_baseline' => $insufficientBaseline,
                'low_sample_size' => $currentLowSample || $comparisonLowSample,
                'data_quality_flags' => array_values(array_unique($dataQualityFlags)),
                'view_mode' => $viewMode,
                'message' => $recommendationMessage,
            ],
            'data_quality_flags' => array_values(array_unique($dataQualityFlags)),
            'notes' => array_values(array_unique($notes)),
            'empty' => (bool) (($currentSnapshot['empty'] ?? true) && ($comparisonSnapshot['empty'] ?? true)),
        ];
    }

    /**
     * @param array<string,mixed> $analytics
     * @return array<string,mixed>
     */
    protected function periodSnapshotFromAnalytics(array $analytics): array
    {
        $metrics = (array) ($analytics['metrics'] ?? []);
        $attempted = (int) ($metrics['birthday_emails_attempted'] ?? 0);
        $issued = (int) ($metrics['rewards_issued'] ?? 0);
        $unsupportedAttempts = (int) data_get(collect($analytics['status_breakdown'] ?? [])->firstWhere('status', 'unsupported'), 'count', 0);
        $unlinkedRows = (int) data_get($analytics, 'attribution.delivery_links.unlinked_count', 0);

        return [
            'date_from' => (string) data_get($analytics, 'filters.date_from', ''),
            'date_to' => (string) data_get($analytics, 'filters.date_to', ''),
            'label' => ((string) data_get($analytics, 'filters.date_from', '')) . ' to ' . ((string) data_get($analytics, 'filters.date_to', '')),
            'metrics' => [
                'rewards_issued' => (int) ($metrics['rewards_issued'] ?? 0),
                'birthday_emails_attempted' => (int) ($metrics['birthday_emails_attempted'] ?? 0),
                'birthday_emails_sent_successfully' => (int) ($metrics['birthday_emails_sent_successfully'] ?? 0),
                'delivered_count' => (int) ($metrics['delivered_count'] ?? 0),
                'opened_count' => (int) ($metrics['opened_count'] ?? 0),
                'clicked_count' => (int) ($metrics['clicked_count'] ?? 0),
                'birthday_emails_failed' => (int) ($metrics['birthday_emails_failed'] ?? 0),
                'coupons_redeemed' => (int) ($metrics['coupons_redeemed'] ?? 0),
                'redemption_rate' => round((float) ($metrics['redemption_rate'] ?? 0), 2),
                'attributed_revenue' => round((float) ($metrics['attributed_revenue'] ?? 0), 2),
                'revenue_per_issued_reward' => round((float) ($metrics['revenue_per_issued_reward'] ?? 0), 2),
                'revenue_per_successfully_sent_birthday_email' => round((float) ($metrics['revenue_per_successfully_sent_birthday_email'] ?? 0), 2),
            ],
            'sample_size' => [
                'attempted' => $attempted,
                'rewards_issued' => $issued,
            ],
            'unsupported_attempts' => $unsupportedAttempts,
            'unlinked_delivery_rows' => $unlinkedRows,
            'notes' => (array) ($analytics['notes'] ?? []),
            'empty' => (bool) ($analytics['empty'] ?? true),
        ];
    }

    /**
     * @return array{
     *   current:array{date_from:string,date_to:string,label:string},
     *   comparison:array{date_from:string,date_to:string,label:string},
     *   period_resolution_mode:'auto_prior_period'|'custom_comparison_period',
     *   custom_range_override:bool,
     *   range_diagnostics:array{
     *     current_period_days:int,
     *     comparison_period_days:int,
     *     range_length_mismatch:bool,
     *     days_difference:int
     *   }
     * }
     */
    protected function resolveComparisonPeriodRange(
        CarbonImmutable $currentFrom,
        CarbonImmutable $currentTo,
        ?CarbonImmutable $compareFrom = null,
        ?CarbonImmutable $compareTo = null
    ): array
    {
        $currentStart = $currentFrom->startOfDay();
        $currentEnd = $currentTo->endOfDay();

        $currentPeriodDays = $currentStart->diffInDays($currentEnd->startOfDay()) + 1;
        $customOverride = $compareFrom !== null && $compareTo !== null;
        if ($customOverride) {
            $comparisonStart = $compareFrom->startOfDay();
            $comparisonEnd = $compareTo->endOfDay();
        } else {
            // Inclusive date-range semantics:
            // if current window has N days, prior window is the N-day window immediately before current start.
            $comparisonEnd = $currentStart->subDay()->endOfDay();
            $comparisonStart = $comparisonEnd->subDays($currentPeriodDays - 1)->startOfDay();
        }
        $comparisonPeriodDays = $comparisonStart->diffInDays($comparisonEnd->startOfDay()) + 1;
        $daysDifference = abs($currentPeriodDays - $comparisonPeriodDays);

        return [
            'current' => [
                'date_from' => $currentStart->toDateString(),
                'date_to' => $currentEnd->toDateString(),
                'label' => $currentStart->toDateString() . ' to ' . $currentEnd->toDateString(),
            ],
            'comparison' => [
                'date_from' => $comparisonStart->toDateString(),
                'date_to' => $comparisonEnd->toDateString(),
                'label' => $comparisonStart->toDateString() . ' to ' . $comparisonEnd->toDateString(),
            ],
            'period_resolution_mode' => $customOverride ? 'custom_comparison_period' : 'auto_prior_period',
            'custom_range_override' => $customOverride,
            'range_diagnostics' => [
                'current_period_days' => $currentPeriodDays,
                'comparison_period_days' => $comparisonPeriodDays,
                'range_length_mismatch' => $daysDifference > 0,
                'days_difference' => $daysDifference,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    protected function isLowSamplePeriod(array $snapshot, int $minimumAttempted, int $minimumIssued): bool
    {
        return (int) data_get($snapshot, 'sample_size.attempted', 0) < $minimumAttempted
            || (int) data_get($snapshot, 'sample_size.rewards_issued', 0) < $minimumIssued;
    }

    /**
     * @return array<int,string>
     */
    protected function dateBucketKeys(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $keys = [];
        $cursor = $dateFrom->startOfDay();
        $end = $dateTo->startOfDay();

        while ($cursor->lte($end)) {
            $keys[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $keys;
    }

    protected function incrementTrendMetric(array &$dailyRows, ?string $dateKey, string $metric, float|int $amount): void
    {
        if ($dateKey === null || ! array_key_exists($dateKey, $dailyRows)) {
            return;
        }

        $existing = (float) ($dailyRows[$dateKey][$metric] ?? 0);
        $dailyRows[$dateKey][$metric] = $existing + (float) $amount;
    }

    protected function deliveryBucketDate(MarketingEmailDelivery $delivery, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = data_get($delivery, $field);
            $date = $this->modelDateValue($value);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    protected function modelDateValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value))->toDateString();
        }

        return null;
    }

    protected function normalizedStatusFilter(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['all', 'attempted', 'sent', 'delivered', 'opened', 'clicked', 'failed', 'bounced', 'unsupported'], true)
            ? $normalized
            : 'all';
    }

    protected function normalizedProviderResolutionSourceFilter(mixed $source): ?string
    {
        $normalized = strtolower(trim((string) $source));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['tenant', 'fallback', 'none', 'unknown'], true)
            ? $normalized
            : null;
    }

    protected function normalizedProviderReadinessStatusFilter(mixed $status): ?string
    {
        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['ready', 'unsupported', 'incomplete', 'error', 'not_configured', 'unknown'], true)
            ? $normalized
            : null;
    }

    protected function normalizedComparisonMode(mixed $mode): string
    {
        $normalized = strtolower(trim((string) $mode));

        return in_array($normalized, ['template', 'provider', 'period'], true)
            ? $normalized
            : 'template';
    }

    protected function normalizedPeriodView(mixed $mode): string
    {
        $normalized = strtolower(trim((string) $mode));

        return in_array($normalized, ['raw', 'per_day'], true)
            ? $normalized
            : 'raw';
    }

    /**
     * @return array<string,array{label:string,format:string,better_when:'up'|'down',normalizable_per_day:bool}>
     */
    protected function periodComparisonMetricConfig(): array
    {
        return [
            'rewards_issued' => ['label' => 'Rewards Issued', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'birthday_emails_attempted' => ['label' => 'Birthday Emails Attempted', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'birthday_emails_sent_successfully' => ['label' => 'Birthday Emails Sent Successfully', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'delivered_count' => ['label' => 'Delivered Count', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'opened_count' => ['label' => 'Opened Count', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'clicked_count' => ['label' => 'Clicked Count', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'birthday_emails_failed' => ['label' => 'Birthday Emails Failed', 'format' => 'number', 'better_when' => 'down', 'normalizable_per_day' => true],
            'coupons_redeemed' => ['label' => 'Coupons Redeemed', 'format' => 'number', 'better_when' => 'up', 'normalizable_per_day' => true],
            'redemption_rate' => ['label' => 'Redemption Rate', 'format' => 'percent', 'better_when' => 'up', 'normalizable_per_day' => false],
            'attributed_revenue' => ['label' => 'Attributed Revenue', 'format' => 'currency', 'better_when' => 'up', 'normalizable_per_day' => true],
            'revenue_per_issued_reward' => ['label' => 'Revenue per Issued Reward', 'format' => 'currency', 'better_when' => 'up', 'normalizable_per_day' => false],
            'revenue_per_successfully_sent_birthday_email' => ['label' => 'Revenue per Successful Send', 'format' => 'currency', 'better_when' => 'up', 'normalizable_per_day' => false],
        ];
    }

    /**
     * @param array{label:string,format:string,better_when:string,normalizable_per_day:bool} $config
     * @return array{
     *   label:string,
     *   format:string,
     *   better_when:string,
     *   current_value:?float,
     *   prior_value:?float,
     *   comparison_value:?float,
     *   absolute_delta:?float,
     *   percent_delta:?float,
     *   direction:string,
     *   insufficient_baseline:bool,
     *   low_sample_size:bool,
     *   range_length_mismatch:bool,
     *   normalized_per_day:bool,
     *   ratio_metric_not_re_normalized:bool
     * }
     */
    protected function buildPeriodMetricDelta(
        string $metricKey,
        array $config,
        ?float $currentValue,
        ?float $comparisonValue,
        bool $currentLowSample,
        bool $comparisonLowSample,
        bool $rangeLengthMismatch,
        bool $normalizedPerDay,
        bool $ratioMetricNotReNormalized = false
    ): array {
        $hasValues = $currentValue !== null && $comparisonValue !== null;
        $deltaPrecision = $normalizedPerDay ? 4 : 2;
        $currentRounded = $currentValue !== null ? round($currentValue, $deltaPrecision) : null;
        $comparisonRounded = $comparisonValue !== null ? round($comparisonValue, $deltaPrecision) : null;
        $absoluteDelta = $hasValues
            ? round((float) $currentValue - (float) $comparisonValue, $deltaPrecision)
            : null;
        $insufficientBaseline = ! $hasValues || abs((float) $comparisonValue) < 0.00001;
        $percentDelta = $insufficientBaseline || ! $hasValues
            ? null
            : round((((float) $currentValue - (float) $comparisonValue) / abs((float) $comparisonValue)) * 100, 2);

        $direction = 'flat';
        if ($currentLowSample || $comparisonLowSample || $insufficientBaseline || ! $hasValues) {
            $direction = 'insufficient_data';
        } elseif (abs((float) $absoluteDelta) < 0.00001) {
            $direction = 'flat';
        } elseif ((float) $absoluteDelta > 0) {
            $direction = 'up';
        } else {
            $direction = 'down';
        }

        return [
            'label' => (string) ($config['label'] ?? $metricKey),
            'format' => (string) ($config['format'] ?? 'number'),
            'better_when' => (string) ($config['better_when'] ?? 'up'),
            'current_value' => $currentRounded,
            'prior_value' => $comparisonRounded,
            'comparison_value' => $comparisonRounded,
            'absolute_delta' => $absoluteDelta,
            'percent_delta' => $percentDelta,
            'direction' => $direction,
            'insufficient_baseline' => $insufficientBaseline,
            'low_sample_size' => $currentLowSample || $comparisonLowSample,
            'range_length_mismatch' => $rangeLengthMismatch,
            'normalized_per_day' => $normalizedPerDay,
            'ratio_metric_not_re_normalized' => $ratioMetricNotReNormalized,
        ];
    }

    protected function normalizedPerDayValue(float $value, int $periodDays): ?float
    {
        if ($periodDays <= 0) {
            return null;
        }

        return $value / $periodDays;
    }

    protected function requireTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new \RuntimeException('Tenant context is required for birthday reporting.');
        }
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
