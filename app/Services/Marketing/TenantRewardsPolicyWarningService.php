<?php

namespace App\Services\Marketing;

class TenantRewardsPolicyWarningService
{
    /**
     * @param  array<string,mixed>  $policy
     * @return array{
     *   errors:array<int,array{level:string,code:string,message:string}>,
     *   warnings:array<int,array{level:string,code:string,message:string}>,
     *   info:array<int,array{level:string,code:string,message:string}>,
     *   all:array<int,array{level:string,code:string,message:string}>
     * }
     */
    public function evaluate(array $policy): array
    {
        $errors = [];
        $warnings = [];
        $info = [];

        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $redemption = (array) ($policy['redemption_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $finance = (array) ($policy['finance_and_safety'] ?? []);
        $access = (array) ($policy['access_state'] ?? []);
        $exclusions = (array) ($redemption['exclusions'] ?? []);

        $minimumPurchase = round((float) ($value['minimum_purchase_dollars'] ?? 0), 2);
        $redeemIncrement = round((float) ($value['redeem_increment_dollars'] ?? 0), 2);
        $maxRedeem = round((float) ($value['max_redeemable_per_order_dollars'] ?? 0), 2);

        if ($minimumPurchase > 0 && $minimumPurchase <= $maxRedeem) {
            $warnings[] = $this->message(
                'warning',
                'minimum_spend_margin_risk',
                'Minimum spend is close to reward value. Confirm margin impact before publishing.'
            );
        }

        if ((string) ($redemption['stacking_mode'] ?? 'no_stacking') !== 'no_stacking') {
            $warnings[] = $this->message(
                'warning',
                'stacking_discount_exposure',
                'Stacking is enabled, which can increase discount costs and reduce margin.'
            );
        }

        if ((string) ($expiration['expiration_mode'] ?? 'days_from_issue') === 'none') {
            $warnings[] = $this->message(
                'warning',
                'no_expiration_liability_growth',
                'No-expiration mode can increase long-term outstanding reward liability.'
            );
        }

        $smsEnabled = (bool) ($expiration['sms_enabled'] ?? false);
        $smsMax = max(0, (int) ($expiration['sms_max_per_reward'] ?? 0));
        $smsQuietDays = max(0, (int) ($expiration['sms_quiet_days'] ?? 0));
        $smsOffsets = array_values(array_map('intval', (array) ($expiration['sms_reminder_offsets_days'] ?? [])));
        if ($smsEnabled && ($smsMax > 2 || $smsQuietDays < 3)) {
            $warnings[] = $this->message(
                'warning',
                'sms_frequency_unsubscribe_risk',
                'High text reminder frequency can raise unsubscribe risk.'
            );
        }

        if ($smsEnabled && $smsOffsets === []) {
            $warnings[] = $this->message(
                'warning',
                'sms_timing_missing',
                'Text reminders are on, but no text reminder timing has been selected yet.'
            );
        }

        if ($redeemIncrement > 0 && $maxRedeem >= ($redeemIncrement * 3)) {
            $info[] = $this->message(
                'info',
                'high_max_redeem_info',
                'Largest per-order reward is materially above the base redemption increment.'
            );
        }

        if ((string) ($finance['fraud_sensitivity_mode'] ?? 'balanced') === 'low') {
            $warnings[] = $this->message(
                'warning',
                'low_fraud_sensitivity',
                'Low fraud sensitivity can increase abuse risk. Keep unique customer codes enabled.'
            );
        }

        if ((string) ($access['launch_state'] ?? 'published') === 'draft') {
            $info[] = $this->message(
                'info',
                'draft_mode_info',
                'Program settings are in draft mode and not live yet.'
            );
        }

        if ((string) ($redemption['code_strategy'] ?? 'unique_per_customer') === 'shared') {
            $errors[] = $this->message(
                'error',
                'shared_code_attribution_risk',
                'Shared reward codes reduce customer-level attribution clarity.'
            );
        }

        if (! $this->hasMeaningfulExclusions($exclusions)) {
            $warnings[] = $this->message(
                'warning',
                'permissive_exclusions',
                'No meaningful product exclusions are selected yet. Review wholesale, sale, subscription, and product-specific exclusions before launch.'
            );
        }

        if (! (bool) ($exclusions['sale_items'] ?? false) && ! (bool) ($exclusions['subscriptions'] ?? false)) {
            $warnings[] = $this->message(
                'warning',
                'high_margin_pressure_exclusions',
                'Sale items and subscription items are both eligible. Confirm margin and operational impact before going live.'
            );
        }

        if ((string) ($earning['rewardable_channels'] ?? 'online_only') === 'show_issued_online_redeemed') {
            $info[] = $this->message(
                'info',
                'show_issue_online_redeem_mode',
                'Show-earned rewards are set to redeem online, which can be a good launch-safe hybrid setup.'
            );
        }

        if ((string) ($earning['rewardable_channels'] ?? 'online_only') === 'exclude_shows') {
            $info[] = $this->message(
                'info',
                'exclude_shows_mode',
                'Show activity is excluded from rewards, so launch results will reflect online behavior only.'
            );
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'all' => array_values([...$errors, ...$warnings, ...$info]),
        ];
    }

    /**
     * @param  array<string,mixed>  $exclusions
     */
    protected function hasMeaningfulExclusions(array $exclusions): bool
    {
        if ((bool) ($exclusions['wholesale'] ?? false)
            || (bool) ($exclusions['sale_items'] ?? false)
            || (bool) ($exclusions['subscriptions'] ?? false)
            || (bool) ($exclusions['bundles'] ?? false)
            || (bool) ($exclusions['limited_releases'] ?? false)) {
            return true;
        }

        return ((array) ($exclusions['collections'] ?? [])) !== []
            || ((array) ($exclusions['products'] ?? [])) !== []
            || ((array) ($exclusions['tags'] ?? [])) !== [];
    }

    /**
     * @return array{level:string,code:string,message:string}
     */
    protected function message(string $level, string $code, string $message): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'message' => $message,
        ];
    }
}
