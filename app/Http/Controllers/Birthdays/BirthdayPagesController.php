<?php

namespace App\Http\Controllers\Birthdays;

use App\Http\Controllers\Controller;
use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayAudit;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\MarketingStorefrontEvent;
use App\Services\Marketing\BirthdayCsvImportService;
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Marketing\BirthdayRewardActivationService;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Marketing\CandleCashLegacyCompatibilityService;
use App\Support\Birthdays\BirthdaySectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BirthdayPagesController extends Controller
{
    public function customers(Request $request, BirthdayReportingService $reportingService): View
    {
        return $this->renderCustomersPage($request, $reportingService);
    }

    public function previewImport(
        Request $request,
        BirthdayCsvImportService $importService,
        BirthdayReportingService $reportingService
    ): View {
        $data = $request->validate([
            'import_file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $preview = $importService->storePreviewUpload($data['import_file']);

        return $this->renderCustomersPage($request, $reportingService, [
            'importPreview' => [
                ...$preview,
                'fieldOptions' => $importService->fieldOptions(),
            ],
        ]);
    }

    public function runImport(Request $request, BirthdayCsvImportService $importService): RedirectResponse
    {
        $data = $request->validate([
            'temp_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        $result = $importService->importStoredFile(
            storedPath: (string) $data['temp_path'],
            mapping: array_map(static fn ($value): string => trim((string) $value), (array) $data['mapping']),
            createdBy: auth()->id(),
            dryRun: false,
        );

        $summary = (array) ($result['summary'] ?? []);

        return redirect()
            ->route('birthdays.customers')
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Birthday import complete. %d imported, %d reviewed, %d skipped.',
                    (int) ($summary['imported'] ?? 0),
                    (int) ($summary['reviewed'] ?? 0),
                    (int) ($summary['skipped'] ?? 0)
                ),
            ]);
    }

    public function analytics(BirthdayReportingService $reportingService): View
    {
        $summary = $reportingService->summary();
        $campaignSummary = $reportingService->campaignSummary();

        return view('birthdays/show', [
            'sectionKey' => 'analytics',
            'section' => BirthdaySectionRegistry::section('analytics'),
            'sections' => $this->navigationItems(),
            'summary' => $summary,
            'campaignSummary' => $campaignSummary,
            'rewardSummary' => $reportingService->rewardSummary(),
        ]);
    }

    public function campaigns(BirthdayReportingService $reportingService): View
    {
        return view('birthdays/show', [
            'sectionKey' => 'campaigns',
            'section' => BirthdaySectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaignSummary' => $reportingService->campaignSummary(),
            'campaignConfig' => $this->campaignConfig(),
        ]);
    }

    public function rewards(BirthdayReportingService $reportingService): View
    {
        return view('birthdays/show', [
            'sectionKey' => 'rewards',
            'section' => BirthdaySectionRegistry::section('rewards'),
            'sections' => $this->navigationItems(),
            'rewardSummary' => $reportingService->rewardSummary(),
            'rewardConfig' => $this->rewardConfig(),
            'rewardIssuances' => BirthdayRewardIssuance::query()
                ->with('marketingProfile:id,first_name,last_name,email,phone', 'birthdayProfile:id,marketing_profile_id')
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function settings(): View
    {
        return view('birthdays/show', [
            'sectionKey' => 'settings',
            'section' => BirthdaySectionRegistry::section('settings'),
            'sections' => $this->navigationItems(),
            'rewardConfig' => $this->rewardConfig(),
            'campaignConfig' => $this->campaignConfig(),
            'captureConfig' => $this->captureConfig(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $scope = trim((string) $request->input('scope', 'reward'));

        if ($scope === 'reward') {
            $existing = $this->rewardConfig();
            $data = $request->validate([
                'enabled' => ['nullable', 'boolean'],
                'reward_type' => ['required', 'in:candle_cash,discount_code,free_shipping'],
                'reward_name' => ['required', 'string', 'max:160'],
                'reward_value' => ['nullable', 'numeric', 'min:0'],
                'candle_cash_amount' => ['nullable', 'integer', 'min:0'],
                'discount_code_prefix' => ['nullable', 'string', 'max:40'],
                'free_shipping_code_prefix' => ['nullable', 'string', 'max:40'],
                'claim_window_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
                'claim_window_days_after' => ['nullable', 'integer', 'min:1', 'max:365'],
            ]);

            MarketingSetting::query()->updateOrCreate(
                ['key' => 'birthday_reward_config'],
                ['value' => [
                    'enabled' => (bool) ($data['enabled'] ?? data_get($existing, 'enabled', false)),
                    'reward_type' => $data['reward_type'],
                    'reward_name' => trim((string) $data['reward_name']),
                    'reward_value' => isset($data['reward_value']) ? (float) $data['reward_value'] : data_get($existing, 'reward_value'),
                    'candle_cash_amount' => isset($data['candle_cash_amount']) ? (int) $data['candle_cash_amount'] : (int) data_get($existing, 'candle_cash_amount', 0),
                    'discount_code_prefix' => trim((string) ($data['discount_code_prefix'] ?? data_get($existing, 'discount_code_prefix', 'BDAY'))) ?: 'BDAY',
                    'free_shipping_code_prefix' => trim((string) ($data['free_shipping_code_prefix'] ?? data_get($existing, 'free_shipping_code_prefix', 'BDAYSHIP'))) ?: 'BDAYSHIP',
                    'claim_window_days_before' => isset($data['claim_window_days_before']) ? (int) $data['claim_window_days_before'] : (int) data_get($existing, 'claim_window_days_before', 0),
                    'claim_window_days_after' => isset($data['claim_window_days_after']) ? (int) $data['claim_window_days_after'] : (int) data_get($existing, 'claim_window_days_after', 14),
                ]]
            );
        } elseif ($scope === 'campaign') {
            $existing = $this->campaignConfig();
            $data = $request->validate([
                'email_enabled' => ['nullable', 'boolean'],
                'sms_enabled' => ['nullable', 'boolean'],
                'birthday_send_offset' => ['nullable', 'integer', 'min:-30', 'max:30'],
                'followup_send_offset' => ['nullable', 'integer', 'min:0', 'max:30'],
                'birthday_email_subject' => ['nullable', 'string', 'max:255'],
                'birthday_email_body' => ['nullable', 'string'],
                'birthday_sms_body' => ['nullable', 'string', 'max:500'],
                'followup_email_subject' => ['nullable', 'string', 'max:255'],
                'followup_email_body' => ['nullable', 'string'],
                'followup_sms_body' => ['nullable', 'string', 'max:500'],
            ]);

            MarketingSetting::query()->updateOrCreate(
                ['key' => 'birthday_campaign_config'],
                ['value' => [
                    'email_enabled' => array_key_exists('email_enabled', $data) ? (bool) $data['email_enabled'] : (bool) data_get($existing, 'email_enabled', false),
                    'sms_enabled' => array_key_exists('sms_enabled', $data) ? (bool) $data['sms_enabled'] : (bool) data_get($existing, 'sms_enabled', false),
                    'birthday_send_offset' => isset($data['birthday_send_offset']) ? (int) $data['birthday_send_offset'] : (int) data_get($existing, 'birthday_send_offset', 0),
                    'followup_send_offset' => isset($data['followup_send_offset']) ? (int) $data['followup_send_offset'] : (int) data_get($existing, 'followup_send_offset', 3),
                    'birthday_email_subject' => trim((string) ($data['birthday_email_subject'] ?? data_get($existing, 'birthday_email_subject', 'Happy Birthday from The Forestry Studio'))),
                    'birthday_email_body' => trim((string) ($data['birthday_email_body'] ?? data_get($existing, 'birthday_email_body', 'Activate your birthday reward and use it on your next order.'))),
                    'birthday_sms_body' => trim((string) ($data['birthday_sms_body'] ?? data_get($existing, 'birthday_sms_body', 'Happy Birthday. Your reward is ready.'))),
                    'followup_email_subject' => trim((string) ($data['followup_email_subject'] ?? data_get($existing, 'followup_email_subject', 'Your birthday reward is still waiting'))),
                    'followup_email_body' => trim((string) ($data['followup_email_body'] ?? data_get($existing, 'followup_email_body', 'Your birthday reward is still available if you want to use it.'))),
                    'followup_sms_body' => trim((string) ($data['followup_sms_body'] ?? data_get($existing, 'followup_sms_body', 'Your birthday reward is still waiting for you.'))),
                ]]
            );
        } else {
            $existing = $this->captureConfig();
            $data = $request->validate([
                'year_optional' => ['nullable', 'boolean'],
                'match_priority' => ['nullable', 'string', 'max:500'],
                'required_fields' => ['nullable', 'string', 'max:500'],
            ]);

            MarketingSetting::query()->updateOrCreate(
                ['key' => 'birthday_capture_config'],
                ['value' => [
                    'year_optional' => array_key_exists('year_optional', $data) ? (bool) $data['year_optional'] : (bool) data_get($existing, 'year_optional', false),
                    'match_priority' => trim((string) ($data['match_priority'] ?? data_get($existing, 'match_priority', 'shopify_customer_id,email,phone,first_name+last_name+birthday'))),
                    'required_fields' => trim((string) ($data['required_fields'] ?? data_get($existing, 'required_fields', 'email,first_name,last_name,birthday'))),
                ]]
            );
        }

        return redirect()
            ->route('birthdays.settings')
            ->with('toast', ['style' => 'success', 'message' => 'Birthday settings saved.']);
    }

    public function activity(): View
    {
        return view('birthdays/show', [
            'sectionKey' => 'activity',
            'section' => BirthdaySectionRegistry::section('activity'),
            'sections' => $this->navigationItems(),
            'recentAudits' => CustomerBirthdayAudit::query()
                ->with('marketingProfile:id,first_name,last_name,email')
                ->latest('id')
                ->paginate(20, ['*'], 'audits_page')
                ->withQueryString(),
            'recentEvents' => BirthdayMessageEvent::query()
                ->with('marketingProfile:id,first_name,last_name,email', 'rewardIssuance:id,reward_code,status')
                ->latest('id')
                ->paginate(20, ['*'], 'events_page')
                ->withQueryString(),
            'recentRewardSignals' => MarketingStorefrontEvent::query()
                ->where('event_type', 'like', 'birthday_reward_%')
                ->latest('id')
                ->paginate(20, ['*'], 'signals_page')
                ->withQueryString(),
            'recentImports' => MarketingImportRun::query()
                ->where('type', 'birthday_customers_import')
                ->latest('id')
                ->limit(15)
                ->get(),
        ]);
    }

    public function issueReward(MarketingProfile $marketingProfile, BirthdayRewardEngineService $engine): RedirectResponse
    {
        $birthdayProfile = $marketingProfile->birthdayProfile;
        if (! $birthdayProfile) {
            return back()->with('toast', ['style' => 'danger', 'message' => 'Birthday is missing for this customer.']);
        }

        $result = $engine->issueAnnualReward($birthdayProfile);
        $message = (bool) ($result['ok'] ?? false)
            ? 'Birthday reward issued.'
            : 'Birthday reward could not be issued: ' . (string) ($result['error'] ?? 'unknown');

        return back()->with('toast', ['style' => (bool) ($result['ok'] ?? false) ? 'success' : 'danger', 'message' => $message]);
    }

    public function activateReward(BirthdayRewardIssuance $issuance, BirthdayRewardActivationService $activationService): RedirectResponse
    {
        $birthdayProfile = $issuance->birthdayProfile;
        if (! $birthdayProfile) {
            return back()->with('toast', ['style' => 'danger', 'message' => 'Birthday reward has no linked profile.']);
        }

        $result = $activationService->activate($issuance, [
            'source_surface' => 'admin',
            'endpoint' => 'birthdays/rewards/activate',
        ]);

        return back()->with('toast', [
            'style' => (bool) ($result['ok'] ?? false) ? 'success' : 'danger',
            'message' => (bool) ($result['ok'] ?? false)
                ? 'Birthday reward activated and synced to Shopify.'
                : 'Birthday reward could not be activated: ' . (string) ($result['error'] ?? 'unknown'),
        ]);
    }

    public function updateRewardStatus(Request $request, BirthdayRewardIssuance $issuance): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:claimed,redeemed,expired,cancelled'],
        ]);

        $updates = ['status' => $data['status']];
        if ($data['status'] === 'claimed' && ! $issuance->claimed_at) {
            $updates['claimed_at'] = now();
            $updates['activated_at'] = $issuance->activated_at ?: now();
        }
        if ($data['status'] === 'redeemed' && ! $issuance->redeemed_at) {
            $updates['redeemed_at'] = now();
            $updates['claimed_at'] = $issuance->claimed_at ?: now();
            $updates['activated_at'] = $issuance->activated_at ?: ($issuance->claimed_at ?: now());
        }

        $issuance->forceFill($updates)->save();

        return back()->with('toast', ['style' => 'success', 'message' => 'Birthday reward status updated.']);
    }

    protected function renderCustomersPage(
        Request $request,
        BirthdayReportingService $reportingService,
        array $extra = []
    ): View {
        $search = trim((string) $request->query('search', ''));
        $month = max(0, (int) $request->query('month', 0));
        $timing = trim((string) $request->query('timing', 'all'));
        $subscription = trim((string) $request->query('subscription', 'all'));
        $rewardStatus = trim((string) $request->query('reward_status', 'all'));
        $source = trim((string) $request->query('source', 'all'));

        $query = CustomerBirthdayProfile::query()
            ->with([
                'marketingProfile.links:id,marketing_profile_id,source_type,source_id',
                'rewardIssuances' => fn ($builder) => $builder->latest('id'),
            ])
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $query) use ($search): void {
                    $query->whereHas('marketingProfile', function (Builder $profileQuery) use ($search): void {
                        $profileQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    })->orWhere('signup_source', 'like', '%' . $search . '%');
                });
            })
            ->when($month >= 1 && $month <= 12, fn (Builder $builder) => $builder->where('birth_month', $month))
            ->when($timing === 'today', fn (Builder $builder) => $builder
                ->where('birth_month', now()->month)
                ->where('birth_day', now()->day))
            ->when($timing === 'this_week', function (Builder $builder): void {
                $dates = collect();
                $cursor = now()->startOfWeek();
                while ($cursor->lte(now()->endOfWeek())) {
                    $dates->push([(int) $cursor->month, (int) $cursor->day]);
                    $cursor = $cursor->copy()->addDay();
                }

                $builder->where(function (Builder $query) use ($dates): void {
                    foreach ($dates as [$monthValue, $dayValue]) {
                        $query->orWhere(function (Builder $dayQuery) use ($monthValue, $dayValue): void {
                            $dayQuery->where('birth_month', $monthValue)->where('birth_day', $dayValue);
                        });
                    }
                });
            })
            ->when($timing === 'this_month', fn (Builder $builder) => $builder->where('birth_month', now()->month))
            ->when($subscription === 'email', fn (Builder $builder) => $builder->where('email_subscribed', true))
            ->when($subscription === 'sms', fn (Builder $builder) => $builder->where('sms_subscribed', true))
            ->when($subscription === 'unsubscribed', fn (Builder $builder) => $builder->where('unsubscribed', true))
            ->when($rewardStatus !== 'all', fn (Builder $builder) => $builder->whereHas('rewardIssuances', fn (Builder $rewardQuery) => $rewardQuery->where('status', $rewardStatus)))
            ->when($source !== 'all', fn (Builder $builder) => $builder->where('signup_source', $source))
            ->orderBy('birth_month')
            ->orderBy('birth_day')
            ->orderByDesc('id');

        $profiles = $query->paginate(25)->withQueryString();

        return view('birthdays/show', array_merge([
            'sectionKey' => 'customers',
            'section' => BirthdaySectionRegistry::section('customers'),
            'sections' => $this->navigationItems(),
            'summary' => $reportingService->summary(),
            'profiles' => $profiles,
            'filters' => [
                'search' => $search,
                'month' => $month,
                'timing' => $timing,
                'subscription' => $subscription,
                'reward_status' => $rewardStatus,
                'source' => $source,
            ],
            'sourceOptions' => CustomerBirthdayProfile::query()
                ->whereNotNull('signup_source')
                ->distinct()
                ->orderBy('signup_source')
                ->pluck('signup_source')
                ->values(),
        ], $extra));
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        return collect(BirthdaySectionRegistry::sections())
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
    protected function rewardConfig(): array
    {
        $config = array_merge(
            [
                'enabled' => true,
                'reward_type' => 'discount_code',
                'reward_name' => 'Birthday Candle Cash',
                'reward_value' => 10.00,
                'candle_cash_amount' => 50,
                'discount_code_prefix' => 'BDAY',
                'free_shipping_code_prefix' => 'BDAYSHIP',
                'claim_window_days_before' => 0,
                'claim_window_days_after' => 14,
            ],
            (array) optional(MarketingSetting::query()->where('key', 'birthday_reward_config')->first())->value
        );

        if (($config['reward_type'] ?? null) === 'points') {
            app(CandleCashLegacyCompatibilityService::class)->record(
                'birthday_reward_config.reward_type',
                'normalization',
                __METHOD__
            );
            $config['reward_type'] = 'candle_cash';
        }

        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    protected function campaignConfig(): array
    {
        return array_merge(
            [
                'email_enabled' => true,
                'sms_enabled' => false,
                'birthday_send_offset' => 0,
                'followup_send_offset' => 3,
                'birthday_email_subject' => 'Happy Birthday from The Forestry Studio',
                'birthday_email_body' => 'Activate your birthday reward and use it on your next order.',
                'birthday_sms_body' => 'Happy Birthday. Your reward is ready.',
                'followup_email_subject' => 'Your birthday reward is still waiting',
                'followup_email_body' => 'Your birthday reward is still available if you want to use it.',
                'followup_sms_body' => 'Your birthday reward is still waiting for you.',
            ],
            (array) optional(MarketingSetting::query()->where('key', 'birthday_campaign_config')->first())->value
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function captureConfig(): array
    {
        return array_merge(
            [
                'year_optional' => true,
                'match_priority' => 'shopify_customer_id,email,phone,first_name+last_name+birthday',
                'required_fields' => 'email,first_name,last_name,birthday',
            ],
            (array) optional(MarketingSetting::query()->where('key', 'birthday_capture_config')->first())->value
        );
    }
}
