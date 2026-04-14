<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\MarketingSetting;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Tenancy\TenantHostBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class TenantRewardsOperationsService
{
    public const OPERATIONS_KEY = 'candle_cash_operations_config';

    public const TEAM_ACCESS_KEY = 'candle_cash_team_access_config';

    public const AUTOMATION_STATE_KEY = 'candle_cash_automation_state';

    public function __construct(
        protected TenantMarketingSettingsResolver $settingsResolver,
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected CandleCashLedgerNormalizationService $normalizer,
        protected CandleCashEarnedAnalyticsService $earnedAnalyticsService,
        protected TenantRewardsReminderScheduleService $scheduleService,
        protected TenantRewardsReminderLogService $reminderLogService,
        protected TenantRewardsFinanceSummaryService $financeSummaryService,
        protected SendGridEmailService $sendGridEmailService,
        protected LandlordCommercialConfigService $commercialConfigService,
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function currentConfig(?int $tenantId): array
    {
        $operations = $this->settingsResolver->array(self::OPERATIONS_KEY, $tenantId, []);
        $teamAccess = $this->settingsResolver->array(self::TEAM_ACCESS_KEY, $tenantId, []);

        return [
            'automation_and_reporting' => $this->normalizeOperationsConfig(is_array($operations) ? $operations : []),
            'team_access' => $this->normalizeTeamAccess(is_array($teamAccess) ? $teamAccess : []),
        ];
    }

    /**
     * @param  array<string,mixed>  $operations
     * @param  array<string,mixed>  $teamAccess
     */
    public function persistConfig(int $tenantId, array $operations, array $teamAccess): void
    {
        $this->saveSetting(
            tenantId: $tenantId,
            key: self::OPERATIONS_KEY,
            value: $this->normalizeOperationsConfig($operations),
            description: 'Tenant rewards automation, alerts, and scheduled report settings.'
        );
        $this->saveSetting(
            tenantId: $tenantId,
            key: self::TEAM_ACCESS_KEY,
            value: $this->normalizeTeamAccess($teamAccess),
            description: 'Tenant rewards team-access and publish permission settings.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimeState(?int $tenantId): array
    {
        $state = $this->settingsResolver->array(self::AUTOMATION_STATE_KEY, $tenantId, []);

        return $this->normalizeRuntimeState(is_array($state) ? $state : []);
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function storeRuntimeState(int $tenantId, array $state): array
    {
        $normalized = $this->normalizeRuntimeState($state);

        $this->saveSetting(
            tenantId: $tenantId,
            key: self::AUTOMATION_STATE_KEY,
            value: $normalized,
            description: 'Tenant rewards automation runtime health and delivery state.'
        );
        $this->settingsResolver->flushArrayCache();

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function resolvedPayload(int $tenantId, array $policy, array $context = []): array
    {
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();
        $runtime = $this->runtimeState($tenantId);
        $operations = (array) ($policy['automation_and_reporting'] ?? $this->currentConfig($tenantId)['automation_and_reporting']);
        $teamAccess = (array) ($policy['team_access'] ?? $this->currentConfig($tenantId)['team_access']);
        $readiness = is_array($context['readiness'] ?? null) ? (array) $context['readiness'] : [];
        $reporting = is_array($context['reminder_reporting'] ?? null) ? (array) $context['reminder_reporting'] : [];
        $financeSummary = is_array($context['finance_summary'] ?? null) ? (array) $context['finance_summary'] : [];
        $tenant = Tenant::query()->find($tenantId);
        $usage = $tenant instanceof Tenant
            ? $this->commercialConfigService->tenantUsageSummary($tenant, false)
            : ['metrics' => [], 'included_limits' => []];
        $rewardsModule = $this->moduleAccessResolver->module($tenantId, 'rewards');

        $permissions = $this->permissionSummary(
            tenantId: $tenantId,
            teamAccess: $teamAccess,
            user: $context['actor_user'] ?? null
        );
        $automation = $this->automationStatus(
            tenantId: $tenantId,
            policy: $policy,
            operations: $operations,
            readiness: $readiness,
            runtime: $runtime,
            now: $now
        );
        $alerts = $this->alerts(
            policy: $policy,
            operations: $operations,
            readiness: $readiness,
            reminderReporting: $reporting,
            financeSummary: $financeSummary,
            automation: $automation,
            now: $now
        );
        $usageIndicators = $this->usageIndicators($usage, (bool) ($rewardsModule['has_access'] ?? false));
        $simulation = $this->simulationView($tenantId, $policy, [
            'now' => $now,
            'finance_summary' => $financeSummary,
        ]);

        return [
            'automation_and_reporting' => $operations,
            'team_access' => $teamAccess,
            'automation' => $automation,
            'alerts' => $alerts,
            'permissions' => $permissions,
            'usage_indicators' => $usageIndicators,
            'simulation_view' => $simulation,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function automationDecision(int $tenantId, array $policy, array $context = []): array
    {
        $operations = (array) ($policy['automation_and_reporting'] ?? $this->currentConfig($tenantId)['automation_and_reporting']);
        $readiness = is_array($context['readiness'] ?? null) ? (array) $context['readiness'] : [];
        $runtime = $this->runtimeState($tenantId);
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();

        return $this->automationStatus(
            tenantId: $tenantId,
            policy: $policy,
            operations: $operations,
            readiness: $readiness,
            runtime: $runtime,
            now: $now
        );
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function recordAutomationRun(int $tenantId, array $policy, array $result, array $context = []): array
    {
        $state = $this->runtimeState($tenantId);
        $summary = (array) ($result['summary'] ?? []);
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();
        $hadFailures = (int) ($summary['failed_count'] ?? 0) > 0;
        $status = $hadFailures ? 'warning' : 'success';

        $updated = [
            ...$state,
            'last_run_at' => $now->toIso8601String(),
            'last_status' => $status,
            'last_success_at' => $status === 'success'
                ? $now->toIso8601String()
                : ($state['last_success_at'] ?? null),
            'last_failure_at' => $hadFailures
                ? $now->toIso8601String()
                : ($state['last_failure_at'] ?? null),
            'failure_count' => $status === 'success'
                ? 0
                : max(1, (int) ($state['failure_count'] ?? 0) + 1),
            'last_error_message' => $hadFailures
                ? sprintf('%d reminder deliveries failed during the last automation run.', (int) ($summary['failed_count'] ?? 0))
                : null,
            'last_summary' => [
                'policy_version' => max(0, (int) ($result['policy_version'] ?? data_get($policy, 'versioning.current_version', 0))),
                'due_count' => (int) ($summary['due_count'] ?? 0),
                'sent_count' => (int) ($summary['sent_count'] ?? 0),
                'failed_count' => (int) ($summary['failed_count'] ?? 0),
                'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
                'upcoming_count' => (int) ($summary['upcoming_count'] ?? 0),
                'dry_run' => (bool) ($summary['dry_run'] ?? false),
            ],
        ];

        return $this->storeRuntimeState($tenantId, $updated);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function recordAutomationFailure(int $tenantId, string $message, array $context = []): array
    {
        $state = $this->runtimeState($tenantId);
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();

        return $this->storeRuntimeState($tenantId, [
            ...$state,
            'last_run_at' => $now->toIso8601String(),
            'last_status' => 'error',
            'last_failure_at' => $now->toIso8601String(),
            'failure_count' => max(1, (int) ($state['failure_count'] ?? 0) + 1),
            'last_error_message' => trim($message) !== '' ? trim($message) : 'Automation run failed.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function maybeSendAlertEmail(int $tenantId, array $policy, array $context = []): array
    {
        $operations = (array) ($policy['automation_and_reporting'] ?? $this->currentConfig($tenantId)['automation_and_reporting']);
        $alertEmailEnabled = (bool) ($operations['alert_email_enabled'] ?? false);
        $toEmail = trim((string) ($operations['alert_email'] ?? ''));
        if (! $alertEmailEnabled || $toEmail === '') {
            return ['status' => 'disabled'];
        }

        $runtime = $this->runtimeState($tenantId);
        $alerts = is_array($context['alerts'] ?? null)
            ? (array) $context['alerts']
            : [];
        if ($alerts === []) {
            return ['status' => 'no_alerts'];
        }

        $signature = sha1(json_encode(array_map(
            fn (array $row): array => [
                'code' => (string) ($row['code'] ?? ''),
                'level' => (string) ($row['level'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ],
            $alerts
        )) ?: 'alerts');

        $lastAlertSignature = trim((string) ($runtime['last_alert_signature'] ?? ''));
        $lastAlertSentAt = $this->asDate($runtime['last_alert_sent_at'] ?? null);
        if ($lastAlertSignature === $signature
            && $lastAlertSentAt instanceof CarbonImmutable
            && $lastAlertSentAt->greaterThan(now()->subDay()->toImmutable())) {
            return ['status' => 'deduped'];
        }

        $subject = sprintf(
            '%s rewards automation needs attention',
            (string) data_get($policy, 'program_identity.program_name', 'Rewards')
        );

        $bodyLines = [
            'Rewards automation surfaced items that need attention.',
            '',
            'Program: '.(string) data_get($policy, 'program_identity.program_name', 'Rewards'),
            'Current live version: v'.max(0, (int) data_get($policy, 'versioning.current_version', 0)),
            'Last automation run: '.$this->displayDate($runtime['last_run_at'] ?? null),
            '',
            'Current alerts:',
        ];

        foreach ($alerts as $alert) {
            $bodyLines[] = '- '.trim((string) ($alert['message'] ?? 'Alert'));
        }

        $result = $this->sendGridEmailService->sendEmail(
            $toEmail,
            $subject,
            implode("\n", $bodyLines),
            [
                'tenant_id' => $tenantId,
                'campaign_type' => 'rewards_automation_alert',
                'template_key' => 'tenant_rewards_automation_alert',
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'alert_codes' => array_values(array_filter(array_map(fn (array $row): string => (string) ($row['code'] ?? ''), $alerts))),
                ],
            ]
        );

        if ((bool) ($result['success'] ?? false)) {
            $this->storeRuntimeState($tenantId, [
                ...$runtime,
                'last_alert_signature' => $signature,
                'last_alert_sent_at' => now()->toIso8601String(),
            ]);
        }

        return [
            'status' => (bool) ($result['success'] ?? false) ? 'sent' : 'failed',
            'result' => $result,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function sendScheduledFinanceReport(int $tenantId, array $policy, array $context = []): array
    {
        $operations = (array) ($policy['automation_and_reporting'] ?? $this->currentConfig($tenantId)['automation_and_reporting']);
        $frequency = (string) ($operations['report_frequency'] ?? 'off');
        $toEmail = trim((string) ($operations['report_email'] ?? ''));
        $deliveryMode = (string) ($operations['report_delivery_mode'] ?? 'email_link');
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();
        $force = (bool) ($context['force'] ?? false);
        $dryRun = (bool) ($context['dry_run'] ?? false);

        if ($frequency === 'off' || $toEmail === '') {
            return ['status' => 'disabled'];
        }

        $runtime = $this->runtimeState($tenantId);
        $lastSentAt = $this->asDate($runtime['last_report_sent_at'] ?? null);

        if (! $force && ! $this->financeReportIsDue($frequency, (string) ($operations['report_day_of_week'] ?? 'monday'), $now, $lastSentAt)) {
            return ['status' => 'not_due'];
        }

        $dateRange = $this->financeReportDateRange($frequency, $now);
        $financeSummary = $this->financeSummaryService->summaryForTenant($tenantId, $policy, [
            'now' => $now->toIso8601String(),
            'expiring_soon_days' => 14,
            'policy_version' => max(0, (int) data_get($policy, 'versioning.current_version', 0)),
        ]);

        $links = [
            'Finance snapshot' => $this->signedExportUrl($tenantId, 'finance_summary', $dateRange),
            'Reward issuance' => $this->signedExportUrl($tenantId, 'reward_issuance', $dateRange),
            'Reward redemption' => $this->signedExportUrl($tenantId, 'reward_redemption', $dateRange),
            'Expiring rewards' => $this->signedExportUrl($tenantId, 'expiring_rewards', $dateRange),
        ];

        $bodyLines = [
            'Rewards finance report',
            '',
            'Program: '.(string) data_get($policy, 'program_identity.program_name', 'Rewards'),
            'Reporting period: '.($dateRange['label'] ?? 'recent activity'),
            '',
            'Outstanding liability: '.(string) data_get($financeSummary, 'outstanding_liability.formatted_amount', '$0.00'),
            'Rewards issued: '.(string) data_get($financeSummary, 'issued.formatted_amount', '$0.00'),
            'Realized discounts: '.(string) data_get($financeSummary, 'realized_discount_value.formatted_amount', '$0.00'),
            'Expiring soon: '.(string) data_get($financeSummary, 'expiring_soon.formatted_amount', '$0.00'),
            '',
            $deliveryMode === 'download_link'
                ? 'Download links for the latest rewards finance exports:'
                : 'Rewards finance export links:',
            '',
        ];

        foreach ($links as $label => $url) {
            $bodyLines[] = $label.': '.$url;
        }

        $result = $this->sendGridEmailService->sendEmail(
            $toEmail,
            sprintf('%s rewards finance report', (string) data_get($policy, 'program_identity.program_name', 'Rewards')),
            implode("\n", $bodyLines),
            [
                'tenant_id' => $tenantId,
                'campaign_type' => 'rewards_finance_report',
                'template_key' => 'tenant_rewards_finance_report',
                'dry_run' => $dryRun,
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'date_from' => $dateRange['date_from'] ?? null,
                    'date_to' => $dateRange['date_to'] ?? null,
                    'report_frequency' => $frequency,
                ],
            ]
        );

        if (! $dryRun && (bool) ($result['success'] ?? false)) {
            $this->storeRuntimeState($tenantId, [
                ...$runtime,
                'last_report_sent_at' => $now->toIso8601String(),
                'last_report_status' => 'sent',
                'last_report_frequency' => $frequency,
            ]);
        }

        return [
            'status' => $dryRun
                ? ((bool) ($result['success'] ?? false) ? 'preview_ready' : 'preview_failed')
                : ((bool) ($result['success'] ?? false) ? 'sent' : 'failed'),
            'result' => $result,
            'date_range' => $dateRange,
        ];
    }

    /**
     * @param  array<string,mixed>  $teamAccess
     * @return array<string,mixed>
     */
    public function permissionSummary(int $tenantId, array $teamAccess, mixed $user = null): array
    {
        $currentUser = $user instanceof User ? $user : null;
        $tenantRole = $currentUser instanceof User ? $this->tenantRole($currentUser, $tenantId) : null;
        $hasUserContext = $currentUser instanceof User;

        $actions = [
            'edit' => [
                'label' => 'Who can edit program settings',
                'required_role' => (string) ($teamAccess['edit_role'] ?? 'manager_or_admin'),
            ],
            'publish' => [
                'label' => 'Who can publish live changes',
                'required_role' => (string) ($teamAccess['publish_role'] ?? 'manager_or_admin'),
            ],
            'support' => [
                'label' => 'Who can use reminder support tools',
                'required_role' => (string) ($teamAccess['support_role'] ?? 'marketing_manager_or_admin'),
            ],
            'automation' => [
                'label' => 'Who can switch automation mode',
                'required_role' => (string) ($teamAccess['automation_role'] ?? 'manager_or_admin'),
            ],
        ];

        foreach ($actions as $key => $row) {
            $actions[$key] = [
                ...$row,
                'required_role_label' => $this->permissionLabel((string) ($row['required_role'] ?? 'manager_or_admin')),
                'allowed' => ! $hasUserContext
                    ? true
                    : $this->userMatchesPermission($currentUser, $tenantRole, (string) ($row['required_role'] ?? 'manager_or_admin')),
            ];
        }

        return [
            'mode' => $hasUserContext ? 'app_user' : 'shopify_admin_fallback',
            'current_user_role' => $hasUserContext ? ($tenantRole ?? strtolower(trim((string) $currentUser->role))) : null,
            'current_user_label' => $hasUserContext
                ? trim((string) $currentUser->name)
                : 'Shopify admin session',
            'actions' => $actions,
            'headline' => $hasUserContext
                ? 'Team access is enforced from the current signed-in user role.'
                : 'Shopify admin access stays available. Team-role restrictions apply when a Backstage user is signed in.',
        ];
    }

    public function userCan(int $tenantId, array $policy, string $action, mixed $user = null): bool
    {
        $teamAccess = (array) ($policy['team_access'] ?? $this->currentConfig($tenantId)['team_access']);
        $summary = $this->permissionSummary($tenantId, $teamAccess, $user);
        $row = is_array($summary['actions'][$action] ?? null) ? (array) $summary['actions'][$action] : null;

        return $row === null ? true : (bool) ($row['allowed'] ?? true);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function simulationView(int $tenantId, array $policy, array $options = []): array
    {
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();
        $currentRewardValue = round((float) data_get($policy, 'earning_rules.second_order_reward_amount', 0), 2);
        $currentExpirationDays = max(1, (int) data_get($policy, 'expiration_and_reminders.expiration_days', 90));
        $scenarioRewardValue = round(max(0, (float) ($options['scenario_reward_value'] ?? ($currentRewardValue + 5))), 2);
        $scenarioExpirationDays = max(1, (int) ($options['scenario_expiration_days'] ?? max(14, $currentExpirationDays - 30)));

        $recentOrderEarnCount = CandleCashTransaction::query()
            ->whereHas('profile', fn ($query) => $query->where('marketing_profiles.tenant_id', $tenantId))
            ->where('candle_cash_delta', '>', 0)
            ->where('created_at', '>=', $now->subDays(30))
            ->get()
            ->filter(fn (CandleCashTransaction $transaction): bool => $this->normalizer->isEarnedLimitEligible($transaction))
            ->filter(fn (CandleCashTransaction $transaction): bool => $this->normalizer->classifyEarnSource($transaction) === 'order_purchase_earn')
            ->count();

        $estimatedCostImpact = round($recentOrderEarnCount * ($scenarioRewardValue - $currentRewardValue), 2);

        $scenarioPolicy = $policy;
        data_set($scenarioPolicy, 'earning_rules.second_order_reward_amount', $scenarioRewardValue);
        data_set($scenarioPolicy, 'expiration_and_reminders.expiration_days', $scenarioExpirationDays);

        $currentProjection = $this->simulationProjection($tenantId, $policy, $now);
        $scenarioProjection = $this->simulationProjection($tenantId, $scenarioPolicy, $now);

        return [
            'headline' => 'What happens if these settings change?',
            'current' => [
                'reward_value' => $currentRewardValue,
                'expiration_days' => $currentExpirationDays,
                'estimated_reminder_volume' => $currentProjection['reminder_volume'],
                'estimated_expiring_rewards' => $currentProjection['expiring_value'],
            ],
            'scenario' => [
                'reward_value' => $scenarioRewardValue,
                'expiration_days' => $scenarioExpirationDays,
                'estimated_reminder_volume' => $scenarioProjection['reminder_volume'],
                'estimated_expiring_rewards' => $scenarioProjection['expiring_value'],
            ],
            'estimated_cost_impact' => [
                'value' => $estimatedCostImpact,
                'formatted_value' => '$'.number_format($estimatedCostImpact, 2),
                'detail' => sprintf(
                    'Estimated from %d recent order-linked reward issuances in the last 30 days.',
                    $recentOrderEarnCount
                ),
            ],
            'messages' => [
                sprintf(
                    'If the second-order reward moves from $%0.2f to $%0.2f, recent issued-value exposure would move by about %s over 30 days.',
                    $currentRewardValue,
                    $scenarioRewardValue,
                    '$'.number_format($estimatedCostImpact, 2)
                ),
                sprintf(
                    'If expiration moves from %d days to %d days, near-term reminder volume would shift from %d to %d.',
                    $currentExpirationDays,
                    $scenarioExpirationDays,
                    $currentProjection['reminder_volume'],
                    $scenarioProjection['reminder_volume']
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function normalizeOperationsConfig(array $input): array
    {
        return [
            'automation_mode' => $this->normalizeAutomationMode($input['automation_mode'] ?? null),
            'alert_email_enabled' => (bool) ($input['alert_email_enabled'] ?? false),
            'alert_email' => $this->nullableEmail($input['alert_email'] ?? null),
            'alert_no_sends_hours' => max(1, min(168, (int) ($input['alert_no_sends_hours'] ?? 24))),
            'alert_high_skip_rate_percent' => max(10, min(100, (int) ($input['alert_high_skip_rate_percent'] ?? 50))),
            'alert_failure_spike_count' => max(1, min(100, (int) ($input['alert_failure_spike_count'] ?? 5))),
            'report_frequency' => $this->enumOrDefault($input['report_frequency'] ?? null, ['off', 'daily', 'weekly'], 'off'),
            'report_delivery_mode' => $this->enumOrDefault($input['report_delivery_mode'] ?? null, ['email_link', 'download_link'], 'email_link'),
            'report_email' => $this->nullableEmail($input['report_email'] ?? null),
            'report_day_of_week' => $this->enumOrDefault(
                $input['report_day_of_week'] ?? null,
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                'monday'
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function normalizeTeamAccess(array $input): array
    {
        return [
            'edit_role' => $this->enumOrDefault(
                $input['edit_role'] ?? null,
                ['tenant_member', 'marketing_manager_or_admin', 'manager_or_admin', 'admin_only'],
                'manager_or_admin'
            ),
            'publish_role' => $this->enumOrDefault(
                $input['publish_role'] ?? null,
                ['tenant_member', 'marketing_manager_or_admin', 'manager_or_admin', 'admin_only'],
                'manager_or_admin'
            ),
            'support_role' => $this->enumOrDefault(
                $input['support_role'] ?? null,
                ['tenant_member', 'marketing_manager_or_admin', 'manager_or_admin', 'admin_only'],
                'marketing_manager_or_admin'
            ),
            'automation_role' => $this->enumOrDefault(
                $input['automation_role'] ?? null,
                ['tenant_member', 'marketing_manager_or_admin', 'manager_or_admin', 'admin_only'],
                'manager_or_admin'
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    protected function normalizeRuntimeState(array $runtime): array
    {
        return [
            'last_run_at' => $this->asDate($runtime['last_run_at'] ?? null)?->toIso8601String(),
            'last_success_at' => $this->asDate($runtime['last_success_at'] ?? null)?->toIso8601String(),
            'last_failure_at' => $this->asDate($runtime['last_failure_at'] ?? null)?->toIso8601String(),
            'last_status' => $this->enumOrDefault($runtime['last_status'] ?? null, ['idle', 'success', 'warning', 'error', 'skipped'], 'idle'),
            'failure_count' => max(0, (int) ($runtime['failure_count'] ?? 0)),
            'last_error_message' => $this->nullableString($runtime['last_error_message'] ?? null),
            'last_summary' => is_array($runtime['last_summary'] ?? null) ? (array) $runtime['last_summary'] : [],
            'last_alert_signature' => $this->nullableString($runtime['last_alert_signature'] ?? null),
            'last_alert_sent_at' => $this->asDate($runtime['last_alert_sent_at'] ?? null)?->toIso8601String(),
            'last_report_sent_at' => $this->asDate($runtime['last_report_sent_at'] ?? null)?->toIso8601String(),
            'last_report_status' => $this->nullableString($runtime['last_report_status'] ?? null),
            'last_report_frequency' => $this->nullableString($runtime['last_report_frequency'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $operations
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    protected function automationStatus(
        int $tenantId,
        array $policy,
        array $operations,
        array $readiness,
        array $runtime,
        CarbonImmutable $now
    ): array {
        $rewardsModule = $this->moduleAccessResolver->module($tenantId, 'rewards');
        $hasAccess = (bool) ($rewardsModule['has_access'] ?? false);
        $launchState = (string) data_get($policy, 'access_state.launch_state', 'published');
        $testMode = (bool) data_get($policy, 'access_state.test_mode', false);
        $channels = is_array($readiness['channels'] ?? null) ? (array) $readiness['channels'] : [];
        $emailEnabledReady = (bool) data_get($channels, 'email.enabled', false) && (bool) data_get($channels, 'email.ready', false);
        $smsEnabledReady = (bool) data_get($channels, 'sms.enabled', false) && (bool) data_get($channels, 'sms.ready', false);
        $hasLiveChannel = $emailEnabledReady || $smsEnabledReady;
        $isActive = ($launchState === 'published' || $launchState === 'scheduled') && ! $testMode;
        $automationMode = $this->normalizeAutomationMode($operations['automation_mode'] ?? null);
        $lastRunAt = $this->asDate($runtime['last_run_at'] ?? null);
        $lastFailureAt = $this->asDate($runtime['last_failure_at'] ?? null);
        $stale = ! $lastRunAt instanceof CarbonImmutable || $lastRunAt->lessThan($now->subHours(3));

        $manualMode = $automationMode === 'manual';
        $running = $hasAccess && $isActive && $hasLiveChannel && $automationMode === 'automatic' && ! $stale;
        $needsAttention = ! $hasAccess
            || ! $hasLiveChannel
            || ! $isActive
            || $stale
            || max(0, (int) ($runtime['failure_count'] ?? 0)) > 0
            || in_array((string) ($runtime['last_status'] ?? 'idle'), ['warning', 'error'], true);

        $headline = match (true) {
            ! $hasAccess => 'Automation is unavailable until the rewards module is enabled.',
            $manualMode => 'Automation is off.',
            ! $isActive => 'Automation is waiting for the program to go live.',
            ! $hasLiveChannel => 'Automation needs attention before customer reminders can run.',
            $stale => 'Automation needs attention because the reminder processor has not run recently.',
            max(0, (int) ($runtime['failure_count'] ?? 0)) > 0 => 'Automation is running with recent delivery issues.',
            default => 'Automation is running.',
        };

        return [
            'status' => ! $hasAccess
                ? 'unavailable'
                : ($manualMode ? 'manual' : ($running ? 'running' : ($needsAttention ? 'needs_attention' : 'idle'))),
            'headline' => $headline,
            'automation_mode' => $automationMode,
            'automatic_enabled' => $automationMode === 'automatic',
            'auto_enabled' => $automationMode === 'automatic',
            'eligible' => $hasAccess,
            'program_active' => $isActive,
            'channels_ready' => $hasLiveChannel,
            'last_run_at' => $lastRunAt?->toIso8601String(),
            'last_success_at' => $this->asDate($runtime['last_success_at'] ?? null)?->toIso8601String(),
            'last_failure_at' => $lastFailureAt?->toIso8601String(),
            'failure_count' => max(0, (int) ($runtime['failure_count'] ?? 0)),
            'last_status' => $runtime['last_status'] ?? 'idle',
            'last_error_message' => $runtime['last_error_message'] ?? null,
            'last_summary' => is_array($runtime['last_summary'] ?? null) ? (array) $runtime['last_summary'] : [],
            'messages' => array_values(array_filter([
                $automationMode === 'automatic'
                    ? 'Eligible live tenants run on the existing hourly reminder processor automatically.'
                    : 'Manual mode: reminders will not run automatically.',
                $hasLiveChannel
                    ? 'At least one live reminder channel is ready.'
                    : 'Set up live email or SMS sending before expecting customer reminders to run.',
                'Last reminder processor run: '.$this->displayDate($lastRunAt?->toIso8601String()),
            ])),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $operations
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $reminderReporting
     * @param  array<string,mixed>  $financeSummary
     * @param  array<string,mixed>  $automation
     * @return array<int,array<string,mixed>>
     */
    protected function alerts(
        array $policy,
        array $operations,
        array $readiness,
        array $reminderReporting,
        array $financeSummary,
        array $automation,
        CarbonImmutable $now
    ): array {
        $alerts = [];
        $history = collect((array) data_get($reminderReporting, 'activity_table.items', []));
        $alertNoSendsHours = max(1, (int) ($operations['alert_no_sends_hours'] ?? 24));
        $alertHighSkipRatePercent = max(10, (int) ($operations['alert_high_skip_rate_percent'] ?? 50));
        $alertFailureSpikeCount = max(1, (int) ($operations['alert_failure_spike_count'] ?? 5));
        $automaticEnabled = (bool) ($automation['automatic_enabled'] ?? $automation['auto_enabled'] ?? false);

        $sentSinceThreshold = $history->filter(function (array $row) use ($now, $alertNoSendsHours): bool {
            if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'sent') {
                return false;
            }

            $occurredAt = $this->asDate($row['occurred_at'] ?? $row['sent_at'] ?? null);

            return $occurredAt instanceof CarbonImmutable
                && $occurredAt->greaterThanOrEqualTo($now->subHours($alertNoSendsHours));
        })->count();

        if ($automaticEnabled
            && (int) data_get($reminderReporting, 'queue_preview.due_now_count', 0) > 0
            && $sentSinceThreshold === 0) {
            $alerts[] = [
                'code' => 'no_recent_sends',
                'level' => 'warning',
                'message' => sprintf('No rewards reminders were sent in the last %d hours even though reminders are due.', $alertNoSendsHours),
            ];
        }

        $completed = $history->whereIn('status', ['sent', 'skipped', 'failed'])->count();
        $skipCount = $history->where('status', 'skipped')->count();
        if ($completed >= 5 && $completed > 0 && (($skipCount / $completed) * 100) >= $alertHighSkipRatePercent) {
            $alerts[] = [
                'code' => 'high_skip_rate',
                'level' => 'warning',
                'message' => sprintf('Recent reminder skips are above %d%% of completed reminder attempts.', $alertHighSkipRatePercent),
            ];
        }

        $failedSinceDay = $history->filter(function (array $row) use ($now): bool {
            if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'failed') {
                return false;
            }

            $occurredAt = $this->asDate($row['occurred_at'] ?? $row['failed_at'] ?? null);

            return $occurredAt instanceof CarbonImmutable
                && $occurredAt->greaterThanOrEqualTo($now->subDay());
        })->count();

        if ($failedSinceDay >= $alertFailureSpikeCount) {
            $alerts[] = [
                'code' => 'dispatch_failures_spike',
                'level' => 'warning',
                'message' => sprintf('Reminder delivery failures reached %d in the last 24 hours.', $failedSinceDay),
            ];
        }

        $liabilityThreshold = (float) data_get($financeSummary, 'alert_threshold', 0);
        $outstandingLiability = (float) data_get($financeSummary, 'outstanding_liability.amount', 0);
        if ($liabilityThreshold > 0 && $outstandingLiability >= $liabilityThreshold) {
            $alerts[] = [
                'code' => 'liability_above_threshold',
                'level' => 'warning',
                'message' => 'Outstanding reward liability is above the current alert threshold.',
            ];
        }

        $expiringSoonAmount = (float) data_get($financeSummary, 'expiring_soon.amount', 0);
        if ($expiringSoonAmount >= max(100.0, $liabilityThreshold > 0 ? $liabilityThreshold * 0.35 : 0)) {
            $alerts[] = [
                'code' => 'large_expiring_reward_volume',
                'level' => 'warning',
                'message' => 'A meaningful amount of reward value is scheduled to expire soon.',
            ];
        }

        if ((string) ($automation['status'] ?? 'idle') === 'needs_attention') {
            $alerts[] = [
                'code' => 'automation_needs_attention',
                'level' => 'warning',
                'message' => (string) ($automation['headline'] ?? 'Automation needs attention.'),
            ];
        }

        if ((bool) data_get($policy, 'expiration_and_reminders.sms_enabled', false)
            && ! (bool) data_get($readiness, 'channels.sms.ready', false)) {
            $alerts[] = [
                'code' => 'sms_not_configured',
                'level' => 'warning',
                'message' => 'Text reminders are turned on, but live SMS sending is not ready yet.',
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'code' => 'healthy',
                'level' => 'info',
                'message' => 'No rewards automation alerts need attention right now.',
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string,mixed>  $usage
     * @return array<string,mixed>
     */
    protected function usageIndicators(array $usage, bool $moduleEnabled): array
    {
        $metrics = is_array($usage['metrics'] ?? null) ? (array) $usage['metrics'] : [];
        $limits = is_array($usage['included_limits'] ?? null) ? (array) $usage['included_limits'] : [];

        $rows = [
            'rewards_issued' => 'Rewards issued',
            'reward_reminder_sends' => 'Rewards reminders sent',
            'email_usage' => 'Email sends',
            'sms_usage' => 'Text sends',
        ];

        $items = [];
        foreach ($rows as $key => $label) {
            $value = is_numeric($metrics[$key] ?? null) ? (int) $metrics[$key] : 0;
            $limit = is_numeric($limits[$key] ?? null) ? (int) $limits[$key] : null;
            $items[] = [
                'metric_key' => $key,
                'label' => $label,
                'value' => $value,
                'included_limit' => $limit,
                'usage_state' => $limit !== null && $limit > 0 && $value >= $limit
                    ? 'high'
                    : ($limit !== null && $limit > 0 && $value >= (int) floor($limit * 0.8)
                        ? 'watch'
                        : 'normal'),
            ];
        }

        return [
            'headline' => $moduleEnabled
                ? 'Rewards module usage and visibility for commercial readiness.'
                : 'Rewards module usage will appear here once plan access is enabled.',
            'module_enabled' => $moduleEnabled,
            'items' => $items,
        ];
    }

    /**
     * @return array{date_from:string,date_to:string,label:string}
     */
    protected function financeReportDateRange(string $frequency, CarbonImmutable $now): array
    {
        if ($frequency === 'weekly') {
            $dateFrom = $now->subDays(7)->toDateString();
            $dateTo = $now->subDay()->toDateString();

            return [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'label' => $dateFrom.' to '.$dateTo,
            ];
        }

        $date = $now->subDay()->toDateString();

        return [
            'date_from' => $date,
            'date_to' => $date,
            'label' => $date,
        ];
    }

    protected function financeReportIsDue(string $frequency, string $reportDayOfWeek, CarbonImmutable $now, ?CarbonImmutable $lastSentAt): bool
    {
        if ($frequency === 'weekly') {
            if (strtolower($now->englishDayOfWeek) !== strtolower(trim($reportDayOfWeek))) {
                return false;
            }

            return ! $lastSentAt instanceof CarbonImmutable
                || $lastSentAt->lessThan($now->startOfWeek());
        }

        return ! $lastSentAt instanceof CarbonImmutable
            || $lastSentAt->toDateString() !== $now->toDateString();
    }

    /**
     * @return array{reminder_volume:int,expiring_value:float}
     */
    protected function simulationProjection(int $tenantId, array $policy, CarbonImmutable $now): array
    {
        $policyVersion = max(0, (int) data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0)));
        $buckets = collect($this->earnedAnalyticsService->outstandingRewardBuckets($tenantId));

        $reminderVolume = 0;
        $expiringValue = 0.0;

        foreach ($buckets as $reward) {
            if (! is_array($reward)) {
                continue;
            }

            $schedule = $this->scheduleService->evaluate($policy, [
                ...$reward,
                'tenant_id' => $tenantId,
                'policy_version' => $policyVersion,
            ], [
                'tenant_id' => $tenantId,
                'policy_version' => $policyVersion,
                'now' => $now->toIso8601String(),
            ]);

            $reminderVolume += count((array) ($schedule['should_send'] ?? []));
            $reminderVolume += collect((array) ($schedule['upcoming'] ?? []))
                ->filter(function (array $entry) use ($now): bool {
                    $scheduledAt = $this->asDate($entry['scheduled_at'] ?? null);

                    return $scheduledAt instanceof CarbonImmutable
                        && $scheduledAt->lessThanOrEqualTo($now->addDays(14));
                })
                ->count();

            $expiresAt = $this->asDate(data_get($schedule, 'reward.expires_at'));
            if ($expiresAt instanceof CarbonImmutable && $expiresAt->lessThanOrEqualTo($now->addDays(14))) {
                $expiringValue += round((float) ($reward['remaining_amount'] ?? 0), 2);
            }
        }

        return [
            'reminder_volume' => $reminderVolume,
            'expiring_value' => round($expiringValue, 2),
        ];
    }

    /**
     * @param  array<string,mixed>  $dateRange
     */
    protected function signedExportUrl(int $tenantId, string $type, array $dateRange): string
    {
        $expiresAt = now()->addDays(7);
        $parameters = [
            'tenant' => $tenantId,
            'type' => $type,
            'date_from' => $dateRange['date_from'] ?? null,
            'date_to' => $dateRange['date_to'] ?? null,
        ];
        $canonicalHost = $this->hostBuilder->canonicalLandlordHost();
        $canonicalScheme = $this->hostBuilder->canonicalScheme();

        if (is_string($canonicalHost) && $canonicalHost !== '') {
            URL::forceRootUrl($canonicalScheme.'://'.$canonicalHost);
            URL::forceScheme($canonicalScheme);

            try {
                return URL::temporarySignedRoute(
                    'rewards.policy.exports.signed',
                    $expiresAt,
                    $parameters
                );
            } finally {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        }

        return URL::temporarySignedRoute(
            'rewards.policy.exports.signed',
            $expiresAt,
            $parameters
        );
    }

    protected function tenantRole(User $user, int $tenantId): ?string
    {
        $membership = $user->tenants()
            ->where('tenants.id', $tenantId)
            ->first();

        if (! $membership) {
            return null;
        }

        $role = strtolower(trim((string) ($membership->pivot->role ?? '')));

        return $role !== '' ? $role : null;
    }

    protected function userMatchesPermission(User $user, ?string $tenantRole, string $requiredRole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return match ($requiredRole) {
            'admin_only' => false,
            'manager_or_admin' => $user->isManager() || in_array($tenantRole, ['manager', 'admin'], true),
            'marketing_manager_or_admin' => $user->isMarketingManager()
                || $user->isManager()
                || in_array($tenantRole, ['marketing_manager', 'manager', 'admin'], true),
            'tenant_member' => $tenantRole !== null,
            default => false,
        };
    }

    protected function permissionLabel(string $requiredRole): string
    {
        return match ($requiredRole) {
            'admin_only' => 'Admin only',
            'manager_or_admin' => 'Manager or admin',
            'marketing_manager_or_admin' => 'Marketing manager, manager, or admin',
            'tenant_member' => 'Any tenant team member',
            default => 'Manager or admin',
        };
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function saveSetting(?int $tenantId, string $key, array $value, string $description): void
    {
        if ($tenantId !== null) {
            if (! Schema::hasTable('tenant_marketing_settings')) {
                throw new \RuntimeException('tenant_marketing_settings table is required for tenant rewards operations settings.');
            }

            TenantMarketingSetting::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'key' => $key,
                ],
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

    protected function displayDate(mixed $value): string
    {
        return $this->asDate($value)?->format('M j, Y g:i A') ?? 'Not recorded yet';
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function nullableEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : null;
    }

    protected function normalizeAutomationMode(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'manual', 'paused' => 'manual',
            'automatic', 'auto' => 'automatic',
            default => 'manual',
        };
    }

    protected function enumOrDefault(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
