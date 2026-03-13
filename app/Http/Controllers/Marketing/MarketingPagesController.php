<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\Event;
use App\Models\EventInstance;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingGroup;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyImportRun;
use App\Models\WholesaleCustomScent;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

class MarketingPagesController extends Controller
{
    public function show(string $section = 'overview'): View
    {
        $sections = MarketingSectionRegistry::sections();
        abort_unless(array_key_exists($section, $sections), 404);

        $sectionConfig = $sections[$section];

        return view('marketing.show', [
            'currentSectionKey' => $section,
            'currentSection' => $sectionConfig,
            'sections' => $this->buildNavigation($sections),
            'overviewCards' => $section === 'overview' ? $this->overviewCards() : [],
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
     * @return array<int,array{title:string,what:string,status:string,next:string}>
     */
    protected function overviewCards(): array
    {
        return [
            [
                'title' => 'Customer Identity Layer',
                'what' => 'Unifies customer identity with profile records and source links.',
                'status' => 'Stage 1 foundation tables and admin placeholders created.',
                'next' => 'Identity merge logic, conflict workflows, and profile drill-downs.',
            ],
            [
                'title' => 'Campaigns & Messaging',
                'what' => 'Controls campaign drafts, channels, and future send orchestration.',
                'status' => 'Stage 1 navigation + route skeleton only.',
                'next' => 'Campaign composer, approvals, and send execution rails.',
            ],
            [
                'title' => 'Candle Cash / Rewards',
                'what' => 'Tracks rewards value and links behavior to retention actions.',
                'status' => 'Balances, transactions, redemptions, storefront endpoints, and ops reconciliation are live; Growave parity is partial.',
                'next' => 'Import Growave opening balances and add full earn/review/expiration reward event ingestion.',
            ],
            [
                'title' => 'Reviews',
                'what' => 'Coordinates review provider ingestion and review engagement flows.',
                'status' => 'Placeholder surface only; provider sync and prompt execution are not live.',
                'next' => 'Review sync, review prompts, and sentiment reporting.',
            ],
            [
                'title' => 'Source Integrations',
                'what' => 'Connects Shopify/Square/review and messaging systems into marketing workflows.',
                'status' => 'Square sync and Shopify Growave metafield snapshot sync are live; full Growave loyalty ingestion is pending.',
                'next' => 'Provider-specific sync monitoring, ingestion handlers, and reward-ledger parity checks.',
            ],
            [
                'title' => 'Consent / Suppression',
                'what' => 'Applies channel-safe consent states and suppression rules.',
                'status' => 'Stage 1 profile-level consent fields and settings seeded.',
                'next' => 'Enforcement checks and suppression lifecycle management.',
            ],
            [
                'title' => 'Event Attribution',
                'what' => 'Relates events, orders, and channels for conversion understanding.',
                'status' => 'Stage 1 event-source mapping table introduced.',
                'next' => 'Attribution processing and event matching workflows.',
            ],
            [
                'title' => 'Recommendations / Optimization',
                'what' => 'Guides next-best actions from profile and behavior signals.',
                'status' => 'Stage 1 planning surface only.',
                'next' => 'Recommendation scoring and optimization experiments.',
            ],
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
