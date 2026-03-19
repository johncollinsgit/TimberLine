<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use Carbon\CarbonImmutable;

class CandleCashEarnedReminderService
{
    public function __construct(
        protected CandleCashEarnedAnalyticsService $analyticsService,
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingEmailReadiness $emailReadiness
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sendManualBatch(array $options = []): array
    {
        $readiness = $this->emailReadiness->summary();
        $limit = max(1, min(500, (int) ($options['limit'] ?? config('marketing.email.candle_cash_reminder.max_send_limit', 200))));
        $cooldownDays = max(1, min(90, (int) ($options['cooldown_days'] ?? config('marketing.email.candle_cash_reminder.cooldown_days', 14))));
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = isset($options['tenant_id']) && (int) $options['tenant_id'] > 0
            ? (int) $options['tenant_id']
            : null;

        if (in_array((string) ($readiness['status'] ?? ''), ['disabled', 'misconfigured'], true)) {
            return [
                'blocked' => true,
                'status' => (string) ($readiness['status'] ?? 'disabled'),
                'message' => 'Email reminders are blocked until marketing email configuration is enabled and complete.',
                'readiness' => $readiness,
                'summary' => [
                    'processed' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'skipped_no_email' => 0,
                    'skipped_cooldown' => 0,
                    'eligible' => 0,
                ],
            ];
        }

        $candidates = (array) $this->analyticsService->reminderCandidates($tenantId);
        $rows = collect((array) ($candidates['rows'] ?? []))
            ->filter(fn (array $row): bool => (float) ($row['outstanding_candle_cash'] ?? 0) > 0)
            ->sortByDesc('outstanding_candle_cash')
            ->values();

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped_no_email' => 0,
            'skipped_cooldown' => 0,
            'eligible' => (int) ($candidates['eligible_customers'] ?? 0),
            'missing_email_customers' => (int) ($candidates['missing_email_customers'] ?? 0),
            'cooldown_days' => $cooldownDays,
            'dry_run' => (bool) ($readiness['dry_run'] ?? false) || $dryRun,
        ];

        foreach ($rows->take($limit) as $recipient) {
            $summary['processed']++;

            $profileId = (int) ($recipient['marketing_profile_id'] ?? 0);
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($profileId <= 0 || $email === '') {
                $summary['skipped_no_email']++;

                continue;
            }

            if ($this->inCooldownWindow($profileId, $email, $cooldownDays)) {
                $summary['skipped_cooldown']++;

                continue;
            }

            $delivery = MarketingEmailDelivery::query()->create([
                'marketing_campaign_recipient_id' => null,
                'marketing_profile_id' => $profileId,
                'email' => $email,
                'status' => 'sending',
                'raw_payload' => [
                    'actor_id' => $actorId,
                    'tenant_id' => $tenantId,
                    'reminder_type' => 'candle_cash_unredeemed_earned',
                    'outstanding_candle_cash' => round((float) ($recipient['outstanding_candle_cash'] ?? 0), 2),
                    'outstanding_amount' => round((float) ($recipient['outstanding_amount'] ?? 0), 2),
                    'earned_date' => $recipient['earned_date'] ?? null,
                    'latest_earned_date' => $recipient['latest_earned_date'] ?? null,
                    'expiration_date' => $recipient['expiration_date'] ?? null,
                    'expiration_policy' => $recipient['expiration_policy'] ?? null,
                ],
            ]);

            $sendResult = $this->sendGridEmailService->sendEmail(
                $email,
                $this->subjectForRecipient($recipient),
                $this->bodyForRecipient($recipient),
                [
                    'dry_run' => $dryRun,
                    'custom_args' => [
                        'marketing_email_delivery_id' => (string) $delivery->id,
                        'marketing_profile_id' => (string) $profileId,
                        'delivery_kind' => 'candle_cash_unredeemed_earned',
                    ],
                ]
            );

            $success = (bool) ($sendResult['success'] ?? false);
            $delivery->forceFill([
                'sendgrid_message_id' => $sendResult['message_id'] ?? null,
                'status' => $success ? 'sent' : 'failed',
                'sent_at' => $success ? now() : null,
                'failed_at' => $success ? null : now(),
                'raw_payload' => array_merge(
                    (array) ($delivery->raw_payload ?? []),
                    [
                        'provider_payload' => is_array($sendResult['payload'] ?? null) ? $sendResult['payload'] : [],
                        'provider_status' => (string) ($sendResult['status'] ?? ''),
                        'error_message' => $sendResult['error_message'] ?? null,
                        'dry_run' => (bool) ($sendResult['dry_run'] ?? false),
                    ]
                ),
            ])->save();

            if ($success) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }

        $statusLabel = $summary['dry_run']
            ? 'Dry run executed'
            : 'Reminder send attempted';
        $message = $statusLabel.': '.$summary['sent'].' sent, '.$summary['failed'].' failed, '
            .$summary['skipped_cooldown'].' skipped by cooldown, '
            .$summary['skipped_no_email'].' skipped for missing email.';

        return [
            'blocked' => false,
            'status' => (string) ($readiness['status'] ?? 'ready_for_live_send'),
            'message' => $message,
            'readiness' => $readiness,
            'summary' => $summary,
        ];
    }

    protected function inCooldownWindow(int $profileId, string $email, int $cooldownDays): bool
    {
        $recentDeliveries = MarketingEmailDelivery::query()
            ->where('marketing_profile_id', $profileId)
            ->where('email', $email)
            ->where('created_at', '>=', now()->subDays($cooldownDays))
            ->orderByDesc('id')
            ->get(['id', 'raw_payload']);

        return $recentDeliveries->contains(function (MarketingEmailDelivery $delivery): bool {
            return strtolower(trim((string) data_get($delivery->raw_payload, 'reminder_type'))) === 'candle_cash_unredeemed_earned';
        });
    }

    /**
     * @param  array<string,mixed>  $recipient
     */
    protected function subjectForRecipient(array $recipient): string
    {
        $amount = trim((string) ($recipient['formatted_outstanding_amount'] ?? ''));
        if ($amount === '') {
            $amount = '$0.00';
        }

        return 'You still have '.$amount.' in Candle Cash waiting';
    }

    /**
     * @param  array<string,mixed>  $recipient
     */
    protected function bodyForRecipient(array $recipient): string
    {
        $firstName = trim((string) ($recipient['first_name'] ?? ''));
        $greeting = $firstName !== '' ? 'Hi '.$firstName.',' : 'Hi there,';
        $amount = trim((string) ($recipient['formatted_outstanding_amount'] ?? '$0.00'));
        $earnedDate = $this->formatDate($recipient['earned_date'] ?? null);
        $latestEarnedDate = $this->formatDate($recipient['latest_earned_date'] ?? null);
        $expirationDate = $this->formatDate($recipient['expiration_date'] ?? null);
        $expirationPolicy = trim((string) ($recipient['expiration_policy'] ?? 'No fixed expiration date is currently configured.'));
        $cta = rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/').'/pages/rewards';

        $sources = collect((array) ($recipient['top_sources'] ?? []))
            ->map(function (array $row): string {
                $label = trim((string) ($row['label'] ?? 'Source'));
                $amount = '$'.number_format(round((float) ($row['amount'] ?? 0), 2), 2);

                return '- '.$label.': '.$amount;
            })
            ->take(3)
            ->implode("\n");

        if ($sources === '') {
            $sources = '- Program-earned Candle Cash';
        }

        $expirationLine = $expirationDate !== null
            ? 'Earliest expiration date: '.$expirationDate
            : 'Expiration policy: '.$expirationPolicy;

        return trim(implode("\n", [
            $greeting,
            '',
            'You currently have '.$amount.' in earned Candle Cash still available.',
            'First earned date in your current outstanding balance: '.($earnedDate ?? 'Not available'),
            'Latest earned date in your current outstanding balance: '.($latestEarnedDate ?? 'Not available'),
            $expirationLine,
            '',
            'Current outstanding sources:',
            $sources,
            '',
            'Use your Candle Cash on a future order:',
            $cta,
            '',
            'Reply to this email if you need help.',
            '',
            'Modern Forestry LLC',
            '406 Piedmont Rd Easley SC 29642',
        ]));
    }

    protected function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
