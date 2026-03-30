<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingGroup;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\MarketingReviewSummary;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyStore;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\ShopifyImportRun;
use App\Models\Tenant;
use App\Services\Marketing\MarketingTenantOwnershipService;
use App\Services\Marketing\MarketingSourceOverlapReportService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingPagesController extends Controller
{
    public function __construct(
        protected MarketingSourceOverlapReportService $sourceOverlapReportService,
        protected TwilioSenderConfigService $senderConfigService,
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    public function show(string $section = 'overview'): View
    {
        $requiresTenant = $this->sectionRequiresTenant($section);
        $tenantId = $requiresTenant
            ? $this->requireTenantId(request())
            : $this->currentTenantId(request());

        if ($tenantId !== null) {
            request()->attributes->set('current_tenant_id', $tenantId);
        }

        $sections = MarketingSectionRegistry::sections();
        abort_unless(array_key_exists($section, $sections), 404);

        $sectionConfig = $sections[$section];

        if ($section === 'settings') {
            return view('marketing.settings', [
                'currentSectionKey' => $section,
                'currentSection' => $sectionConfig,
                'sections' => $this->buildNavigation($sections),
                'settingsDashboard' => $this->settingsDashboard(),
            ]);
        }

        return view('marketing.show', [
            'currentSectionKey' => $section,
            'currentSection' => $sectionConfig,
            'sections' => $this->buildNavigation($sections),
            'overviewDashboard' => $section === 'overview' && $tenantId !== null
                ? $this->overviewDashboard($tenantId)
                : [],
            'messagesDashboard' => $section === 'messages' && $tenantId !== null
                ? $this->messagesDashboard((int) $tenantId)
                : [],
            'customersFocusAreas' => $section === 'customers' ? $this->customersFocusAreas() : [],
            'customersDiscoverySummary' => $section === 'customers' && $tenantId !== null
                ? $this->customersDiscoverySummary((int) $tenantId)
                : [],
            'candleCashDashboard' => $section === 'candle-cash' ? $this->candleCashDashboard() : [],
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:sms_senders'],
            'default_sender_key' => ['required', 'string', 'max:80'],
        ]);

        $sender = collect($this->senderConfigService->sendable())
            ->firstWhere('key', trim((string) $data['default_sender_key']));

        if (! $sender) {
            return back()->with('toast', [
                'style' => 'warning',
                'message' => 'Choose an enabled SMS sender before saving.',
            ]);
        }

        $this->senderConfigService->updateDefaultSender((string) $sender['key']);

        return redirect()
            ->route('marketing.settings')
            ->with('toast', [
                'style' => 'success',
                'message' => 'Default SMS sender updated.',
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
    protected function overviewDashboard(int $tenantId): array
    {
        $rewardsLabel = $this->displayLabel('rewards_label', 'Rewards');
        $totalProfiles = Schema::hasTable('marketing_profiles')
            ? (int) MarketingProfile::query()->forTenantId($tenantId)->count()
            : 0;
        $missingBothContact = Schema::hasTable('marketing_profiles')
            ? (int) MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where(function ($query): void {
                    $query->whereNull('email')->orWhere('email', '');
                })
                ->where(function ($query): void {
                    $query->whereNull('phone')->orWhere('phone', '');
                })
                ->count()
            : 0;
        $reachableProfiles = max(0, $totalProfiles - $missingBothContact);

        $overlapSummary = $this->sourceOverlapReportService->summary($tenantId);
        $bucketDefinitions = $this->sourceOverlapReportService->bucketDefinitions();

        $sourcePresence = [
            'shopify' => $this->profileCountForSource($overlapSummary, 'shopify', $bucketDefinitions),
            'square' => $this->profileCountForSource($overlapSummary, 'square', $bucketDefinitions),
            'growave' => $this->profileCountForSource($overlapSummary, 'growave', $bucketDefinitions),
        ];

        $messagesDashboard = $this->tenantMessagingSummary($tenantId);
        $groupsCount = (int) data_get($messagesDashboard, 'counts.groups', 0);
        $campaignCount = (int) data_get($messagesDashboard, 'counts.campaigns', 0);
        $templateCount = (int) data_get($messagesDashboard, 'counts.templates', 0);
        $segmentCount = (int) data_get($messagesDashboard, 'counts.segment_count', 0);
        $queuedApprovals = (int) data_get($messagesDashboard, 'counts.queued_approvals', 0);

        $positiveBalanceProfiles = Schema::hasTable('candle_cash_balances')
            ? (int) CandleCashBalance::query()
                ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->where('balance', '>', 0)
                ->count()
            : 0;
        $totalCandleCashBalance = Schema::hasTable('candle_cash_balances')
            ? round((float) CandleCashBalance::query()
                ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->sum('balance'), 3)
            : 0;
        $growaveActivityCount = Schema::hasTable('candle_cash_transactions')
            ? (int) CandleCashTransaction::query()
                ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->where('source', 'growave_activity')
                ->count()
            : 0;
        $reviewSummaryCount = Schema::hasTable('marketing_review_summaries')
            ? (int) MarketingReviewSummary::query()
                ->where('integration', 'growave')
                ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->count()
            : 0;

        $pendingIdentityReviews = Schema::hasTable('marketing_identity_reviews')
            ? (int) MarketingIdentityReview::query()
                ->where('status', 'pending')
                ->whereHas('proposedMarketingProfile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->count()
            : 0;

        $latestShopifyRun = Schema::hasTable('shopify_import_runs')
            ? $this->latestShopifyRunForTenant($tenantId)
            : null;
        $recentImportRuns = $this->recentImportRunsForTenant($tenantId);

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
                    ? (int) CustomerExternalProfile::query()
                        ->forTenantId($tenantId)
                        ->where('integration', 'shopify_customer')
                        ->count()
                    : 0,
                'supporting_label' => 'external customer rows',
                'detail' => 'Canonical profiles with ecommerce customer or order linkage.',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Square',
                'profiles' => $sourcePresence['square'],
                'supporting_value' => Schema::hasTable('square_customers') ? (int) SquareCustomer::query()->forTenantId($tenantId)->count() : 0,
                'supporting_label' => 'directory customers',
                'detail' => 'POS and event buyers landed through the source-table ingestion path.',
                'tone' => 'sky',
            ],
            [
                'label' => 'Growave',
                'profiles' => $sourcePresence['growave'],
                'supporting_value' => Schema::hasTable('customer_external_profiles')
                    ? (int) CustomerExternalProfile::query()
                        ->forTenantId($tenantId)
                        ->where('integration', 'growave')
                        ->count()
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
                    'title' => $rewardsLabel,
                    'primary_label' => 'Positive Balance Profiles',
                    'primary_value' => number_format($positiveBalanceProfiles),
                    'secondary' => number_format($totalCandleCashBalance) . ' total balance · '
                        . number_format($growaveActivityCount) . ' Growave activity rows · '
                        . number_format($reviewSummaryCount) . ' review summaries',
                    'href' => route('marketing.candle-cash'),
                    'cta' => 'Open ' . $rewardsLabel,
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
                'square_customers' => Schema::hasTable('square_customers') ? (int) SquareCustomer::query()->forTenantId($tenantId)->count() : 0,
                'square_orders' => Schema::hasTable('square_orders') ? (int) SquareOrder::query()->forTenantId($tenantId)->count() : 0,
                'square_payments' => Schema::hasTable('square_payments') ? (int) SquarePayment::query()->forTenantId($tenantId)->count() : 0,
                'growave_external_profiles' => Schema::hasTable('customer_external_profiles')
                    ? (int) CustomerExternalProfile::query()
                        ->forTenantId($tenantId)
                        ->where('integration', 'growave')
                        ->count()
                    : 0,
                'review_summaries' => $reviewSummaryCount,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function settingsDashboard(): array
    {
        $senders = $this->senderConfigService->all();
        $defaultSender = $this->senderConfigService->defaultSender();
        $defaultSetting = Schema::hasTable('marketing_settings')
            ? MarketingSetting::query()->where('key', 'sms_default_sender')->first()
            : null;

        return [
            'sms_enabled' => (bool) config('marketing.sms.enabled'),
            'twilio_enabled' => (bool) config('marketing.twilio.enabled'),
            'verify_signature' => (bool) config('marketing.twilio.verify_signature'),
            'status_callback_url' => trim((string) config('marketing.twilio.status_callback_url', '')) ?: route('marketing.webhooks.twilio-status'),
            'test_number' => trim((string) config('marketing.sms.test_number', '')) ?: null,
            'senders' => $senders,
            'default_sender' => $defaultSender,
            'default_source' => $defaultSetting ? 'marketing_settings' : 'config',
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
    protected function messagesDashboard(int $tenantId): array
    {
        return $this->tenantMessagingSummary($tenantId);
    }

    /**
     * @return array<int,array{title:string,detail:string}>
     */
    protected function customersFocusAreas(): array
    {
        $rewardsLabel = $this->displayLabel('rewards_label', 'Rewards');

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
                'title' => $rewardsLabel . ' Balance/Activity',
                'detail' => 'Future ' . strtolower($rewardsLabel) . ' snapshots and activity feeds tied to marketing profile state.',
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
    protected function customersDiscoverySummary(int $tenantId): array
    {
        $shopifyStoreKeys = $this->tenantShopifyStoreKeys($tenantId);
        $rows = [];

        try {
            if (Schema::hasTable('orders')) {
                $rows[] = [
                    'label' => 'Orders (existing operational table)',
                    'value' => (int) Order::query()->forTenantId($tenantId)->count(),
                    'note' => 'All order records currently available for downstream marketing linkage.',
                ];

                $rows[] = [
                    'label' => 'Distinct customer_name values in orders',
                    'value' => (int) Order::query()
                        ->forTenantId($tenantId)
                        ->whereNotNull('customer_name')
                        ->where('customer_name', '!=', '')
                        ->distinct()
                        ->count('customer_name'),
                    'note' => 'Current customer-like identity footprint from operational order data.',
                ];

                $rows[] = [
                    'label' => 'Shopify-linked orders',
                    'value' => (int) Order::query()
                        ->forTenantId($tenantId)
                        ->whereNotNull('shopify_order_id')
                        ->count(),
                    'note' => 'Orders tied to Shopify identifiers in the existing ingestion pipeline.',
                ];
            }

            if (Schema::hasTable('order_lines')) {
                $rows[] = [
                    'label' => 'Order lines',
                    'value' => (int) OrderLine::query()
                        ->whereHas('order', fn (Builder $query) => $query->forTenantId($tenantId))
                        ->count(),
                    'note' => 'Line-item detail currently available for profile/order enrichment.',
                ];
            }

            if (Schema::hasTable('shopify_import_runs')) {
                $rows[] = [
                    'label' => 'Shopify import runs',
                    'value' => $shopifyStoreKeys === []
                        ? 0
                        : (int) ShopifyImportRun::query()
                            ->whereIn('store_key', $shopifyStoreKeys)
                            ->count(),
                    'note' => 'Operational sync runs currently tracked for Shopify imports.',
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

        $tenantId = $this->currentTenantId(request(), true);

        $redemptionQuery = CandleCashRedemption::query()
            ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId));

        $statusBreakdown = (clone $redemptionQuery)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $platformBreakdown = (clone $redemptionQuery)
            ->selectRaw("coalesce(platform, 'unknown') as platform_key, count(*) as aggregate")
            ->groupBy('platform_key')
            ->pluck('aggregate', 'platform_key')
            ->map(fn ($value) => (int) $value)
            ->all();

        $outstanding = (clone $redemptionQuery)
            ->where('status', 'issued')
            ->count();

        $recentRedemptions = (clone $redemptionQuery)
            ->with(['profile:id,first_name,last_name,email,phone', 'reward:id,name'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $recentTransactions = Schema::hasTable('candle_cash_transactions')
            ? CandleCashTransaction::query()
                ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                ->orderByDesc('id')
                ->limit(25)
                ->get()
            : collect();

        $openIssues = Schema::hasTable('marketing_storefront_events')
            ? (int) MarketingStorefrontEvent::query()
                ->forTenantId($tenantId)
                ->where('resolution_status', 'open')
                ->whereIn('status', ['error', 'verification_required', 'pending'])
                ->count()
            : 0;

        $widgetEvents24h = Schema::hasTable('marketing_storefront_events')
            ? (int) MarketingStorefrontEvent::query()
                ->forTenantId($tenantId)
                ->where('source_surface', 'shopify_widget')
                ->where('occurred_at', '>=', now()->subDay())
                ->count()
            : 0;

        $rewardAssistedOrders = (int) ((clone $redemptionQuery)
            ->where('status', 'redeemed')
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '!=', '')
            ->selectRaw("count(distinct concat(coalesce(external_order_source,''), ':', coalesce(external_order_id,''))) as aggregate")
            ->value('aggregate') ?? 0);

        return [
            'profiles_count' => Schema::hasTable('marketing_profiles') ? (int) MarketingProfile::query()->forTenantId($tenantId)->count() : 0,
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

    protected function displayLabel(string $key, string $fallback): string
    {
        /** @var TenantDisplayLabelResolver $resolver */
        $resolver = app(TenantDisplayLabelResolver::class);

        return $resolver->label($this->currentTenantId(request()), $key, $fallback);
    }

    protected function recentImportRunsForTenant(?int $tenantId): \Illuminate\Support\Collection
    {
        if ($tenantId === null || ! Schema::hasTable('marketing_import_runs')) {
            return collect();
        }

        return MarketingImportRun::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    protected function currentTenantId(Request $request, bool $required = false): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $tenantId = $this->positiveInt($request->attributes->get($attribute));
            if ($tenantId !== null) {
                $request->attributes->set('current_tenant_id', $tenantId);

                return $tenantId;
            }
        }

        $sessionTenantId = $this->positiveInt($request->session()->get('tenant_id'));
        if ($sessionTenantId !== null) {
            $request->attributes->set('current_tenant_id', $sessionTenantId);

            return $sessionTenantId;
        }

        $user = $request->user();
        if ($user) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                $tenantId = (int) $tenantIds->first();
                $request->attributes->set('current_tenant_id', $tenantId);

                return $tenantId;
            }
        }

        if ($required) {
            abort(403, 'Tenant context is required to view this page.');
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function tenantShopifyStoreKeys(int $tenantId): array
    {
        if (! Schema::hasTable('shopify_stores')) {
            return [];
        }

        return ShopifyStore::query()
            ->forTenantId($tenantId)
            ->pluck('store_key')
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    protected function latestShopifyRunForTenant(int $tenantId): ?ShopifyImportRun
    {
        $storeKeys = $this->tenantShopifyStoreKeys($tenantId);
        if ($storeKeys === []) {
            return null;
        }

        return ShopifyImportRun::query()
            ->whereIn('store_key', $storeKeys)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantMessagingSummary(int $tenantId): array
    {
        $campaignIds = $this->ownershipService->tenantCampaignIds($tenantId);
        $groupIds = $this->ownershipService->tenantGroupIds($tenantId);
        $templateIds = $this->ownershipService->tenantTemplateIds($tenantId);
        $segmentIds = $this->ownershipService->tenantSegmentIds($tenantId);

        $groups = $groupIds->isEmpty()
            ? collect()
            : MarketingGroup::query()
                ->whereIn('id', $groupIds->all())
                ->withCount([
                    'members as members_count' => function (Builder $query) use ($tenantId): void {
                        $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
                    },
                ])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'description', 'is_internal', 'updated_at']);

        $internalGroups = $groups
            ->where('is_internal', true)
            ->values();

        $campaigns = $campaignIds->isEmpty()
            ? collect()
            : MarketingCampaign::query()
                ->whereIn('id', $campaignIds->all())
                ->withCount([
                    'recipients as recipients_count' => function (Builder $query) use ($tenantId): void {
                        $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
                    },
                ])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'status', 'channel', 'updated_at']);

        $templates = $templateIds->isEmpty()
            ? collect()
            : MarketingMessageTemplate::query()
                ->whereIn('id', $templateIds->all())
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'channel', 'objective', 'is_active', 'updated_at']);

        return [
            'counts' => [
                'groups' => (int) $groupIds->count(),
                'internal_groups' => (int) $internalGroups->count(),
                'campaigns' => (int) $campaignIds->count(),
                'queued_approvals' => Schema::hasTable('marketing_campaign_recipients')
                    ? (int) MarketingCampaignRecipient::query()
                        ->where('status', 'queued_for_approval')
                        ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                        ->count()
                    : 0,
                'templates' => (int) $templateIds->count(),
                'active_templates' => (int) $templates->where('is_active', true)->count(),
                'segment_count' => (int) $segmentIds->count(),
            ],
            'groups' => $groups,
            'internal_groups' => $internalGroups,
            'campaigns' => $campaigns,
            'templates' => $templates,
        ];
    }

    protected function sectionRequiresTenant(string $section): bool
    {
        if (! array_key_exists($section, MarketingSectionRegistry::sections())) {
            return false;
        }

        if (! Schema::hasTable('tenants')) {
            return false;
        }

        return Tenant::query()->count() > 0;
    }

    protected function requireTenantId(Request $request): int
    {
        $tenantId = $this->currentTenantId($request, true);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required to view this page.');
        }

        return $tenantId;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
