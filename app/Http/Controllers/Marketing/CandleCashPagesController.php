<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashReferral;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleState;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashRewardsOverviewService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashGiftReportService;
use App\Services\Marketing\CandleCashTaskEligibilityService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\ProductReviewService;
use App\Services\Marketing\ProductReviewNotificationService;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use App\Support\Marketing\CandleCashSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

class CandleCashPagesController extends Controller
{
    public function dashboard(CandleCashRewardsOverviewService $overviewService): View
    {
        return view('marketing.candle-cash.show', [
            'sectionKey' => 'dashboard',
            'section' => CandleCashSectionRegistry::section('dashboard'),
            'sections' => $this->navigationItems(),
            'dashboard' => $overviewService->build(),
        ]);
    }

    public function redeem(ShopifyEmbeddedRewardsService $rewardsService): View
    {
        $payload = $rewardsService->payload();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'redeem',
            'section' => CandleCashSectionRegistry::section('redeem'),
            'sections' => $this->navigationItems(),
            'redeemRules' => data_get($payload, 'redeem.items', []),
            'redeemSummary' => data_get($payload, 'redeem.summary', [
                'total' => 0,
                'enabled' => 0,
                'disabled' => 0,
            ]),
        ]);
    }

    public function updateReward(
        Request $request,
        CandleCashReward $reward,
        ShopifyEmbeddedRewardsService $rewardsService
    ): RedirectResponse {
        try {
            $rewardsService->updateRedeemRule($reward, $this->validateRedeemPayload($request));
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->withInput()
                ->with('toast', ['style' => 'danger', 'message' => $this->displayLabel('rewards_label', 'Rewards') . ' rule could not be saved.']);
        }

        return back()->with('toast', ['style' => 'success', 'message' => 'Redeem rule updated.']);
    }

    public function tasks(Request $request): View
    {
        $filter = trim((string) $request->query('filter', 'active'));
        $type = trim((string) $request->query('type', 'all'));
        $verification = trim((string) $request->query('verification', 'all'));

        $tasks = CandleCashTask::query()
            ->when($filter === 'active', fn (Builder $builder) => $builder->where('enabled', true)->whereNull('archived_at'))
            ->when($filter === 'inactive', fn (Builder $builder) => $builder->where('enabled', false)->whereNull('archived_at'))
            ->when($filter === 'archived', fn (Builder $builder) => $builder->whereNotNull('archived_at'))
            ->when($filter === 'manual', fn (Builder $builder) => $builder->where(function (Builder $query): void {
                $query->where('requires_manual_approval', true)
                    ->orWhere('requires_customer_submission', true);
            }))
            ->when($filter === 'auto', fn (Builder $builder) => $builder->where('requires_manual_approval', false)->where('requires_customer_submission', false))
            ->when($type !== 'all', fn (Builder $builder) => $builder->where('task_type', $type))
            ->when($verification !== 'all', fn (Builder $builder) => $builder->where('verification_mode', $verification))
            ->withCount([
                'completions as awarded_count' => fn ($builder) => $builder->where('status', 'awarded'),
                'completions as pending_count' => fn ($builder) => $builder->where('status', 'pending'),
                'completions as blocked_count' => fn ($builder) => $builder->where('status', 'blocked'),
                'events as event_count',
            ])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'tasks',
            'section' => CandleCashSectionRegistry::section('tasks'),
            'sections' => $this->navigationItems(),
            'tasks' => $tasks,
            'taskFilters' => [
                'filter' => $filter,
                'type' => $type,
                'verification' => $verification,
            ],
            'taskTypes' => CandleCashTask::query()->distinct()->orderBy('task_type')->pluck('task_type')->filter()->values(),
            'taskVerificationModes' => CandleCashTask::query()->distinct()->orderBy('verification_mode')->pluck('verification_mode')->filter()->values(),
            'newTask' => $this->defaultTaskPayload(),
        ]);
    }

    public function storeTask(Request $request): RedirectResponse
    {
        $task = CandleCashTask::query()->create($this->validatedTaskPayload($request));

        return redirect()->route('marketing.candle-cash.tasks')
            ->with('toast', ['style' => 'success', 'message' => $this->displayLabel('rewards_label', 'Rewards') . ' task created: ' . $task->title]);
    }

    public function updateTask(Request $request, CandleCashTask $task): RedirectResponse
    {
        $task->fill($this->validatedTaskPayload($request, $task))->save();

        return back()->with('toast', ['style' => 'success', 'message' => 'Task updated.']);
    }

    public function toggleTask(CandleCashTask $task): RedirectResponse
    {
        $task->forceFill([
            'enabled' => ! $task->enabled,
            'archived_at' => $task->archived_at,
        ])->save();

        return back()->with('toast', ['style' => 'success', 'message' => $task->enabled ? 'Task enabled.' : 'Task disabled.']);
    }

    public function archiveTask(CandleCashTask $task): RedirectResponse
    {
        $task->forceFill([
            'archived_at' => now(),
            'enabled' => false,
        ])->save();

        return back()->with('toast', ['style' => 'success', 'message' => 'Task archived.']);
    }

    public function queue(Request $request, GoogleBusinessProfileConnectionService $googleBusinessConnectionService): View
    {
        $status = trim((string) $request->query('status', 'all'));
        $query = CandleCashTaskEvent::query()
            ->with([
                'task:id,title,handle,verification_mode,auto_award',
                'profile:id,first_name,last_name,email,phone',
                'completion:id,status,review_notes,proof_url,proof_text,submission_payload,submitted_at',
            ])
            ->latest('id');

        if ($status === 'duplicates') {
            $query->where('duplicate_hits', '>', 0);
        } elseif ($status === 'awarded') {
            $query->where('reward_awarded', true);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $events = $query->paginate(25)->withQueryString();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'queue',
            'section' => CandleCashSectionRegistry::section('queue'),
            'sections' => $this->navigationItems(),
            'eventLog' => $events,
            'queueStatus' => $status,
            'googleBusinessReviewUrl' => $googleBusinessConnectionService->resolveReviewUrl(),
            'queueSummary' => [
                'all' => CandleCashTaskEvent::query()->count(),
                'awarded' => CandleCashTaskEvent::query()->where('reward_awarded', true)->count(),
                'pending' => CandleCashTaskEvent::query()->where('status', 'pending')->count(),
                'blocked' => CandleCashTaskEvent::query()->where('status', 'blocked')->count(),
                'duplicates' => CandleCashTaskEvent::query()->where('duplicate_hits', '>', 0)->count(),
            ],
        ]);
    }

    public function approveCompletion(Request $request, CandleCashTaskCompletion $completion, CandleCashTaskService $taskService): RedirectResponse
    {
        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $taskService->approveCompletion($completion, auth()->id(), $data['review_notes'] ?? null);

        return back()->with('toast', ['style' => 'success', 'message' => 'Task approved and ' . strtolower($this->displayLabel('reward_credit_label', 'reward credit')) . ' credited.']);
    }

    public function rejectCompletion(Request $request, CandleCashTaskCompletion $completion, CandleCashTaskService $taskService): RedirectResponse
    {
        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $taskService->rejectCompletion($completion, auth()->id(), $data['review_notes']);

        return back()->with('toast', ['style' => 'success', 'message' => 'Task rejected.']);
    }

    public function reviews(Request $request): View
    {
        $tenantId = $this->currentTenantId($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'all'));
        $rating = trim((string) $request->query('rating', 'all'));
        $source = trim((string) $request->query('source', 'all'));
        $rewardStatus = trim((string) $request->query('reward_status', 'all'));
        $queue = trim((string) $request->query('queue', 'all'));
        $customerId = (int) $request->query('customer_id', 0);
        $selectedId = (int) $request->query('review', 0);

        $reviewsQuery = $this->reviewIndexQuery($tenantId)
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $query) use ($search): void {
                    $query->where('product_title', 'like', '%' . $search . '%')
                        ->orWhere('product_handle', 'like', '%' . $search . '%')
                        ->orWhere('reviewer_name', 'like', '%' . $search . '%')
                        ->orWhere('reviewer_email', 'like', '%' . $search . '%')
                        ->orWhere('body', 'like', '%' . $search . '%')
                        ->orWhere('title', 'like', '%' . $search . '%')
                        ->orWhere('reward_eligibility_status', 'like', '%' . $search . '%')
                        ->orWhere('reward_award_status', 'like', '%' . $search . '%');
                });
            })
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
            ->when($rating !== 'all', fn (Builder $builder) => $builder->where('rating', (int) $rating))
            ->when($source === 'imported', fn (Builder $builder) => $builder->where('submission_source', 'growave_import'))
            ->when($source === 'native', fn (Builder $builder) => $builder->where('submission_source', '!=', 'growave_import'))
            ->when($rewardStatus !== 'all', fn (Builder $builder) => $this->applyRewardStatusFilter($builder, $rewardStatus))
            ->when($queue !== 'all', fn (Builder $builder) => $this->applyReviewQueueFilter($builder, $queue))
            ->when($customerId > 0, fn (Builder $builder) => $builder->where('marketing_profile_id', $customerId))
            ->orderByDesc('approved_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
        ;

        $reviews = $reviewsQuery
            ->paginate(25)
            ->withQueryString();

        $selectedReview = $selectedId > 0
            ? $this->reviewIndexQuery($tenantId)->find($selectedId)
            : $reviews->first();

        $filteredCustomer = $customerId > 0
            ? MarketingProfile::query()->select(['id', 'first_name', 'last_name', 'email'])->find($customerId)
            : null;

        $summaryQuery = MarketingReviewHistory::query()
            ->when($tenantId !== null, fn (Builder $builder) => $builder->where('tenant_id', $tenantId));

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'reviews',
            'section' => CandleCashSectionRegistry::section('reviews'),
            'sections' => $this->navigationItems(),
            'reviews' => $reviews,
            'reviewFilters' => [
                'search' => $search,
                'status' => $status,
                'rating' => $rating,
                'source' => $source,
                'reward_status' => $rewardStatus,
                'queue' => $queue,
                'customer_id' => $customerId,
            ],
            'selectedReview' => $selectedReview,
            'filteredCustomer' => $filteredCustomer,
            'reviewSummary' => [
                'all' => (clone $summaryQuery)->count(),
                'approved' => (clone $summaryQuery)->where('status', 'approved')->count(),
                'pending' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'rejected' => (clone $summaryQuery)->where('status', 'rejected')->count(),
                'imported' => (clone $summaryQuery)->where('submission_source', 'growave_import')->count(),
                'new_reviews' => (clone $summaryQuery)
                    ->where('submitted_at', '>=', now()->subDays(7))
                    ->count(),
                'reward_exceptions' => (clone $summaryQuery)
                    ->where(function (Builder $builder): void {
                        $this->applyRewardStatusFilter($builder, 'exceptions');
                    })
                    ->count(),
            ],
            'reviewTenantId' => $tenantId,
        ]);
    }

    public function approveReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService
    ): RedirectResponse {
        $this->assertReviewInTenantScope($review, $request);

        $data = $request->validate([
            'moderation_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $productReviewService->approve($review, auth()->id(), $data['moderation_notes'] ?? null);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review approved.']);
    }

    public function rejectReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService
    ): RedirectResponse {
        $this->assertReviewInTenantScope($review, $request);

        $data = $request->validate([
            'moderation_notes' => ['required', 'string', 'max:2000'],
        ]);

        $productReviewService->reject($review, auth()->id(), $data['moderation_notes']);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review rejected.']);
    }

    public function respondToReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService,
        ProductReviewNotificationService $notificationService
    ): RedirectResponse {
        $this->assertReviewInTenantScope($review, $request);

        $data = $request->validate([
            'admin_response' => ['required', 'string', 'max:5000'],
        ]);

        $isFirstResponse = blank($review->admin_response);

        $updated = $productReviewService->respondToReview($review, $data['admin_response'], $request->user());

        if ($isFirstResponse && $updated->admin_response_notified_at === null) {
            $notificationService->sendResponseNotification($updated);
        }

        return back()->with('toast', ['style' => 'success', 'message' => 'Response saved.']);
    }

    public function updateReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService
    ): RedirectResponse {
        $this->assertReviewInTenantScope($review, $request);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $productReviewService->updateReviewContent($review, $data, $request->user());

        return back()->with('toast', ['style' => 'success', 'message' => 'Review updated.']);
    }

    public function deleteReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService
    ): RedirectResponse
    {
        $this->assertReviewInTenantScope($review, $request);

        $productReviewService->delete($review);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review deleted.']);
    }

    public function resendReviewNotification(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewNotificationService $notificationService
    ): RedirectResponse {
        $this->assertReviewInTenantScope($review, $request);

        $notificationService->send($review, true);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review notification email resent.']);
    }

    public function customers(Request $request, CandleCashService $candleCashService, CandleCashTaskEligibilityService $eligibilityService): View
    {
        $search = trim((string) $request->query('search', ''));
        $selectedId = (int) $request->query('profile', 0);

        $profiles = MarketingProfile::query()
            ->where(function (Builder $builder): void {
                $builder->whereHas('candleCashTransactions')
                    ->orWhereHas('candleCashTaskCompletions')
                    ->orWhereHas('candleCashReferralsMade')
                    ->orWhereHas('candleCashReferralsReceived');
            })
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $query) use ($search): void {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            })
            ->withCount([
                'candleCashTaskCompletions as pending_task_count' => fn ($builder) => $builder->where('status', 'pending'),
                'candleCashReferralsMade as referral_count',
            ])
            ->with('candleCashBalance:marketing_profile_id,balance')
            ->orderByDesc(CandleCashBalance::query()
                ->select('balance')
                ->whereColumn('marketing_profile_id', 'marketing_profiles.id')
                ->limit(1))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $selectedProfile = $selectedId > 0
            ? MarketingProfile::query()->with([
                'candleCashBalance',
                'candleCashTransactions' => fn ($builder) => $builder->latest('id')->limit(20),
                'candleCashTaskCompletions.task:id,title,handle',
                'candleCashReferralsMade.referredProfile:id,first_name,last_name,email',
                'candleCashReferralsReceived.referrer:id,first_name,last_name,email',
            ])->find($selectedId)
            : null;

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'customers',
            'section' => CandleCashSectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'customerProfiles' => $profiles,
            'customerSearch' => $search,
            'selectedProfile' => $selectedProfile,
            'selectedProfileSummary' => $selectedProfile ? [
                'balance_candle_cash' => $candleCashService->currentBalance($selectedProfile),
                'balance_amount' => $candleCashService->amountFromPoints($candleCashService->currentBalance($selectedProfile)),
                'lifetime_earned' => round((float) $selectedProfile->candleCashTransactions->where('candle_cash_delta', '>', 0)->sum('candle_cash_delta'), 3),
                'lifetime_earned_amount' => $candleCashService->amountFromPoints($selectedProfile->candleCashTransactions->where('candle_cash_delta', '>', 0)->sum('candle_cash_delta')),
                'lifetime_redeemed' => round(abs((float) $selectedProfile->candleCashTransactions->where('candle_cash_delta', '<', 0)->sum('candle_cash_delta')), 3),
                'lifetime_redeemed_amount' => $candleCashService->amountFromPoints(abs((float) $selectedProfile->candleCashTransactions->where('candle_cash_delta', '<', 0)->sum('candle_cash_delta'))),
                'membership_status' => $eligibilityService->membershipStatusForProfile($selectedProfile),
                'blocked_duplicate_attempts' => (int) $selectedProfile->candleCashTaskEvents()->where('duplicate_hits', '>', 0)->sum('duplicate_hits'),
            ] : null,
        ]);
    }

    public function adjustCustomer(Request $request, MarketingProfile $marketingProfile, CandleCashService $candleCashService): RedirectResponse
    {
        $data = $request->validate([
            'adjustment_type' => ['required', 'in:add,deduct'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $points = $candleCashService->pointsFromAmount((float) $data['amount']);
        if ($data['adjustment_type'] === 'deduct') {
            $points *= -1;
            if ($candleCashService->currentBalance($marketingProfile) < abs($points)) {
                return back()->with('toast', ['style' => 'danger', 'message' => 'Cannot deduct more ' . strtolower($this->displayLabel('reward_credit_label', 'reward credit')) . ' than the current balance.']);
            }
        }

        $candleCashService->addPoints(
            profile: $marketingProfile,
            points: $points,
            type: $points >= 0 ? 'earn' : 'adjustment',
            source: 'admin_adjustment',
            sourceId: 'profile:' . $marketingProfile->id . ':user:' . auth()->id() . ':' . now()->timestamp,
            description: trim((string) ($data['note'] ?? '')) ?: 'Manual ' . strtolower($this->displayLabel('rewards_balance_label', 'Rewards balance')) . ' adjustment'
        );

        return back()->with('toast', ['style' => 'success', 'message' => Str::title($this->displayLabel('rewards_balance_label', 'Rewards balance')) . ' adjusted.']);
    }

    public function referrals(Request $request): View
    {
        $status = trim((string) $request->query('status', 'all'));
        $query = CandleCashReferral::query()
            ->with(['referrer:id,first_name,last_name,email', 'referredProfile:id,first_name,last_name,email'])
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'referrals',
            'section' => CandleCashSectionRegistry::section('referrals'),
            'sections' => $this->navigationItems(),
            'referrals' => $query->paginate(25)->withQueryString(),
            'referralStatus' => $status,
            'referralSummary' => [
                'captured' => CandleCashReferral::query()->where('status', 'captured')->count(),
                'qualified' => CandleCashReferral::query()->where('status', 'qualified')->count(),
                'pending' => CandleCashReferral::query()->where(function (Builder $builder): void {
                    $builder->where('referrer_reward_status', 'pending')->orWhere('referred_reward_status', 'pending');
                })->count(),
            ],
        ]);
    }

    public function giftsReport(Request $request, CandleCashGiftReportService $giftReportService): View
    {
        $from = $this->parseGiftReportDate($request->query('from'));
        $to = $this->parseGiftReportDate($request->query('to'));
        $report = $giftReportService->generate($from, $to);

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'gifts-report',
            'section' => CandleCashSectionRegistry::section('gifts-report'),
            'sections' => $this->navigationItems(),
            'giftReport' => $report,
            'reportFilters' => [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function reprocessReferral(CandleCashReferral $referral, CandleCashReferralService $referralService): RedirectResponse
    {
        $orderId = (int) $referral->qualifying_order_id;
        $order = $orderId > 0 ? Order::query()->find($orderId) : null;
        if (! $order) {
            return back()->with('toast', ['style' => 'danger', 'message' => 'Qualifying order could not be found for this referral.']);
        }

        $referralService->qualifyFromOrder($order, $referral->referredProfile, [
            'referral_code' => $referral->referral_code,
        ]);

        return back()->with('toast', ['style' => 'success', 'message' => 'Referral reprocessed.']);
    }

    public function settings(
        CandleCashTaskService $taskService,
        GoogleBusinessProfileConnectionService $googleBusinessConnectionService
    ): View
    {
        $tenantId = $this->currentTenantId(request());

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'settings',
            'section' => CandleCashSectionRegistry::section('settings'),
            'sections' => $this->navigationItems(),
            'programConfig' => $taskService->programConfig($tenantId),
            'referralConfig' => $taskService->referralConfig($tenantId),
            'frontendConfig' => $taskService->frontendConfig($tenantId),
            'integrationConfig' => $taskService->integrationConfig($tenantId),
            'googleBusinessStatus' => $googleBusinessConnectionService->status(),
            'settingsTenantId' => $tenantId,
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $tenantId = $this->currentTenantId($request);
        $scope = trim((string) $request->input('scope', 'program'));

        if ($scope === 'program') {
            $existing = $this->settingValue('candle_cash_program_config', $tenantId);
            $data = $request->validate([
                'label' => ['required', 'string', 'max:120'],
                'email_signup_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'sms_signup_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'google_review_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'birthday_signup_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'candle_club_join_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'candle_club_vote_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'second_order_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'birthday_reward_frequency' => ['required', 'in:once_per_year,once_per_lifetime'],
                'homepage_signup_copy' => ['required', 'string', 'max:255'],
                'homepage_central_title' => ['required', 'string', 'max:160'],
                'homepage_central_copy' => ['required', 'string', 'max:255'],
            ]);

            $this->saveSetting('candle_cash_program_config', array_merge($existing, [
                'label' => trim((string) $data['label']),
                'candle_cash_units_per_dollar' => CandleCashService::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
                'email_signup_reward_amount' => (float) $data['email_signup_reward_amount'],
                'sms_signup_reward_amount' => (float) $data['sms_signup_reward_amount'],
                'google_review_reward_amount' => (float) $data['google_review_reward_amount'],
                'birthday_signup_reward_amount' => (float) $data['birthday_signup_reward_amount'],
                'candle_club_join_reward_amount' => (float) $data['candle_club_join_reward_amount'],
                'candle_club_vote_reward_amount' => (float) $data['candle_club_vote_reward_amount'],
                'second_order_reward_amount' => (float) $data['second_order_reward_amount'],
                'birthday_reward_frequency' => $data['birthday_reward_frequency'],
                'homepage_signup_copy' => trim((string) $data['homepage_signup_copy']),
                'homepage_central_title' => trim((string) $data['homepage_central_title']),
                'homepage_central_copy' => trim((string) $data['homepage_central_copy']),
            ]), $this->displayLabel('rewards_program_label', 'Rewards program') . ' settings.', $tenantId);
        } elseif ($scope === 'referral') {
            $existing = $this->settingValue('candle_cash_referral_config', $tenantId);
            $data = $request->validate([
                'enabled' => ['nullable', 'boolean'],
                'referrer_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'referred_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'qualifying_event' => ['required', 'in:first_order,account_or_first_order'],
                'qualifying_min_order_total' => ['nullable', 'numeric', 'min:0', 'max:9999'],
                'program_headline' => ['required', 'string', 'max:160'],
                'program_copy' => ['required', 'string', 'max:255'],
            ]);

            $this->saveSetting('candle_cash_referral_config', array_merge($existing, [
                'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : false,
                'referrer_reward_amount' => (float) $data['referrer_reward_amount'],
                'referred_reward_amount' => (float) $data['referred_reward_amount'],
                'qualifying_event' => $data['qualifying_event'],
                'qualifying_min_order_total' => $data['qualifying_min_order_total'] !== null ? (float) $data['qualifying_min_order_total'] : null,
                'program_headline' => trim((string) $data['program_headline']),
                'program_copy' => trim((string) $data['program_copy']),
            ]), $this->displayLabel('rewards_label', 'Rewards') . ' referral settings.', $tenantId);
        } elseif ($scope === 'frontend') {
            $existing = $this->settingValue('candle_cash_frontend_config', $tenantId);
            $data = $request->validate([
                'central_title' => ['required', 'string', 'max:160'],
                'central_subtitle' => ['required', 'string', 'max:255'],
                'faq_approval_copy' => ['required', 'string', 'max:255'],
                'faq_stack_copy' => ['required', 'string', 'max:255'],
                'faq_pending_copy' => ['required', 'string', 'max:255'],
                'faq_verification_copy' => ['required', 'string', 'max:255'],
            ]);

            $this->saveSetting('candle_cash_frontend_config', array_merge($existing, [
                'central_title' => trim((string) $data['central_title']),
                'central_subtitle' => trim((string) $data['central_subtitle']),
                'faq_approval_copy' => trim((string) $data['faq_approval_copy']),
                'faq_stack_copy' => trim((string) $data['faq_stack_copy']),
                'faq_pending_copy' => trim((string) $data['faq_pending_copy']),
                'faq_verification_copy' => trim((string) $data['faq_verification_copy']),
            ]), $this->displayLabel('rewards_label', 'Rewards') . ' frontend copy settings.', $tenantId);
        } else {
            $existing = $this->settingValue('candle_cash_integration_config', $tenantId);
            $data = $request->validate([
                'reviews_enabled' => ['nullable', 'boolean'],
                'wishlist_enabled' => ['nullable', 'boolean'],
                'rewards_incentivized_reviews_enabled' => ['nullable', 'boolean'],
                'google_review_enabled' => ['nullable', 'boolean'],
                'google_review_url' => ['nullable', 'string', 'max:500'],
                'google_business_location_id' => ['nullable', 'string', 'max:120'],
                'google_review_matching_strategy' => ['required', 'string', 'max:120'],
                'product_review_enabled' => ['nullable', 'boolean'],
                'product_review_platform' => ['nullable', 'string', 'max:120'],
                'product_review_matching_strategy' => ['required', 'string', 'max:120'],
                'product_review_moderation_enabled' => ['nullable', 'boolean'],
                'product_review_allow_guest' => ['nullable', 'boolean'],
                'product_review_min_length' => ['required', 'integer', 'min:12', 'max:500'],
                'product_review_reward_amount' => ['required', 'numeric', 'min:0', 'max:50'],
                'product_review_require_order_match' => ['nullable', 'boolean'],
                'product_review_reward_dedupe_mode' => ['required', 'in:order_line,customer_product'],
                'review_auto_publish_enabled' => ['nullable', 'boolean'],
                'product_review_notification_email' => ['nullable', 'email', 'max:255'],
                'wishlist_discount_outreach_enabled' => ['nullable', 'boolean'],
                'sms_provider_enabled' => ['nullable', 'boolean'],
                'tenant_branding_tokens' => ['nullable', 'string', 'max:5000'],
                'sms_signup_enabled' => ['nullable', 'boolean'],
                'email_signup_enabled' => ['nullable', 'boolean'],
                'vote_locked_join_url' => ['nullable', 'string', 'max:500'],
            ]);

            $autoPublishEnabled = array_key_exists('review_auto_publish_enabled', $data)
                ? (bool) $data['review_auto_publish_enabled']
                : ! (bool) ($data['product_review_moderation_enabled'] ?? false);
            $reviewRewardAmount = (float) $data['product_review_reward_amount'];
            $brandingTokens = $this->decodeJsonField($data['tenant_branding_tokens'] ?? null);

            $this->saveSetting('candle_cash_integration_config', array_merge($existing, [
                'reviews_enabled' => array_key_exists('reviews_enabled', $data) ? (bool) $data['reviews_enabled'] : data_get($existing, 'reviews_enabled', true),
                'wishlist_enabled' => array_key_exists('wishlist_enabled', $data) ? (bool) $data['wishlist_enabled'] : data_get($existing, 'wishlist_enabled', true),
                'rewards_incentivized_reviews_enabled' => array_key_exists('rewards_incentivized_reviews_enabled', $data) ? (bool) $data['rewards_incentivized_reviews_enabled'] : data_get($existing, 'rewards_incentivized_reviews_enabled', true),
                'google_review_enabled' => array_key_exists('google_review_enabled', $data) ? (bool) $data['google_review_enabled'] : false,
                'google_review_url' => trim((string) ($data['google_review_url'] ?? '')) ?: null,
                'google_business_location_id' => trim((string) ($data['google_business_location_id'] ?? '')) ?: null,
                'google_review_matching_strategy' => trim((string) $data['google_review_matching_strategy']),
                'product_review_enabled' => array_key_exists('product_review_enabled', $data) ? (bool) $data['product_review_enabled'] : false,
                'product_review_platform' => trim((string) ($data['product_review_platform'] ?? '')) ?: null,
                'product_review_matching_strategy' => trim((string) $data['product_review_matching_strategy']),
                'product_review_moderation_enabled' => ! $autoPublishEnabled,
                'product_review_allow_guest' => array_key_exists('product_review_allow_guest', $data) ? (bool) $data['product_review_allow_guest'] : false,
                'product_review_min_length' => (int) $data['product_review_min_length'],
                'product_review_reward_amount_cents' => (int) round($reviewRewardAmount * 100),
                'product_review_reward_amount' => $reviewRewardAmount,
                'product_review_require_order_match' => array_key_exists('product_review_require_order_match', $data) ? (bool) $data['product_review_require_order_match'] : true,
                'product_review_reward_dedupe_mode' => trim((string) $data['product_review_reward_dedupe_mode']),
                'review_auto_publish_enabled' => $autoPublishEnabled,
                'product_review_notification_email' => trim((string) ($data['product_review_notification_email'] ?? '')) ?: null,
                'wishlist_discount_outreach_enabled' => array_key_exists('wishlist_discount_outreach_enabled', $data) ? (bool) $data['wishlist_discount_outreach_enabled'] : false,
                'sms_provider_enabled' => array_key_exists('sms_provider_enabled', $data) ? (bool) $data['sms_provider_enabled'] : false,
                'tenant_branding_tokens' => $brandingTokens,
                'sms_signup_enabled' => array_key_exists('sms_signup_enabled', $data) ? (bool) $data['sms_signup_enabled'] : false,
                'email_signup_enabled' => array_key_exists('email_signup_enabled', $data) ? (bool) $data['email_signup_enabled'] : false,
                'vote_locked_join_url' => trim((string) ($data['vote_locked_join_url'] ?? '')) ?: null,
            ]), $this->displayLabel('rewards_label', 'Rewards') . ' integration settings.', $tenantId);

            $this->syncTenantModuleStates($tenantId, [
                'reviews' => array_key_exists('reviews_enabled', $data) ? (bool) $data['reviews_enabled'] : data_get($existing, 'reviews_enabled', true),
                'wishlist' => array_key_exists('wishlist_enabled', $data) ? (bool) $data['wishlist_enabled'] : data_get($existing, 'wishlist_enabled', true),
            ]);
        }

        return back()->with('toast', ['style' => 'success', 'message' => $this->displayLabel('rewards_label', 'Rewards') . ' settings saved.']);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        return collect(CandleCashSectionRegistry::sections())
            ->map(function (array $section, string $key): array {
                return [
                    'key' => $key,
                    'label' => $section['label'],
                    'href' => route($section['route']),
                    'current' => request()->routeIs($section['route']),
                ];
            })
            ->values()
            ->all();
    }

    private function parseGiftReportDate(?string $value): ?CarbonImmutable
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function validateRedeemPayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'candle_cash_cost' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'reward_value' => ['nullable', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedTaskPayload(Request $request, ?CandleCashTask $task = null): array
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:candle_cash_tasks,handle' . ($task ? ',' . $task->id : '')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'reward_amount' => ['required', 'numeric', 'min:0', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0', 'max:999'],
            'task_type' => ['required', 'string', 'max:80'],
            'verification_mode' => ['required', 'string', 'max:80'],
            'auto_award' => ['nullable', 'boolean'],
            'action_url' => ['nullable', 'string', 'max:500'],
            'button_text' => ['nullable', 'string', 'max:80'],
            'max_completions_per_customer' => ['required', 'integer', 'min:1', 'max:999'],
            'requires_manual_approval' => ['nullable', 'boolean'],
            'requires_customer_submission' => ['nullable', 'boolean'],
            'icon' => ['nullable', 'string', 'max:80'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'eligibility_type' => ['required', 'string', 'max:80'],
            'required_customer_tags' => ['nullable', 'string', 'max:5000'],
            'required_membership_status' => ['nullable', 'string', 'max:120'],
            'visible_to_noneligible_customers' => ['nullable', 'boolean'],
            'locked_message' => ['nullable', 'string', 'max:255'],
            'locked_cta_text' => ['nullable', 'string', 'max:80'],
            'locked_cta_url' => ['nullable', 'string', 'max:500'],
            'campaign_key' => ['nullable', 'string', 'max:160'],
            'external_object_id' => ['nullable', 'string', 'max:160'],
            'verification_window_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'matching_rules' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['nullable', 'string', 'max:10000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'handle' => trim((string) $data['handle']),
            'title' => trim((string) $data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'reward_amount' => (float) $data['reward_amount'],
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : false,
            'display_order' => (int) $data['display_order'],
            'task_type' => trim((string) $data['task_type']),
            'verification_mode' => trim((string) $data['verification_mode']),
            'auto_award' => array_key_exists('auto_award', $data) ? (bool) $data['auto_award'] : false,
            'action_url' => trim((string) ($data['action_url'] ?? '')) ?: null,
            'button_text' => trim((string) ($data['button_text'] ?? '')) ?: null,
            'completion_rule' => $task?->completion_rule,
            'max_completions_per_customer' => (int) $data['max_completions_per_customer'],
            'requires_manual_approval' => array_key_exists('requires_manual_approval', $data) ? (bool) $data['requires_manual_approval'] : false,
            'requires_customer_submission' => array_key_exists('requires_customer_submission', $data) ? (bool) $data['requires_customer_submission'] : false,
            'icon' => trim((string) ($data['icon'] ?? '')) ?: null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'eligibility_type' => trim((string) $data['eligibility_type']),
            'required_customer_tags' => $this->decodeJsonField($data['required_customer_tags'] ?? null),
            'required_membership_status' => trim((string) ($data['required_membership_status'] ?? '')) ?: null,
            'visible_to_noneligible_customers' => array_key_exists('visible_to_noneligible_customers', $data) ? (bool) $data['visible_to_noneligible_customers'] : false,
            'locked_message' => trim((string) ($data['locked_message'] ?? '')) ?: null,
            'locked_cta_text' => trim((string) ($data['locked_cta_text'] ?? '')) ?: null,
            'locked_cta_url' => trim((string) ($data['locked_cta_url'] ?? '')) ?: null,
            'campaign_key' => trim((string) ($data['campaign_key'] ?? '')) ?: null,
            'external_object_id' => trim((string) ($data['external_object_id'] ?? '')) ?: null,
            'verification_window_hours' => array_key_exists('verification_window_hours', $data) && $data['verification_window_hours'] !== null ? (int) $data['verification_window_hours'] : null,
            'matching_rules' => $this->decodeJsonField($data['matching_rules'] ?? null),
            'metadata' => $this->decodeJsonField($data['metadata'] ?? null),
            'admin_notes' => trim((string) ($data['admin_notes'] ?? '')) ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function defaultTaskPayload(): array
    {
        return [
            'handle' => '',
            'title' => '',
            'description' => '',
            'reward_amount' => 1,
            'enabled' => true,
            'display_order' => 999,
            'task_type' => 'system_event',
            'verification_mode' => 'system_event',
            'auto_award' => true,
            'action_url' => '',
            'button_text' => 'Complete task',
            'max_completions_per_customer' => 1,
            'requires_manual_approval' => false,
            'requires_customer_submission' => false,
            'icon' => '',
            'start_date' => null,
            'end_date' => null,
            'eligibility_type' => 'everyone',
            'required_membership_status' => null,
            'required_customer_tags' => null,
            'visible_to_noneligible_customers' => false,
            'locked_message' => '',
            'locked_cta_text' => '',
            'locked_cta_url' => '',
            'campaign_key' => '',
            'external_object_id' => '',
            'verification_window_hours' => 24,
            'matching_rules' => "{\n  \"allow_manual_submit\": false\n}",
            'metadata' => "{\n  \"customer_visible\": true\n}",
            'admin_notes' => '',
        ];
    }

    protected function decodeJsonField(mixed $value): ?array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function settingValue(string $key, ?int $tenantId = null): array
    {
        /** @var TenantMarketingSettingsResolver $resolver */
        $resolver = app(TenantMarketingSettingsResolver::class);

        return $resolver->array($key, $tenantId);
    }

    /**
     * @param array<string,mixed> $value
     */
    protected function saveSetting(string $key, array $value, string $description, ?int $tenantId = null): void
    {
        if ($tenantId !== null && Schema::hasTable('tenant_marketing_settings')) {
            TenantMarketingSetting::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                [
                    'value' => $value,
                    'description' => $description,
                ]
            );

            return;
        }

        MarketingSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'description' => $description,
            ]
        );
    }

    protected function currentTenantId(Request $request): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $tenantId = $request->attributes->get($attribute);
            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                $request->attributes->set('current_tenant_id', (int) $tenantId);

                return (int) $tenantId;
            }
        }

        $sessionTenantId = $request->session()->get('tenant_id');
        if (is_numeric($sessionTenantId) && (int) $sessionTenantId > 0) {
            $request->attributes->set('current_tenant_id', (int) $sessionTenantId);

            return (int) $sessionTenantId;
        }

        $user = $request->user();
        if ($user) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                $resolved = (int) $tenantIds->first();
                $request->attributes->set('current_tenant_id', $resolved);

                return $resolved;
            }
        }

        return null;
    }

    protected function displayLabel(string $key, string $fallback): string
    {
        /** @var TenantDisplayLabelResolver $resolver */
        $resolver = app(TenantDisplayLabelResolver::class);

        return $resolver->label($this->currentTenantId(request()), $key, $fallback);
    }

    protected function reviewIndexQuery(?int $tenantId = null): Builder
    {
        return MarketingReviewHistory::query()
            ->with([
                'profile:id,first_name,last_name,email,tenant_id',
                'tenant:id,name,slug',
                'order:id,tenant_id,shopify_name,order_number,ordered_at,total_price',
                'orderLine:id,order_id,shopify_product_id,shopify_variant_id,raw_title,raw_variant',
                'adminResponder:id,name',
            ])
            ->select('*')
            ->selectSub(
                MarketingReviewHistory::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('marketing_profile_id', 'marketing_review_histories.marketing_profile_id'),
                'customer_review_count'
            )
            ->when($tenantId !== null, fn (Builder $builder) => $builder->where('tenant_id', $tenantId));
    }

    protected function applyReviewQueueFilter(Builder $builder, string $queue): Builder
    {
        return match ($queue) {
            'new_reviews' => $builder->where('submitted_at', '>=', now()->subDays(7)),
            'pending_moderation' => $builder->where('status', 'pending'),
            'reward_exceptions' => $builder->where(function (Builder $query): void {
                $this->applyRewardStatusFilter($query, 'exceptions');
            }),
            default => $builder,
        };
    }

    protected function applyRewardStatusFilter(Builder $builder, string $rewardStatus): Builder
    {
        return match ($rewardStatus) {
            'awarded' => $builder->where('reward_award_status', 'awarded'),
            'eligible' => $builder->whereIn('reward_eligibility_status', ['eligible_verified_purchase', 'eligible_without_order_match']),
            'ineligible' => $builder->whereIn('reward_eligibility_status', ['guest_submitted', 'no_order_match', 'selected_order_not_eligible', 'reward_disabled']),
            'exceptions' => $builder->where(function (Builder $query): void {
                $query->whereIn('reward_eligibility_status', ['reward_already_awarded', 'selected_order_not_eligible', 'no_order_match'])
                    ->orWhereIn('reward_award_status', ['failed', 'error']);
            }),
            default => $builder,
        };
    }

    protected function assertReviewInTenantScope(MarketingReviewHistory $review, Request $request): void
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            return;
        }

        if ((int) ($review->tenant_id ?? 0) !== $tenantId) {
            abort(404);
        }
    }

    /**
     * @param array<string,bool> $states
     */
    protected function syncTenantModuleStates(?int $tenantId, array $states): void
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_module_states')) {
            return;
        }

        foreach ($states as $moduleKey => $enabled) {
            TenantModuleState::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                ],
                [
                    'enabled_override' => $enabled,
                    'setup_status' => $enabled ? 'configured' : 'not_started',
                    'setup_completed_at' => $enabled ? now() : null,
                    'coming_soon_override' => false,
                    'upgrade_prompt_override' => ! $enabled,
                ]
            );
        }
    }
}
