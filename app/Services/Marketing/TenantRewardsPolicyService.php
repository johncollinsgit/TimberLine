<?php

namespace App\Services\Marketing;

use App\Models\MarketingSetting;
use App\Models\TenantMarketingSetting;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TenantRewardsPolicyService
{
    public const POLICY_KEY = 'candle_cash_policy_config';
    public const PROGRAM_KEY = 'candle_cash_program_config';
    public const FRONTEND_KEY = 'candle_cash_frontend_config';
    public const NOTIFICATION_KEY = 'candle_cash_notification_config';
    public const FINANCE_KEY = 'candle_cash_finance_config';
    public const ACCESS_STATE_KEY = 'candle_cash_access_state';
    public const OPERATIONS_KEY = 'candle_cash_operations_config';
    public const TEAM_ACCESS_KEY = 'candle_cash_team_access_config';
    public const AUTOMATION_STATE_KEY = 'candle_cash_automation_state';
    public const VERSION_META_KEY = 'candle_cash_policy_version_meta';
    public const VERSION_HISTORY_KEY = 'candle_cash_policy_versions';

    public function __construct(
        protected TenantMarketingSettingsResolver $settingsResolver,
        protected CandleCashService $candleCashService,
        protected TenantRewardsPolicySummaryService $summaryService,
        protected TenantRewardsPolicyWarningService $warningService,
        protected TenantRewardsPolicyMessagePreviewService $previewService,
        protected TenantRewardsPolicyAuditService $auditService,
        protected TenantRewardsPolicyReadinessService $readinessService,
        protected TenantRewardsReminderScheduleService $reminderScheduleService,
        protected TenantRewardsReminderLogService $reminderLogService,
        protected TenantRewardsReminderAnalyticsService $reminderAnalyticsService,
        protected TenantRewardsFinanceSummaryService $financeSummaryService,
        protected TenantRewardsOperationsService $operationsService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(?int $tenantId, array $context = []): array
    {
        $core = $this->corePolicy($tenantId);
        $messages = $this->warningService->evaluate($core);
        $versioning = $this->versioningMetadata($tenantId);

        return $this->resolvedPolicyPayload($tenantId, $core, $messages, $versioning, $context);
    }

    /**
     * Lightweight storefront policy snapshot.
     *
     * Storefront widgets only need customer-facing messaging and program identity
     * and should avoid the heavier readiness/reporting payload used in Backstage.
     *
     * @return array<string,mixed>
     */
    public function storefrontSnapshot(?int $tenantId): array
    {
        if ($tenantId === null || $tenantId <= 0) {
            return [];
        }

        $core = $this->corePolicy($tenantId);

        return [
            'program_identity' => (array) ($core['program_identity'] ?? []),
            'customer_experience' => (array) ($core['customer_experience'] ?? []),
            'expiration_and_reminders' => (array) ($core['expiration_and_reminders'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $patch
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function review(int $tenantId, array $patch, array $context = []): array
    {
        $current = $this->corePolicy($tenantId);
        $allowedPatch = $this->bridgeLegacyReminderOffsets($this->allowedPatch($patch));
        $merged = $this->deepMerge($current, $allowedPatch);
        $normalized = $this->normalizeCorePolicy($merged);
        $controls = $this->fieldControls($normalized, $context);

        $errors = $this->mergeErrors(
            $this->restrictedFieldErrors($allowedPatch, $controls),
            $this->validationErrors($normalized, [
                'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
            ])
        );

        $messages = $this->warningService->evaluate($normalized);
        $versioning = $this->versioningMetadata($tenantId);
        $resolved = $this->resolvedPolicyPayload($tenantId, $normalized, $messages, $versioning, $context);

        return [
            ...$resolved,
            'field_controls' => $controls,
            'validation_errors' => $errors,
            'review_ready' => $errors === [],
            'publish_preview' => [
                'live_version' => max(0, (int) ($versioning['current_version'] ?? 0)),
                'pending_version' => max(1, (int) ($versioning['current_version'] ?? 0) + 1),
                'live_summary' => $this->summaryService->summarize($current),
                'pending_summary' => $this->summaryService->summarize($normalized),
                'change_preview' => $this->changePreview($current, $normalized),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $patch
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function update(int $tenantId, array $patch, array $context = []): array
    {
        $current = $this->corePolicy($tenantId);
        $allowedPatch = $this->bridgeLegacyReminderOffsets($this->allowedPatch($patch));
        $merged = $this->deepMerge($current, $allowedPatch);
        $normalized = $this->normalizeCorePolicy($merged);
        $controls = $this->fieldControls($normalized, $context);
        $restrictedErrors = (bool) ($context['bypass_restricted_fields'] ?? false)
            ? []
            : $this->restrictedFieldErrors($allowedPatch, $controls);

        $errors = $this->mergeErrors(
            $restrictedErrors,
            $this->validationErrors($normalized, [
                'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
            ])
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = $this->policyChanges($current, $normalized);
        $this->persist($tenantId, $normalized, $changes, $context);
        $this->settingsResolver->flushArrayCache();

        return $this->resolve($tenantId, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function applyAlphaDefaults(int $tenantId, array $context = []): array
    {
        $patch = $this->alphaDefaultPatch();
        if (! (bool) ($context['sms_channel_enabled'] ?? true)) {
            $patch['expiration_and_reminders']['sms_enabled'] = false;
        }

        return $this->update(
            $tenantId,
            $patch,
            [
                ...$context,
                'source' => 'alpha_default_preset',
                'bypass_restricted_fields' => true,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function corePolicy(?int $tenantId): array
    {
        $program = $this->setting(self::PROGRAM_KEY, $tenantId, (array) config('marketing.candle_cash', []));
        $frontend = $this->setting(self::FRONTEND_KEY, $tenantId);
        $policy = $this->setting(self::POLICY_KEY, $tenantId);
        $notifications = $this->setting(self::NOTIFICATION_KEY, $tenantId);
        $finance = $this->setting(self::FINANCE_KEY, $tenantId);
        $access = $this->setting(self::ACCESS_STATE_KEY, $tenantId);
        $operations = $this->setting(self::OPERATIONS_KEY, $tenantId);
        $teamAccess = $this->setting(self::TEAM_ACCESS_KEY, $tenantId);

        $policyIdentity = is_array($policy['program_identity'] ?? null) ? $policy['program_identity'] : [];
        $policyValue = is_array($policy['value_model'] ?? null) ? $policy['value_model'] : [];
        $policyEarning = is_array($policy['earning_rules'] ?? null) ? $policy['earning_rules'] : [];
        $policyRedemption = is_array($policy['redemption_rules'] ?? null) ? $policy['redemption_rules'] : [];
        $policyCustomer = is_array($policy['customer_experience'] ?? null) ? $policy['customer_experience'] : [];
        $defaultReminderOffsets = $this->normalizedReminderOffsets([14, 7, 1]);
        $defaultSmsReminderOffsets = $this->normalizedReminderOffsets([3], [3], true);
        $legacyReminderOffsets = $this->normalizedReminderOffsets($notifications['reminder_offsets_days'] ?? [14, 7, 1]);

        $normalized = [
            'program_identity' => [
                'program_name' => $this->stringOrDefault($policyIdentity['program_name'] ?? null, $this->stringOrDefault($program['label'] ?? null, 'Candle Cash')),
                'short_label' => $this->stringOrDefault($policyIdentity['short_label'] ?? null, $this->stringOrDefault($program['label'] ?? null, 'Candle Cash')),
                'description' => $this->nullableString($policyIdentity['description'] ?? null),
                'terminology_mode' => $this->enumOrDefault($policyIdentity['terminology_mode'] ?? null, ['cash', 'points'], 'cash'),
                'rewards_singular' => $this->stringOrDefault($policyIdentity['rewards_singular'] ?? null, 'reward'),
                'rewards_plural' => $this->stringOrDefault($policyIdentity['rewards_plural'] ?? null, 'rewards'),
                'accent_copy' => $this->nullableString($policyIdentity['accent_copy'] ?? null),
            ],
            'value_model' => [
                'currency_mode' => $this->enumOrDefault($policyValue['currency_mode'] ?? null, ['fixed_cash', 'points_to_cash'], 'fixed_cash'),
                'points_per_dollar' => max(1, (int) ($policyValue['points_per_dollar'] ?? $program['legacy_points_per_candle_cash'] ?? CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH)),
                'redeem_increment_dollars' => round((float) ($program['redeem_increment_dollars'] ?? $this->candleCashService->fixedRedemptionAmount($tenantId)), 2),
                'max_redeemable_per_order_dollars' => round((float) ($program['max_redeemable_per_order_dollars'] ?? $this->candleCashService->maxRedeemablePerOrderAmount($tenantId)), 2),
                'minimum_purchase_dollars' => round(max(0, (float) ($program['minimum_purchase_dollars'] ?? 0)), 2),
                'rounding_behavior' => $this->enumOrDefault($policyValue['rounding_behavior'] ?? null, ['nearest_cent', 'floor', 'ceiling'], 'nearest_cent'),
                'max_wallet_balance_dollars' => $this->nullablePositiveFloat($program['max_wallet_balance_dollars'] ?? null),
            ],
            'earning_rules' => [
                'enable_milestone_rewards' => (bool) ($policyEarning['enable_milestone_rewards'] ?? true),
                'enable_task_rewards' => (bool) ($policyEarning['enable_task_rewards'] ?? true),
                'enable_order_based_earning' => (bool) ($policyEarning['enable_order_based_earning'] ?? true),
                'first_order_reward_amount' => $this->nullablePositiveFloat($policyEarning['first_order_reward_amount'] ?? null),
                'second_order_reward_amount' => round(max(0, (float) ($program['second_order_reward_amount'] ?? 5)), 2),
                'third_order_reward_amount' => $this->nullablePositiveFloat($policyEarning['third_order_reward_amount'] ?? null),
                'candle_club_multiplier_enabled' => (bool) ($policyEarning['candle_club_multiplier_enabled'] ?? $program['candle_club_multiplier_enabled'] ?? true),
                'candle_club_multiplier_value' => round(max(1, (float) ($policyEarning['candle_club_multiplier_value'] ?? $program['candle_club_multiplier_value'] ?? 2)), 2),
                'candle_club_free_shipping_enabled' => (bool) ($policyEarning['candle_club_free_shipping_enabled'] ?? $program['candle_club_free_shipping_enabled'] ?? false),
                'spend_threshold_rewards' => $this->normalizedThresholdRewards($policyEarning['spend_threshold_rewards'] ?? []),
                'event_rewards_enabled' => (bool) ($policyEarning['event_rewards_enabled'] ?? false),
                'manual_grants_enabled' => (bool) ($policyEarning['manual_grants_enabled'] ?? true),
                'rewardable_channels' => $this->enumOrDefault(
                    $policyEarning['rewardable_channels'] ?? $program['rewardable_channels'] ?? null,
                    ['online_only', 'show_issued_online_redeemed', 'exclude_shows'],
                    'online_only'
                ),
            ],
            'redemption_rules' => [
                'max_codes_per_order' => max(1, (int) ($policyRedemption['max_codes_per_order'] ?? 1)),
                'stacking_mode' => $this->enumOrDefault($policyRedemption['stacking_mode'] ?? null, ['no_stacking', 'shipping_only', 'selected_promo_types'], 'no_stacking'),
                'selected_stackable_promo_types' => $this->normalizedStringList($policyRedemption['selected_stackable_promo_types'] ?? []),
                'code_strategy' => $this->enumOrDefault($policyRedemption['code_strategy'] ?? null, ['unique_per_customer', 'shared'], 'unique_per_customer'),
                'attribution_required' => (bool) ($policyRedemption['attribution_required'] ?? true),
                'platform_supports_multi_code' => (bool) ($policyRedemption['platform_supports_multi_code'] ?? false),
                'exclusions' => $this->normalizedExclusions($policyRedemption['exclusions'] ?? ($program['exclusions'] ?? [])),
            ],
            'expiration_and_reminders' => [
                'expiration_mode' => $this->enumOrDefault($notifications['expiration_mode'] ?? null, ['days_from_issue', 'end_of_season', 'none'], 'days_from_issue'),
                'expiration_days' => max(1, (int) ($notifications['expiration_days'] ?? config('marketing.candle_cash.code_expiry_days', 90))),
                'email_enabled' => (bool) ($notifications['email_enabled'] ?? true),
                'sms_enabled' => (bool) ($notifications['sms_enabled'] ?? false),
                'reminder_offsets_days' => $legacyReminderOffsets,
                'email_reminder_offsets_days' => $this->normalizedReminderOffsets(
                    $notifications['email_reminder_offsets_days'] ?? $notifications['reminder_offsets_days'] ?? $defaultReminderOffsets,
                    $defaultReminderOffsets
                ),
                'sms_reminder_offsets_days' => $this->normalizedReminderOffsets(
                    $notifications['sms_reminder_offsets_days'] ?? ((bool) ($notifications['sms_enabled'] ?? false) ? $defaultSmsReminderOffsets : []),
                    (bool) ($notifications['sms_enabled'] ?? false) ? $defaultSmsReminderOffsets : [],
                    true
                ),
                'sms_max_per_reward' => max(0, (int) ($notifications['sms_max_per_reward'] ?? 1)),
                'sms_quiet_days' => max(0, (int) ($notifications['sms_quiet_days'] ?? 14)),
                'templates' => [
                    'subject_line' => $this->stringOrDefault($notifications['templates']['subject_line'] ?? null, 'You still have rewards available'),
                    'preview_text' => $this->nullableString($notifications['templates']['preview_text'] ?? null),
                    'sms_body' => $this->nullableString($notifications['templates']['sms_body'] ?? null),
                    'email_headline' => $this->nullableString($notifications['templates']['email_headline'] ?? null),
                    'email_body' => $this->nullableString($notifications['templates']['email_body'] ?? null),
                    'email_cta' => $this->nullableString($notifications['templates']['email_cta'] ?? null),
                ],
            ],
            'customer_experience' => [
                'lookup_experience_name' => $this->stringOrDefault($policyCustomer['lookup_experience_name'] ?? null, $this->stringOrDefault($frontend['central_title'] ?? null, 'Rewards Central')),
                'lookup_copy' => $this->stringOrDefault($policyCustomer['lookup_copy'] ?? null, $this->stringOrDefault($frontend['central_subtitle'] ?? null, 'See your available rewards and use them on your next order.')),
                'redeem_cta_label' => $this->stringOrDefault($policyCustomer['redeem_cta_label'] ?? null, $this->stringOrDefault($frontend['redeem_cta_label'] ?? null, 'Redeem Reward Credit')),
                'wallet_label' => $this->stringOrDefault($policyCustomer['wallet_label'] ?? null, $this->stringOrDefault($frontend['wallet_label'] ?? null, 'Rewards wallet')),
                'success_message' => $this->nullableString($policyCustomer['success_message'] ?? null),
                'expiration_message' => $this->nullableString($policyCustomer['expiration_message'] ?? null),
                'empty_state_message' => $this->nullableString($policyCustomer['empty_state_message'] ?? null),
                'coming_soon_message' => $this->nullableString($policyCustomer['coming_soon_message'] ?? null),
            ],
            'finance_and_safety' => [
                'liability_alert_threshold_dollars' => $this->nullablePositiveFloat($finance['liability_alert_threshold_dollars'] ?? null),
                'max_open_codes' => max(1, (int) ($program['max_open_codes'] ?? 1)),
                'breakage_reporting_visible' => (bool) ($finance['breakage_reporting_visible'] ?? true),
                'fraud_sensitivity_mode' => $this->enumOrDefault($finance['fraud_sensitivity_mode'] ?? null, ['low', 'balanced', 'high'], 'balanced'),
                'require_minimum_order_for_redemption' => (bool) ($finance['require_minimum_order_for_redemption'] ?? ((float) ($program['minimum_purchase_dollars'] ?? 0) > 0)),
                'manual_grant_approval_threshold_dollars' => $this->nullablePositiveFloat($finance['manual_grant_approval_threshold_dollars'] ?? null),
            ],
            'access_state' => [
                'launch_state' => $this->enumOrDefault($access['launch_state'] ?? null, ['draft', 'published', 'scheduled'], 'published'),
                'test_mode' => (bool) ($access['test_mode'] ?? false),
                'scheduled_launch_at' => $this->nullableString($access['scheduled_launch_at'] ?? null),
            ],
            'automation_and_reporting' => $this->operationsService->normalizeOperationsConfig(is_array($operations) ? $operations : []),
            'team_access' => $this->operationsService->normalizeTeamAccess(is_array($teamAccess) ? $teamAccess : []),
        ];

        return $this->normalizeCorePolicy($normalized);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,array<int,string>>
     */
    protected function validationErrors(array $policy, array $context = []): array
    {
        $errors = [];

        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $redemption = (array) ($policy['redemption_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $access = (array) ($policy['access_state'] ?? []);
        $operations = (array) ($policy['automation_and_reporting'] ?? []);

        if (($value['currency_mode'] ?? 'fixed_cash') === 'points_to_cash'
            && max(0, (int) ($value['points_per_dollar'] ?? 0)) <= 0) {
            $errors['value_model.points_per_dollar'][] = 'Points mode requires a points-per-dollar conversion rate greater than zero.';
        }

        $redeemIncrement = round((float) ($value['redeem_increment_dollars'] ?? 0), 2);
        if ($redeemIncrement <= 0) {
            $errors['value_model.redeem_increment_dollars'][] = 'Redemption increment must be greater than zero.';
        }

        $maxRedeemable = round((float) ($value['max_redeemable_per_order_dollars'] ?? 0), 2);
        if ($maxRedeemable <= 0) {
            $errors['value_model.max_redeemable_per_order_dollars'][] = 'Maximum redeemable per order must be greater than zero.';
        }

        if ($maxRedeemable < $redeemIncrement) {
            $errors['value_model.max_redeemable_per_order_dollars'][] = 'Maximum redeemable per order must be greater than or equal to the redemption increment.';
        }

        if (round((float) ($earning['candle_club_multiplier_value'] ?? 1), 2) < 1) {
            $errors['earning_rules.candle_club_multiplier_value'][] = 'Candle Club multiplier must be at least 1x.';
        }

        $maxCodesPerOrder = max(1, (int) ($redemption['max_codes_per_order'] ?? 1));
        $platformSupportsMultiCode = (bool) ($redemption['platform_supports_multi_code'] ?? false);
        if ($maxCodesPerOrder > 1 && ! $platformSupportsMultiCode) {
            $errors['redemption_rules.max_codes_per_order'][] = 'Multiple codes per order cannot be enabled until platform stacking support is confirmed.';
        }

        if (($redemption['code_strategy'] ?? 'unique_per_customer') === 'shared'
            && (bool) ($redemption['attribution_required'] ?? true)) {
            $errors['redemption_rules.code_strategy'][] = 'Shared codes are incompatible with per-customer attribution reporting requirements.';
        }

        if ((bool) ($expiration['sms_enabled'] ?? false) && ! (bool) ($context['sms_channel_enabled'] ?? true)) {
            $errors['expiration_and_reminders.sms_enabled'][] = 'Text reminders require plan access for SMS and a ready channel setup.';
        }

        $expirationMode = (string) ($expiration['expiration_mode'] ?? 'days_from_issue');
        $expirationDays = max(1, (int) ($expiration['expiration_days'] ?? 90));
        $offsetGroups = [
            'expiration_and_reminders.reminder_offsets_days' => (array) ($expiration['reminder_offsets_days'] ?? []),
            'expiration_and_reminders.email_reminder_offsets_days' => (array) ($expiration['email_reminder_offsets_days'] ?? []),
            'expiration_and_reminders.sms_reminder_offsets_days' => (array) ($expiration['sms_reminder_offsets_days'] ?? []),
        ];

        foreach ($offsetGroups as $path => $offsets) {
            foreach ($offsets as $index => $offset) {
                $offsetValue = (int) $offset;
                if ($offsetValue < 0) {
                    $errors[$path.'.'.$index][] = 'Reminder timing offsets must be zero or greater.';
                    continue;
                }

                if ($expirationMode === 'days_from_issue' && $offsetValue >= $expirationDays) {
                    $errors[$path.'.'.$index][] = 'Reminder timing must occur before the expiration window.';
                }
            }
        }

        if ((bool) ($expiration['email_enabled'] ?? true)
            && $this->normalizedReminderOffsets(
                $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? [14, 7, 1],
                [14, 7, 1]
            ) === []) {
            $errors['expiration_and_reminders.email_reminder_offsets_days'][] = 'Choose at least one email reminder timing before launch.';
        }

        if ((bool) ($expiration['sms_enabled'] ?? false)
            && $this->normalizedReminderOffsets(
                $expiration['sms_reminder_offsets_days'] ?? [],
                [],
                true
            ) === []) {
            $errors['expiration_and_reminders.sms_reminder_offsets_days'][] = 'Choose at least one text reminder timing before launch.';
        }

        if (($access['launch_state'] ?? 'published') === 'scheduled' && $this->nullableString($access['scheduled_launch_at'] ?? null) === null) {
            $errors['access_state.scheduled_launch_at'][] = 'Scheduled launch state requires a scheduled launch timestamp.';
        }

        if ((bool) ($operations['alert_email_enabled'] ?? false)
            && $this->nullableString($operations['alert_email'] ?? null) === null) {
            $errors['automation_and_reporting.alert_email'][] = 'Add an alert email address before turning on automation alerts.';
        }

        if (($operations['report_frequency'] ?? 'off') !== 'off'
            && $this->nullableString($operations['report_email'] ?? null) === null) {
            $errors['automation_and_reporting.report_email'][] = 'Add a report email address before turning on scheduled finance reports.';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    protected function allowedPatch(array $policy): array
    {
        $allowed = [
            'program_identity',
            'value_model',
            'earning_rules',
            'redemption_rules',
            'expiration_and_reminders',
            'customer_experience',
            'finance_and_safety',
            'access_state',
            'automation_and_reporting',
            'team_access',
        ];

        return collect($policy)
            ->only($allowed)
            ->map(fn ($value): array => is_array($value) ? $value : [])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    protected function bridgeLegacyReminderOffsets(array $policy): array
    {
        $expiration = is_array($policy['expiration_and_reminders'] ?? null)
            ? (array) $policy['expiration_and_reminders']
            : null;

        if ($expiration === null) {
            return $policy;
        }

        if (array_key_exists('reminder_offsets_days', $expiration) && ! array_key_exists('email_reminder_offsets_days', $expiration)) {
            $expiration['email_reminder_offsets_days'] = $expiration['reminder_offsets_days'];
        }

        $policy['expiration_and_reminders'] = $expiration;

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function normalizeCorePolicy(array $input): array
    {
        $identity = (array) ($input['program_identity'] ?? []);
        $value = (array) ($input['value_model'] ?? []);
        $earning = (array) ($input['earning_rules'] ?? []);
        $redemption = (array) ($input['redemption_rules'] ?? []);
        $expiration = (array) ($input['expiration_and_reminders'] ?? []);
        $customer = (array) ($input['customer_experience'] ?? []);
        $finance = (array) ($input['finance_and_safety'] ?? []);
        $access = (array) ($input['access_state'] ?? []);
        $operations = (array) ($input['automation_and_reporting'] ?? []);
        $teamAccess = (array) ($input['team_access'] ?? []);
        $defaultReminderOffsets = [14, 7, 1];
        $defaultSmsReminderOffsets = (bool) ($expiration['sms_enabled'] ?? false) ? [3] : [];
        $emailReminderOffsets = $this->normalizedReminderOffsets(
            $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? $defaultReminderOffsets,
            $defaultReminderOffsets
        );
        $smsReminderOffsets = $this->normalizedReminderOffsets(
            $expiration['sms_reminder_offsets_days'] ?? $defaultSmsReminderOffsets,
            $defaultSmsReminderOffsets,
            true
        );

        return [
            'program_identity' => [
                'program_name' => $this->stringOrDefault($identity['program_name'] ?? null, 'Rewards'),
                'short_label' => $this->stringOrDefault($identity['short_label'] ?? null, $this->stringOrDefault($identity['program_name'] ?? null, 'Rewards')),
                'description' => $this->nullableString($identity['description'] ?? null),
                'terminology_mode' => $this->enumOrDefault($identity['terminology_mode'] ?? null, ['cash', 'points'], 'cash'),
                'rewards_singular' => $this->stringOrDefault($identity['rewards_singular'] ?? null, 'reward'),
                'rewards_plural' => $this->stringOrDefault($identity['rewards_plural'] ?? null, 'rewards'),
                'accent_copy' => $this->nullableString($identity['accent_copy'] ?? null),
            ],
            'value_model' => [
                'currency_mode' => $this->enumOrDefault($value['currency_mode'] ?? null, ['fixed_cash', 'points_to_cash'], 'fixed_cash'),
                'points_per_dollar' => (int) ($value['points_per_dollar'] ?? CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH),
                'redeem_increment_dollars' => round(max(0.01, (float) ($value['redeem_increment_dollars'] ?? 10)), 2),
                'max_redeemable_per_order_dollars' => round(max(0.01, (float) ($value['max_redeemable_per_order_dollars'] ?? 10)), 2),
                'minimum_purchase_dollars' => round(max(0, (float) ($value['minimum_purchase_dollars'] ?? 0)), 2),
                'rounding_behavior' => $this->enumOrDefault($value['rounding_behavior'] ?? null, ['nearest_cent', 'floor', 'ceiling'], 'nearest_cent'),
                'max_wallet_balance_dollars' => $this->nullablePositiveFloat($value['max_wallet_balance_dollars'] ?? null),
            ],
            'earning_rules' => [
                'enable_milestone_rewards' => (bool) ($earning['enable_milestone_rewards'] ?? true),
                'enable_task_rewards' => (bool) ($earning['enable_task_rewards'] ?? true),
                'enable_order_based_earning' => (bool) ($earning['enable_order_based_earning'] ?? true),
                'first_order_reward_amount' => $this->nullablePositiveFloat($earning['first_order_reward_amount'] ?? null),
                'second_order_reward_amount' => round(max(0, (float) ($earning['second_order_reward_amount'] ?? 5)), 2),
                'third_order_reward_amount' => $this->nullablePositiveFloat($earning['third_order_reward_amount'] ?? null),
                'candle_club_multiplier_enabled' => (bool) ($earning['candle_club_multiplier_enabled'] ?? true),
                'candle_club_multiplier_value' => round(max(1, (float) ($earning['candle_club_multiplier_value'] ?? 2)), 2),
                'candle_club_free_shipping_enabled' => (bool) ($earning['candle_club_free_shipping_enabled'] ?? false),
                'spend_threshold_rewards' => $this->normalizedThresholdRewards($earning['spend_threshold_rewards'] ?? []),
                'event_rewards_enabled' => (bool) ($earning['event_rewards_enabled'] ?? false),
                'manual_grants_enabled' => (bool) ($earning['manual_grants_enabled'] ?? true),
                'rewardable_channels' => $this->enumOrDefault($earning['rewardable_channels'] ?? null, ['online_only', 'show_issued_online_redeemed', 'exclude_shows'], 'online_only'),
            ],
            'redemption_rules' => [
                'max_codes_per_order' => max(1, (int) ($redemption['max_codes_per_order'] ?? 1)),
                'stacking_mode' => $this->enumOrDefault($redemption['stacking_mode'] ?? null, ['no_stacking', 'shipping_only', 'selected_promo_types'], 'no_stacking'),
                'selected_stackable_promo_types' => $this->normalizedStringList($redemption['selected_stackable_promo_types'] ?? []),
                'code_strategy' => $this->enumOrDefault($redemption['code_strategy'] ?? null, ['unique_per_customer', 'shared'], 'unique_per_customer'),
                'attribution_required' => (bool) ($redemption['attribution_required'] ?? true),
                'platform_supports_multi_code' => (bool) ($redemption['platform_supports_multi_code'] ?? false),
                'exclusions' => $this->normalizedExclusions($redemption['exclusions'] ?? []),
            ],
            'expiration_and_reminders' => [
                'expiration_mode' => $this->enumOrDefault($expiration['expiration_mode'] ?? null, ['days_from_issue', 'end_of_season', 'none'], 'days_from_issue'),
                'expiration_days' => max(1, (int) ($expiration['expiration_days'] ?? 90)),
                'email_enabled' => (bool) ($expiration['email_enabled'] ?? true),
                'sms_enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                'reminder_offsets_days' => $emailReminderOffsets,
                'email_reminder_offsets_days' => $emailReminderOffsets,
                'sms_reminder_offsets_days' => $smsReminderOffsets,
                'sms_max_per_reward' => max(0, (int) ($expiration['sms_max_per_reward'] ?? 1)),
                'sms_quiet_days' => max(0, (int) ($expiration['sms_quiet_days'] ?? 14)),
                'templates' => [
                    'subject_line' => $this->stringOrDefault($expiration['templates']['subject_line'] ?? null, 'You still have rewards available'),
                    'preview_text' => $this->nullableString($expiration['templates']['preview_text'] ?? null),
                    'sms_body' => $this->nullableString($expiration['templates']['sms_body'] ?? null),
                    'email_headline' => $this->nullableString($expiration['templates']['email_headline'] ?? null),
                    'email_body' => $this->nullableString($expiration['templates']['email_body'] ?? null),
                    'email_cta' => $this->nullableString($expiration['templates']['email_cta'] ?? null),
                ],
            ],
            'customer_experience' => [
                'lookup_experience_name' => $this->stringOrDefault($customer['lookup_experience_name'] ?? null, 'Rewards Central'),
                'lookup_copy' => $this->stringOrDefault($customer['lookup_copy'] ?? null, 'See your available rewards and use them on your next order.'),
                'redeem_cta_label' => $this->stringOrDefault($customer['redeem_cta_label'] ?? null, 'Redeem Reward Credit'),
                'wallet_label' => $this->stringOrDefault($customer['wallet_label'] ?? null, 'Rewards wallet'),
                'success_message' => $this->nullableString($customer['success_message'] ?? null),
                'expiration_message' => $this->nullableString($customer['expiration_message'] ?? null),
                'empty_state_message' => $this->nullableString($customer['empty_state_message'] ?? null),
                'coming_soon_message' => $this->nullableString($customer['coming_soon_message'] ?? null),
            ],
            'finance_and_safety' => [
                'liability_alert_threshold_dollars' => $this->nullablePositiveFloat($finance['liability_alert_threshold_dollars'] ?? null),
                'max_open_codes' => max(1, (int) ($finance['max_open_codes'] ?? 1)),
                'breakage_reporting_visible' => (bool) ($finance['breakage_reporting_visible'] ?? true),
                'fraud_sensitivity_mode' => $this->enumOrDefault($finance['fraud_sensitivity_mode'] ?? null, ['low', 'balanced', 'high'], 'balanced'),
                'require_minimum_order_for_redemption' => (bool) ($finance['require_minimum_order_for_redemption'] ?? false),
                'manual_grant_approval_threshold_dollars' => $this->nullablePositiveFloat($finance['manual_grant_approval_threshold_dollars'] ?? null),
            ],
            'access_state' => [
                'launch_state' => $this->enumOrDefault($access['launch_state'] ?? null, ['draft', 'published', 'scheduled'], 'published'),
                'test_mode' => (bool) ($access['test_mode'] ?? false),
                'scheduled_launch_at' => $this->nullableString($access['scheduled_launch_at'] ?? null),
            ],
            'automation_and_reporting' => $this->operationsService->normalizeOperationsConfig($operations),
            'team_access' => $this->operationsService->normalizeTeamAccess($teamAccess),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $changes
     * @param  array<string,mixed>  $context
     */
    protected function persist(int $tenantId, array $policy, array $changes, array $context = []): void
    {
        $program = (array) $this->setting(self::PROGRAM_KEY, $tenantId, (array) config('marketing.candle_cash', []));
        $frontend = (array) $this->setting(self::FRONTEND_KEY, $tenantId);
        $notifications = (array) $this->setting(self::NOTIFICATION_KEY, $tenantId);
        $finance = (array) $this->setting(self::FINANCE_KEY, $tenantId);
        $access = (array) $this->setting(self::ACCESS_STATE_KEY, $tenantId);
        $versionMeta = (array) $this->setting(self::VERSION_META_KEY, $tenantId);
        $versionHistory = (array) $this->setting(self::VERSION_HISTORY_KEY, $tenantId, ['items' => []]);

        $identity = (array) ($policy['program_identity'] ?? []);
        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $redemption = (array) ($policy['redemption_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $customer = (array) ($policy['customer_experience'] ?? []);
        $financePolicy = (array) ($policy['finance_and_safety'] ?? []);
        $accessPolicy = (array) ($policy['access_state'] ?? []);
        $operationsPolicy = (array) ($policy['automation_and_reporting'] ?? []);
        $teamAccessPolicy = (array) ($policy['team_access'] ?? []);

        $currentVersion = max(0, (int) ($versionMeta['current_version'] ?? 0));
        $savedAt = now()->toIso8601String();
        $savedBy = [
            'actor_user_id' => is_numeric($context['actor_user_id'] ?? null) ? (int) $context['actor_user_id'] : null,
            'shopify_admin_user_id' => $this->nullableString($context['shopify_admin_user_id'] ?? null),
        ];
        $versionedPrefixes = [
            'program_identity.',
            'value_model.',
            'earning_rules.',
            'redemption_rules.',
            'expiration_and_reminders.',
            'customer_experience.',
            'finance_and_safety.',
            'access_state.',
        ];
        $versionedChanges = collect($changes)
            ->filter(function ($row, string $path) use ($versionedPrefixes): bool {
                foreach ($versionedPrefixes as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return true;
                    }
                }

                return false;
            })
            ->all();
        $operationalChanges = collect($changes)
            ->reject(function ($row, string $path) use ($versionedPrefixes): bool {
                foreach ($versionedPrefixes as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return true;
                    }
                }

                return false;
            })
            ->all();
        $nextVersion = $versionedChanges !== [] ? $currentVersion + 1 : $currentVersion;

        $this->operationsService->persistConfig($tenantId, $operationsPolicy, $teamAccessPolicy);

        if ($versionedChanges !== []) {
            $program = array_merge($program, [
                'label' => $this->stringOrDefault($identity['program_name'] ?? null, 'Candle Cash'),
                'legacy_points_per_candle_cash' => max(1, (int) ($value['points_per_dollar'] ?? CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH)),
                'candle_cash_units_per_dollar' => CandleCashService::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
                'redeem_increment_dollars' => round(max(0.01, (float) ($value['redeem_increment_dollars'] ?? 10)), 2),
                'max_redeemable_per_order_dollars' => round(max(0.01, (float) ($value['max_redeemable_per_order_dollars'] ?? 10)), 2),
                'minimum_purchase_dollars' => round(max(0, (float) ($value['minimum_purchase_dollars'] ?? 0)), 2),
                'max_wallet_balance_dollars' => $this->nullablePositiveFloat($value['max_wallet_balance_dollars'] ?? null),
                'max_open_codes' => max(1, (int) ($financePolicy['max_open_codes'] ?? 1)),
                'second_order_reward_amount' => round(max(0, (float) ($earning['second_order_reward_amount'] ?? 5)), 2),
                'candle_club_multiplier_enabled' => (bool) ($earning['candle_club_multiplier_enabled'] ?? true),
                'candle_club_multiplier_value' => round(max(1, (float) ($earning['candle_club_multiplier_value'] ?? 2)), 2),
                'candle_club_free_shipping_enabled' => (bool) ($earning['candle_club_free_shipping_enabled'] ?? false),
                'rewardable_channels' => $this->enumOrDefault($earning['rewardable_channels'] ?? null, ['online_only', 'show_issued_online_redeemed', 'exclude_shows'], 'online_only'),
                'exclusions' => $this->normalizedExclusions($redemption['exclusions'] ?? []),
            ]);

            $frontend = array_merge($frontend, [
                'central_title' => $this->stringOrDefault($customer['lookup_experience_name'] ?? null, 'Rewards Central'),
                'central_subtitle' => $this->stringOrDefault($customer['lookup_copy'] ?? null, 'See your available rewards and use them on your next order.'),
                'redeem_cta_label' => $this->stringOrDefault($customer['redeem_cta_label'] ?? null, 'Redeem Reward Credit'),
                'wallet_label' => $this->stringOrDefault($customer['wallet_label'] ?? null, 'Rewards wallet'),
                'success_message' => $this->nullableString($customer['success_message'] ?? null),
                'expiration_message' => $this->nullableString($customer['expiration_message'] ?? null),
                'empty_state_message' => $this->nullableString($customer['empty_state_message'] ?? null),
                'coming_soon_message' => $this->nullableString($customer['coming_soon_message'] ?? null),
            ]);

            $notifications = array_merge($notifications, [
                'expiration_mode' => $this->enumOrDefault($expiration['expiration_mode'] ?? null, ['days_from_issue', 'end_of_season', 'none'], 'days_from_issue'),
                'expiration_days' => max(1, (int) ($expiration['expiration_days'] ?? 90)),
                'email_enabled' => (bool) ($expiration['email_enabled'] ?? true),
                'sms_enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                'reminder_offsets_days' => $this->normalizedReminderOffsets(
                    $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? [14, 7, 1],
                    [14, 7, 1]
                ),
                'email_reminder_offsets_days' => $this->normalizedReminderOffsets(
                    $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? [14, 7, 1],
                    [14, 7, 1]
                ),
                'sms_reminder_offsets_days' => $this->normalizedReminderOffsets(
                    $expiration['sms_reminder_offsets_days'] ?? ((bool) ($expiration['sms_enabled'] ?? false) ? [3] : []),
                    (bool) ($expiration['sms_enabled'] ?? false) ? [3] : [],
                    true
                ),
                'sms_max_per_reward' => max(0, (int) ($expiration['sms_max_per_reward'] ?? 1)),
                'sms_quiet_days' => max(0, (int) ($expiration['sms_quiet_days'] ?? 14)),
                'templates' => [
                    'subject_line' => $this->stringOrDefault($expiration['templates']['subject_line'] ?? null, 'You still have rewards available'),
                    'preview_text' => $this->nullableString($expiration['templates']['preview_text'] ?? null),
                    'sms_body' => $this->nullableString($expiration['templates']['sms_body'] ?? null),
                    'email_headline' => $this->nullableString($expiration['templates']['email_headline'] ?? null),
                    'email_body' => $this->nullableString($expiration['templates']['email_body'] ?? null),
                    'email_cta' => $this->nullableString($expiration['templates']['email_cta'] ?? null),
                ],
            ]);

            $finance = array_merge($finance, [
                'liability_alert_threshold_dollars' => $this->nullablePositiveFloat($financePolicy['liability_alert_threshold_dollars'] ?? null),
                'breakage_reporting_visible' => (bool) ($financePolicy['breakage_reporting_visible'] ?? true),
                'fraud_sensitivity_mode' => $this->enumOrDefault($financePolicy['fraud_sensitivity_mode'] ?? null, ['low', 'balanced', 'high'], 'balanced'),
                'require_minimum_order_for_redemption' => (bool) ($financePolicy['require_minimum_order_for_redemption'] ?? false),
                'manual_grant_approval_threshold_dollars' => $this->nullablePositiveFloat($financePolicy['manual_grant_approval_threshold_dollars'] ?? null),
            ]);

            $access = array_merge($access, [
                'launch_state' => $this->enumOrDefault($accessPolicy['launch_state'] ?? null, ['draft', 'published', 'scheduled'], 'published'),
                'test_mode' => (bool) ($accessPolicy['test_mode'] ?? false),
                'scheduled_launch_at' => $this->nullableString($accessPolicy['scheduled_launch_at'] ?? null),
                'policy_version' => $nextVersion,
                'policy_last_updated_at' => $savedAt,
                'policy_last_updated_by' => $savedBy,
            ]);

            $historyItems = is_array($versionHistory['items'] ?? null)
                ? (array) $versionHistory['items']
                : [];

            $historyItems[] = [
                'version' => $nextVersion,
                'saved_at' => $savedAt,
                'saved_by' => $savedBy,
                'changed_fields' => array_keys($versionedChanges),
                'changes' => $versionedChanges,
                'policy_snapshot' => [
                    'program_identity' => $policy['program_identity'] ?? [],
                    'value_model' => $policy['value_model'] ?? [],
                    'earning_rules' => $policy['earning_rules'] ?? [],
                    'redemption_rules' => $policy['redemption_rules'] ?? [],
                    'expiration_and_reminders' => $policy['expiration_and_reminders'] ?? [],
                    'customer_experience' => $policy['customer_experience'] ?? [],
                    'finance_and_safety' => $policy['finance_and_safety'] ?? [],
                    'access_state' => $policy['access_state'] ?? [],
                ],
            ];

            $historyItems = array_slice(array_values($historyItems), -30);

            $versionMetaPayload = [
                'current_version' => $nextVersion,
                'last_updated_at' => $savedAt,
                'last_updated_by' => $savedBy,
                'last_changed_fields' => array_keys($versionedChanges),
            ];

            $this->saveSetting($tenantId, self::PROGRAM_KEY, $program, 'Tenant rewards program runtime config.');
            $this->saveSetting($tenantId, self::FRONTEND_KEY, $frontend, 'Tenant rewards customer-facing copy and labels.');
            $this->saveSetting($tenantId, self::NOTIFICATION_KEY, $notifications, 'Tenant rewards expiration and reminder settings.');
            $this->saveSetting($tenantId, self::FINANCE_KEY, $finance, 'Tenant rewards finance and safety settings.');
            $this->saveSetting($tenantId, self::ACCESS_STATE_KEY, $access, 'Tenant rewards launch and activation state.');
            $this->saveSetting($tenantId, self::VERSION_META_KEY, $versionMetaPayload, 'Tenant rewards policy version metadata.');
            $this->saveSetting($tenantId, self::VERSION_HISTORY_KEY, ['items' => $historyItems], 'Tenant rewards policy version history.');

            $policyPayload = [
                'program_identity' => $policy['program_identity'] ?? [],
                'value_model' => $policy['value_model'] ?? [],
                'earning_rules' => $policy['earning_rules'] ?? [],
                'redemption_rules' => $policy['redemption_rules'] ?? [],
                'customer_experience' => $policy['customer_experience'] ?? [],
            ];

            $this->saveSetting($tenantId, self::POLICY_KEY, $policyPayload, 'Tenant rewards policy schema backing business-language admin controls.');
        }

        if ($versionedChanges !== [] || $operationalChanges !== []) {
            $this->auditService->record(
                tenantId: $tenantId,
                policyVersion: $nextVersion,
                launchState: (string) (($accessPolicy['launch_state'] ?? $access['launch_state'] ?? 'published')),
                changes: $changes,
                context: $context
            );
        }
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $messages
     * @param  array<string,mixed>  $versioning
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function resolvedPolicyPayload(
        ?int $tenantId,
        array $policy,
        array $messages,
        array $versioning,
        array $context = []
    ): array {
        $summary = $this->summaryService->summarize($policy);
        $fieldControls = $this->fieldControls($policy, $context);
        $reminderHistory = $tenantId !== null ? $this->reminderLogService->recentForTenant($tenantId) : [];
        $supportActionHistory = $tenantId !== null ? $this->auditService->recentSupportActionsForTenant($tenantId) : [];
        $alphaReference = $this->alphaReferencePolicy($policy);
        $readiness = $this->readinessService->evaluate($tenantId, $policy, [
            'versioning' => $versioning,
            'messages' => $messages,
            'summary' => $summary,
            'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
            'editable' => (bool) ($context['editable'] ?? true),
            'alpha_reference' => $alphaReference,
        ]);
        $reportFilters = is_array($context['report_filters'] ?? null) ? (array) $context['report_filters'] : [];
        $financeSummary = $tenantId !== null
            ? $this->financeSummaryService->summaryForTenant($tenantId, $policy, [
                'policy_version' => max(0, (int) ($versioning['current_version'] ?? 0)),
                'expiring_soon_days' => max(1, (int) ($reportFilters['expiring_soon_days'] ?? 14)),
            ])
            : [];
        $reminderReporting = $tenantId !== null
            ? $this->reminderAnalyticsService->reportForTenant($tenantId, $policy, [
                'policy_version' => max(0, (int) ($versioning['current_version'] ?? 0)),
                'readiness' => $readiness,
                'finance_summary' => $financeSummary,
                'alert_thresholds' => (array) ($policy['automation_and_reporting'] ?? []),
                'filters' => $reportFilters,
                'activity_window_days' => max(1, (int) ($reportFilters['activity_window_days'] ?? 30)),
                'upcoming_window_days' => max(1, (int) ($reportFilters['upcoming_window_days'] ?? 7)),
                'expiring_soon_days' => max(1, (int) ($reportFilters['expiring_soon_days'] ?? 14)),
            ])
            : [];
        $operations = $tenantId !== null
            ? $this->operationsService->resolvedPayload($tenantId, $policy, [
                'actor_user' => $context['actor_user'] ?? null,
                'readiness' => $readiness,
                'reminder_reporting' => $reminderReporting,
                'finance_summary' => $financeSummary,
            ])
            : [
                'automation_and_reporting' => $policy['automation_and_reporting'] ?? [],
                'team_access' => $policy['team_access'] ?? [],
                'automation' => [],
                'alerts' => [],
                'permissions' => [],
                'usage_indicators' => [],
                'simulation_view' => [],
            ];

        return [
            ...$policy,
            'automation_and_reporting' => $operations['automation_and_reporting'] ?? ($policy['automation_and_reporting'] ?? []),
            'team_access' => $operations['team_access'] ?? ($policy['team_access'] ?? []),
            'summary' => $summary,
            'exclusions_summary' => $this->summaryService->exclusionsSummary($policy),
            'channel_strategy_summary' => $this->summaryService->channelStrategySummary($policy),
            'warnings' => $messages['warnings'] ?? [],
            'messages' => $messages,
            'has_risk_warnings' => ((array) ($messages['warnings'] ?? [])) !== [],
            'field_controls' => $fieldControls,
            'message_previews' => $this->previewService->previews($policy),
            'versioning' => $versioning,
            'audit_history' => $tenantId !== null ? $this->auditService->recentForTenant($tenantId) : [],
            'support_action_history' => $supportActionHistory,
            'reminder_history' => $reminderHistory,
            'reminder_reporting' => $reminderReporting,
            'finance_summary' => $financeSummary,
            'automation' => $operations['automation'] ?? [],
            'alerts' => $operations['alerts'] ?? [],
            'permissions' => $operations['permissions'] ?? [],
            'usage_indicators' => $operations['usage_indicators'] ?? [],
            'simulation_view' => $operations['simulation_view'] ?? [],
            'readiness' => $readiness,
            'policy_options' => [
                'channel_strategies' => $this->channelStrategyOptions(),
                'exclusion_labels' => $this->exclusionLabels(),
                'automation_modes' => $this->automationModeOptions(),
                'report_frequencies' => $this->reportFrequencyOptions(),
                'report_delivery_modes' => $this->reportDeliveryModeOptions(),
                'team_access_roles' => $this->teamAccessRoleOptions(),
            ],
            'publish_preview' => [
                'live_version' => max(0, (int) ($versioning['current_version'] ?? 0)),
                'pending_version' => max(1, (int) ($versioning['current_version'] ?? 0)),
                'live_summary' => $summary,
                'pending_summary' => $summary,
                'change_preview' => [],
            ],
            'alpha_preset' => $this->alphaPresetStatus($policy, $alphaReference),
            'runtime_traceability' => [
                'active_policy_version' => max(0, (int) ($versioning['current_version'] ?? 0)),
                'reminder_trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
                'reminder_history_count' => count($reminderHistory),
                'support_action_history_count' => count($supportActionHistory),
                'latest_reminder_reporting_version' => max(0, (int) data_get($reminderReporting, 'policy_version', 0)),
            ],
            'context' => [
                'tenant_id' => $tenantId,
                'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
                'editable' => (bool) ($context['editable'] ?? true),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $alphaReference
     * @return array<string,mixed>
     */
    protected function alphaPresetStatus(array $policy, array $alphaReference): array
    {
        $matches = $this->readinessService->matchesAlphaPreset($policy, $alphaReference);

        return [
            'matches_recommended_default' => (bool) $matches,
            'headline' => $matches ? 'Alpha starter setup is active.' : 'Alpha starter setup has custom changes.',
            'items' => [
                'Program name: '.((string) data_get($policy, 'program_identity.program_name', 'Candle Cash')),
                'Second-order reward: $'.number_format((float) data_get($policy, 'earning_rules.second_order_reward_amount', 0), 2),
                'Minimum order to use rewards: $'.number_format((float) data_get($policy, 'value_model.minimum_purchase_dollars', 0), 2),
                'Largest reward per order: $'.number_format((float) data_get($policy, 'value_model.max_redeemable_per_order_dollars', 0), 2),
                'Expiration window: '.((int) data_get($policy, 'expiration_and_reminders.expiration_days', 0)).' days',
                'Reminder emails: '.$this->humanizeOffsets((array) data_get($policy, 'expiration_and_reminders.email_reminder_offsets_days', [])),
                'Text reminders: '.$this->humanizeOffsets((array) data_get($policy, 'expiration_and_reminders.sms_reminder_offsets_days', [])),
                'Channel strategy: '.$this->summaryService->channelStrategySummary($policy),
                'Exclusions: '.$this->summaryService->exclusionsSummary($policy),
                'Reward code delivery: '.str_replace('_', ' ', (string) data_get($policy, 'redemption_rules.code_strategy', 'unique_per_customer')),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $basePolicy
     * @return array<string,mixed>
     */
    protected function alphaReferencePolicy(array $basePolicy): array
    {
        return $this->normalizeCorePolicy($this->deepMerge($basePolicy, $this->alphaDefaultPatch()));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,array{access:string,message:?string}>
     */
    protected function fieldControls(array $policy, array $context = []): array
    {
        $value = (array) ($policy['value_model'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $access = (array) ($policy['access_state'] ?? []);

        $controls = [
            'program_identity.program_name' => ['access' => 'editable', 'message' => null],
            'program_identity.short_label' => ['access' => 'editable', 'message' => null],
            'program_identity.terminology_mode' => ['access' => 'editable', 'message' => null],
            'program_identity.description' => ['access' => 'editable', 'message' => null],

            'value_model.currency_mode' => ['access' => 'editable', 'message' => null],
            'value_model.points_per_dollar' => ['access' => 'editable', 'message' => null],
            'value_model.redeem_increment_dollars' => ['access' => 'editable', 'message' => null],
            'value_model.max_redeemable_per_order_dollars' => ['access' => 'editable', 'message' => null],
            'value_model.minimum_purchase_dollars' => ['access' => 'editable_with_warning', 'message' => 'Lower minimum purchase values can tighten margin.'],

            'finance_and_safety.max_open_codes' => ['access' => 'editable_with_warning', 'message' => 'Higher open-code limits can increase outstanding liability.'],
            'earning_rules.rewardable_channels' => ['access' => 'editable', 'message' => null],
            'redemption_rules.code_strategy' => ['access' => 'editable_with_warning', 'message' => 'Shared codes reduce customer-level attribution precision.'],
            'redemption_rules.stacking_mode' => ['access' => 'editable_with_warning', 'message' => 'Stacking can increase discount spend and reduce margin.'],
            'redemption_rules.max_codes_per_order' => ['access' => 'editable_with_warning', 'message' => 'Allowing multiple codes per order can increase discount exposure.'],
            'redemption_rules.platform_supports_multi_code' => ['access' => 'restricted', 'message' => 'Platform compatibility is managed by operations and cannot be changed here.'],
            'redemption_rules.exclusions.wholesale' => ['access' => 'editable', 'message' => null],
            'redemption_rules.exclusions.sale_items' => ['access' => 'editable', 'message' => null],
            'redemption_rules.exclusions.bundles' => ['access' => 'editable', 'message' => null],
            'redemption_rules.exclusions.limited_releases' => ['access' => 'editable', 'message' => null],
            'redemption_rules.exclusions.subscriptions' => ['access' => 'editable', 'message' => null],
            'redemption_rules.exclusions.collections' => ['access' => 'editable_with_warning', 'message' => 'Collection-specific exclusions should be reviewed as merchandising changes.'],
            'redemption_rules.exclusions.products' => ['access' => 'editable_with_warning', 'message' => 'Product-specific exclusions should be reviewed as the catalog changes.'],
            'redemption_rules.exclusions.tags' => ['access' => 'editable_with_warning', 'message' => 'Tag-based exclusions depend on current product tagging staying clean.'],

            'expiration_and_reminders.expiration_mode' => ['access' => 'editable_with_warning', 'message' => 'No-expiration settings can grow long-term liability.'],
            'expiration_and_reminders.expiration_days' => ['access' => 'editable', 'message' => null],
            'expiration_and_reminders.reminder_offsets_days' => ['access' => 'editable', 'message' => null],
            'expiration_and_reminders.email_reminder_offsets_days' => ['access' => 'editable', 'message' => null],
            'expiration_and_reminders.sms_reminder_offsets_days' => ['access' => 'editable_with_warning', 'message' => 'Text reminder timing should be used sparingly and stay close to expiration.'],
            'expiration_and_reminders.sms_max_per_reward' => ['access' => 'editable_with_warning', 'message' => 'Frequent text reminders can increase unsubscribe risk.'],
            'expiration_and_reminders.sms_quiet_days' => ['access' => 'editable_with_warning', 'message' => 'Short quiet periods can increase opt-out risk.'],
            'expiration_and_reminders.email_enabled' => ['access' => 'editable', 'message' => null],
            'expiration_and_reminders.sms_enabled' => ['access' => 'editable', 'message' => null],

            'finance_and_safety.liability_alert_threshold_dollars' => ['access' => 'editable_with_warning', 'message' => 'Use a threshold that reflects your monthly liability tolerance.'],
            'finance_and_safety.fraud_sensitivity_mode' => ['access' => 'editable_with_warning', 'message' => 'Lower fraud sensitivity can increase reward abuse risk.'],
            'finance_and_safety.manual_grant_approval_threshold_dollars' => ['access' => 'editable_with_warning', 'message' => 'Set approval thresholds carefully for manual grants.'],

            'access_state.launch_state' => ['access' => 'editable', 'message' => null],
            'access_state.scheduled_launch_at' => ['access' => 'editable', 'message' => null],
            'access_state.test_mode' => ['access' => 'editable_with_warning', 'message' => 'Test mode can hide live behavior from customers if left enabled.'],

            'automation_and_reporting.automation_mode' => ['access' => 'editable', 'message' => null],
            'automation_and_reporting.alert_email_enabled' => ['access' => 'editable', 'message' => null],
            'automation_and_reporting.alert_email' => ['access' => 'editable_with_warning', 'message' => 'Alert emails should go to an actively monitored inbox.'],
            'automation_and_reporting.alert_no_sends_hours' => ['access' => 'editable_with_warning', 'message' => 'A shorter threshold will surface more no-send warnings.'],
            'automation_and_reporting.alert_high_skip_rate_percent' => ['access' => 'editable_with_warning', 'message' => 'Lower skip-rate thresholds will surface more operational alerts.'],
            'automation_and_reporting.alert_failure_spike_count' => ['access' => 'editable_with_warning', 'message' => 'Lower failure thresholds will surface more delivery alerts.'],
            'automation_and_reporting.report_frequency' => ['access' => 'editable', 'message' => null],
            'automation_and_reporting.report_delivery_mode' => ['access' => 'editable', 'message' => null],
            'automation_and_reporting.report_email' => ['access' => 'editable_with_warning', 'message' => 'Scheduled finance reports should go to a shared finance inbox.'],
            'automation_and_reporting.report_day_of_week' => ['access' => 'editable', 'message' => null],

            'team_access.edit_role' => ['access' => 'editable_with_warning', 'message' => 'Restrict edit access carefully so daily operators do not get blocked unexpectedly.'],
            'team_access.publish_role' => ['access' => 'editable_with_warning', 'message' => 'Publishing controls should match who is accountable for live changes.'],
            'team_access.support_role' => ['access' => 'editable_with_warning', 'message' => 'Support tools should stay limited to trusted operators.'],
            'team_access.automation_role' => ['access' => 'editable_with_warning', 'message' => 'Automation mode changes should stay limited to trusted operators.'],
        ];

        if (($value['currency_mode'] ?? 'fixed_cash') !== 'points_to_cash') {
            $controls['value_model.points_per_dollar'] = [
                'access' => 'restricted',
                'message' => 'Points conversion is only shown when value mode is points.',
            ];
        }

        if (($access['launch_state'] ?? 'published') !== 'scheduled') {
            $controls['access_state.scheduled_launch_at'] = [
                'access' => 'restricted',
                'message' => 'Launch timestamp is only used when launch state is scheduled.',
            ];
        }

        if (! (bool) ($context['sms_channel_enabled'] ?? true)) {
            $controls['expiration_and_reminders.sms_enabled'] = [
                'access' => 'restricted',
                'message' => 'Text reminders require plan access for SMS.',
            ];
            $controls['expiration_and_reminders.sms_max_per_reward'] = [
                'access' => 'restricted',
                'message' => 'Text reminder limits are unavailable until SMS is enabled.',
            ];
            $controls['expiration_and_reminders.sms_quiet_days'] = [
                'access' => 'restricted',
                'message' => 'Text quiet period is unavailable until SMS is enabled.',
            ];
            $controls['expiration_and_reminders.sms_reminder_offsets_days'] = [
                'access' => 'restricted',
                'message' => 'Text reminder timing is unavailable until SMS is enabled.',
            ];
        }

        if (! (bool) ($context['editable'] ?? true)) {
            return collect($controls)
                ->map(fn (array $row): array => [
                    'access' => 'restricted',
                    'message' => 'Plan access is required before this setting can be edited.',
                ])
                ->all();
        }

        return $controls;
    }

    /**
     * @param  array<string,mixed>  $patch
     * @param  array<string,array{access:string,message:?string}>  $controls
     * @return array<string,array<int,string>>
     */
    protected function restrictedFieldErrors(array $patch, array $controls): array
    {
        $errors = [];
        $paths = array_keys(Arr::dot($patch));

        foreach ($paths as $path) {
            $matched = $this->matchedControlPath($path, $controls);
            if ($matched === null) {
                continue;
            }

            $control = (array) ($controls[$matched] ?? []);
            if (($control['access'] ?? 'editable') !== 'restricted') {
                continue;
            }

            $errors[$matched][] = (string) ($control['message'] ?? 'This setting is restricted and cannot be changed here.');
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     * @return array<string,mixed>
     */
    protected function deepMerge(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $key => $value) {
            if (is_array($value) && is_array($merged[$key] ?? null)) {
                if (array_is_list($value) || array_is_list((array) $merged[$key])) {
                    $merged[$key] = $value;

                    continue;
                }

                $merged[$key] = $this->deepMerge((array) $merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    protected function setting(string $key, ?int $tenantId, array $fallback = []): array
    {
        return $this->settingsResolver->array($key, $tenantId, $fallback);
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function saveSetting(?int $tenantId, string $key, array $value, string $description): void
    {
        if ($tenantId !== null) {
            if (! Schema::hasTable('tenant_marketing_settings')) {
                throw new \RuntimeException('tenant_marketing_settings table is required for tenant rewards policy updates.');
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

    protected function stringOrDefault(mixed $value, string $fallback): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function nullablePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = round((float) $value, 2);

        return $number > 0 ? $number : null;
    }

    /**
     * @param  array<int,string>  $allowed
     */
    protected function enumOrDefault(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * @return array<int,string>
     */
    protected function normalizedStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => strtolower(trim((string) $item)))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->unique()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function normalizedExclusions(mixed $value): array
    {
        $input = is_array($value) ? $value : [];

        return [
            'wholesale' => (bool) ($input['wholesale'] ?? true),
            'sale_items' => (bool) ($input['sale_items'] ?? true),
            'bundles' => (bool) ($input['bundles'] ?? false),
            'limited_releases' => (bool) ($input['limited_releases'] ?? false),
            'subscriptions' => (bool) ($input['subscriptions'] ?? true),
            'collections' => $this->normalizedStringList($input['collections'] ?? []),
            'products' => $this->normalizedStringList($input['products'] ?? []),
            'tags' => $this->normalizedStringList($input['tags'] ?? []),
        ];
    }

    /**
     * @return array<int,array{spend_dollars:float,reward_dollars:float}>
     */
    protected function normalizedThresholdRewards(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $spend = round(max(0, (float) ($row['spend_dollars'] ?? 0)), 2);
                $reward = round(max(0, (float) ($row['reward_dollars'] ?? 0)), 2);
                if ($spend <= 0 || $reward <= 0) {
                    return null;
                }

                return [
                    'spend_dollars' => $spend,
                    'reward_dollars' => $reward,
                ];
            })
            ->filter(fn ($row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    protected function normalizedReminderOffsets(mixed $value, array $fallback = [14, 7, 1], bool $allowEmpty = false): array
    {
        if (! is_array($value)) {
            return $allowEmpty ? $fallback : ($fallback !== [] ? $fallback : [14, 7, 1]);
        }

        $offsets = collect($value)
            ->map(fn ($item): int => max(0, (int) $item))
            ->filter(fn (int $item): bool => $item >= 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->take(10)
            ->all();

        if ($offsets !== []) {
            return $offsets;
        }

        if ($allowEmpty) {
            return $fallback;
        }

        return $fallback !== [] ? $fallback : [14, 7, 1];
    }

    /**
     * @param  array<string,array<int,string>>  ...$bags
     * @return array<string,array<int,string>>
     */
    protected function mergeErrors(array ...$bags): array
    {
        $merged = [];

        foreach ($bags as $bag) {
            foreach ($bag as $key => $messages) {
                if (! is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    $message = trim((string) $message);
                    if ($message === '') {
                        continue;
                    }

                    $merged[$key] ??= [];
                    if (! in_array($message, $merged[$key], true)) {
                        $merged[$key][] = $message;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * @param  array<string,array{access:string,message:?string}>  $controls
     */
    protected function matchedControlPath(string $path, array $controls): ?string
    {
        $path = $this->canonicalPath($path);
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        while ($segments !== []) {
            $candidate = implode('.', $segments);
            if (array_key_exists($candidate, $controls)) {
                return $candidate;
            }

            array_pop($segments);
        }

        return null;
    }

    protected function canonicalPath(string $path): string
    {
        $segments = collect(explode('.', trim($path)))
            ->map(fn (string $segment): string => trim($segment))
            ->filter(fn (string $segment): bool => $segment !== '' && ! ctype_digit($segment))
            ->values()
            ->all();

        return implode('.', $segments);
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,array{old:mixed,new:mixed}>
     */
    protected function policyChanges(array $before, array $after): array
    {
        $beforeFlat = Arr::dot($before);
        $afterFlat = Arr::dot($after);

        $keys = collect(array_keys($beforeFlat))
            ->merge(array_keys($afterFlat))
            ->unique()
            ->sort()
            ->values();

        $changes = [];
        foreach ($keys as $key) {
            $old = $beforeFlat[$key] ?? null;
            $new = $afterFlat[$key] ?? null;
            if ($old === $new) {
                continue;
            }

            $changes[$key] = [
                'old' => $old,
                'new' => $new,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<int,array<string,mixed>>
     */
    protected function changePreview(array $before, array $after): array
    {
        $rows = [];
        $comparisons = [
            [
                'path' => 'program_identity.program_name',
                'label' => 'Program name',
                'formatter' => fn (array $policy): string => (string) data_get($policy, 'program_identity.program_name', 'Rewards'),
            ],
            [
                'path' => 'earning_rules.second_order_reward_amount',
                'label' => 'Second-order reward',
                'formatter' => fn (array $policy): string => '$'.number_format((float) data_get($policy, 'earning_rules.second_order_reward_amount', 0), 2),
            ],
            [
                'path' => 'value_model.minimum_purchase_dollars',
                'label' => 'Minimum order to use rewards',
                'formatter' => fn (array $policy): string => '$'.number_format((float) data_get($policy, 'value_model.minimum_purchase_dollars', 0), 2),
            ],
            [
                'path' => 'value_model.max_redeemable_per_order_dollars',
                'label' => 'Largest reward per order',
                'formatter' => fn (array $policy): string => '$'.number_format((float) data_get($policy, 'value_model.max_redeemable_per_order_dollars', 0), 2),
            ],
            [
                'path' => 'expiration_and_reminders.expiration_days',
                'label' => 'Expiration window',
                'formatter' => fn (array $policy): string => (int) data_get($policy, 'expiration_and_reminders.expiration_days', 0).' days',
            ],
            [
                'path' => 'expiration_and_reminders.email_reminder_offsets_days',
                'label' => 'Reminder emails',
                'formatter' => fn (array $policy): string => $this->humanizeOffsets((array) data_get($policy, 'expiration_and_reminders.email_reminder_offsets_days', [])),
            ],
            [
                'path' => 'expiration_and_reminders.sms_reminder_offsets_days',
                'label' => 'Text reminders',
                'formatter' => fn (array $policy): string => $this->humanizeOffsets((array) data_get($policy, 'expiration_and_reminders.sms_reminder_offsets_days', [])),
            ],
            [
                'path' => 'expiration_and_reminders.sms_max_per_reward',
                'label' => 'Text reminder limit',
                'formatter' => fn (array $policy): string => (string) max(0, (int) data_get($policy, 'expiration_and_reminders.sms_max_per_reward', 0)),
            ],
            [
                'path' => 'earning_rules.rewardable_channels',
                'label' => 'Channel strategy',
                'formatter' => fn (array $policy): string => $this->summaryService->channelStrategySummary($policy),
            ],
            [
                'path' => 'redemption_rules.stacking_mode',
                'label' => 'Stacking behavior',
                'formatter' => fn (array $policy): string => str_replace('_', ' ', (string) data_get($policy, 'redemption_rules.stacking_mode', 'no_stacking')),
            ],
            [
                'path' => 'redemption_rules.code_strategy',
                'label' => 'Reward code delivery',
                'formatter' => fn (array $policy): string => str_replace('_', ' ', (string) data_get($policy, 'redemption_rules.code_strategy', 'unique_per_customer')),
            ],
            [
                'path' => 'access_state.launch_state',
                'label' => 'Program status',
                'formatter' => fn (array $policy): string => str_replace('_', ' ', (string) data_get($policy, 'access_state.launch_state', 'published')),
            ],
        ];

        foreach ($comparisons as $comparison) {
            $old = ($comparison['formatter'])($before);
            $new = ($comparison['formatter'])($after);
            if ($old === $new) {
                continue;
            }

            $label = (string) ($comparison['label'] ?? 'Setting');
            $rows[] = [
                'path' => (string) ($comparison['path'] ?? ''),
                'label' => $label,
                'old_value' => $old,
                'new_value' => $new,
                'message' => $label.' will change from '.$old.' to '.$new.'.',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int,int>  $offsets
     */
    protected function humanizeOffsets(array $offsets): string
    {
        $values = collect($offsets)
            ->map(fn (int $offset): string => $offset.' day'.($offset === 1 ? '' : 's'))
            ->values()
            ->all();

        if ($values === []) {
            return 'None selected';
        }

        if (count($values) === 1) {
            return $values[0].' before expiration';
        }

        if (count($values) === 2) {
            return $values[0].' and '.$values[1].' before expiration';
        }

        $last = array_pop($values);

        return implode(', ', $values).', and '.$last.' before expiration';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function channelStrategyOptions(): array
    {
        return [
            [
                'value' => 'online_only',
                'label' => 'Online only',
                'description' => 'Customers earn and use rewards on online orders only.',
                'available' => true,
            ],
            [
                'value' => 'show_issued_online_redeemed',
                'label' => 'Show issued, online redeemed',
                'description' => 'Customers can earn rewards at shows and use them on future online orders.',
                'available' => true,
            ],
            [
                'value' => 'exclude_shows',
                'label' => 'Exclude shows',
                'description' => 'Rewards stay focused on online purchases and skip show activity.',
                'available' => true,
            ],
            [
                'value' => 'online_show_hybrid',
                'label' => 'Online + show hybrid',
                'description' => 'Unavailable until show redemption support is confirmed in the commerce flow.',
                'available' => false,
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function exclusionLabels(): array
    {
        return [
            'wholesale' => 'Wholesale orders',
            'sale_items' => 'Sale items',
            'bundles' => 'Bundles / gift sets',
            'limited_releases' => 'Limited releases',
            'subscriptions' => 'Subscription items',
            'collections' => 'Excluded collections',
            'products' => 'Excluded products',
            'tags' => 'Excluded product tags',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function automationModeOptions(): array
    {
        return [
            [
                'value' => 'manual',
                'label' => 'Manual mode',
                'description' => 'Keep settings visible while requiring a team member to run reminders manually for this tenant.',
            ],
            [
                'value' => 'automatic',
                'label' => 'Run automatically',
                'description' => 'The hourly reminder processor handles eligible live reminders without manual intervention.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function reportFrequencyOptions(): array
    {
        return [
            ['value' => 'off', 'label' => 'Off', 'description' => 'Finance reports stay available in the rewards workspace only.'],
            ['value' => 'daily', 'label' => 'Daily', 'description' => 'Send a finance snapshot each day.'],
            ['value' => 'weekly', 'label' => 'Weekly', 'description' => 'Send a weekly finance snapshot on the selected weekday.'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function reportDeliveryModeOptions(): array
    {
        return [
            ['value' => 'email_link', 'label' => 'Email links', 'description' => 'Send finance summaries with temporary download links.'],
            ['value' => 'download_link', 'label' => 'Workspace only', 'description' => 'Keep finance exports available in the rewards workspace without email delivery.'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function teamAccessRoleOptions(): array
    {
        return [
            ['value' => 'tenant_member', 'label' => 'Any team member', 'description' => 'Any mapped tenant teammate can use this action.'],
            ['value' => 'marketing_manager_or_admin', 'label' => 'Marketing lead or admin', 'description' => 'Marketing managers, managers, and admins can use this action.'],
            ['value' => 'manager_or_admin', 'label' => 'Manager or admin', 'description' => 'Managers and admins can use this action.'],
            ['value' => 'admin_only', 'label' => 'Admin only', 'description' => 'Only admins can use this action.'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function versioningMetadata(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [
                'current_version' => 0,
                'last_updated_at' => null,
                'last_updated_by' => null,
                'history_count' => 0,
            ];
        }

        $versionMeta = (array) $this->setting(self::VERSION_META_KEY, $tenantId);
        $versionHistory = (array) $this->setting(self::VERSION_HISTORY_KEY, $tenantId, ['items' => []]);
        $access = (array) $this->setting(self::ACCESS_STATE_KEY, $tenantId);
        $historyItems = is_array($versionHistory['items'] ?? null)
            ? (array) $versionHistory['items']
            : [];
        $latestHistory = is_array(end($historyItems)) ? (array) end($historyItems) : [];

        $currentVersion = max(
            0,
            (int) ($versionMeta['current_version'] ?? 0),
            (int) ($access['policy_version'] ?? 0)
        );

        return [
            'current_version' => $currentVersion,
            'last_updated_at' => $this->nullableString($versionMeta['last_updated_at'] ?? null)
                ?? $this->nullableString($access['policy_last_updated_at'] ?? null)
                ?? $this->nullableString($latestHistory['saved_at'] ?? null),
            'last_updated_by' => is_array($versionMeta['last_updated_by'] ?? null)
                ? (array) $versionMeta['last_updated_by']
                : (is_array($access['policy_last_updated_by'] ?? null)
                    ? (array) $access['policy_last_updated_by']
                    : null),
            'history_count' => count($historyItems),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function alphaDefaultPatch(): array
    {
        return [
            'program_identity' => [
                'program_name' => 'Candle Cash',
                'short_label' => 'Candle Cash',
                'terminology_mode' => 'cash',
            ],
            'value_model' => [
                'currency_mode' => 'fixed_cash',
                'points_per_dollar' => 10,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'minimum_purchase_dollars' => 50,
            ],
            'earning_rules' => [
                'second_order_reward_amount' => 10,
                'candle_club_multiplier_enabled' => true,
                'candle_club_multiplier_value' => 2,
                'candle_club_free_shipping_enabled' => false,
                'rewardable_channels' => 'show_issued_online_redeemed',
            ],
            'redemption_rules' => [
                'code_strategy' => 'unique_per_customer',
                'stacking_mode' => 'no_stacking',
                'max_codes_per_order' => 1,
                'platform_supports_multi_code' => false,
                'exclusions' => [
                    'wholesale' => true,
                    'sale_items' => true,
                    'bundles' => false,
                    'limited_releases' => false,
                    'subscriptions' => true,
                ],
            ],
            'expiration_and_reminders' => [
                'expiration_mode' => 'days_from_issue',
                'expiration_days' => 90,
                'email_enabled' => true,
                'sms_enabled' => true,
                'reminder_offsets_days' => [14, 7, 1],
                'email_reminder_offsets_days' => [14, 7, 1],
                'sms_reminder_offsets_days' => [3],
                'sms_max_per_reward' => 1,
                'sms_quiet_days' => 3,
            ],
            'finance_and_safety' => [
                'max_open_codes' => 1,
                'fraud_sensitivity_mode' => 'balanced',
            ],
            'access_state' => [
                'launch_state' => 'published',
                'test_mode' => false,
            ],
        ];
    }
}
