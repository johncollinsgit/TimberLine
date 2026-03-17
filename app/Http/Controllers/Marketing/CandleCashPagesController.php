<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashReferral;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\MarketingReviewHistory;
use App\Models\CandleCashTransaction;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashGiftReportService;
use App\Services\Marketing\CandleCashTaskEligibilityService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\ProductReviewService;
use App\Support\Marketing\CandleCashSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

class CandleCashPagesController extends Controller
{
    public function dashboard(CandleCashService $candleCashService): View
    {
        $taskSummary = CandleCashTaskEvent::query()
            ->selectRaw('count(*) as total_events')
            ->selectRaw("sum(case when reward_awarded = 1 then 1 else 0 end) as awarded_events")
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_events")
            ->selectRaw("sum(case when duplicate_hits > 0 then duplicate_hits else 0 end) as duplicate_hits")
            ->first();

        $positivePoints = (int) CandleCashTransaction::query()
            ->where('points', '>', 0)
            ->sum('points');

        $currentOutstandingPoints = (int) CandleCashBalance::query()
            ->where('balance', '>', 0)
            ->sum('balance');

        $activeBalanceHolders = (int) CandleCashBalance::query()
            ->where('balance', '>', 0)
            ->count();

        $redeemedPoints = abs((int) CandleCashTransaction::query()
            ->where('type', 'redeem')
            ->sum('points'));

        $legacyRebasePoints = abs((int) CandleCashTransaction::query()
            ->where('source', 'legacy_rebase')
            ->sum('points'));

        $latestRebaseRun = MarketingImportRun::query()
            ->where('type', 'candle_cash_balance_rebase')
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        $topTasks = CandleCashTask::query()
            ->leftJoin('candle_cash_task_completions as completions', 'completions.candle_cash_task_id', '=', 'candle_cash_tasks.id')
            ->select('candle_cash_tasks.*')
            ->selectRaw("sum(case when completions.status = 'awarded' then 1 else 0 end) as awarded_count")
            ->selectRaw("sum(case when completions.status in ('pending','submitted','started') then 1 else 0 end) as pending_count")
            ->groupBy('candle_cash_tasks.id')
            ->orderByDesc('awarded_count')
            ->orderBy('display_order')
            ->limit(6)
            ->get();

        $reviewHandles = ['google-review', 'product-review'];
        $reviewGenerated = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->whereIn('handle', $reviewHandles))
            ->count();

        $secondOrderRewards = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->where('handle', 'second-order'))
            ->count();

        $googleReviewsMatched = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->where('handle', 'google-review'))
            ->count();

        $productReviewsMatched = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->where('handle', 'product-review'))
            ->count();

        $candleClubParticipation = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->whereIn('handle', ['candle-club-join', 'candle-club-vote']))
            ->count();

        $referralConversions = (int) CandleCashTaskEvent::query()
            ->where('reward_awarded', true)
            ->whereHas('task', fn (Builder $builder) => $builder->whereIn('handle', ['refer-a-friend', 'referred-friend-bonus']))
            ->count();

        $avgRewardAmount = (float) CandleCashTaskCompletion::query()
            ->where('status', 'awarded')
            ->avg('reward_amount');

        $activeTasksCount = CandleCashTask::query()
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->count();

        $activeTasks = CandleCashTask::query()
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->limit(8)
            ->get(['id', 'title', 'handle', 'verification_mode', 'reward_amount']);

        $recentCompletions = CandleCashTaskEvent::query()
            ->with(['task:id,title,handle', 'profile:id,first_name,last_name,email'])
            ->latest('id')
            ->limit(12)
            ->get();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'dashboard',
            'section' => CandleCashSectionRegistry::section('dashboard'),
            'sections' => $this->navigationItems(),
            'dashboard' => [
                'points_per_dollar' => $candleCashService->pointsPerDollar(),
                'current_outstanding_points' => $currentOutstandingPoints,
                'current_outstanding_amount' => $candleCashService->amountFromPoints($currentOutstandingPoints),
                'active_balance_holders' => $activeBalanceHolders,
                'total_issued_points' => $positivePoints,
                'total_issued_amount' => $candleCashService->amountFromPoints($positivePoints),
                'lifetime_redeemed_points' => $redeemedPoints,
                'lifetime_redeemed_amount' => $candleCashService->amountFromPoints($redeemedPoints),
                'legacy_rebase_points' => $legacyRebasePoints,
                'legacy_rebase_amount' => $candleCashService->amountFromPoints($legacyRebasePoints),
                'latest_rebase_run' => $latestRebaseRun,
                'pending_events' => (int) ($taskSummary?->pending_events ?? 0),
                'total_referrals' => (int) CandleCashReferral::query()->count(),
                'referral_conversions' => $referralConversions,
                'active_tasks' => (int) $activeTasksCount,
                'reviews_generated' => $reviewGenerated,
                'google_reviews_matched' => $googleReviewsMatched,
                'product_reviews_matched' => $productReviewsMatched,
                'second_order_rewards' => $secondOrderRewards,
                'candle_club_participation' => $candleClubParticipation,
                'duplicate_hits' => (int) ($taskSummary?->duplicate_hits ?? 0),
                'avg_reward_cost' => round($avgRewardAmount, 2),
                'top_tasks' => $topTasks,
                'active_task_rows' => $activeTasks,
                'recent_completions' => $recentCompletions,
            ],
        ]);
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
            ->with('toast', ['style' => 'success', 'message' => 'Candle Cash task created: ' . $task->title]);
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

    public function queue(Request $request): View
    {
        $status = trim((string) $request->query('status', 'all'));
        $query = CandleCashTaskEvent::query()
            ->with(['task:id,title,handle,verification_mode,auto_award', 'profile:id,first_name,last_name,email,phone', 'completion:id,status,review_notes'])
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

        return back()->with('toast', ['style' => 'success', 'message' => 'Task approved and Candle Cash credited.']);
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
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'all'));
        $rating = trim((string) $request->query('rating', 'all'));
        $source = trim((string) $request->query('source', 'all'));
        $selectedId = (int) $request->query('review', 0);

        $reviews = MarketingReviewHistory::query()
            ->with('profile:id,first_name,last_name,email')
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $query) use ($search): void {
                    $query->where('product_title', 'like', '%' . $search . '%')
                        ->orWhere('product_handle', 'like', '%' . $search . '%')
                        ->orWhere('reviewer_name', 'like', '%' . $search . '%')
                        ->orWhere('reviewer_email', 'like', '%' . $search . '%')
                        ->orWhere('body', 'like', '%' . $search . '%')
                        ->orWhere('title', 'like', '%' . $search . '%');
                });
            })
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
            ->when($rating !== 'all', fn (Builder $builder) => $builder->where('rating', (int) $rating))
            ->when($source === 'imported', fn (Builder $builder) => $builder->where('submission_source', 'growave_import'))
            ->when($source === 'native', fn (Builder $builder) => $builder->where('submission_source', '!=', 'growave_import'))
            ->orderByDesc('approved_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $selectedReview = $selectedId > 0
            ? MarketingReviewHistory::query()->with('profile:id,first_name,last_name,email')->find($selectedId)
            : $reviews->first();

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
            ],
            'selectedReview' => $selectedReview,
            'reviewSummary' => [
                'all' => MarketingReviewHistory::query()->count(),
                'approved' => MarketingReviewHistory::query()->where('status', 'approved')->count(),
                'pending' => MarketingReviewHistory::query()->where('status', 'pending')->count(),
                'rejected' => MarketingReviewHistory::query()->where('status', 'rejected')->count(),
                'imported' => MarketingReviewHistory::query()->where('submission_source', 'growave_import')->count(),
            ],
        ]);
    }

    public function approveReview(
        Request $request,
        MarketingReviewHistory $review,
        ProductReviewService $productReviewService
    ): RedirectResponse {
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
        $data = $request->validate([
            'moderation_notes' => ['required', 'string', 'max:2000'],
        ]);

        $productReviewService->reject($review, auth()->id(), $data['moderation_notes']);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review rejected.']);
    }

    public function deleteReview(MarketingReviewHistory $review, ProductReviewService $productReviewService): RedirectResponse
    {
        $productReviewService->delete($review);

        return back()->with('toast', ['style' => 'success', 'message' => 'Review deleted.']);
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
                'balance_points' => $candleCashService->currentBalance($selectedProfile),
                'balance_amount' => $candleCashService->amountFromPoints($candleCashService->currentBalance($selectedProfile)),
                'lifetime_earned_points' => (int) $selectedProfile->candleCashTransactions->where('points', '>', 0)->sum('points'),
                'lifetime_redeemed_points' => abs((int) $selectedProfile->candleCashTransactions->where('points', '<', 0)->sum('points')),
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
                return back()->with('toast', ['style' => 'danger', 'message' => 'Cannot deduct more Candle Cash than the current balance.']);
            }
        }

        $candleCashService->addPoints(
            profile: $marketingProfile,
            points: $points,
            type: $points >= 0 ? 'earn' : 'adjustment',
            source: 'admin_adjustment',
            sourceId: 'profile:' . $marketingProfile->id . ':user:' . auth()->id() . ':' . now()->timestamp,
            description: trim((string) ($data['note'] ?? '')) ?: 'Manual Candle Cash adjustment'
        );

        return back()->with('toast', ['style' => 'success', 'message' => 'Candle Cash adjusted.']);
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
        return view('marketing.candle-cash.show', [
            'sectionKey' => 'settings',
            'section' => CandleCashSectionRegistry::section('settings'),
            'sections' => $this->navigationItems(),
            'programConfig' => $taskService->programConfig(),
            'referralConfig' => $taskService->referralConfig(),
            'frontendConfig' => $taskService->frontendConfig(),
            'integrationConfig' => $taskService->integrationConfig(),
            'googleBusinessStatus' => $googleBusinessConnectionService->status(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $scope = trim((string) $request->input('scope', 'program'));

        if ($scope === 'program') {
            $existing = $this->settingValue('candle_cash_program_config');
            $data = $request->validate([
                'label' => ['required', 'string', 'max:120'],
                'points_per_dollar' => ['required', 'integer', 'min:1', 'max:100'],
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
                'points_per_dollar' => (int) $data['points_per_dollar'],
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
            ]), 'Candle Cash program settings.');
        } elseif ($scope === 'referral') {
            $existing = $this->settingValue('candle_cash_referral_config');
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
            ]), 'Candle Cash referral settings.');
        } elseif ($scope === 'frontend') {
            $existing = $this->settingValue('candle_cash_frontend_config');
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
            ]), 'Candle Cash frontend copy settings.');
        } else {
            $existing = $this->settingValue('candle_cash_integration_config');
            $data = $request->validate([
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
                'product_review_notification_email' => ['nullable', 'email', 'max:255'],
                'sms_signup_enabled' => ['nullable', 'boolean'],
                'email_signup_enabled' => ['nullable', 'boolean'],
                'vote_locked_join_url' => ['nullable', 'string', 'max:500'],
            ]);

            $this->saveSetting('candle_cash_integration_config', array_merge($existing, [
                'google_review_enabled' => array_key_exists('google_review_enabled', $data) ? (bool) $data['google_review_enabled'] : false,
                'google_review_url' => trim((string) ($data['google_review_url'] ?? '')) ?: null,
                'google_business_location_id' => trim((string) ($data['google_business_location_id'] ?? '')) ?: null,
                'google_review_matching_strategy' => trim((string) $data['google_review_matching_strategy']),
                'product_review_enabled' => array_key_exists('product_review_enabled', $data) ? (bool) $data['product_review_enabled'] : false,
                'product_review_platform' => trim((string) ($data['product_review_platform'] ?? '')) ?: null,
                'product_review_matching_strategy' => trim((string) $data['product_review_matching_strategy']),
                'product_review_moderation_enabled' => array_key_exists('product_review_moderation_enabled', $data) ? (bool) $data['product_review_moderation_enabled'] : false,
                'product_review_allow_guest' => array_key_exists('product_review_allow_guest', $data) ? (bool) $data['product_review_allow_guest'] : false,
                'product_review_min_length' => (int) $data['product_review_min_length'],
                'product_review_notification_email' => trim((string) ($data['product_review_notification_email'] ?? '')) ?: null,
                'sms_signup_enabled' => array_key_exists('sms_signup_enabled', $data) ? (bool) $data['sms_signup_enabled'] : false,
                'email_signup_enabled' => array_key_exists('email_signup_enabled', $data) ? (bool) $data['email_signup_enabled'] : false,
                'vote_locked_join_url' => trim((string) ($data['vote_locked_join_url'] ?? '')) ?: null,
            ]), 'Candle Cash integration settings.');
        }

        return back()->with('toast', ['style' => 'success', 'message' => 'Candle Cash settings saved.']);
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
    protected function settingValue(string $key): array
    {
        return (array) optional(MarketingSetting::query()->where('key', $key)->first())->value;
    }

    /**
     * @param array<string,mixed> $value
     */
    protected function saveSetting(string $key, array $value, string $description): void
    {
        MarketingSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'description' => $description,
            ]
        );
    }
}
