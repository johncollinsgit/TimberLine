<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingGroup;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\GrowaveProjectionService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\MarketingNextBestActionService;
use App\Services\Marketing\MarketingProfileMatcher;
use App\Services\Marketing\MarketingProfileScoreService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Services\Marketing\ShopifyBirthdayMetafieldService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Marketing\MarketingSectionRegistry;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        protected MarketingProfileMatcher $profileMatcher,
        protected BirthdayProfileService $birthdayProfileService,
        protected BirthdayRewardEngineService $birthdayRewardEngine,
        protected BirthdayReportingService $birthdayReportingService,
        protected ShopifyBirthdayMetafieldService $shopifyBirthdayMetafieldService,
        protected GrowaveProjectionService $growaveProjectionService
    ) {
    }

    public function index(Request $request): View
    {
        $today = now();
        $tenantId = $this->currentTenantId($request);
        $filters = $this->normalizeIndexFilters($request);
        $profiles = $this->customerIndexQuery($filters, $tenantId)
            ->with(['birthdayProfile:id,marketing_profile_id,birth_month,birth_day,birth_year,birthday_full_date,source,reward_last_issued_at,reward_last_issued_year'])
            ->withCount('links')
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $derivedStats = $this->buildDerivedStats($profiles->getCollection());
        $loyaltyStats = $this->buildLoyaltyEnrichment($profiles->getCollection());
        $birthdayReporting = $this->birthdayReportingService->summary($today);
        $emptyStateDiagnostics = $this->buildEmptyStateDiagnostics((int) $profiles->total());
        $quickStats = $this->buildIndexQuickStats((int) $profiles->total());

        return view('marketing.customers.index', [
            'section' => MarketingSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'profiles' => $profiles,
            'search' => (string) $filters['search'],
            'sort' => (string) $filters['sort'],
            'dir' => (string) $filters['dir'],
            'perPage' => (int) $filters['per_page'],
            'birthdayFilter' => (string) $filters['birthday_filter'],
            'sourceFilter' => (string) $filters['source'],
            'hasPointsFilter' => (string) $filters['has_points'],
            'hasPhoneFilter' => (string) $filters['has_phone'],
            'derivedStats' => $derivedStats,
            'loyaltyStats' => $loyaltyStats,
            'birthdayReporting' => $birthdayReporting,
            'emptyStateDiagnostics' => $emptyStateDiagnostics,
            'quickStats' => $quickStats,
            'customerGrid' => [
                'endpoint' => route('marketing.customers.data'),
                'detail_base_url' => url('/marketing/customers'),
                'filters' => $filters,
                'sort_options' => $this->customerIndexSortOptions(),
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);
        $filters = $this->normalizeIndexFilters($request);
        $profiles = $this->customerIndexQuery($filters, $tenantId)
            ->with(['birthdayProfile:id,marketing_profile_id,birth_month,birth_day,birth_year,birthday_full_date,source,reward_last_issued_at,reward_last_issued_year'])
            ->withCount('links')
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $derivedStats = $this->buildDerivedStats($profiles->getCollection());
        $loyaltyStats = $this->buildLoyaltyEnrichment($profiles->getCollection());

        $rows = $profiles->getCollection()
            ->map(function (MarketingProfile $profile) use ($derivedStats, $loyaltyStats): array {
                return $this->serializeCustomerGridRow(
                    $profile,
                    $derivedStats[(int) $profile->id] ?? null,
                    $loyaltyStats[(int) $profile->id] ?? null
                );
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'rows' => $rows,
            'meta' => [
                'columns' => $this->customerGridColumns(),
                'pagination' => [
                    'page' => $profiles->currentPage(),
                    'per_page' => $profiles->perPage(),
                    'total' => $profiles->total(),
                    'last_page' => $profiles->lastPage(),
                ],
                'filters' => $filters,
                'sort_options' => $this->customerIndexSortOptions(),
            ],
        ]);
    }

    /**
     * @return array{
     *   search:string,
     *   sort:string,
     *   dir:string,
     *   per_page:int,
     *   birthday_filter:string,
     *   source:string,
     *   has_points:string,
     *   has_phone:string
     * }
     */
    protected function normalizeIndexFilters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'updated_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $birthdayFilter = trim((string) $request->query('birthday_filter', 'all'));
        $sourceFilter = trim((string) $request->query('source', 'all'));
        $hasPointsFilter = trim((string) $request->query('has_points', 'all'));
        $hasPhoneFilter = trim((string) $request->query('has_phone', 'all'));

        if (! in_array($birthdayFilter, ['all', 'today', 'week', 'month', 'missing'], true)) {
            $birthdayFilter = 'all';
        }
        if (! in_array($sourceFilter, ['all', 'shopify', 'growave', 'square', 'wholesale', 'event', 'manual'], true)) {
            $sourceFilter = 'all';
        }
        if (! in_array($hasPointsFilter, ['all', 'yes', 'no'], true)) {
            $hasPointsFilter = 'all';
        }
        if (! in_array($hasPhoneFilter, ['all', 'yes', 'no'], true)) {
            $hasPhoneFilter = 'all';
        }

        if (! in_array($sort, ['updated_at', 'created_at', 'email', 'first_name', 'last_name', 'candle_cash_balance'], true)) {
            $sort = 'updated_at';
        }

        return [
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'per_page' => $perPage,
            'birthday_filter' => $birthdayFilter,
            'source' => $sourceFilter,
            'has_points' => $hasPointsFilter,
            'has_phone' => $hasPhoneFilter,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function customerIndexQuery(array $filters, ?int $tenantId = null): Builder
    {
        $search = (string) ($filters['search'] ?? '');
        $sort = (string) ($filters['sort'] ?? 'updated_at');
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $birthdayFilter = (string) ($filters['birthday_filter'] ?? 'all');
        $sourceFilter = (string) ($filters['source'] ?? 'all');
        $hasPointsFilter = (string) ($filters['has_points'] ?? 'all');
        $hasPhoneFilter = (string) ($filters['has_phone'] ?? 'all');

        $today = now();
        $weekTuples = $this->birthdayWeekTuples($today);
        $supportsCandleCashBalances = Schema::hasTable('candle_cash_balances');
        $searchLike = '%' . $search . '%';

        $query = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->when($search !== '', function ($builder) use ($searchLike): void {
                $builder->where(function ($nested) use ($searchLike): void {
                    $nested->where('first_name', 'like', $searchLike)
                        ->orWhere('last_name', 'like', $searchLike)
                        ->orWhere('email', 'like', $searchLike)
                        ->orWhere('phone', 'like', $searchLike)
                        ->orWhere('notes', 'like', $searchLike)
                        ->orWhereHas('links', function ($linkQuery) use ($searchLike): void {
                            $linkQuery->where('source_id', 'like', $searchLike);
                        })
                        ->orWhereHas('externalProfiles', function ($externalQuery) use ($searchLike): void {
                            $externalQuery
                                ->where('external_customer_id', 'like', $searchLike)
                                ->orWhere('full_name', 'like', $searchLike)
                                ->orWhere('email', 'like', $searchLike)
                                ->orWhere('phone', 'like', $searchLike)
                                ->orWhere('store_key', 'like', $searchLike);
                        });
                });
            })
            ->when($sourceFilter !== 'all', function ($builder) use ($sourceFilter): void {
                $this->applySourceFilter($builder, $sourceFilter);
            })
            ->when($hasPhoneFilter === 'yes', function ($builder): void {
                $builder->whereNotNull('normalized_phone');
            })
            ->when($hasPhoneFilter === 'no', function ($builder): void {
                $builder->whereNull('normalized_phone');
            })
            ->when($hasPointsFilter === 'yes', function ($builder) use ($supportsCandleCashBalances): void {
                $builder->where(function ($pointsQuery) use ($supportsCandleCashBalances): void {
                    $pointsQuery->whereHas('externalProfiles', function ($externalQuery): void {
                        $externalQuery
                            ->where('integration', 'growave')
                            ->where('points_balance', '>', 0);
                    });

                    if ($supportsCandleCashBalances) {
                        $pointsQuery->orWhereHas('candleCashBalance', function ($balanceQuery): void {
                            $balanceQuery->where('balance', '>', 0);
                        });
                    }
                });
            })
            ->when($hasPointsFilter === 'no', function ($builder) use ($supportsCandleCashBalances): void {
                $builder->whereDoesntHave('externalProfiles', function ($externalQuery): void {
                    $externalQuery
                        ->where('integration', 'growave')
                        ->where('points_balance', '>', 0);
                });

                if ($supportsCandleCashBalances) {
                    $builder->whereDoesntHave('candleCashBalance', function ($balanceQuery): void {
                        $balanceQuery->where('balance', '>', 0);
                    });
                }
            })
            ->when($birthdayFilter === 'today', function ($builder) use ($today): void {
                $builder->whereHas('birthdayProfile', function ($birthdayQuery) use ($today): void {
                    $birthdayQuery
                        ->where('birth_month', (int) $today->month)
                        ->where('birth_day', (int) $today->day);
                });
            })
            ->when($birthdayFilter === 'week', function ($builder) use ($weekTuples): void {
                $builder->whereHas('birthdayProfile', function ($birthdayQuery) use ($weekTuples): void {
                    $birthdayQuery->where(function ($tupleQuery) use ($weekTuples): void {
                        foreach ($weekTuples as [$month, $day]) {
                            $tupleQuery->orWhere(function ($dayQuery) use ($month, $day): void {
                                $dayQuery->where('birth_month', $month)->where('birth_day', $day);
                            });
                        }
                    });
                });
            })
            ->when($birthdayFilter === 'month', function ($builder) use ($today): void {
                $builder->whereHas('birthdayProfile', function ($birthdayQuery) use ($today): void {
                    $birthdayQuery->where('birth_month', (int) $today->month);
                });
            })
            ->when($birthdayFilter === 'missing', function ($builder): void {
                $builder->where(function ($missingQuery): void {
                    $missingQuery->whereDoesntHave('birthdayProfile')
                        ->orWhereHas('birthdayProfile', function ($birthdayQuery): void {
                            $birthdayQuery
                                ->whereNull('birth_month')
                                ->orWhereNull('birth_day');
                        });
                });
            });

        if ($sort === 'candle_cash_balance' && $supportsCandleCashBalances) {
            $query->orderBy(
                CandleCashBalance::query()
                    ->select('balance')
                    ->whereColumn('marketing_profile_id', 'marketing_profiles.id')
                    ->limit(1),
                $dir
            )->orderBy('updated_at', 'desc');

            return $query;
        }

        return $query->orderBy($sort, $dir);
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    protected function customerIndexSortOptions(): array
    {
        return [
            ['value' => 'updated_at', 'label' => 'Updated'],
            ['value' => 'created_at', 'label' => 'Created'],
            ['value' => 'candle_cash_balance', 'label' => 'Candle Cash balance'],
            ['value' => 'email', 'label' => 'Email'],
            ['value' => 'first_name', 'label' => 'First name'],
            ['value' => 'last_name', 'label' => 'Last name'],
        ];
    }

    /**
     * @return array<int,array{key:string,label:string,type:string}>
     */
    protected function customerGridColumns(): array
    {
        return [
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'candle_cash_points', 'label' => 'Candle Cash', 'type' => 'number'],
            ['key' => 'candle_cash_amount', 'label' => 'Value ($)', 'type' => 'number'],
            ['key' => 'legacy_growave_points', 'label' => 'Legacy Growave', 'type' => 'number'],
            ['key' => 'tier', 'label' => 'Tier', 'type' => 'text'],
            ['key' => 'referrals', 'label' => 'Referrals', 'type' => 'number'],
            ['key' => 'review_count', 'label' => 'Reviews', 'type' => 'number'],
            ['key' => 'average_rating', 'label' => 'Avg Rating', 'type' => 'number'],
            ['key' => 'order_count', 'label' => 'Orders', 'type' => 'number'],
            ['key' => 'last_order_at', 'label' => 'Last Order', 'type' => 'text'],
            ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'text'],
            ['key' => 'sources', 'label' => 'Sources', 'type' => 'text'],
            ['key' => 'updated_at', 'label' => 'Updated', 'type' => 'text'],
        ];
    }

    /**
     * @param array<string,mixed>|null $stats
     * @param array<string,mixed>|null $loyalty
     * @return array<string,mixed>
     */
    protected function serializeCustomerGridRow(MarketingProfile $profile, ?array $stats, ?array $loyalty): array
    {
        $stats = $stats ?? ['order_count' => 0, 'last_order_at' => null, 'source_badges' => []];
        $loyalty = $loyalty ?? [
            'candle_cash_points' => 0,
            'candle_cash_amount' => 0.0,
            'legacy_growave_points' => 0,
            'tier' => null,
            'referrals' => 0,
            'review_count' => 0,
            'average_rating' => null,
        ];

        $displayName = trim((string) ($profile->first_name . ' ' . $profile->last_name));
        if ($displayName === '') {
            $displayName = $profile->email ?: ($profile->phone ?: 'Profile #' . $profile->id);
        }

        $birthday = 'Missing';
        if ($profile->birthdayProfile) {
            if ($profile->birthdayProfile->birthday_full_date) {
                $birthday = (string) $profile->birthdayProfile->birthday_full_date;
            } elseif ($profile->birthdayProfile->birth_month && $profile->birthdayProfile->birth_day) {
                $birthday = sprintf('%02d/%02d', (int) $profile->birthdayProfile->birth_month, (int) $profile->birthdayProfile->birth_day);
            }
        }

        $sources = collect(array_merge(
            (array) ($stats['source_badges'] ?? []),
            array_map(
                static fn ($channel): string => ucwords(str_replace('_', ' ', (string) $channel)),
                (array) ($profile->source_channels ?? [])
            )
        ))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');

        return [
            'id' => (int) $profile->id,
            'customer' => $displayName,
            'email' => $profile->email ?: '—',
            'phone' => $profile->phone ?: '—',
            'candle_cash_points' => (int) ($loyalty['candle_cash_points'] ?? 0),
            'candle_cash_amount' => (float) ($loyalty['candle_cash_amount'] ?? 0),
            'legacy_growave_points' => (int) ($loyalty['legacy_growave_points'] ?? 0),
            'tier' => (string) ($loyalty['tier'] ?? '—'),
            'referrals' => (int) ($loyalty['referrals'] ?? 0),
            'review_count' => (int) ($loyalty['review_count'] ?? 0),
            'average_rating' => $loyalty['average_rating'] !== null ? (float) $loyalty['average_rating'] : null,
            'order_count' => (int) ($stats['order_count'] ?? 0),
            'last_order_at' => (string) ($stats['last_order_at'] ?? '—'),
            'birthday' => $birthday,
            'sources' => $sources !== '' ? $sources : '—',
            'updated_at' => optional($profile->updated_at)->format('Y-m-d') ?: '—',
            'profile_url' => route('marketing.customers.show', $profile),
        ];
    }

    public function create(Request $request): View
    {
        if ($request->boolean('reset')) {
            $request->session()->forget('marketing.customers.create_wizard');
        }

        $step = $this->normalizeCreateWizardStep((int) $request->query('step', 1));
        $wizardState = $this->customerCreateWizardState($request);
        $duplicateCandidates = $this->buildDuplicateCandidates(
            $wizardState,
            $this->currentTenantId($request)
        );

        return view('marketing.customers.create', [
            'section' => MarketingSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'step' => $step,
            'wizardState' => $wizardState,
            'duplicateCandidates' => $duplicateCandidates,
        ]);
    }

    public function storeCreate(Request $request): RedirectResponse
    {
        $step = $this->normalizeCreateWizardStep((int) $request->input('step', 1));
        $direction = (string) $request->input('direction', 'next');
        $wizardState = $this->customerCreateWizardState($request);

        if ($direction === 'back') {
            $target = max(1, $step - 1);

            return redirect()->route('marketing.customers.create', ['step' => $target])->withInput();
        }

        if ($step === 1) {
            $data = $request->validate([
                'first_name' => ['nullable', 'string', 'max:120'],
                'last_name' => ['nullable', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
            ]);

            $email = trim((string) ($data['email'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));

            if ($email === '' && $phone === '') {
                return redirect()
                    ->route('marketing.customers.create', ['step' => 1])
                    ->withInput()
                    ->withErrors(['email' => 'Provide at least an email or phone so duplicate checks can run safely.']);
            }

            $wizardState['identity'] = [
                'first_name' => trim((string) ($data['first_name'] ?? '')) ?: null,
                'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'normalized_email' => $email !== '' ? $this->identityNormalizer->normalizeEmail($email) : null,
                'normalized_phone' => $phone !== '' ? $this->identityNormalizer->normalizePhone($phone) : null,
            ];
            $wizardState['duplicate'] = [
                'decision' => 'continue',
                'selected_profile_id' => null,
            ];
            $this->persistCustomerCreateWizardState($request, $wizardState);

            return redirect()->route('marketing.customers.create', ['step' => 2]);
        }

        if ($step === 2) {
            $data = $request->validate([
                'customer_context' => ['required', 'in:retail,wholesale,event_manual,general'],
            ]);
            $wizardState['context'] = [
                'customer_context' => (string) $data['customer_context'],
            ];
            $this->persistCustomerCreateWizardState($request, $wizardState);

            return redirect()->route('marketing.customers.create', ['step' => 3]);
        }

        if ($step === 3) {
            $duplicateCandidates = $this->buildDuplicateCandidates(
                $wizardState,
                $this->currentTenantId($request)
            );
            $candidateIds = $duplicateCandidates
                ->map(fn (array $candidate): int => (int) $candidate['profile']->id)
                ->all();

            $data = $request->validate([
                'decision' => ['required', 'in:use_existing,continue'],
                'selected_profile_id' => ['nullable', 'integer', 'exists:marketing_profiles,id'],
            ]);

            if ((string) $data['decision'] === 'use_existing') {
                $selectedProfileId = (int) ($data['selected_profile_id'] ?? 0);
                if ($selectedProfileId <= 0 || ! in_array($selectedProfileId, $candidateIds, true)) {
                    return redirect()
                        ->route('marketing.customers.create', ['step' => 3])
                        ->withInput()
                        ->withErrors(['selected_profile_id' => 'Select one of the duplicate candidates to continue with an existing customer.']);
                }
            }

            $wizardState['duplicate'] = [
                'decision' => (string) $data['decision'],
                'selected_profile_id' => (string) $data['decision'] === 'use_existing'
                    ? (int) ($data['selected_profile_id'] ?? 0)
                    : null,
            ];
            $this->persistCustomerCreateWizardState($request, $wizardState);

            return redirect()->route('marketing.customers.create', ['step' => 4]);
        }

        if ($step === 4) {
            $data = $request->validate([
                'notes' => ['nullable', 'string', 'max:2000'],
                'company_store_name' => ['nullable', 'string', 'max:160'],
                'tags' => ['nullable', 'string', 'max:255'],
                'accepts_email_marketing' => ['nullable', 'boolean'],
                'accepts_sms_marketing' => ['nullable', 'boolean'],
            ]);

            $wizardState['additional'] = [
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'company_store_name' => trim((string) ($data['company_store_name'] ?? '')) ?: null,
                'tags' => trim((string) ($data['tags'] ?? '')) ?: null,
                'accepts_email_marketing' => array_key_exists('accepts_email_marketing', $data)
                    ? (bool) $data['accepts_email_marketing']
                    : null,
                'accepts_sms_marketing' => array_key_exists('accepts_sms_marketing', $data)
                    ? (bool) $data['accepts_sms_marketing']
                    : null,
            ];
            $this->persistCustomerCreateWizardState($request, $wizardState);

            return redirect()->route('marketing.customers.create', ['step' => 5]);
        }

        $request->validate([
            'confirm_create' => ['required', 'accepted'],
        ]);

        $profile = $this->finalizeCustomerCreateWizard(
            wizardState: $wizardState,
            actorId: auth()->id(),
            tenantId: $this->currentTenantId($request)
        );
        $request->session()->forget('marketing.customers.create_wizard');

        return redirect()
            ->route('marketing.customers.show', $profile)
            ->with('toast', [
                'style' => 'success',
                'message' => 'Customer saved in the canonical profile layer.',
            ]);
    }

    public function show(MarketingProfile $marketingProfile, Request $request): View
    {
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $externalProfiles = $marketingProfile->externalProfiles()
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
        $latestGrowaveExternal = $this->growaveProjectionService->preferredExternal(
            $externalProfiles->filter(fn (CustomerExternalProfile $row): bool => (string) $row->integration === 'growave')
        );
        $latestGrowaveReviewSummary = $this->growaveProjectionService->preferredReviewSummary(
            $marketingProfile->reviewSummaries()
                ->where('provider', 'growave')
                ->where('integration', 'growave')
                ->get(),
            $latestGrowaveExternal
        );

        if (! $latestGrowaveReviewSummary && $latestGrowaveExternal) {
            $latestGrowaveReviewSummary = MarketingReviewSummary::query()
                ->where('provider', 'growave')
                ->where('integration', 'growave')
                ->where('store_key', $latestGrowaveExternal->store_key)
                ->where('external_customer_id', $latestGrowaveExternal->external_customer_id)
                ->orderByDesc('source_synced_at')
                ->orderByDesc('id')
                ->first();
        }

        $growaveReviewHistory = $marketingProfile->reviewHistory()
            ->where('provider', 'growave')
            ->where('integration', 'growave')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        if ($growaveReviewHistory->isEmpty() && $latestGrowaveReviewSummary) {
            $growaveReviewHistory = MarketingReviewHistory::query()
                ->where('marketing_review_summary_id', $latestGrowaveReviewSummary->id)
                ->orderByDesc('reviewed_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get();
        }

        $growaveLastSyncAt = collect([
            optional($latestGrowaveExternal?->synced_at)->toDateTimeString(),
            optional($latestGrowaveReviewSummary?->source_synced_at)->toDateTimeString(),
        ])->filter()->max();

        $growaveSourceMeta = [
            'provider' => $latestGrowaveExternal?->provider ?: 'growave',
            'integration' => $latestGrowaveExternal?->integration ?: 'growave',
            'store_key' => $latestGrowaveExternal?->store_key ?: $latestGrowaveReviewSummary?->store_key,
            'external_customer_id' => $latestGrowaveExternal?->external_customer_id ?: $latestGrowaveReviewSummary?->external_customer_id,
            'external_customer_email' => $latestGrowaveReviewSummary?->external_customer_email,
            'last_synced_at' => $growaveLastSyncAt,
        ];

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
        $growaveLoyaltyTransactions = $this->normalizeGrowaveLoyaltyTransactions(
            $marketingProfile->candleCashTransactions()
                ->where('source', 'growave_activity')
                ->orderByDesc('id')
                ->limit(120)
                ->get()
        );
        $candleRedemptions = $marketingProfile->candleCashRedemptions()
            ->with('reward:id,name,reward_type,reward_value')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
        $nextBestAction = $this->nextBestActionService->forProfile($marketingProfile);
        $activeRewards = CandleCashReward::query()
            ->where('is_active', true)
            ->orderBy('candle_cash_cost')
            ->get(['id', 'name', 'candle_cash_cost', 'reward_type', 'reward_value']);
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
            'externalProfiles' => $externalProfiles,
            'latestGrowaveExternal' => $latestGrowaveExternal,
            'latestGrowaveReviewSummary' => $latestGrowaveReviewSummary,
            'growaveReviewHistory' => $growaveReviewHistory,
            'growaveSourceMeta' => $growaveSourceMeta,
            'growaveLoyaltyTransactions' => $growaveLoyaltyTransactions,
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
        $data = $request->validate([
            'type' => ['required', 'in:earn,adjust'],
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-100000', 'max:100000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = (float) $data['amount'];
        $points = $this->candleCashService->pointsFromAmount(abs($amount));
        if ($amount < 0) {
            $points *= -1;
        }
        $type = (string) $data['type'];
        if ($type === 'earn' && $points < 0) {
            return redirect()
                ->route('marketing.customers.show', $marketingProfile)
                ->with('toast', ['style' => 'warning', 'message' => 'Earn entries must use positive Candle Cash.']);
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
                'message' => 'Candle Cash updated. New balance: ' . $this->candleCashService->formatCandleCash($this->candleCashService->amountFromPoints((int) ($result['balance'] ?? 0))),
            ]);
    }

    public function redeemCandleCash(MarketingProfile $marketingProfile, Request $request): RedirectResponse
    {
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
        $this->assertProfileInTenantScope($marketingProfile, $request);
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
     * @param array<string,mixed> $wizardState
     */
    protected function finalizeCustomerCreateWizard(array $wizardState, ?int $actorId = null, ?int $tenantId = null): MarketingProfile
    {
        $identity = is_array($wizardState['identity'] ?? null) ? $wizardState['identity'] : [];
        $context = is_array($wizardState['context'] ?? null) ? $wizardState['context'] : [];
        $duplicate = is_array($wizardState['duplicate'] ?? null) ? $wizardState['duplicate'] : [];
        $additional = is_array($wizardState['additional'] ?? null) ? $wizardState['additional'] : [];

        $contextChannel = match ((string) ($context['customer_context'] ?? 'general')) {
            'retail' => ['manual', 'retail'],
            'wholesale' => ['manual', 'wholesale'],
            'event_manual' => ['manual', 'event'],
            default => ['manual'],
        };

        $notesParts = [];
        if (($additional['notes'] ?? null) !== null) {
            $notesParts[] = (string) $additional['notes'];
        }
        if (($additional['company_store_name'] ?? null) !== null) {
            $notesParts[] = 'Company/Store: ' . (string) $additional['company_store_name'];
        }
        if (($additional['tags'] ?? null) !== null) {
            $notesParts[] = 'Tags: ' . (string) $additional['tags'];
        }
        $composedNotes = $notesParts !== [] ? implode(PHP_EOL, $notesParts) : null;

        return DB::transaction(function () use (
            $identity,
            $duplicate,
            $contextChannel,
            $composedNotes,
            $additional,
            $actorId,
            $tenantId
        ): MarketingProfile {
            $selectedProfileId = (int) ($duplicate['selected_profile_id'] ?? 0);
            if ((string) ($duplicate['decision'] ?? 'continue') === 'use_existing' && $selectedProfileId > 0) {
                /** @var MarketingProfile $profile */
                $profile = MarketingProfile::query()->lockForUpdate()->findOrFail($selectedProfileId);

                if ($tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
                    abort(404);
                }

                $updates = [];
                if (! $profile->first_name && ($identity['first_name'] ?? null)) {
                    $updates['first_name'] = (string) $identity['first_name'];
                }
                if (! $profile->last_name && ($identity['last_name'] ?? null)) {
                    $updates['last_name'] = (string) $identity['last_name'];
                }
                if (! $profile->email && ($identity['email'] ?? null)) {
                    $updates['email'] = (string) $identity['email'];
                    $updates['normalized_email'] = $this->identityNormalizer->normalizeEmail((string) $identity['email']);
                }
                if (! $profile->phone && ($identity['phone'] ?? null)) {
                    $updates['phone'] = (string) $identity['phone'];
                    $updates['normalized_phone'] = $this->identityNormalizer->normalizePhone((string) $identity['phone']);
                }
                if (($additional['accepts_email_marketing'] ?? null) !== null) {
                    $updates['accepts_email_marketing'] = (bool) $additional['accepts_email_marketing'];
                }
                if (($additional['accepts_sms_marketing'] ?? null) !== null) {
                    $updates['accepts_sms_marketing'] = (bool) $additional['accepts_sms_marketing'];
                }

                $existingChannels = is_array($profile->source_channels) ? $profile->source_channels : [];
                $updates['source_channels'] = array_values(array_unique(array_filter(array_merge($existingChannels, $contextChannel))));

                if ($composedNotes !== null) {
                    $updates['notes'] = trim(implode(PHP_EOL.PHP_EOL, array_filter([
                        (string) ($profile->notes ?? ''),
                        $composedNotes,
                    ]))) ?: null;
                }

                $profile->forceFill($updates)->save();

                MarketingProfileLink::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'source_type' => 'manual_customer',
                        'source_id' => 'manual_profile:' . $profile->id,
                    ],
                    [
                        'marketing_profile_id' => $profile->id,
                        'source_meta' => [
                            'created_by' => $actorId,
                            'flow' => 'customers_wizard',
                        ],
                        'match_method' => 'manual_existing',
                        'confidence' => 1.00,
                    ]
                );

                return $profile;
            }

            /** @var MarketingProfile $profile */
            $profile = MarketingProfile::query()->create([
                'tenant_id' => $tenantId,
                'first_name' => trim((string) ($identity['first_name'] ?? '')) ?: null,
                'last_name' => trim((string) ($identity['last_name'] ?? '')) ?: null,
                'email' => ($identity['email'] ?? null) ? (string) $identity['email'] : null,
                'normalized_email' => ($identity['email'] ?? null)
                    ? $this->identityNormalizer->normalizeEmail((string) $identity['email'])
                    : null,
                'phone' => ($identity['phone'] ?? null) ? (string) $identity['phone'] : null,
                'normalized_phone' => ($identity['phone'] ?? null)
                    ? $this->identityNormalizer->normalizePhone((string) $identity['phone'])
                    : null,
                'accepts_email_marketing' => (bool) ($additional['accepts_email_marketing'] ?? false),
                'accepts_sms_marketing' => (bool) ($additional['accepts_sms_marketing'] ?? false),
                'source_channels' => array_values(array_unique(array_filter($contextChannel))),
                'notes' => $composedNotes,
            ]);

            MarketingProfileLink::query()->create([
                'tenant_id' => $tenantId,
                'marketing_profile_id' => $profile->id,
                'source_type' => 'manual_customer',
                'source_id' => 'manual_profile:' . $profile->id,
                'source_meta' => [
                    'created_by' => $actorId,
                    'flow' => 'customers_wizard',
                ],
                'match_method' => 'manual_create',
                'confidence' => 1.00,
            ]);

            return $profile;
        });
    }

    /**
     * @param array<string,mixed> $wizardState
     * @return Collection<int,array{profile:MarketingProfile,reasons:array<int,string>}>
     */
    protected function buildDuplicateCandidates(array $wizardState, ?int $tenantId = null): Collection
    {
        $identity = is_array($wizardState['identity'] ?? null) ? $wizardState['identity'] : [];
        $normalizedEmail = $this->identityNormalizer->normalizeEmail((string) ($identity['email'] ?? ''));
        $normalizedPhone = $this->identityNormalizer->normalizePhone((string) ($identity['phone'] ?? ''));

        if ($normalizedEmail === null && $normalizedPhone === null) {
            return collect();
        }

        $reasonMap = [];
        $appendReason = static function (int $profileId, string $reason) use (&$reasonMap): void {
            if (! array_key_exists($profileId, $reasonMap)) {
                $reasonMap[$profileId] = [];
            }
            if (! in_array($reason, $reasonMap[$profileId], true)) {
                $reasonMap[$profileId][] = $reason;
            }
        };

        if ($normalizedEmail !== null) {
            MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_email', $normalizedEmail)
                ->get(['id'])
                ->each(fn (MarketingProfile $profile) => $appendReason((int) $profile->id, 'Exact email'));
        }

        if ($normalizedPhone !== null) {
            MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_phone', $normalizedPhone)
                ->get(['id'])
                ->each(fn (MarketingProfile $profile) => $appendReason((int) $profile->id, 'Exact phone'));
        }

        $firstName = trim((string) ($identity['first_name'] ?? ''));
        $lastName = trim((string) ($identity['last_name'] ?? ''));
        if ($firstName !== '' || $lastName !== '') {
            MarketingProfile::query()
                ->where(function ($nameQuery) use ($firstName, $lastName): void {
                    if ($firstName !== '' && $lastName !== '') {
                        $nameQuery->where('first_name', 'like', '%' . $firstName . '%')
                            ->where('last_name', 'like', '%' . $lastName . '%');

                        return;
                    }

                    if ($firstName !== '') {
                        $nameQuery->where('first_name', 'like', '%' . $firstName . '%');

                        return;
                    }

                    $nameQuery->where('last_name', 'like', '%' . $lastName . '%');
                })
                ->limit(20)
                ->get(['id'])
                ->each(fn (MarketingProfile $profile) => $appendReason((int) $profile->id, 'Name similarity'));
        }

        if ($reasonMap === []) {
            return collect();
        }

        $profiles = MarketingProfile::query()
            ->whereIn('id', array_keys($reasonMap))
            ->withCount('links')
            ->orderByDesc('updated_at')
            ->get();

        return $profiles->map(function (MarketingProfile $profile) use ($reasonMap): array {
            return [
                'profile' => $profile,
                'reasons' => $reasonMap[(int) $profile->id] ?? [],
            ];
        })->values();
    }

    /**
     * @return array{
     *   identity:array<string,mixed>,
     *   context:array<string,mixed>,
     *   duplicate:array<string,mixed>,
     *   additional:array<string,mixed>
     * }
     */
    protected function customerCreateWizardState(Request $request): array
    {
        $stored = $request->session()->get('marketing.customers.create_wizard', []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'identity' => is_array($stored['identity'] ?? null) ? $stored['identity'] : [],
            'context' => is_array($stored['context'] ?? null) ? $stored['context'] : ['customer_context' => 'general'],
            'duplicate' => is_array($stored['duplicate'] ?? null) ? $stored['duplicate'] : ['decision' => 'continue', 'selected_profile_id' => null],
            'additional' => is_array($stored['additional'] ?? null) ? $stored['additional'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    protected function persistCustomerCreateWizardState(Request $request, array $state): void
    {
        $request->session()->put('marketing.customers.create_wizard', $state);
    }

    protected function normalizeCreateWizardStep(int $step): int
    {
        return max(1, min(5, $step));
    }

    protected function applySourceFilter(Builder $query, string $sourceFilter): void
    {
        $query->where(function ($sourceQuery) use ($sourceFilter): void {
            if ($sourceFilter === 'growave') {
                $sourceQuery->whereHas('externalProfiles', function ($externalQuery): void {
                    $externalQuery->where('integration', 'growave');
                });

                return;
            }

            if ($sourceFilter === 'manual') {
                $sourceQuery->whereHas('links', function ($linkQuery): void {
                    $linkQuery->where('source_type', 'manual_customer');
                });

                return;
            }

            $channelToken = match ($sourceFilter) {
                'shopify' => 'shopify',
                'square' => 'square',
                'wholesale' => 'wholesale',
                'event' => 'event',
                default => null,
            };

            if ($channelToken !== null) {
                $sourceQuery->whereJsonContains('source_channels', $channelToken)
                    ->orWhereHas('links', function ($linkQuery) use ($sourceFilter): void {
                        if ($sourceFilter === 'shopify') {
                            $linkQuery->whereIn('source_type', ['shopify_order', 'shopify_customer']);

                            return;
                        }
                        if ($sourceFilter === 'square') {
                            $linkQuery->whereIn('source_type', ['square_customer', 'square_order', 'square_payment']);

                            return;
                        }
                        if ($sourceFilter === 'wholesale') {
                            $linkQuery->where('source_type', 'like', 'wholesale%');

                            return;
                        }
                        if ($sourceFilter === 'event') {
                            $linkQuery->where('source_type', 'like', 'event%');
                        }
                    });
            }
        });
    }

    /**
     * @param Collection<int,MarketingProfile> $profiles
     * @return array<int,array{
     *   candle_cash_delta:int,
     *   tier:?string,
     *   referrals:int,
     *   has_growave:bool,
     *   review_count:int,
     *   average_rating:?float,
     *   last_synced_at:?string
     * }>
     */
    protected function buildLoyaltyEnrichment(Collection $profiles): array
    {
        if ($profiles->isEmpty()) {
            return [];
        }

        $profileIds = $profiles->pluck('id')->all();
        $externalProfiles = CustomerExternalProfile::query()
            ->whereIn('marketing_profile_id', $profileIds)
            ->where('integration', 'growave')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get(['id', 'marketing_profile_id', 'points_balance', 'vip_tier', 'referral_link', 'raw_metafields', 'synced_at']);

        $latestExternal = $this->growaveProjectionService->preferredExternalMap($externalProfiles);

        $reviewSummaries = MarketingReviewSummary::query()
            ->whereIn('marketing_profile_id', $profileIds)
            ->where('provider', 'growave')
            ->where('integration', 'growave')
            ->get([
                'id',
                'marketing_profile_id',
                'store_key',
                'external_customer_id',
                'review_count',
                'published_review_count',
                'average_rating',
                'last_reviewed_at',
                'source_synced_at',
            ]);

        $latestReviewSummary = $this->growaveProjectionService->preferredReviewSummaryMap($reviewSummaries, $latestExternal);

        $candleBalances = [];
        if (Schema::hasTable('candle_cash_balances')) {
            $candleBalances = DB::table('candle_cash_balances')
                ->whereIn('marketing_profile_id', $profileIds)
                ->pluck('balance', 'marketing_profile_id')
                ->map(fn ($value): int => (int) $value)
                ->all();
        }

        $rows = [];
        foreach ($profiles as $profile) {
            $profileId = (int) $profile->id;
            $external = $latestExternal[$profileId] ?? null;
            $reviewSummary = $latestReviewSummary[$profileId] ?? null;
            $legacyPoints = $external?->points_balance !== null
                ? (int) $external->points_balance
                : 0;
            $candleCashPoints = (int) ($candleBalances[$profileId] ?? 0);

            $referrals = 0;
            if ($external) {
                $referrals = $this->extractReferralCount((array) ($external->raw_metafields ?? []));
                if ($referrals === 0 && $external->referral_link) {
                    $referrals = 1;
                }
            }

            $lastSyncedAt = collect([
                optional($external?->synced_at)->toDateTimeString(),
                optional($reviewSummary?->source_synced_at)->toDateTimeString(),
            ])->filter()->max();

            $rows[$profileId] = [
                'legacy_growave_points' => $legacyPoints,
                'candle_cash_points' => $candleCashPoints,
                'candle_cash_amount' => $this->candleCashService->amountFromPoints($candleCashPoints),
                'tier' => $external?->vip_tier ? (string) $external->vip_tier : null,
                'referrals' => $referrals,
                'has_growave' => $external !== null,
                'review_count' => (int) ($reviewSummary?->review_count ?? 0),
                'average_rating' => $reviewSummary?->average_rating !== null
                    ? (float) $reviewSummary->average_rating
                    : null,
                'last_synced_at' => $lastSyncedAt,
            ];
        }

        return $rows;
    }

    protected function extractReferralCount(array $metafields): int
    {
        foreach ($metafields as $metafield) {
            $key = strtolower(trim((string) ($metafield['key'] ?? '')));
            if (! str_contains($key, 'referral') || ! str_contains($key, 'count')) {
                continue;
            }

            $value = trim((string) ($metafield['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (is_numeric($value)) {
                return max(0, (int) round((float) $value));
            }
        }

        return 0;
    }

    /**
     * @return array{total_customers:int,candle_cash_holders:int,growave_linked:int,shopify_or_order_linked:int,missing_contact:int}
     */
    protected function buildIndexQuickStats(int $totalProfiles): array
    {
        $candleCashHolders = Schema::hasTable('candle_cash_balances')
            ? (int) CandleCashBalance::query()
                ->where('balance', '>', 0)
                ->count()
            : 0;

        $growaveLinked = Schema::hasTable('customer_external_profiles')
            ? (int) CustomerExternalProfile::query()
                ->where('integration', 'growave')
                ->whereNotNull('marketing_profile_id')
                ->distinct('marketing_profile_id')
                ->count('marketing_profile_id')
            : 0;

        $shopifyOrOrderLinked = (int) MarketingProfileLink::query()
            ->whereIn('source_type', ['order', 'shopify_order', 'shopify_customer'])
            ->distinct('marketing_profile_id')
            ->count('marketing_profile_id');

        $missingContact = (int) MarketingProfile::query()
            ->where(function ($query): void {
                $query->whereNull('normalized_email')->orWhere('normalized_email', '');
            })
            ->where(function ($query): void {
                $query->whereNull('normalized_phone')->orWhere('normalized_phone', '');
            })
            ->count();

        return [
            'total_customers' => $totalProfiles,
            'candle_cash_holders' => $candleCashHolders,
            'growave_linked' => $growaveLinked,
            'shopify_or_order_linked' => $shopifyOrOrderLinked,
            'missing_contact' => $missingContact,
        ];
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
            $squareOrderCount = (int) $profileLinks
                ->where('source_type', 'square_order')
                ->pluck('source_id')
                ->filter()
                ->unique()
                ->count();

            $shopifyOrderTimestamp = optional($lastOrder?->ordered_at)->timestamp;
            $squareOrderTimestamp = $squareOrderDates->max();
            $lastOrderTimestamp = collect([$shopifyOrderTimestamp, $squareOrderTimestamp])->filter()->max();
            $latestTimestamp = collect([$shopifyOrderTimestamp, $squareOrderTimestamp, ...$squarePaymentDates->all()])
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
                'order_count' => $allOrders->count() + $squareOrderCount,
                'last_order_at' => $lastOrderTimestamp ? date('Y-m-d', (int) $lastOrderTimestamp) : null,
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
     * @param Collection<int,\App\Models\CandleCashTransaction> $transactions
     * @return Collection<int,array{
     *   id:int,
     *   occurred_at:?string,
     *   points:int,
     *   category:string,
     *   provider_activity:string,
     *   source_id:?string,
     *   description:?string,
     *   note:?string
     * }>
     */
    protected function normalizeGrowaveLoyaltyTransactions(Collection $transactions): Collection
    {
        return $transactions
            ->map(function ($transaction): array {
                $description = trim((string) ($transaction->description ?? ''));
                $providerActivity = $this->extractProviderActivityTypeFromDescription($description) ?: 'unknown';
                $note = $this->extractProviderActivityNoteFromDescription($description);

                return [
                    'id' => (int) $transaction->id,
                    'occurred_at' => optional($transaction->created_at)->toDateTimeString(),
                    'candle_cash_delta' => (int) $transaction->candle_cash_delta,
                    'category' => $this->normalizeGrowaveActivityCategory(
                        providerActivity: $providerActivity,
                        note: $note,
                        points: (int) $transaction->candle_cash_delta
                    ),
                    'provider_activity' => strtoupper(str_replace('_', ' ', $providerActivity)),
                    'source_id' => $this->nullableString($transaction->source_id),
                    'description' => $description !== '' ? $description : null,
                    'note' => $note,
                ];
            })
            ->values();
    }

    protected function extractProviderActivityTypeFromDescription(string $description): ?string
    {
        if ($description === '') {
            return null;
        }

        if (preg_match('/\(([a-z_]+)\)/i', $description, $matches) !== 1) {
            return null;
        }

        $value = strtolower(trim((string) ($matches[1] ?? '')));

        return $value !== '' ? $value : null;
    }

    protected function extractProviderActivityNoteFromDescription(string $description): ?string
    {
        if (! str_contains($description, '):')) {
            return null;
        }

        [, $note] = explode('):', $description, 2);
        $note = trim((string) $note);

        return $note !== '' ? $note : null;
    }

    protected function normalizeGrowaveActivityCategory(string $providerActivity, ?string $note, int $points): string
    {
        $noteLower = strtolower(trim((string) $note));

        if (in_array($providerActivity, ['referrer', 'referred'], true)) {
            return 'Referral Reward';
        }

        if ($providerActivity === 'expired') {
            return 'Expired';
        }

        if ($providerActivity === 'redeem') {
            return 'Redeemed';
        }

        if (in_array($providerActivity, ['manual', 'import', 'refund'], true)) {
            return 'Manual Adjustment';
        }

        if (str_contains($noteLower, 'review')) {
            return 'Review Reward';
        }

        if (str_contains($noteLower, 'refer')) {
            return 'Referral Reward';
        }

        return $points >= 0 ? 'Earned' : 'Redeemed';
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
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

        $squareCustomerCandidates = Schema::hasTable('square_customers')
            ? (int) SquareCustomer::query()->count()
            : 0;
        $squareOrderCandidates = Schema::hasTable('square_orders')
            ? (int) SquareOrder::query()->count()
            : 0;
        $squarePaymentCandidates = Schema::hasTable('square_payments')
            ? (int) SquarePayment::query()->count()
            : 0;

        $upstreamCandidates = $shopifyOrderCandidates
            + $shopifyCustomerCandidates
            + $growaveCandidates
            + $squareCustomerCandidates
            + $squareOrderCandidates
            + $squarePaymentCandidates;
        if ($upstreamCandidates === 0) {
            return null;
        }

        $lastSyncRun = Schema::hasTable('marketing_import_runs')
            ? MarketingImportRun::query()
                ->whereIn('type', [
                    'marketing_profiles_sync',
                    'shopify_customer_metafields_sync',
                    'square_customers_sync',
                    'square_orders_sync',
                    'square_payments_sync',
                ])
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first()
            : null;

        return [
            'shopify_order_candidates' => $shopifyOrderCandidates,
            'shopify_customer_candidates' => $shopifyCustomerCandidates,
            'growave_candidates' => $growaveCandidates,
            'square_customer_candidates' => $squareCustomerCandidates,
            'square_order_candidates' => $squareOrderCandidates,
            'square_payment_candidates' => $squarePaymentCandidates,
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

    protected function currentTenantId(Request $request): ?int
    {
        $tenantId = $request->attributes->get('current_tenant_id');

        return is_numeric($tenantId) ? (int) $tenantId : null;
    }

    protected function assertProfileInTenantScope(MarketingProfile $profile, Request $request): void
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            return;
        }

        if ((int) ($profile->tenant_id ?? 0) !== $tenantId) {
            abort(404);
        }
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
