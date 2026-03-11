<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingGroup;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\MarketingNextBestActionService;
use App\Services\Marketing\MarketingProfileScoreService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Services\Marketing\ShopifyBirthdayMetafieldService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Marketing\MarketingSectionRegistry;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketingCustomersController extends Controller
{
    public function __construct(
        protected MarketingEventAttributionService $attributionService,
        protected MarketingProfileScoreService $scoreService,
        protected MarketingSegmentEvaluator $segmentEvaluator,
        protected MarketingConsentService $consentService,
        protected CandleCashService $candleCashService,
        protected CandleCashRedemptionReconciliationService $redemptionReconciliationService,
        protected MarketingNextBestActionService $nextBestActionService,
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected BirthdayProfileService $birthdayProfileService,
        protected BirthdayRewardEngineService $birthdayRewardEngine,
        protected BirthdayReportingService $birthdayReportingService,
        protected ShopifyBirthdayMetafieldService $shopifyBirthdayMetafieldService
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'updated_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $birthdayFilter = trim((string) $request->query('birthday_filter', 'all'));
        if (! in_array($birthdayFilter, ['all', 'today', 'week', 'month', 'missing'], true)) {
            $birthdayFilter = 'all';
        }

        if (!in_array($sort, ['updated_at', 'created_at', 'email', 'first_name', 'last_name'], true)) {
            $sort = 'updated_at';
        }

        $today = now();
        $weekTuples = $this->birthdayWeekTuples($today);

        $profiles = MarketingProfile::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            })
            ->when($birthdayFilter === 'today', function ($query) use ($today): void {
                $query->whereHas('birthdayProfile', function ($birthdayQuery) use ($today): void {
                    $birthdayQuery
                        ->where('birth_month', (int) $today->month)
                        ->where('birth_day', (int) $today->day);
                });
            })
            ->when($birthdayFilter === 'week', function ($query) use ($weekTuples): void {
                $query->whereHas('birthdayProfile', function ($birthdayQuery) use ($weekTuples): void {
                    $birthdayQuery->where(function ($tupleQuery) use ($weekTuples): void {
                        foreach ($weekTuples as [$month, $day]) {
                            $tupleQuery->orWhere(function ($dayQuery) use ($month, $day): void {
                                $dayQuery->where('birth_month', $month)->where('birth_day', $day);
                            });
                        }
                    });
                });
            })
            ->when($birthdayFilter === 'month', function ($query) use ($today): void {
                $query->whereHas('birthdayProfile', function ($birthdayQuery) use ($today): void {
                    $birthdayQuery->where('birth_month', (int) $today->month);
                });
            })
            ->when($birthdayFilter === 'missing', function ($query): void {
                $query->where(function ($missingQuery): void {
                    $missingQuery->whereDoesntHave('birthdayProfile')
                        ->orWhereHas('birthdayProfile', function ($birthdayQuery): void {
                            $birthdayQuery
                                ->whereNull('birth_month')
                                ->orWhereNull('birth_day');
                        });
                });
            })
            ->with(['birthdayProfile:id,marketing_profile_id,birth_month,birth_day,birth_year,birthday_full_date,source,reward_last_issued_at,reward_last_issued_year'])
            ->withCount('links')
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        $derivedStats = $this->buildDerivedStats($profiles->getCollection());
        $birthdayReporting = $this->birthdayReportingService->summary($today);
        $emptyStateDiagnostics = $this->buildEmptyStateDiagnostics((int) $profiles->total());

        return view('marketing.customers.index', [
            'section' => MarketingSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'profiles' => $profiles,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'perPage' => $perPage,
            'birthdayFilter' => $birthdayFilter,
            'derivedStats' => $derivedStats,
            'birthdayReporting' => $birthdayReporting,
            'emptyStateDiagnostics' => $emptyStateDiagnostics,
        ]);
    }

    public function show(MarketingProfile $marketingProfile): View
    {
        $marketingProfile->load([
            'links' => fn ($query) => $query->orderByDesc('id'),
            'groups:id,name,is_internal',
        ]);

        /** @var CustomerBirthdayProfile|null $birthdayProfile */
        $birthdayProfile = $marketingProfile->birthdayProfile()
            ->with([
                'audits' => fn ($query) => $query->orderByDesc('id')->limit(25),
                'rewardIssuances' => fn ($query) => $query->orderByDesc('id')->limit(25),
            ])
            ->first();

        $birthdayRewardStatus = $this->birthdayRewardEngine->statusForProfile($birthdayProfile);

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

        $emailDeliveries = $marketingProfile->emailDeliveries()
            ->with(['recipient.campaign:id,name'])
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $candleBalance = $this->candleCashService->currentBalance($marketingProfile);
        $candleTransactions = $marketingProfile->candleCashTransactions()
            ->orderByDesc('id')
            ->limit(25)
            ->get();
        $candleRedemptions = $marketingProfile->candleCashRedemptions()
            ->with('reward:id,name,reward_type,reward_value')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
        $nextBestAction = $this->nextBestActionService->forProfile($marketingProfile);
        $activeRewards = CandleCashReward::query()
            ->where('is_active', true)
            ->orderBy('points_cost')
            ->get(['id', 'name', 'points_cost', 'reward_type', 'reward_value']);
        $allGroups = MarketingGroup::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_internal']);
        $storefrontTouchpoints = $marketingProfile->links
            ->whereIn('source_type', [
                'shopify_widget_contact',
                'shopify_widget_reward_balance',
                'shopify_widget_reward_history',
                'shopify_widget_redeem_request',
                'shopify_widget_customer_status',
                'shopify_widget_optin',
                'shopify_widget_birthday_status',
                'shopify_widget_birthday_capture',
                'shopify_widget_birthday_claim',
                'event_public_optin',
                'event_reward_lookup',
                'reward_lookup',
                'storefront_optin',
                'storefront_verify',
            ])
            ->values();
        $storefrontEvents = $marketingProfile->storefrontEvents()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();
        $openStorefrontIssues = (int) $storefrontEvents
            ->where('resolution_status', 'open')
            ->whereIn('status', ['error', 'verification_required', 'pending'])
            ->count();
        $redemptionSummary = [
            'issued' => (int) $candleRedemptions->where('status', 'issued')->count(),
            'redeemed' => (int) $candleRedemptions->where('status', 'redeemed')->count(),
            'canceled' => (int) $candleRedemptions->where('status', 'canceled')->count(),
            'expired' => (int) $candleRedemptions->where('status', 'expired')->count(),
        ];

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
            'emailDeliveries' => $emailDeliveries,
            'conversions' => $conversions,
            'consentEvents' => $consentEvents,
            'candleBalance' => $candleBalance,
            'candleTransactions' => $candleTransactions,
            'candleRedemptions' => $candleRedemptions,
            'redemptionSummary' => $redemptionSummary,
            'storefrontTouchpoints' => $storefrontTouchpoints,
            'storefrontEvents' => $storefrontEvents,
            'openStorefrontIssues' => $openStorefrontIssues,
            'nextBestAction' => $nextBestAction,
            'activeRewards' => $activeRewards,
            'allGroups' => $allGroups,
            'birthdayProfile' => $birthdayProfile,
            'birthdayRewardStatus' => $birthdayRewardStatus,
        ]);
    }

    public function update(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        $marketingProfile->forceFill([
            'first_name' => trim((string) ($data['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
            'email' => $email !== '' ? $email : null,
            'normalized_email' => $email !== '' ? $this->identityNormalizer->normalizeEmail($email) : null,
            'phone' => $phone !== '' ? $phone : null,
            'normalized_phone' => $phone !== '' ? $this->identityNormalizer->normalizePhone($phone) : null,
            'address_line_1' => trim((string) ($data['address_line_1'] ?? '')) ?: null,
            'address_line_2' => trim((string) ($data['address_line_2'] ?? '')) ?: null,
            'city' => trim((string) ($data['city'] ?? '')) ?: null,
            'state' => trim((string) ($data['state'] ?? '')) ?: null,
            'postal_code' => trim((string) ($data['postal_code'] ?? '')) ?: null,
            'country' => trim((string) ($data['country'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', ['style' => 'success', 'message' => 'Customer profile updated.']);
    }

    public function updateBirthday(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_year' => ['nullable', 'integer', 'between:1900,2100'],
            'birthday_full_date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:120'],
            'clear' => ['nullable', 'boolean'],
            'sync_shopify' => ['nullable', 'boolean'],
            'issue_reward_now' => ['nullable', 'boolean'],
        ]);

        try {
            $birthdayProfile = $this->birthdayProfileService->captureForProfile(
                profile: $marketingProfile,
                payload: [
                    'birth_month' => $data['birth_month'] ?? null,
                    'birth_day' => $data['birth_day'] ?? null,
                    'birth_year' => $data['birth_year'] ?? null,
                    'birthday_full_date' => $data['birthday_full_date'] ?? null,
                    'source' => (string) ($data['source'] ?? 'admin_backstage'),
                    'clear' => (bool) ($data['clear'] ?? false),
                ],
                options: [
                    'source' => (string) ($data['source'] ?? 'admin_backstage'),
                    'replace_source' => true,
                ]
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('marketing.customers.show', $marketingProfile)
                ->with('toast', ['style' => 'warning', 'message' => 'Birthday update failed: '.$e->getMessage()]);
        }

        if (! (bool) ($data['clear'] ?? false) && (bool) ($data['issue_reward_now'] ?? false)) {
            $this->birthdayRewardEngine->issueAnnualReward($birthdayProfile);
        }

        $syncErrors = [];
        if ((bool) ($data['sync_shopify'] ?? true)) {
            $syncResult = $this->shopifyBirthdayMetafieldService->writeBirthdayForProfile($marketingProfile, $birthdayProfile);
            $syncErrors = (array) ($syncResult['errors'] ?? []);
        }

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => $syncErrors === [] ? 'success' : 'warning',
                'message' => $syncErrors === []
                    ? 'Birthday profile updated.'
                    : 'Birthday saved locally, but Shopify sync failed: '.implode(' | ', $syncErrors),
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

    public function grantCandleCash(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:earn,adjust'],
            'points' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $points = (int) $data['points'];
        $type = (string) $data['type'];
        if ($type === 'earn' && $points < 0) {
            return redirect()
                ->route('marketing.customers.show', $marketingProfile)
                ->with('toast', ['style' => 'warning', 'message' => 'Earn entries must use positive points.']);
        }

        $result = $this->candleCashService->addPoints(
            profile: $marketingProfile,
            points: $points,
            type: $type,
            source: 'admin',
            sourceId: (string) (auth()->id() ?? ''),
            description: trim((string) ($data['description'] ?? '')) ?: null
        );

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => 'success',
                'message' => 'Candle Cash updated. New balance: ' . (int) ($result['balance'] ?? 0),
            ]);
    }

    public function redeemCandleCash(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:candle_cash_rewards,id'],
            'platform' => ['required', 'in:shopify,square'],
        ]);

        $reward = CandleCashReward::query()->findOrFail((int) $data['reward_id']);
        $result = $this->candleCashService->redeemReward(
            profile: $marketingProfile,
            reward: $reward,
            platform: (string) $data['platform']
        );

        if (! (bool) ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? 'redemption_failed');
            $message = match ($error) {
                'insufficient_balance' => 'Not enough Candle Cash balance for that reward.',
                'inactive_reward' => 'Reward is inactive.',
                default => 'Reward redemption failed.',
            };

            return redirect()
                ->route('marketing.customers.show', $marketingProfile)
                ->with('toast', ['style' => 'warning', 'message' => $message]);
        }

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => 'success',
                'message' => 'Reward redeemed. Code: ' . (string) ($result['code'] ?? 'n/a'),
            ]);
    }

    public function markCandleCashRedemptionRedeemed(
        MarketingProfile $marketingProfile,
        CandleCashRedemption $redemption,
        Request $request
    ): RedirectResponse {
        abort_unless((int) $redemption->marketing_profile_id === (int) $marketingProfile->id, 404);

        $data = $request->validate([
            'platform' => ['nullable', 'in:shopify,square,manual'],
            'external_order_source' => ['nullable', 'string', 'max:80'],
            'external_order_id' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1200'],
        ]);

        $this->redemptionReconciliationService->markRedeemedManually($redemption, [
            'platform' => $data['platform'] ?? null,
            'external_order_source' => $data['external_order_source'] ?? null,
            'external_order_id' => $data['external_order_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'redeemed_by' => auth()->id(),
        ]);

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => 'success',
                'message' => 'Redemption marked as redeemed.',
            ]);
    }

    public function cancelCandleCashRedemption(
        MarketingProfile $marketingProfile,
        CandleCashRedemption $redemption,
        Request $request
    ): RedirectResponse {
        abort_unless((int) $redemption->marketing_profile_id === (int) $marketingProfile->id, 404);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1200'],
        ]);

        $this->redemptionReconciliationService->cancelRedemption($redemption, [
            'notes' => $data['notes'] ?? null,
            'actor_id' => auth()->id(),
        ]);

        return redirect()
            ->route('marketing.customers.show', $marketingProfile)
            ->with('toast', [
                'style' => 'success',
                'message' => 'Redemption canceled.',
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

        $ordersById = $orderIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('id', $orderIds->all())
                ->get(['id', 'ordered_at', 'shopify_customer_id', 'shopify_store_key', 'shopify_store'])
                ->keyBy('id');

        $ordersByShopifyCustomer = collect();
        if (Schema::hasColumn('orders', 'shopify_customer_id')) {
            $shopifyCustomerSourceIds = $links
                ->where('source_type', 'shopify_customer')
                ->pluck('source_id')
                ->filter()
                ->unique()
                ->values();

            $shopifyCustomerIds = $shopifyCustomerSourceIds
                ->map(function ($value): ?string {
                    [$storeKey, $customerId] = $this->parseShopifyCustomerSourceId((string) $value);

                    return $customerId;
                })
                ->filter()
                ->unique()
                ->values();

            $ordersByShopifyCustomer = $shopifyCustomerIds->isEmpty()
                ? collect()
                : Order::query()
                    ->whereIn('shopify_customer_id', $shopifyCustomerIds->all())
                    ->get(['id', 'ordered_at', 'shopify_customer_id', 'shopify_store_key', 'shopify_store'])
                    ->groupBy(function (Order $order): string {
                        return $this->shopifyCustomerOrderKey(
                            (string) ($order->shopify_store_key ?: $order->shopify_store ?: ''),
                            (string) ($order->shopify_customer_id ?? '')
                        );
                    });
        }

        $squareOrderIds = $links
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        $squareOrdersById = $squareOrderIds->isEmpty()
            ? collect()
            : SquareOrder::query()
                ->whereIn('square_order_id', $squareOrderIds->all())
                ->get(['square_order_id', 'closed_at'])
                ->keyBy('square_order_id');

        $attributedSquareOrderIds = $squareOrderIds->isEmpty()
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

        $squarePaymentsById = $squarePaymentIds->isEmpty()
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

            $linkedOrders = $ids
                ->map(fn (int $id) => $ordersById->get($id))
                ->filter()
                ->values();

            $customerOrders = $profileLinks
                ->where('source_type', 'shopify_customer')
                ->flatMap(function (MarketingProfileLink $link) use ($ordersByShopifyCustomer): Collection {
                    [$storeKey, $customerId] = $this->parseShopifyCustomerSourceId((string) $link->source_id);
                    if (! $customerId) {
                        return collect();
                    }

                    $exactKey = $this->shopifyCustomerOrderKey($storeKey, $customerId);
                    $fallbackKey = $this->shopifyCustomerOrderKey('', $customerId);

                    if ($ordersByShopifyCustomer->has($exactKey)) {
                        return $ordersByShopifyCustomer->get($exactKey);
                    }

                    return $ordersByShopifyCustomer->get($fallbackKey, collect());
                })
                ->filter()
                ->values();

            $allOrders = $linkedOrders
                ->concat($customerOrders)
                ->unique('id')
                ->values();

            $lastOrder = $allOrders
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
                'order_count' => $allOrders->count(),
                'last_order_at' => optional($lastOrder?->ordered_at)->toDateString(),
                'last_activity_at' => $latestTimestamp ? date('Y-m-d', (int) $latestTimestamp) : null,
                'source_badges' => $badges,
            ];
        }

        return $stats;
    }

    /**
     * @return array{0:string,1:?string}
     */
    protected function parseShopifyCustomerSourceId(string $value): array
    {
        $sourceId = trim($value);
        if ($sourceId === '') {
            return ['', null];
        }

        if (! str_contains($sourceId, ':')) {
            return ['', $sourceId];
        }

        [$storeKey, $customerId] = explode(':', $sourceId, 2);

        return [trim((string) $storeKey), trim((string) $customerId) ?: null];
    }

    protected function shopifyCustomerOrderKey(string $storeKey, string $customerId): string
    {
        return strtolower(trim($storeKey)) . ':' . trim($customerId);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function buildEmptyStateDiagnostics(int $profileTotal): ?array
    {
        if ($profileTotal > 0) {
            return null;
        }

        $shopifyOrderCandidates = Schema::hasTable('orders')
            ? (int) Order::query()
                ->where(function ($query): void {
                    $query->whereNotNull('shopify_order_id')
                        ->orWhere('source', 'like', 'shopify%');
                })
                ->count()
            : 0;

        $shopifyCustomerCandidates = Schema::hasTable('customer_external_profiles')
            ? (int) CustomerExternalProfile::query()
                ->where('integration', 'shopify_customer')
                ->count()
            : 0;

        $growaveCandidates = Schema::hasTable('customer_external_profiles')
            ? (int) CustomerExternalProfile::query()
                ->where('integration', 'growave')
                ->count()
            : 0;

        $upstreamCandidates = $shopifyOrderCandidates + $shopifyCustomerCandidates + $growaveCandidates;
        if ($upstreamCandidates === 0) {
            return null;
        }

        $lastSyncRun = Schema::hasTable('marketing_import_runs')
            ? MarketingImportRun::query()
                ->whereIn('type', ['marketing_profiles_sync', 'shopify_customer_metafields_sync'])
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first()
            : null;

        return [
            'shopify_order_candidates' => $shopifyOrderCandidates,
            'shopify_customer_candidates' => $shopifyCustomerCandidates,
            'growave_candidates' => $growaveCandidates,
            'upstream_candidates' => $upstreamCandidates,
            'last_sync_at' => optional($lastSyncRun?->finished_at ?? $lastSyncRun?->started_at)?->toDateTimeString(),
            'last_sync_status' => $lastSyncRun?->status,
            'last_sync_type' => $lastSyncRun?->type,
        ];
    }

    /**
     * @return array<int,array{0:int,1:int}>
     */
    protected function birthdayWeekTuples(CarbonInterface $anchor): array
    {
        $start = $anchor->copy()->startOfWeek();
        $end = $anchor->copy()->endOfWeek();

        $tuples = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor = $cursor->copy()->addDay()) {
            $tuples[] = [(int) $cursor->month, (int) $cursor->day];
        }

        return $tuples;
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
