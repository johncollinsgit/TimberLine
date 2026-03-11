<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\MarketingProfileScoreService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketingCustomersController extends Controller
{
    public function __construct(
        protected MarketingEventAttributionService $attributionService,
        protected MarketingProfileScoreService $scoreService,
        protected MarketingSegmentEvaluator $segmentEvaluator,
        protected MarketingConsentService $consentService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'updated_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $usingFallbackIndex = false;

        if (!in_array($sort, ['updated_at', 'created_at', 'email', 'first_name', 'last_name'], true)) {
            $sort = 'updated_at';
        }

        $profiles = $this->emptyPaginator($request, $perPage);
        if (Schema::hasTable('marketing_profiles')) {
            $profileQuery = MarketingProfile::query()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($nested) use ($search): void {
                        $nested->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
                });

            if (Schema::hasTable('marketing_profile_links')) {
                $profileQuery->withCount('links');
            }

            $profiles = $profileQuery
                ->orderBy($sort, $dir)
                ->paginate($perPage)
                ->withQueryString();
        }

        $derivedStats = $this->buildDerivedStats($profiles->getCollection());
        if ($profiles->total() === 0) {
            [$fallbackProfiles, $fallbackStats] = $this->buildShopifyFallbackPaginator(
                request: $request,
                search: $search,
                sort: $sort,
                dir: $dir,
                perPage: $perPage
            );

            if ($fallbackProfiles instanceof LengthAwarePaginator && $fallbackProfiles->total() > 0) {
                $profiles = $fallbackProfiles;
                $derivedStats = $fallbackStats;
                $usingFallbackIndex = true;
            }
        }

        [$indexStatus, $growaveStatus] = $this->buildStatusSummaries($usingFallbackIndex);

        return view('marketing.customers.index', [
            'section' => MarketingSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'profiles' => $profiles,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'perPage' => $perPage,
            'derivedStats' => $derivedStats,
            'usingFallbackIndex' => $usingFallbackIndex,
            'indexStatus' => $indexStatus,
            'growaveStatus' => $growaveStatus,
        ]);
    }

    protected function emptyPaginator(Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    /**
     * @return array{
     *   0:?LengthAwarePaginator,
     *   1:array<int,array{order_count:int,last_order_at:?string,last_activity_at:?string,source_badges:array<int,string>}>
     * }
     */
    protected function buildShopifyFallbackPaginator(
        Request $request,
        string $search,
        string $sort,
        string $dir,
        int $perPage
    ): array {
        if (!Schema::hasTable('orders')) {
            return [null, []];
        }

        $emailColumns = $this->availableOrderColumns([
            'email',
            'customer_email',
            'shipping_email',
            'billing_email',
        ]);
        $phoneColumns = $this->availableOrderColumns([
            'phone',
            'customer_phone',
            'shipping_phone',
            'billing_phone',
        ]);

        $query = Order::query()
            ->where(function ($nested): void {
                $nested->whereNotNull('shopify_order_id')
                    ->orWhere('source', 'like', '%shopify%');
            })
            ->orderBy('id');

        $groups = [];
        $fallbackId = -1;
        $needle = strtolower($search);

        foreach ($query->lazyById(300) as $order) {
            $name = $this->firstNonEmptyOrderValue($order, [
                'customer_name',
                'shipping_name',
                'billing_name',
                'order_label',
                'shopify_name',
            ]) ?: ('Shopify order #' . (string) $order->id);

            [$firstName, $lastName] = $this->identityNormalizer->splitName($name);

            $email = $this->firstNonEmptyOrderValue($order, $emailColumns);
            $normalizedEmail = $this->identityNormalizer->normalizeEmail($email);
            if ($normalizedEmail === null) {
                $email = null;
            }

            $phone = $this->firstNonEmptyOrderValue($order, $phoneColumns);
            $normalizedPhone = $this->identityNormalizer->normalizePhone($phone);
            if ($normalizedPhone === null) {
                $phone = null;
            }

            $searchable = strtolower(implode(' ', array_filter([
                $name,
                $firstName,
                $lastName,
                $email,
                $phone,
                (string) ($order->order_number ?? ''),
                (string) ($order->shopify_name ?? ''),
            ])));
            if ($needle !== '' && !str_contains($searchable, $needle)) {
                continue;
            }

            $groupKey = $this->fallbackGroupKey(
                order: $order,
                displayName: $name,
                normalizedEmail: $normalizedEmail,
                normalizedPhone: $normalizedPhone
            );

            $orderedTs = optional($order->ordered_at)->timestamp;
            $updatedTs = optional($order->updated_at)->timestamp;
            $createdTs = optional($order->created_at)->timestamp;
            $channels = $this->deriveFallbackSourceChannels($order);
            $sourceRef = $order->shopify_order_id !== null
                ? 'shopify_order:' . (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . (string) $order->shopify_order_id
                : 'order:' . (string) $order->id;

            if (!array_key_exists($groupKey, $groups)) {
                $groups[$groupKey] = [
                    'id' => $fallbackId--,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'source_channels' => $channels,
                    'order_count' => 0,
                    'last_order_ts' => null,
                    'last_activity_ts' => null,
                    'created_ts' => $createdTs,
                    'updated_ts' => $updatedTs,
                    'linked_sources' => [],
                ];
            }

            $groups[$groupKey]['order_count']++;
            $groups[$groupKey]['source_channels'] = array_values(array_unique(array_merge(
                $groups[$groupKey]['source_channels'],
                $channels
            )));
            $groups[$groupKey]['linked_sources'][$sourceRef] = true;
            $groups[$groupKey]['last_order_ts'] = $this->maxTimestamp(
                $groups[$groupKey]['last_order_ts'],
                $orderedTs
            );
            $groups[$groupKey]['last_activity_ts'] = $this->maxTimestamp(
                $groups[$groupKey]['last_activity_ts'],
                $orderedTs,
                $updatedTs
            );
            $groups[$groupKey]['created_ts'] = $this->minTimestamp(
                $groups[$groupKey]['created_ts'],
                $createdTs
            );
            $groups[$groupKey]['updated_ts'] = $this->maxTimestamp(
                $groups[$groupKey]['updated_ts'],
                $updatedTs,
                $orderedTs
            );
        }

        if ($groups === []) {
            return [null, []];
        }

        $derived = [];
        $fallbackProfiles = collect(array_values($groups))
            ->map(function (array $group) use (&$derived): object {
                $derived[(int) $group['id']] = [
                    'order_count' => (int) $group['order_count'],
                    'last_order_at' => $group['last_order_ts'] ? date('Y-m-d', (int) $group['last_order_ts']) : null,
                    'last_activity_at' => $group['last_activity_ts'] ? date('Y-m-d', (int) $group['last_activity_ts']) : null,
                    'source_badges' => $this->sourceBadgesFromChannels((array) $group['source_channels']),
                ];

                return (object) [
                    'id' => (int) $group['id'],
                    'first_name' => $group['first_name'],
                    'last_name' => $group['last_name'],
                    'email' => $group['email'],
                    'phone' => $group['phone'],
                    'source_channels' => $group['source_channels'],
                    'links_count' => count($group['linked_sources']),
                    'accepts_email_marketing' => false,
                    'accepts_sms_marketing' => false,
                    'marketing_score' => null,
                    'created_at' => $group['created_ts'] ? Carbon::createFromTimestamp((int) $group['created_ts']) : null,
                    'updated_at' => $group['updated_ts'] ? Carbon::createFromTimestamp((int) $group['updated_ts']) : null,
                    'is_fallback' => true,
                ];
            });

        $sorted = $this->sortFallbackProfiles($fallbackProfiles, $sort, $dir);
        $page = max(1, (int) $request->query('page', 1));
        $total = $sorted->count();
        $pageItems = $sorted->forPage($page, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            items: $pageItems,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return [$paginator, $derived];
    }

    /**
     * @param Collection<int,object> $profiles
     * @return Collection<int,object>
     */
    protected function sortFallbackProfiles(Collection $profiles, string $sort, string $dir): Collection
    {
        $sorted = match ($sort) {
            'email' => $profiles->sortBy(fn (object $profile) => strtolower((string) ($profile->email ?? '')), SORT_NATURAL),
            'first_name' => $profiles->sortBy(fn (object $profile) => strtolower((string) ($profile->first_name ?? '')), SORT_NATURAL),
            'last_name' => $profiles->sortBy(fn (object $profile) => strtolower((string) ($profile->last_name ?? '')), SORT_NATURAL),
            'created_at' => $profiles->sortBy(fn (object $profile) => optional($profile->created_at)->timestamp ?? 0),
            default => $profiles->sortBy(fn (object $profile) => optional($profile->updated_at)->timestamp ?? 0),
        };

        if ($dir === 'desc') {
            $sorted = $sorted->reverse();
        }

        return $sorted->values();
    }

    /**
     * @param array<int,string> $columns
     * @return array<int,string>
     */
    protected function availableOrderColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('orders', $column)
        ));
    }

    /**
     * @param array<int,string> $columns
     */
    protected function firstNonEmptyOrderValue(Order $order, array $columns): ?string
    {
        foreach ($columns as $column) {
            $value = trim((string) data_get($order->getAttributes(), $column, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function fallbackGroupKey(
        Order $order,
        string $displayName,
        ?string $normalizedEmail,
        ?string $normalizedPhone
    ): string {
        if ($normalizedEmail !== null) {
            return 'email:' . $normalizedEmail;
        }
        if ($normalizedPhone !== null) {
            return 'phone:' . $normalizedPhone;
        }

        if ($displayName !== '') {
            $storeKey = strtolower((string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown'));

            return 'name:' . sha1(strtolower(trim($displayName)) . '|' . $storeKey);
        }

        return 'order:' . (string) $order->id;
    }

    /**
     * @return array<int,string>
     */
    protected function deriveFallbackSourceChannels(Order $order): array
    {
        $channels = [];
        $source = strtolower(trim((string) ($order->source ?? '')));
        $orderType = strtolower(trim((string) ($order->order_type ?? '')));

        if ($order->shopify_order_id !== null || str_contains($source, 'shopify')) {
            $channels[] = 'shopify';
            $channels[] = 'online';
        }
        if ($orderType === 'wholesale') {
            $channels[] = 'wholesale';
        }
        if ($orderType === 'event' || $order->event_id !== null) {
            $channels[] = 'event';
        }

        return array_values(array_unique($channels));
    }

    /**
     * @param array<int,string> $channels
     * @return array<int,string>
     */
    protected function sourceBadgesFromChannels(array $channels): array
    {
        $badges = [];
        $normalized = collect($channels)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values();

        if ($normalized->contains('shopify')) {
            $badges[] = 'Shopify';
        }
        if ($normalized->contains('online')) {
            $badges[] = 'Online Buyer';
        }
        if ($normalized->contains('wholesale')) {
            $badges[] = 'Wholesale';
        }
        if ($normalized->contains('event')) {
            $badges[] = 'Event Buyer';
        }

        return $badges;
    }

    protected function maxTimestamp(?int ...$timestamps): ?int
    {
        $valid = array_filter($timestamps, fn (?int $value): bool => $value !== null);
        if ($valid === []) {
            return null;
        }

        return (int) max($valid);
    }

    protected function minTimestamp(?int ...$timestamps): ?int
    {
        $valid = array_filter($timestamps, fn (?int $value): bool => $value !== null);
        if ($valid === []) {
            return null;
        }

        return (int) min($valid);
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    protected function buildStatusSummaries(bool $usingFallbackIndex): array
    {
        $indexStatus = [
            'tone' => 'neutral',
            'title' => 'Marketing index status',
            'message' => 'Marketing customer profile rows are ready for filtering.',
            'commands' => [],
        ];
        $growaveStatus = [
            'tone' => 'neutral',
            'title' => 'Growave status',
            'message' => 'Growave snapshot status is unavailable.',
            'commands' => [],
        ];

        $profileCount = Schema::hasTable('marketing_profiles')
            ? (int) MarketingProfile::query()->count()
            : 0;
        $shopifyOrderCount = 0;
        if (Schema::hasTable('orders')) {
            $shopifyOrderCount = (int) Order::query()
                ->where(function ($nested): void {
                    $nested->whereNotNull('shopify_order_id')
                        ->orWhere('source', 'like', '%shopify%');
                })
                ->count();
        }

        if (!Schema::hasTable('marketing_profiles') || !Schema::hasTable('marketing_profile_links')) {
            $indexStatus = [
                'tone' => 'warning',
                'title' => 'Marketing tables are missing',
                'message' => 'The marketing foundation tables are not fully migrated in this environment.',
                'commands' => ['php artisan migrate'],
            ];
        } elseif ($profileCount === 0 && $shopifyOrderCount > 0) {
            $indexStatus = [
                'tone' => 'warning',
                'title' => 'Customer index is empty',
                'message' => 'Shopify order records exist, but no marketing profiles are indexed yet. Rebuild the customer index to populate rows.',
                'commands' => [
                    'php artisan marketing:rebuild-customers --shopify-only',
                    'php artisan marketing:sync-profiles --shopify-only',
                ],
            ];
        } elseif ($usingFallbackIndex) {
            $indexStatus = [
                'tone' => 'info',
                'title' => 'Showing Shopify fallback rows',
                'message' => 'Results are currently coming from Shopify-derived order records because no indexed profiles matched the current filters.',
                'commands' => [
                    'php artisan marketing:rebuild-customers --shopify-only',
                ],
            ];
        } else {
            $indexStatus = [
                'tone' => 'success',
                'title' => 'Marketing customer index is active',
                'message' => "Indexed profiles: {$profileCount}. Shopify-linked orders discovered: {$shopifyOrderCount}.",
                'commands' => [],
            ];
        }

        if (!Schema::hasTable('customer_external_profiles')) {
            $growaveStatus = [
                'tone' => 'warning',
                'title' => 'Growave snapshots table missing',
                'message' => 'External profile snapshots are not migrated yet, so Growave loyalty data cannot be stored.',
                'commands' => ['php artisan migrate'],
            ];
        } else {
            $externalCount = (int) CustomerExternalProfile::query()->count();
            $linkedCount = (int) CustomerExternalProfile::query()
                ->whereNotNull('marketing_profile_id')
                ->count();

            if ($externalCount === 0) {
                $growaveStatus = [
                    'tone' => 'info',
                    'title' => 'No Growave snapshots synced yet',
                    'message' => 'Run the Shopify customer metafield sync to ingest Growave loyalty snapshots.',
                    'commands' => [
                        'php artisan shopify:sync-customer-metafields retail --limit=200',
                        'php artisan shopify:sync-customer-metafields wholesale --limit=200',
                    ],
                ];
            } elseif ($linkedCount === 0) {
                $growaveStatus = [
                    'tone' => 'warning',
                    'title' => 'Growave snapshots are unlinked',
                    'message' => "Found {$externalCount} Growave snapshot rows, but none are linked to marketing profiles yet.",
                    'commands' => [
                        'php artisan marketing:rebuild-customers --shopify-only',
                    ],
                ];
            } else {
                $growaveStatus = [
                    'tone' => 'success',
                    'title' => 'Growave snapshots are connected',
                    'message' => "External snapshots: {$externalCount}. Linked to marketing profiles: {$linkedCount}.",
                    'commands' => [],
                ];
            }
        }

        return [$indexStatus, $growaveStatus];
    }

    public function show(MarketingProfile $marketingProfile): View
    {
        $marketingProfile->load([
            'links' => fn ($query) => $query->orderByDesc('id'),
            'externalProfiles' => fn ($query) => $query->orderByDesc('synced_at')->orderByDesc('id'),
        ]);

        $orderLinks = $marketingProfile->links
            ->where('source_type', 'order')
            ->map(fn (MarketingProfileLink $link) => (int) $link->source_id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $orders = $orderLinks->isEmpty()
            ? collect()
            : Order::query()
                ->with('event')
                ->whereIn('id', $orderLinks->all())
                ->orderByDesc('ordered_at')
                ->orderByDesc('id')
                ->get();

        $eventOrders = $orders->filter(function (Order $order): bool {
            return $order->event_id !== null || (string) ($order->order_type ?? '') === 'event';
        });

        $squareOrderIds = $marketingProfile->links
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->filter()
            ->values();

        $squareOrders = $squareOrderIds->isEmpty()
            ? collect()
            : SquareOrder::query()
                ->with(['attributions.eventInstance'])
                ->whereIn('square_order_id', $squareOrderIds->all())
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->get();

        $squarePaymentIds = $marketingProfile->links
            ->where('source_type', 'square_payment')
            ->pluck('source_id')
            ->filter()
            ->values();

        $squarePayments = $squarePaymentIds->isEmpty()
            ? collect()
            : SquarePayment::query()
                ->whereIn('square_payment_id', $squarePaymentIds->all())
                ->orderByDesc('created_at_source')
                ->orderByDesc('id')
                ->get();

        $legacyLinks = $marketingProfile->links
            ->whereIn('source_type', ['yotpo_contact', 'square_marketing_contact'])
            ->values();

        $eventSummary = $this->attributionService->eventSummaryForProfile($marketingProfile);
        $unresolvedAttributionValues = $this->attributionService->unresolvedValuesForProfile($marketingProfile);
        $campaignStats = $marketingProfile->externalCampaignStats()->orderByDesc('updated_at')->get();
        $scoreResult = $this->scoreService->refreshForProfile($marketingProfile);
        $latestScore = $this->scoreService->latestScoreForProfile($marketingProfile);

        $matchingSegments = [];
        $segmentCandidates = MarketingSegment::query()->where('status', 'active')->orderBy('name')->limit(50)->get();
        foreach ($segmentCandidates as $segment) {
            $evaluation = $this->segmentEvaluator->evaluateProfile($segment, $marketingProfile);
            if ($evaluation['matched']) {
                $matchingSegments[] = [
                    'id' => (int) $segment->id,
                    'name' => (string) $segment->name,
                    'reasons' => $evaluation['reasons'],
                ];
            }
        }
        $campaignOptions = MarketingCampaign::query()
            ->whereIn('status', ['draft', 'ready_for_review', 'active'])
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['id', 'name', 'status']);

        $deliveries = $marketingProfile->messageDeliveries()
            ->with(['campaign:id,name', 'variant:id,name', 'recipient:id,status'])
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $conversions = $marketingProfile->campaignConversions()
            ->with(['campaign:id,name', 'recipient:id,status'])
            ->orderByDesc('converted_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $consentEvents = $marketingProfile->consentEvents()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return view('marketing.customers.show', [
            'section' => MarketingSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'profile' => $marketingProfile,
            'orders' => $orders,
            'eventOrders' => $eventOrders,
            'squareOrders' => $squareOrders,
            'squarePayments' => $squarePayments,
            'legacyLinks' => $legacyLinks,
            'eventSummary' => $eventSummary,
            'unresolvedAttributionValues' => $unresolvedAttributionValues,
            'campaignStats' => $campaignStats,
            'latestScore' => $latestScore,
            'scoreResult' => $scoreResult,
            'matchingSegments' => $matchingSegments,
            'campaignOptions' => $campaignOptions,
            'deliveries' => $deliveries,
            'conversions' => $conversions,
            'consentEvents' => $consentEvents,
            'externalProfiles' => $marketingProfile->externalProfiles,
        ]);
    }

    public function updateConsent(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:sms,email,both'],
            'consented' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $consented = (bool) $data['consented'];
        $channel = (string) $data['channel'];
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;

        $context = [
            'source_type' => 'admin_manual',
            'source_id' => (string) (auth()->id() ?? ''),
            'details' => [
                'notes' => $notes,
            ],
        ];

        $changed = false;
        if ($channel === 'sms' || $channel === 'both') {
            $changed = $this->consentService->setSmsConsent($marketingProfile, $consented, $context) || $changed;
        }
        if ($channel === 'email' || $channel === 'both') {
            $changed = $this->consentService->setEmailConsent($marketingProfile, $consented, $context) || $changed;
        }

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => $changed ? 'success' : 'warning',
                'message' => $changed
                    ? 'Consent state updated.'
                    : 'Consent state was already set to that value.',
            ]);
    }

    /**
     * @param Collection<int,MarketingProfile> $profiles
     * @return array<int,array{order_count:int,last_order_at:?string,last_activity_at:?string,source_badges:array<int,string>}>
     */
    protected function buildDerivedStats(Collection $profiles): array
    {
        if ($profiles->isEmpty()) {
            return [];
        }
        if (!Schema::hasTable('marketing_profile_links')) {
            $fallback = [];
            foreach ($profiles as $profile) {
                $fallback[(int) $profile->id] = [
                    'order_count' => 0,
                    'last_order_at' => null,
                    'last_activity_at' => null,
                    'source_badges' => [],
                ];
            }

            return $fallback;
        }

        $profileIds = $profiles->pluck('id')->all();
        $links = MarketingProfileLink::query()
            ->whereIn('marketing_profile_id', $profileIds)
            ->get(['marketing_profile_id', 'source_type', 'source_id']);

        $orderLinks = $links->where('source_type', 'order');
        $orderIds = $orderLinks
            ->map(fn (MarketingProfileLink $link) => (int) $link->source_id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $ordersById = $orderIds->isEmpty() || !Schema::hasTable('orders')
            ? collect()
            : Order::query()
                ->whereIn('id', $orderIds->all())
                ->get(['id', 'ordered_at'])
                ->keyBy('id');

        $squareOrderIds = $links
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        $squareOrdersById = $squareOrderIds->isEmpty() || !Schema::hasTable('square_orders')
            ? collect()
            : SquareOrder::query()
                ->whereIn('square_order_id', $squareOrderIds->all())
                ->get(['square_order_id', 'closed_at'])
                ->keyBy('square_order_id');

        $attributedSquareOrderIds = $squareOrderIds->isEmpty() || !Schema::hasTable('marketing_order_event_attributions')
            ? collect()
            : MarketingOrderEventAttribution::query()
                ->where('source_type', 'square_order')
                ->whereIn('source_id', $squareOrderIds->all())
                ->pluck('source_id')
                ->unique()
                ->values();

        $squarePaymentIds = $links
            ->where('source_type', 'square_payment')
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        $squarePaymentsById = $squarePaymentIds->isEmpty() || !Schema::hasTable('square_payments')
            ? collect()
            : SquarePayment::query()
                ->whereIn('square_payment_id', $squarePaymentIds->all())
                ->get(['square_payment_id', 'created_at_source'])
                ->keyBy('square_payment_id');

        $stats = [];
        foreach ($profiles as $profile) {
            $profileLinks = $links->where('marketing_profile_id', $profile->id);

            $ids = $profileLinks->where('source_type', 'order')
                ->map(fn (MarketingProfileLink $link) => (int) $link->source_id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values();

            $lastOrder = $ids
                ->map(fn (int $id) => $ordersById->get($id))
                ->filter()
                ->sortByDesc(fn (Order $order) => optional($order->ordered_at)->timestamp ?? 0)
                ->first();

            $squareOrderDates = $profileLinks->where('source_type', 'square_order')
                ->map(fn (MarketingProfileLink $link) => optional($squareOrdersById->get((string) $link->source_id)?->closed_at)->timestamp)
                ->filter();
            $squarePaymentDates = $profileLinks->where('source_type', 'square_payment')
                ->map(fn (MarketingProfileLink $link) => optional($squarePaymentsById->get((string) $link->source_id)?->created_at_source)->timestamp)
                ->filter();
            $orderDate = optional($lastOrder?->ordered_at)->timestamp;
            $latestTimestamp = collect([$orderDate, ...$squareOrderDates->all(), ...$squarePaymentDates->all()])
                ->filter()
                ->max();

            $channels = is_array($profile->source_channels) ? $profile->source_channels : [];
            $sourceTypes = $profileLinks->pluck('source_type')->unique()->values()->all();
            $badges = [];
            if (in_array('shopify', $channels, true) || in_array('shopify_order', $sourceTypes, true)) {
                $badges[] = 'Shopify';
            }
            if (in_array('square', $channels, true) || collect($sourceTypes)->contains(fn (string $type) => str_starts_with($type, 'square_'))) {
                $badges[] = 'Square';
            }
            if (collect($sourceTypes)->intersect(['yotpo_contact', 'square_marketing_contact'])->isNotEmpty()) {
                $badges[] = 'Legacy Import';
            }
            $profileSquareOrderIds = $profileLinks->where('source_type', 'square_order')->pluck('source_id')->values();
            $hasAttributedSquareOrder = $profileSquareOrderIds->intersect($attributedSquareOrderIds)->isNotEmpty();
            if (in_array('event', $channels, true) || $hasAttributedSquareOrder) {
                $badges[] = 'Event Buyer';
            }
            if (in_array('online', $channels, true) || in_array('Shopify', $badges, true) || in_array('Square', $badges, true)) {
                $badges[] = 'Online Buyer';
            }

            $stats[(int) $profile->id] = [
                'order_count' => $ids->count(),
                'last_order_at' => optional($lastOrder?->ordered_at)->toDateString(),
                'last_activity_at' => $latestTimestamp ? date('Y-m-d', (int) $latestTimestamp) : null,
                'source_badges' => $badges,
            ];
        }

        return $stats;
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }
}
