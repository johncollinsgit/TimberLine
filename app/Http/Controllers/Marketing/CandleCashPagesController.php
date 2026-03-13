<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashBalance;
use App\Models\CandleCashReferral;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashTaskEligibilityService;
use App\Services\Marketing\CandleCashTaskService;
use App\Support\Marketing\CandleCashSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CandleCashPagesController extends Controller
{
    public function dashboard(CandleCashService $candleCashService): View
    {
        $taskSummary = CandleCashTaskCompletion::query()
            ->selectRaw('count(*) as total_completions')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_approvals")
            ->selectRaw("sum(case when status = 'awarded' then 1 else 0 end) as awarded_completions")
            ->selectRaw("avg(case when status = 'awarded' then reward_amount end) as avg_reward_amount")
            ->first();

        $positivePoints = (int) (CandleCashTransaction::query()
            ->where('points', '>', 0)
            ->sum('points'));

        $topTasks = CandleCashTask::query()
            ->leftJoin('candle_cash_task_completions as completions', 'completions.candle_cash_task_id', '=', 'candle_cash_tasks.id')
            ->select('candle_cash_tasks.*')
            ->selectRaw("sum(case when completions.status = 'awarded' then 1 else 0 end) as awarded_count")
            ->selectRaw("sum(case when completions.status = 'pending' then 1 else 0 end) as pending_count")
            ->groupBy('candle_cash_tasks.id')
            ->orderByDesc('awarded_count')
            ->orderBy('display_order')
            ->limit(6)
            ->get();

        $reviewHandles = ['google-review', 'product-review', 'photo-review'];
        $reviewGenerated = (int) CandleCashTaskCompletion::query()
            ->whereIn('status', ['pending', 'awarded'])
            ->whereHas('task', fn (Builder $builder) => $builder->whereIn('handle', $reviewHandles))
            ->count();

        $activeTasks = CandleCashTask::query()
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->count();

        $recentCompletions = CandleCashTaskCompletion::query()
            ->with(['task:id,title,handle', 'profile:id,first_name,last_name,email'])
            ->latest('id')
            ->limit(12)
            ->get();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'dashboard',
            'section' => CandleCashSectionRegistry::section('dashboard'),
            'sections' => $this->navigationItems(),
            'dashboard' => [
                'total_issued_points' => $positivePoints,
                'total_issued_amount' => $candleCashService->amountFromPoints($positivePoints),
                'pending_approvals' => (int) ($taskSummary?->pending_approvals ?? 0),
                'total_referrals' => (int) CandleCashReferral::query()->count(),
                'active_tasks' => (int) $activeTasks,
                'reviews_generated' => $reviewGenerated,
                'avg_reward_cost' => round((float) ($taskSummary?->avg_reward_amount ?? 0), 2),
                'top_tasks' => $topTasks,
                'recent_completions' => $recentCompletions,
            ],
        ]);
    }

    public function tasks(Request $request): View
    {
        $filter = trim((string) $request->query('filter', 'active'));
        $type = trim((string) $request->query('type', 'all'));

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
            ->withCount([
                'completions as awarded_count' => fn ($builder) => $builder->where('status', 'awarded'),
                'completions as pending_count' => fn ($builder) => $builder->where('status', 'pending'),
                'completions as blocked_count' => fn ($builder) => $builder->where('status', 'blocked'),
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
            ],
            'taskTypes' => CandleCashTask::query()->distinct()->orderBy('task_type')->pluck('task_type')->filter()->values(),
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
        $status = trim((string) $request->query('status', 'pending'));
        $query = CandleCashTaskCompletion::query()
            ->with(['task:id,title,handle,requires_manual_approval,requires_customer_submission', 'profile:id,first_name,last_name,email,phone'])
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $completions = $query->paginate(25)->withQueryString();

        return view('marketing.candle-cash.show', [
            'sectionKey' => 'queue',
            'section' => CandleCashSectionRegistry::section('queue'),
            'sections' => $this->navigationItems(),
            'completionQueue' => $completions,
            'queueStatus' => $status,
            'queueSummary' => [
                'pending' => CandleCashTaskCompletion::query()->where('status', 'pending')->count(),
                'blocked' => CandleCashTaskCompletion::query()->where('status', 'blocked')->count(),
                'rejected' => CandleCashTaskCompletion::query()->where('status', 'rejected')->count(),
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

    public function settings(CandleCashTaskService $taskService): View
    {
        return view('marketing.candle-cash.show', [
            'sectionKey' => 'settings',
            'section' => CandleCashSectionRegistry::section('settings'),
            'sections' => $this->navigationItems(),
            'programConfig' => $taskService->programConfig(),
            'referralConfig' => $taskService->referralConfig(),
            'frontendConfig' => $taskService->frontendConfig(),
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
                'google_review_requires_manual_approval' => ['nullable', 'boolean'],
                'email_signup_auto_award' => ['nullable', 'boolean'],
                'instagram_follow_approval_mode' => ['required', 'in:honor,manual'],
                'birthday_reward_frequency' => ['required', 'in:once_per_year,once_per_lifetime'],
                'homepage_signup_copy' => ['required', 'string', 'max:255'],
                'homepage_central_title' => ['required', 'string', 'max:160'],
                'homepage_central_copy' => ['required', 'string', 'max:255'],
            ]);

            $this->saveSetting('candle_cash_program_config', array_merge($existing, [
                'label' => trim((string) $data['label']),
                'points_per_dollar' => (int) $data['points_per_dollar'],
                'google_review_requires_manual_approval' => array_key_exists('google_review_requires_manual_approval', $data) ? (bool) $data['google_review_requires_manual_approval'] : false,
                'email_signup_auto_award' => array_key_exists('email_signup_auto_award', $data) ? (bool) $data['email_signup_auto_award'] : false,
                'instagram_follow_approval_mode' => $data['instagram_follow_approval_mode'],
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
        } else {
            $existing = $this->settingValue('candle_cash_frontend_config');
            $data = $request->validate([
                'central_title' => ['required', 'string', 'max:160'],
                'central_subtitle' => ['required', 'string', 'max:255'],
                'faq_approval_copy' => ['required', 'string', 'max:255'],
                'faq_stack_copy' => ['required', 'string', 'max:255'],
                'faq_pending_copy' => ['required', 'string', 'max:255'],
            ]);

            $this->saveSetting('candle_cash_frontend_config', array_merge($existing, [
                'central_title' => trim((string) $data['central_title']),
                'central_subtitle' => trim((string) $data['central_subtitle']),
                'faq_approval_copy' => trim((string) $data['faq_approval_copy']),
                'faq_stack_copy' => trim((string) $data['faq_stack_copy']),
                'faq_pending_copy' => trim((string) $data['faq_pending_copy']),
            ]), 'Candle Cash frontend copy settings.');
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
            'action_url' => ['nullable', 'url', 'max:500'],
            'button_text' => ['nullable', 'string', 'max:80'],
            'max_completions_per_customer' => ['required', 'integer', 'min:1', 'max:999'],
            'requires_manual_approval' => ['nullable', 'boolean'],
            'requires_customer_submission' => ['nullable', 'boolean'],
            'icon' => ['nullable', 'string', 'max:80'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'eligibility_type' => ['required', 'string', 'max:80'],
            'required_membership_status' => ['nullable', 'string', 'max:120'],
            'visible_to_noneligible_customers' => ['nullable', 'boolean'],
            'locked_message' => ['nullable', 'string', 'max:255'],
            'locked_cta_text' => ['nullable', 'string', 'max:80'],
            'locked_cta_url' => ['nullable', 'url', 'max:500'],
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
            'required_membership_status' => trim((string) ($data['required_membership_status'] ?? '')) ?: null,
            'visible_to_noneligible_customers' => array_key_exists('visible_to_noneligible_customers', $data) ? (bool) $data['visible_to_noneligible_customers'] : false,
            'locked_message' => trim((string) ($data['locked_message'] ?? '')) ?: null,
            'locked_cta_text' => trim((string) ($data['locked_cta_text'] ?? '')) ?: null,
            'locked_cta_url' => trim((string) ($data['locked_cta_url'] ?? '')) ?: null,
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
            'task_type' => 'external_link',
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
            'visible_to_noneligible_customers' => false,
            'locked_message' => '',
            'locked_cta_text' => '',
            'locked_cta_url' => '',
            'admin_notes' => '',
        ];
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
