<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\Event;
use App\Models\EventInstance;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingGroup;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewSummary;
use App\Models\MarketingSegment;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\ShopifyImportRun;
use App\Models\WholesaleCustomScent;
use App\Services\Marketing\MarketingSourceOverlapReportService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketingPagesController extends Controller
{
    public function __construct(
        protected MarketingSourceOverlapReportService $sourceOverlapReportService
    ) {
    }

    public function show(string $section = 'overview'): View
    {
        $sections = MarketingSectionRegistry::sections();
        abort_unless(array_key_exists($section, $sections), 404);

        $sectionConfig = $sections[$section];

        return view('marketing.show', [
            'currentSectionKey' => $section,
            'currentSection' => $sectionConfig,
            'sections' => $this->buildNavigation($sections),
            'overviewDashboard' => $section === 'overview' ? $this->overviewDashboard() : [],
            'messagesDashboard' => $section === 'messages' ? $this->messagesDashboard() : [],
            'customersFocusAreas' => $section === 'customers' ? $this->customersFocusAreas() : [],
            'customersDiscoverySummary' => $section === 'customers' ? $this->customersDiscoverySummary() : [],
            'candleCashDashboard' => $section === 'candle-cash' ? $this->candleCashDashboard() : [],
        ]);
    }

    /**
     * @param array<string,array{label:string,route:string,description:string,hint_title:string,hint_text:string,coming_next:array<int,string>}> $sections
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function buildNavigation(array $sections): array
    {
        $items = [];
        foreach ($sections as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    protected function overviewDashboard(): array
    {
        $totalProfiles = Schema::hasTable('marketing_profiles')
            ? (int) MarketingProfile::query()->count()
            : 0;
        $missingBothContact = Schema::hasTable('marketing_profiles')
            ? (int) MarketingProfile::query()
                ->where(function ($query): void {
                    $query->whereNull('email')->orWhere('email', '');
                })
                ->where(function ($query): void {
                    $query->whereNull('phone')->orWhere('phone', '');
                })
                ->count()
            : 0;
        $reachableProfiles = max(0, $totalProfiles - $missingBothContact);

        $overlapSummary = $this->sourceOverlapReportService->summary();
        $bucketDefinitions = $this->sourceOverlapReportService->bucketDefinitions();

        $sourcePresence = [
            'shopify' => $this->profileCountForSource($overlapSummary, 'shopify', $bucketDefinitions),
            'square' => $this->profileCountForSource($overlapSummary, 'square', $bucketDefinitions),
            'growave' => $this->profileCountForSource($overlapSummary, 'growave', $bucketDefinitions),
        ];

        $groupsCount = Schema::hasTable('marketing_groups') ? (int) MarketingGroup::query()->count() : 0;
        $campaignCount = Schema::hasTable('marketing_campaigns') ? (int) MarketingCampaign::query()->count() : 0;
        $templateCount = Schema::hasTable('marketing_message_templates') ? (int) MarketingMessageTemplate::query()->count() : 0;
        $segmentCount = Schema::hasTable('marketing_segments') ? (int) MarketingSegment::query()->count() : 0;
        $queuedApprovals = Schema::hasTable('marketing_campaign_recipients')
            ? (int) MarketingCampaignRecipient::query()->where('status', 'queued_for_approval')->count()
            : 0;

        $positiveBalanceProfiles = Schema::hasTable('candle_cash_balances')
            ? (int) CandleCashBalance::query()->where('balance', '>', 0)->count()
            : 0;
        $totalCandleCashBalance = Schema::hasTable('candle_cash_balances')
            ? (int) CandleCashBalance::query()->sum('balance')
            : 0;
        $growaveActivityCount = Schema::hasTable('candle_cash_transactions')
            ? (int) CandleCashTransaction::query()->where('source', 'growave_activity')->count()
            : 0;
        $reviewSummaryCount = Schema::hasTable('marketing_review_summaries')
            ? (int) MarketingReviewSummary::query()->where('integration', 'growave')->count()
            : 0;

        $pendingIdentityReviews = Schema::hasTable('marketing_identity_reviews')
            ? (int) MarketingIdentityReview::query()->where('status', 'pending')->count()
            : 0;

        $latestShopifyRun = Schema::hasTable('shopify_import_runs')
            ? ShopifyImportRun::query()->orderByDesc('id')->first()
            : null;
        $recentImportRuns = Schema::hasTable('marketing_import_runs')
            ? MarketingImportRun::query()->orderByDesc('id')->limit(6)->get()
            : collect();

        $topBuckets = collect([
            'shopify_square_growave',
            'square_only',
            'shopify_growave',
            'shopify_only',
            'unlinked_or_other',
        ])->map(function (string $key) use ($overlapSummary): array {
            return (array) ($overlapSummary[$key] ?? []);
        })->filter(fn (array $bucket): bool => ! empty($bucket))->values()->all();

        $sourceCards = [
            [
                'label' => 'Shopify',
                'profiles' => $sourcePresence['shopify'],
                'supporting_value' => Schema::hasTable('customer_external_profiles')
                    ? (int) CustomerExternalProfile::query()->where('integration', 'shopify_customer')->count()
                    : 0,
                'supporting_label' => 'external customer rows',
                'detail' => 'Canonical profiles with ecommerce customer or order linkage.',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Square',
                'profiles' => $sourcePresence['square'],
                'supporting_value' => Schema::hasTable('square_customers') ? (int) SquareCustomer::query()->count() : 0,
                'supporting_label' => 'directory customers',
                'detail' => 'POS and event buyers landed through the source-table ingestion path.',
                'tone' => 'sky',
            ],
            [
                'label' => 'Growave',
                'profiles' => $sourcePresence['growave'],
                'supporting_value' => Schema::hasTable('customer_external_profiles')
                    ? (int) CustomerExternalProfile::query()->where('integration', 'growave')->count()
                    : 0,
                'supporting_label' => 'loyalty external rows',
                'detail' => 'Imported loyalty, referral, review, and historical activity attached locally.',
                'tone' => 'amber',
            ],
        ];

        return [
            'hero_metrics' => [
                [
                    'label' => 'Canonical Profiles',
                    'value' => $totalProfiles,
                    'caption' => 'Unified customers now resident in `marketing_profiles`.',
                    'tone' => 'emerald',
                ],
                [
                    'label' => 'Reachable Profiles',
                    'value' => $reachableProfiles,
                    'caption' => $totalProfiles > 0
                        ? number_format(($reachableProfiles / max(1, $totalProfiles)) * 100, 1) . '% have an email or phone.'
                        : 'No reachable profiles yet.',
                    'tone' => 'sky',
                ],
                [
                    'label' => 'Cross-channel Core',
                    'value' => (int) data_get($overlapSummary, 'shopify_square_growave.profile_count', 0),
                    'caption' => 'Profiles with Shopify + Square + Growave overlap.',
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Square-only Missing Contact',
                    'value' => (int) data_get($overlapSummary, 'square_only.missing_both_count', 0),
                    'caption' => 'POS buyers that still need contact capture before messaging.',
                    'tone' => 'rose',
                ],
            ],
            'source_cards' => $sourceCards,
            'system_cards' => [
                [
                    'title' => 'Messages',
                    'primary_label' => 'Campaigns / Templates',
                    'primary_value' => $campaignCount . ' / ' . $templateCount,
                    'secondary' => number_format($groupsCount) . ' groups · ' . number_format($segmentCount) . ' segments · ' . number_format($queuedApprovals) . ' queued approvals',
                    'href' => route('marketing.messages'),
                    'cta' => 'Open Messages',
                    'tone' => 'sky',
                ],
                [
                    'title' => 'Rewards',
                    'primary_label' => 'Positive Balance Profiles',
                    'primary_value' => number_format($positiveBalanceProfiles),
                    'secondary' => number_format($totalCandleCashBalance) . ' total balance · '
                        . number_format($growaveActivityCount) . ' Growave activity rows · '
                        . number_format($reviewSummaryCount) . ' review summaries',
                    'href' => route('marketing.candle-cash'),
                    'cta' => 'Open Rewards',
                    'tone' => 'amber',
                ],
                [
                    'title' => 'Fix Matches',
                    'primary_label' => 'Pending Match Fixes',
                    'primary_value' => number_format($pendingIdentityReviews),
                    'secondary' => number_format((int) data_get($overlapSummary, 'unlinked_or_other.profile_count', 0)) . ' unlinked/other profiles · '
                        . number_format((int) data_get($overlapSummary, 'square_only.missing_both_count', 0)) . ' square-only no-contact profiles',
                    'href' => route('marketing.identity-review'),
                    'cta' => 'Open Fix Matches',
                    'tone' => 'rose',
                ],
            ],
            'bucket_summary' => $topBuckets,
            'focus_actions' => collect([
                [
                    'title' => 'Capture Square buyer contact info',
                    'metric' => (int) data_get($overlapSummary, 'square_only.missing_both_count', 0),
                    'detail' => 'Square-only profiles with neither email nor phone. These buyers are countable but not marketable yet.',
                    'href' => route('marketing.providers-integrations', ['square_filter' => 'square_only_missing_contact', 'overlap_filter' => 'square_only_missing_contact']),
                    'cta' => 'Open contact quality queue',
                    'tone' => 'rose',
                ],
                [
                    'title' => 'Move Shopify customers into loyalty',
                    'metric' => $this->bucketCount($overlapSummary, 'shopify_only'),
                    'detail' => 'Shopify-linked profiles without Growave enrichment are the cleanest loyalty expansion target.',
                    'href' => route('marketing.providers-integrations', ['overlap_filter' => 'shopify_without_growave']),
                    'cta' => 'Inspect Shopify without Growave',
                    'tone' => 'emerald',
                ],
                [
                    'title' => 'Strengthen multi-channel core customers',
                    'metric' => $this->bucketCount($overlapSummary, 'shopify_square_growave'),
                    'detail' => 'These are the highest-context customers already touching ecommerce, POS, and loyalty.',
                    'href' => route('marketing.providers-integrations', ['overlap_filter' => 'all_three']),
                    'cta' => 'Open all-source overlap',
                    'tone' => 'amber',
                ],
                [
                    'title' => 'Clear identity review backlog',
                    'metric' => $pendingIdentityReviews,
                    'detail' => 'Pending reviews are intentionally blocking ambiguous merges from entering the canonical customer graph.',
                    'href' => route('marketing.identity-review'),
                    'cta' => 'Resolve conflicts',
                    'tone' => 'sky',
                ],
            ])->filter(fn (array $item): bool => $item['metric'] > 0)->values()->all(),
            'recent_import_runs' => $recentImportRuns
                ->map(fn (MarketingImportRun $run): array => $this->presentImportRun($run))
                ->values()
                ->all(),
            'latest_shopify_run' => $latestShopifyRun ? [
                'id' => (int) $latestShopifyRun->id,
                'status' => (string) ($latestShopifyRun->status ?? 'unknown'),
                'store' => (string) ($latestShopifyRun->store_key ?? $latestShopifyRun->store ?? 'unknown'),
                'type' => (string) ($latestShopifyRun->type ?? 'shopify'),
                'finished_at' => optional($latestShopifyRun->finished_at)->toDateTimeString(),
                'started_at' => optional($latestShopifyRun->started_at)->toDateTimeString(),
            ] : null,
            'source_overlap_total_profiles' => array_sum(array_map(
                fn (array $bucket): int => (int) ($bucket['profile_count'] ?? 0),
                $overlapSummary
            )),
            'source_metrics' => [
                'square_customers' => Schema::hasTable('square_customers') ? (int) SquareCustomer::query()->count() : 0,
                'square_orders' => Schema::hasTable('square_orders') ? (int) SquareOrder::query()->count() : 0,
                'square_payments' => Schema::hasTable('square_payments') ? (int) SquarePayment::query()->count() : 0,
                'growave_external_profiles' => Schema::hasTable('customer_external_profiles')
                    ? (int) CustomerExternalProfile::query()->where('integration', 'growave')->count()
                    : 0,
                'review_summaries' => $reviewSummaryCount,
            ],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $summary
     * @param array<string,array<string,mixed>> $definitions
     */
    protected function profileCountForSource(array $summary, string $source, array $definitions): int
    {
        $count = 0;

        foreach ($definitions as $bucketKey => $definition) {
            if (($definition[$source] ?? false) !== true) {
                continue;
            }

            $count += (int) data_get($summary, $bucketKey . '.profile_count', 0);
        }

        return $count;
    }

    /**
     * @param array<string,array<string,mixed>> $summary
     */
    protected function bucketCount(array $summary, string $bucketKey): int
    {
        return (int) data_get($summary, $bucketKey . '.profile_count', 0);
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentImportRun(MarketingImportRun $run): array
    {
        $summary = is_array($run->summary) ? $run->summary : [];
        $processed = data_get($summary, 'checkpoint.processed')
            ?? data_get($summary, 'processed')
            ?? data_get($summary, 'candidates_scanned')
            ?? data_get($summary, 'created')
            ?? data_get($summary, 'rows_processed')
            ?? null;
        $errors = data_get($summary, 'checkpoint.errors')
            ?? data_get($summary, 'errors')
            ?? null;

        return [
            'id' => (int) $run->id,
            'type' => (string) $run->type,
            'source_label' => (string) ($run->source_label ?: $run->type),
            'status' => (string) $run->status,
            'processed' => $processed !== null ? (int) $processed : null,
            'errors' => $errors !== null ? (int) $errors : null,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'updated_at' => optional($run->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function messagesDashboard(): array
    {
        $groups = MarketingGroup::query()
            ->withCount('members')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'description', 'is_internal', 'updated_at']);

        $internalGroups = $groups
            ->where('is_internal', true)
            ->values();

        $campaigns = MarketingCampaign::query()
            ->withCount('recipients')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'status', 'channel', 'updated_at']);

        $templates = MarketingMessageTemplate::query()
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'channel', 'objective', 'is_active', 'updated_at']);

        return [
            'counts' => [
                'groups' => MarketingGroup::query()->count(),
                'internal_groups' => MarketingGroup::query()->where('is_internal', true)->count(),
                'campaigns' => MarketingCampaign::query()->count(),
                'queued_approvals' => MarketingCampaignRecipient::query()->where('status', 'queued_for_approval')->count(),
                'templates' => MarketingMessageTemplate::query()->count(),
                'active_templates' => MarketingMessageTemplate::query()->where('is_active', true)->count(),
            ],
            'groups' => $groups,
            'internal_groups' => $internalGroups,
            'campaigns' => $campaigns,
            'templates' => $templates,
        ];
    }

    /**
     * @return array<int,array{title:string,detail:string}>
     */
    protected function customersFocusAreas(): array
    {
        return [
            [
                'title' => 'Unified Identity Layer',
                'detail' => 'Single marketing profile per person with normalized contact fields and opt-in flags.',
            ],
            [
                'title' => 'Source-Linked Records',
                'detail' => 'Traceable links to source systems without mutating operational customer/order structures.',
            ],
            [
                'title' => 'Order Visibility',
                'detail' => 'Marketing-friendly view over existing order and line-item history.',
            ],
            [
                'title' => 'Event Purchase Visibility',
                'detail' => 'Future event and market purchase context connected to customer identity.',
            ],
            [
                'title' => 'Candle Cash Balance/Activity',
                'detail' => 'Future rewards snapshots and activity feeds tied to marketing profile state.',
            ],
            [
                'title' => 'Campaign/Message History',
                'detail' => 'Future timeline for campaign delivery, opens, clicks, and responses.',
            ],
            [
                'title' => 'Marketing Likelihood Score',
                'detail' => 'Planned profile scoring to help prioritize outreach and segmentation.',
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,value:int,note:string}>
     */
    protected function customersDiscoverySummary(): array
    {
        $rows = [];

        try {
            if (Schema::hasTable('orders')) {
                $rows[] = [
                    'label' => 'Orders (existing operational table)',
                    'value' => (int) Order::query()->count(),
                    'note' => 'All order records currently available for downstream marketing linkage.',
                ];

                $rows[] = [
                    'label' => 'Distinct customer_name values in orders',
                    'value' => (int) Order::query()
                        ->whereNotNull('customer_name')
                        ->where('customer_name', '!=', '')
                        ->distinct()
                        ->count('customer_name'),
                    'note' => 'Current customer-like identity footprint from operational order data.',
                ];

                $rows[] = [
                    'label' => 'Shopify-linked orders',
                    'value' => (int) Order::query()->whereNotNull('shopify_order_id')->count(),
                    'note' => 'Orders tied to Shopify identifiers in the existing ingestion pipeline.',
                ];
            }

            if (Schema::hasTable('order_lines')) {
                $rows[] = [
                    'label' => 'Order lines',
                    'value' => (int) OrderLine::query()->count(),
                    'note' => 'Line-item detail currently available for profile/order enrichment.',
                ];
            }

            if (Schema::hasTable('events')) {
                $rows[] = [
                    'label' => 'Events',
                    'value' => (int) Event::query()->count(),
                    'note' => 'Event records that can later support attribution and lifecycle analysis.',
                ];
            }

            if (Schema::hasTable('event_instances')) {
                $rows[] = [
                    'label' => 'Event instances',
                    'value' => (int) EventInstance::query()->count(),
                    'note' => 'Imported historical event instances available for mapping and analytics.',
                ];
            }

            if (Schema::hasTable('shopify_import_runs')) {
                $rows[] = [
                    'label' => 'Shopify import runs',
                    'value' => (int) ShopifyImportRun::query()->count(),
                    'note' => 'Operational sync runs currently tracked for Shopify imports.',
                ];
            }

            if (Schema::hasTable('wholesale_custom_scents')) {
                $rows[] = [
                    'label' => 'Wholesale custom scent records',
                    'value' => (int) WholesaleCustomScent::query()->count(),
                    'note' => 'Existing account-linked scent records that may influence profile linking rules.',
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    protected function candleCashDashboard(): array
    {
        if (! Schema::hasTable('candle_cash_redemptions')) {
            return [];
        }

        $statusBreakdown = CandleCashRedemption::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $platformBreakdown = CandleCashRedemption::query()
            ->selectRaw("coalesce(platform, 'unknown') as platform_key, count(*) as aggregate")
            ->groupBy('platform_key')
            ->pluck('aggregate', 'platform_key')
            ->map(fn ($value) => (int) $value)
            ->all();

        $outstanding = CandleCashRedemption::query()
            ->where('status', 'issued')
            ->count();

        $recentRedemptions = CandleCashRedemption::query()
            ->with(['profile:id,first_name,last_name,email,phone', 'reward:id,name'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $recentTransactions = Schema::hasTable('candle_cash_transactions')
            ? CandleCashTransaction::query()->orderByDesc('id')->limit(25)->get()
            : collect();

        $openIssues = Schema::hasTable('marketing_storefront_events')
            ? (int) MarketingStorefrontEvent::query()
                ->where('resolution_status', 'open')
                ->whereIn('status', ['error', 'verification_required', 'pending'])
                ->count()
            : 0;

        $widgetEvents24h = Schema::hasTable('marketing_storefront_events')
            ? (int) MarketingStorefrontEvent::query()
                ->where('source_surface', 'shopify_widget')
                ->where('occurred_at', '>=', now()->subDay())
                ->count()
            : 0;

        $rewardAssistedOrders = (int) (CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '!=', '')
            ->selectRaw("count(distinct concat(coalesce(external_order_source,''), ':', coalesce(external_order_id,''))) as aggregate")
            ->value('aggregate') ?? 0);

        return [
            'profiles_count' => Schema::hasTable('marketing_profiles') ? (int) MarketingProfile::query()->count() : 0,
            'status_breakdown' => $statusBreakdown,
            'platform_breakdown' => $platformBreakdown,
            'outstanding_issued' => (int) $outstanding,
            'recent_redemptions' => $recentRedemptions,
            'recent_transactions' => $recentTransactions,
            'unresolved_issues_open' => $openIssues,
            'widget_events_24h' => $widgetEvents24h,
            'reward_assisted_orders' => $rewardAssistedOrders,
        ];
    }
}
