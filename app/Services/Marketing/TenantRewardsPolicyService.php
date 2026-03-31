<?php

namespace App\Services\Marketing;

use App\Models\MarketingSetting;
use App\Models\TenantMarketingSetting;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
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

    public function __construct(
        protected TenantMarketingSettingsResolver $settingsResolver,
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(?int $tenantId, array $context = []): array
    {
        $core = $this->corePolicy($tenantId);
        $warnings = $this->warnings($core);

        return [
            ...$core,
            'summary' => $this->summary($core),
            'warnings' => $warnings,
            'has_risk_warnings' => $warnings !== [],
            'context' => [
                'tenant_id' => $tenantId,
                'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
                'editable' => (bool) ($context['editable'] ?? true),
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
        $merged = $this->deepMerge($current, $this->allowedPatch($patch));
        $normalized = $this->normalizeCorePolicy($merged);

        $errors = $this->validationErrors($normalized, [
            'sms_channel_enabled' => (bool) ($context['sms_channel_enabled'] ?? true),
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->persist($tenantId, $normalized);
        $this->settingsResolver->flushArrayCache();

        return $this->resolve($tenantId, $context);
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

        $policyIdentity = is_array($policy['program_identity'] ?? null) ? $policy['program_identity'] : [];
        $policyValue = is_array($policy['value_model'] ?? null) ? $policy['value_model'] : [];
        $policyEarning = is_array($policy['earning_rules'] ?? null) ? $policy['earning_rules'] : [];
        $policyRedemption = is_array($policy['redemption_rules'] ?? null) ? $policy['redemption_rules'] : [];
        $policyCustomer = is_array($policy['customer_experience'] ?? null) ? $policy['customer_experience'] : [];

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
                'expiration_days' => max(1, (int) ($notifications['expiration_days'] ?? config('marketing.candle_cash.code_expiry_days', 30))),
                'email_enabled' => (bool) ($notifications['email_enabled'] ?? true),
                'sms_enabled' => (bool) ($notifications['sms_enabled'] ?? false),
                'reminder_offsets_days' => $this->normalizedReminderOffsets($notifications['reminder_offsets_days'] ?? [14, 7, 1]),
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
        ];

        return $this->normalizeCorePolicy($normalized);
    }

    /**
     * @param  array<string,mixed>  $core
     * @return array<int,array{level:string,code:string,message:string}>
     */
    protected function warnings(array $core): array
    {
        $warnings = [];

        $expiration = (array) ($core['expiration_and_reminders'] ?? []);
        $value = (array) ($core['value_model'] ?? []);
        $finance = (array) ($core['finance_and_safety'] ?? []);
        $redemption = (array) ($core['redemption_rules'] ?? []);

        if (($expiration['expiration_mode'] ?? 'days_from_issue') === 'none') {
            $warnings[] = [
                'level' => 'warning',
                'code' => 'no_expiration_liability_growth',
                'message' => 'No-expiration mode can increase long-term reward liability. Review liability thresholds regularly.',
            ];
        }

        $minimumPurchase = (float) ($value['minimum_purchase_dollars'] ?? 0);
        $maxRedeemable = (float) ($value['max_redeemable_per_order_dollars'] ?? 0);
        if ($minimumPurchase > 0 && $maxRedeemable > $minimumPurchase) {
            $warnings[] = [
                'level' => 'warning',
                'code' => 'max_redeem_exceeds_minimum_purchase',
                'message' => 'Max redeemable per order is higher than the minimum purchase threshold. Verify margin impact before publishing.',
            ];
        }

        if (($redemption['stacking_mode'] ?? 'no_stacking') === 'selected_promo_types'
            && ((array) ($redemption['selected_stackable_promo_types'] ?? [])) === []) {
            $warnings[] = [
                'level' => 'warning',
                'code' => 'selected_stacking_without_types',
                'message' => 'Selected promo stacking is enabled without selected promo types.',
            ];
        }

        if (($finance['fraud_sensitivity_mode'] ?? 'balanced') === 'low') {
            $warnings[] = [
                'level' => 'warning',
                'code' => 'low_fraud_sensitivity',
                'message' => 'Low fraud sensitivity may increase abuse risk. Unique per-customer codes are recommended.',
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string,mixed>  $core
     */
    protected function summary(array $core): string
    {
        $identity = (array) ($core['program_identity'] ?? []);
        $value = (array) ($core['value_model'] ?? []);
        $earning = (array) ($core['earning_rules'] ?? []);
        $expiration = (array) ($core['expiration_and_reminders'] ?? []);
        $redemption = (array) ($core['redemption_rules'] ?? []);

        $programName = (string) ($identity['program_name'] ?? 'Rewards');
        $secondOrder = round((float) ($earning['second_order_reward_amount'] ?? 0), 2);
        $maxRedeem = round((float) ($value['max_redeemable_per_order_dollars'] ?? 0), 2);
        $minimumOrder = round((float) ($value['minimum_purchase_dollars'] ?? 0), 2);
        $expirationMode = (string) ($expiration['expiration_mode'] ?? 'days_from_issue');
        $expirationDays = max(1, (int) ($expiration['expiration_days'] ?? 30));
        $emailOffsets = collect((array) ($expiration['reminder_offsets_days'] ?? []))
            ->filter(fn ($value): bool => is_int($value) || ctype_digit((string) $value))
            ->map(fn ($value): int => (int) $value)
            ->values();

        $expirationText = match ($expirationMode) {
            'end_of_season' => 'expire at the end of the selected season',
            'none' => 'do not expire',
            default => 'expire ' . $expirationDays . ' days after being earned',
        };

        $reminderText = (bool) ($expiration['email_enabled'] ?? true)
            ? ($emailOffsets->isNotEmpty()
                ? 'Email reminders are sent ' . $emailOffsets->implode(', ') . ' days before expiration.'
                : 'Email reminders are enabled.')
            : 'Email reminders are disabled.';

        $smsText = (bool) ($expiration['sms_enabled'] ?? false)
            ? 'SMS reminders are enabled (max ' . max(0, (int) ($expiration['sms_max_per_reward'] ?? 1)) . ' per reward).'
            : 'SMS reminders are disabled.';

        $stackingText = match ((string) ($redemption['stacking_mode'] ?? 'no_stacking')) {
            'shipping_only' => 'stacking with shipping discounts only',
            'selected_promo_types' => 'stacking with selected promo types',
            default => 'no stacking',
        };

        $minimumText = $minimumOrder > 0
            ? 'orders over $' . number_format($minimumOrder, 2)
            : 'any order';

        return sprintf(
            'Customers earn $%s in %s after their second order. They can use up to $%s per purchase on %s with %s. Rewards %s %s %s',
            number_format($secondOrder, 2),
            $programName,
            number_format($maxRedeem, 2),
            $minimumText,
            $stackingText,
            $expirationText,
            $reminderText,
            $smsText
        );
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
        $redemption = (array) ($policy['redemption_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $access = (array) ($policy['access_state'] ?? []);

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
            $errors['expiration_and_reminders.sms_enabled'][] = 'Text reminders require SMS plan/module access and channel readiness.';
        }

        $expirationMode = (string) ($expiration['expiration_mode'] ?? 'days_from_issue');
        $expirationDays = max(1, (int) ($expiration['expiration_days'] ?? 30));
        $offsets = (array) ($expiration['reminder_offsets_days'] ?? []);
        foreach ($offsets as $index => $offset) {
            $offsetValue = (int) $offset;
            if ($offsetValue < 0) {
                $errors['expiration_and_reminders.reminder_offsets_days.' . $index][] = 'Reminder timing offsets must be zero or greater.';
                continue;
            }

            if ($expirationMode === 'days_from_issue' && $offsetValue >= $expirationDays) {
                $errors['expiration_and_reminders.reminder_offsets_days.' . $index][] = 'Reminder timing must occur before the expiration window.';
            }
        }

        if (($access['launch_state'] ?? 'published') === 'scheduled' && $this->nullableString($access['scheduled_launch_at'] ?? null) === null) {
            $errors['access_state.scheduled_launch_at'][] = 'Scheduled launch state requires a scheduled launch timestamp.';
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
        ];

        return collect($policy)
            ->only($allowed)
            ->map(fn ($value): array => is_array($value) ? $value : [])
            ->all();
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
                'expiration_days' => max(1, (int) ($expiration['expiration_days'] ?? 30)),
                'email_enabled' => (bool) ($expiration['email_enabled'] ?? true),
                'sms_enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                'reminder_offsets_days' => $this->normalizedReminderOffsets($expiration['reminder_offsets_days'] ?? [14, 7, 1]),
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
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    protected function persist(int $tenantId, array $policy): void
    {
        $program = (array) $this->setting(self::PROGRAM_KEY, $tenantId, (array) config('marketing.candle_cash', []));
        $frontend = (array) $this->setting(self::FRONTEND_KEY, $tenantId);
        $notifications = (array) $this->setting(self::NOTIFICATION_KEY, $tenantId);
        $finance = (array) $this->setting(self::FINANCE_KEY, $tenantId);
        $access = (array) $this->setting(self::ACCESS_STATE_KEY, $tenantId);

        $identity = (array) ($policy['program_identity'] ?? []);
        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $redemption = (array) ($policy['redemption_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $customer = (array) ($policy['customer_experience'] ?? []);
        $financePolicy = (array) ($policy['finance_and_safety'] ?? []);
        $accessPolicy = (array) ($policy['access_state'] ?? []);

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
            'expiration_days' => max(1, (int) ($expiration['expiration_days'] ?? 30)),
            'email_enabled' => (bool) ($expiration['email_enabled'] ?? true),
            'sms_enabled' => (bool) ($expiration['sms_enabled'] ?? false),
            'reminder_offsets_days' => $this->normalizedReminderOffsets($expiration['reminder_offsets_days'] ?? [14, 7, 1]),
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
        ]);

        $this->saveSetting($tenantId, self::PROGRAM_KEY, $program, 'Tenant rewards program runtime config.');
        $this->saveSetting($tenantId, self::FRONTEND_KEY, $frontend, 'Tenant rewards customer-facing copy and labels.');
        $this->saveSetting($tenantId, self::NOTIFICATION_KEY, $notifications, 'Tenant rewards expiration and reminder settings.');
        $this->saveSetting($tenantId, self::FINANCE_KEY, $finance, 'Tenant rewards finance and safety settings.');
        $this->saveSetting($tenantId, self::ACCESS_STATE_KEY, $access, 'Tenant rewards launch and activation state.');

        $policyPayload = [
            'program_identity' => $policy['program_identity'] ?? [],
            'value_model' => $policy['value_model'] ?? [],
            'earning_rules' => $policy['earning_rules'] ?? [],
            'redemption_rules' => $policy['redemption_rules'] ?? [],
            'customer_experience' => $policy['customer_experience'] ?? [],
        ];
        $this->saveSetting($tenantId, self::POLICY_KEY, $policyPayload, 'Tenant rewards policy schema backing business-language admin controls.');
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
    protected function normalizedReminderOffsets(mixed $value): array
    {
        if (! is_array($value)) {
            return [14, 7, 1];
        }

        $offsets = collect($value)
            ->map(fn ($item): int => max(0, (int) $item))
            ->filter(fn (int $item): bool => $item >= 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->take(10)
            ->all();

        return $offsets !== [] ? $offsets : [14, 7, 1];
    }

}
