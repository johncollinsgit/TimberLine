<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventInstance;
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
            'customersFocusAreas' => $section === 'customers' ? $this->customersFocusAreas() : [],
            'customersDiscoverySummary' => $section === 'customers' ? $this->customersDiscoverySummary() : [],
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
                'status' => 'Stage 1 reserved area and settings hooks only.',
                'next' => 'Ledger, balance usage, and reward event integrations.',
            ],
            [
                'title' => 'Reviews',
                'what' => 'Coordinates review provider ingestion and review engagement flows.',
                'status' => 'Stage 1 placeholder only, no provider execution.',
                'next' => 'Review sync, review prompts, and sentiment reporting.',
            ],
            [
                'title' => 'Source Integrations',
                'what' => 'Connects Shopify/Square/review and messaging systems into marketing workflows.',
                'status' => 'Stage 1 discovery findings documented and integration surface mapped.',
                'next' => 'Provider-specific sync monitoring and ingestion handlers.',
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
}
