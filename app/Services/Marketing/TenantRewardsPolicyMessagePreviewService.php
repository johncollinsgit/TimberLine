<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class TenantRewardsPolicyMessagePreviewService
{
    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function previews(array $policy): array
    {
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $reward = [
            'first_name' => 'Avery',
            'remaining_amount' => (float) data_get($policy, 'earning_rules.second_order_reward_amount', 0),
            'expires_at' => match ((string) ($expiration['expiration_mode'] ?? 'days_from_issue')) {
                'none' => null,
                'end_of_season' => now()->endOfQuarter()->toIso8601String(),
                default => now()->addDays(max(1, (int) ($expiration['expiration_days'] ?? 30)))->toIso8601String(),
            },
        ];

        $sms = $this->smsReminder($policy, $reward);
        $email = $this->emailReminder($policy, $reward);

        return [
            'sms' => [
                'enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                'body' => $sms['body'],
                'character_count' => mb_strlen($sms['body']),
                'segments' => max(1, (int) ceil(mb_strlen($sms['body']) / 160)),
            ],
            'email' => [
                'enabled' => (bool) ($expiration['email_enabled'] ?? true),
                'subject' => $email['subject'],
                'preview_text' => $email['preview_text'],
                'headline' => $email['headline'],
                'body' => $email['body'],
                'cta' => $email['cta'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     * @return array{subject:string,preview_text:string,headline:string,body:string,cta:string}
     */
    public function emailReminder(array $policy, array $reward = [], array $context = []): array
    {
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $templates = (array) ($expiration['templates'] ?? []);
        $tokens = $this->tokens($policy, $reward, $context);
        $programName = $this->stringOrDefault($tokens['program_name'] ?? null, 'Rewards');

        return [
            'subject' => $this->renderTemplate(
                $this->stringOrDefault($templates['subject_line'] ?? null, 'Your {{ program_name }} reward expires soon'),
                $tokens
            ),
            'preview_text' => $this->renderTemplate(
                $this->stringOrDefault($templates['preview_text'] ?? null, 'Use up to {{ max_redeem }} before {{ expiration_date }}.'),
                $tokens
            ),
            'headline' => $this->renderTemplate(
                $this->stringOrDefault($templates['email_headline'] ?? null, $programName.' is ready to use'),
                $tokens
            ),
            'body' => $this->renderTemplate(
                $this->stringOrDefault(
                    $templates['email_body'] ?? null,
                    'Hi {{ first_name }}, you still have {{ reward_value }} in {{ program_name }}. Use up to {{ max_redeem }} on orders over {{ minimum_purchase }} before {{ expiration_date }}. See your rewards: {{ rewards_url }}'
                ),
                $tokens
            ),
            'cta' => $this->renderTemplate(
                $this->stringOrDefault($templates['email_cta'] ?? null, 'Use my reward'),
                $tokens
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     * @return array{body:string}
     */
    public function smsReminder(array $policy, array $reward = [], array $context = []): array
    {
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $templates = (array) ($expiration['templates'] ?? []);
        $tokens = $this->tokens($policy, $reward, $context);

        return [
            'body' => $this->renderTemplate(
                $this->stringOrDefault(
                    $templates['sms_body'] ?? null,
                    'Hi {{ first_name }}, you still have {{ reward_value }} in {{ program_name }}. Use up to {{ max_redeem }} on orders over {{ minimum_purchase }} before {{ expiration_date }}. {{ sms_lead }} {{ rewards_url }}'
                ),
                $tokens
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $expiration
     */
    protected function expirationPhrase(array $expiration): string
    {
        $mode = (string) ($expiration['expiration_mode'] ?? 'days_from_issue');
        $days = max(1, (int) ($expiration['expiration_days'] ?? 30));

        return match ($mode) {
            'end_of_season' => 'the end of the season',
            'none' => 'your convenience (no expiration)',
            default => sprintf('%d days after it is earned', $days),
        };
    }

    /**
     * @param  array<string,mixed>  $expiration
     */
    protected function smsLead(array $expiration): string
    {
        $offsets = collect((array) ($expiration['reminder_offsets_days'] ?? []))
            ->map(fn ($item): int => max(0, (int) $item))
            ->sortDesc()
            ->values();

        $smsOffsets = collect((array) ($expiration['sms_reminder_offsets_days'] ?? []))
            ->map(fn ($item): int => max(0, (int) $item))
            ->sortDesc()
            ->values();

        if ($smsOffsets->isNotEmpty()) {
            $first = (int) $smsOffsets->first();

            return sprintf('Text reminder timing is set for %d days before expiration. Reply STOP to opt out.', $first);
        }

        if ($offsets->isEmpty()) {
            return 'Reply STOP to opt out.';
        }

        $first = (int) $offsets->first();

        return sprintf('Reminder timing is set for %d days before expiration. Reply STOP to opt out.', $first);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     * @return array<string,string>
     */
    protected function tokens(array $policy, array $reward, array $context = []): array
    {
        $identity = (array) ($policy['program_identity'] ?? []);
        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);

        $programName = $this->stringOrDefault($identity['program_name'] ?? null, 'Rewards');
        $firstName = $this->stringOrDefault($reward['first_name'] ?? null, 'there');
        $currencyMode = (string) ($value['currency_mode'] ?? 'fixed_cash');
        $pointsPerDollar = max(1, (int) ($value['points_per_dollar'] ?? CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH));
        $rewardAmount = round((float) ($reward['remaining_amount'] ?? $reward['remaining_candle_cash'] ?? $earning['second_order_reward_amount'] ?? 0), 2);
        $maxRedeem = round((float) ($value['max_redeemable_per_order_dollars'] ?? 0), 2);
        $minimumPurchase = round((float) ($value['minimum_purchase_dollars'] ?? 0), 2);
        $expiresAt = $this->asDate($reward['expires_at'] ?? $context['expires_at'] ?? null);
        $daysUntilExpiration = $expiresAt?->isFuture() || $expiresAt?->isToday()
            ? max(0, now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false))
            : null;

        $rewardValueLabel = $currencyMode === 'points_to_cash'
            ? sprintf('%d points', (int) round($rewardAmount * $pointsPerDollar))
            : '$'.number_format($rewardAmount, 2);

        $minimumPurchaseLabel = $minimumPurchase > 0
            ? '$'.number_format($minimumPurchase, 2)
            : 'any amount';

        $expirationDate = $expiresAt?->format('F j, Y') ?? $this->expirationPhrase($expiration);
        $rewardsUrl = rtrim((string) ($context['rewards_url'] ?? config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com')), '/').'/pages/rewards';

        return [
            'first_name' => $firstName,
            'program_name' => $programName,
            'reward_value' => $rewardValueLabel,
            'max_redeem' => '$'.number_format($maxRedeem, 2),
            'minimum_purchase' => $minimumPurchaseLabel,
            'expiration_date' => $expirationDate,
            'days_until_expiration' => $daysUntilExpiration !== null ? (string) $daysUntilExpiration : '0',
            'rewards_url' => $rewardsUrl,
            'sms_lead' => $this->smsLead($expiration),
        ];
    }

    /**
     * @param  array<string,string>  $tokens
     */
    protected function renderTemplate(string $template, array $tokens): string
    {
        $rendered = $template;
        foreach ($tokens as $key => $value) {
            $rendered = str_replace('{{ '.$key.' }}', $value, $rendered);
            $rendered = str_replace('{{'.$key.'}}', $value, $rendered);
        }

        return trim(preg_replace('/\s+/', ' ', $rendered) ?? $rendered);
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
}
