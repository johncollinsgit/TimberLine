<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingEmailDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingCampaignDeliveryDiagnostics
{
    protected int $webhookDelayMinutes = 15;

    protected int $recentWebhookHours = 24;

    /**
     * @var array<int,string>
     */
    protected array $failureEvents = [
        'bounce',
        'bounced',
        'blocked',
        'deferred',
        'drop',
        'dropped',
        'spamreport',
        'spam_report',
    ];

    /**
     * @var array<int,string>
     */
    protected array $unsubscribeEvents = [
        'unsubscribe',
        'group_unsubscribe',
    ];

    /**
     * @param array<string,mixed> $readiness
     */
    public function summarize(MarketingCampaign $campaign, array $readiness, Collection $deliveries): array
    {
        $smokeRecipient = trim((string) ($readiness['smoke_test_recipient_email'] ?? ''));
        $smokeEmail = $this->normalizedEmail($smokeRecipient);
        $rows = $this->buildRows($deliveries, $smokeEmail);

        $lastSmoke = $rows->firstWhere('is_smoke_test', true);
        $lastLive = $rows->first(fn (array $row): bool => (string) ($row['mode'] ?? '') === 'live');
        $latestWebhook = $this->latestWebhook($rows);

        $recipientMetrics = $this->recipientMetrics($rows);
        $webhookHealth = $this->webhookHealth($rows);
        $overallStatus = $this->determineStatus($readiness, $lastSmoke, $webhookHealth);
        $overallHint = $this->overallHint($overallStatus, $readiness, $lastSmoke, $webhookHealth);

        return [
            'smoke_test_configured' => $smokeRecipient !== '',
            'smoke_test_recipient' => $smokeRecipient !== '' ? $smokeRecipient : null,
            'last_smoke_test_attempt_at' => data_get($lastSmoke, 'sent_at'),
            'last_smoke_test_status' => data_get($lastSmoke, 'status'),
            'last_smoke_test_sendgrid_message_id' => data_get($lastSmoke, 'sendgrid_message_id'),
            'last_smoke_test_webhook_event' => data_get($lastSmoke, 'last_webhook_event'),
            'last_smoke_test_webhook_at' => data_get($lastSmoke, 'last_webhook_at'),
            'last_live_send_at' => data_get($lastLive, 'sent_at'),
            'last_live_send_status' => data_get($lastLive, 'status'),
            'last_webhook_at' => data_get($latestWebhook, 'at'),
            'last_webhook_event' => data_get($latestWebhook, 'event'),
            'overall_status' => $overallStatus,
            'overall_hint' => $overallHint,
            'webhook_health' => $webhookHealth,
            'recipient_tracking' => $recipientMetrics,
            'deliveries' => $rows->values()->all(),
            // Backwards-compatible keys for existing campaign views.
            'last_smoke' => $this->legacySummary($lastSmoke, $latestWebhook),
            'last_live' => $this->legacySummary($lastLive, $latestWebhook, false),
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array{event:?string,at:?CarbonInterface}|null $globalWebhook
     * @return array<string,mixed>
     */
    protected function legacySummary(?array $row, ?array $globalWebhook, bool $includeWebhook = true): array
    {
        if (! $row) {
            return [
                'sent_at' => null,
                'status' => null,
                'sendgrid_message_id' => null,
                'last_webhook_event' => $globalWebhook['event'] ?? null,
                'last_webhook_at' => $globalWebhook['at'] ?? null,
            ];
        }

        return [
            'sent_at' => $row['sent_at'] ?? null,
            'status' => $row['status'] ?? null,
            'sendgrid_message_id' => $row['sendgrid_message_id'] ?? null,
            'last_webhook_event' => $includeWebhook ? ($row['last_webhook_event'] ?? null) : null,
            'last_webhook_at' => $includeWebhook ? ($row['last_webhook_at'] ?? null) : null,
        ];
    }

    /**
     * @param Collection<int,MarketingEmailDelivery> $deliveries
     */
    protected function buildRows(Collection $deliveries, string $smokeEmail): Collection
    {
        return $deliveries
            ->sortByDesc(fn (MarketingEmailDelivery $delivery) => (int) ($delivery->id ?? 0))
            ->values()
            ->map(function (MarketingEmailDelivery $delivery) use ($smokeEmail): array {
                $email = trim((string) ($delivery->email ?: $delivery->profile?->email ?: ''));
                $normalizedEmail = $this->normalizedEmail($email);
                $isSmoke = $smokeEmail !== '' && $normalizedEmail === $smokeEmail;
                $isDryRun = (bool) data_get((array) $delivery->raw_payload, 'dry_run', false);

                $events = collect((array) data_get((array) $delivery->raw_payload, 'events', []))
                    ->map(function ($event): array {
                        $name = strtolower(trim((string) data_get($event, 'event', '')));
                        $at = $this->coerceDate(data_get($event, 'at'));

                        return [
                            'event' => $name !== '' ? $name : 'unknown',
                            'at' => $at,
                            'raw_at' => data_get($event, 'at'),
                            'sg_message_id' => data_get($event, 'sg_message_id'),
                        ];
                    })
                    ->filter(fn (array $event): bool => (string) ($event['event'] ?? '') !== '')
                    ->values();

                $lastEvent = $events->last();
                $lastFailure = $events->filter(fn (array $event): bool => $this->isFailureEvent((string) ($event['event'] ?? '')))->last();
                $eventNames = $events->pluck('event')->all();

                $providerAccepted = trim((string) ($delivery->sendgrid_message_id ?? '')) !== '';
                $sentAt = $delivery->sent_at ?: $delivery->created_at;
                $awaitingWebhook = $providerAccepted && $events->isEmpty();
                $awaitingWebhookOverdue = $awaitingWebhook && $this->isOlderThan($sentAt, $this->webhookDelayMinutes);
                $hasFailure = $this->hasFailure($delivery, $eventNames);
                $status = strtolower(trim((string) ($delivery->status ?? 'unknown')));

                return [
                    'id' => (int) $delivery->id,
                    'delivery' => $delivery,
                    'recipient_email' => $email !== '' ? $email : 'n/a',
                    'recipient_phone' => trim((string) ($delivery->profile?->phone ?? '')),
                    'recipient_name' => trim((string) (($delivery->profile?->first_name ?? '') . ' ' . ($delivery->profile?->last_name ?? ''))),
                    'mode' => $isSmoke ? 'smoke_test' : ($isDryRun ? 'dry_run' : 'live'),
                    'mode_label' => $isSmoke ? ($isDryRun ? 'Smoke Test (Dry Run)' : 'Smoke Test') : ($isDryRun ? 'Dry Run' : 'Live'),
                    'is_smoke_test' => $isSmoke,
                    'is_dry_run' => $isDryRun,
                    'status' => $status !== '' ? $status : 'unknown',
                    'status_label' => Str::headline($status !== '' ? $status : 'unknown'),
                    'status_tone' => $this->statusTone($status, $eventNames),
                    'sent_at' => $delivery->sent_at,
                    'sendgrid_message_id' => $delivery->sendgrid_message_id,
                    'sendgrid_message_id_short' => Str::limit((string) ($delivery->sendgrid_message_id ?? '—'), 28),
                    'provider_accepted' => $providerAccepted,
                    'last_webhook_event' => data_get($lastEvent, 'event'),
                    'last_webhook_at' => data_get($lastEvent, 'at'),
                    'last_failure_event' => data_get($lastFailure, 'event'),
                    'last_failure_at' => data_get($lastFailure, 'at'),
                    'webhook_event_count' => $events->count(),
                    'webhook_events' => $events->all(),
                    'opened' => $delivery->opened_at !== null || in_array('open', $eventNames, true),
                    'clicked' => $delivery->clicked_at !== null || in_array('click', $eventNames, true),
                    'delivered' => $delivery->delivered_at !== null || in_array('delivered', $eventNames, true) || in_array($status, ['delivered', 'opened', 'clicked'], true),
                    'failed' => $hasFailure,
                    'bounced' => in_array('bounce', $eventNames, true) || in_array('bounced', $eventNames, true),
                    'dropped' => in_array('drop', $eventNames, true) || in_array('dropped', $eventNames, true),
                    'deferred' => in_array('deferred', $eventNames, true),
                    'spam_report' => in_array('spamreport', $eventNames, true) || in_array('spam_report', $eventNames, true),
                    'unsubscribed' => $this->hasUnsubscribe($eventNames),
                    'awaiting_webhook' => $awaitingWebhook,
                    'awaiting_webhook_overdue' => $awaitingWebhookOverdue,
                    'hint' => $this->rowHint($providerAccepted, $events, $awaitingWebhook, $awaitingWebhookOverdue, $status, $lastFailure),
                ];
            });
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     */
    protected function latestWebhook(Collection $rows): ?array
    {
        $latest = null;
        foreach ($rows as $row) {
            $at = data_get($row, 'last_webhook_at');
            if (! $at instanceof CarbonInterface) {
                continue;
            }

            if (! $latest || $at->greaterThan($latest['at'])) {
                $latest = [
                    'event' => data_get($row, 'last_webhook_event'),
                    'at' => $at,
                ];
            }
        }

        return $latest;
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<string,int|float>
     */
    protected function recipientMetrics(Collection $rows): array
    {
        $total = $rows->count();
        $delivered = $rows->where('delivered', true)->count();
        $opened = $rows->where('opened', true)->count();
        $clicked = $rows->where('clicked', true)->count();
        $failed = $rows->where('failed', true)->count();
        $bounceDropDeferred = $rows
            ->filter(fn (array $row): bool => (bool) ($row['bounced'] ?? false) || (bool) ($row['dropped'] ?? false) || (bool) ($row['deferred'] ?? false))
            ->count();
        $unsubscribed = $rows->where('unsubscribed', true)->count();
        $spamReports = $rows->where('spam_report', true)->count();
        $awaitingWebhook = $rows->where('awaiting_webhook', true)->count();

        return [
            'total_deliveries' => $total,
            'delivered_count' => $delivered,
            'open_count' => $opened,
            'click_count' => $clicked,
            'failure_count' => $failed,
            'bounce_drop_deferred_count' => $bounceDropDeferred,
            'unsubscribe_count' => $unsubscribed,
            'spam_report_count' => $spamReports,
            'awaiting_webhook_count' => $awaitingWebhook,
            'smoke_test_count' => $rows->where('is_smoke_test', true)->count(),
            'live_count' => $rows->where('mode', 'live')->count(),
            'dry_run_count' => $rows->where('is_dry_run', true)->count(),
            'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0.0,
            'open_rate' => $total > 0 ? round(($opened / $total) * 100, 1) : 0.0,
            'click_rate' => $total > 0 ? round(($clicked / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<string,int|string|float|CarbonInterface|null>
     */
    protected function webhookHealth(Collection $rows): array
    {
        $eventRows = $rows->flatMap(function (array $row): array {
            $events = collect((array) ($row['webhook_events'] ?? []));

            return $events
                ->filter(fn (array $event): bool => data_get($event, 'at') instanceof CarbonInterface)
                ->map(fn (array $event): array => [
                    'event' => (string) ($event['event'] ?? 'unknown'),
                    'at' => $event['at'],
                ])
                ->values()
                ->all();
        });

        $lastWebhook = $eventRows
            ->sortByDesc(fn (array $event) => data_get($event, 'at') instanceof CarbonInterface ? data_get($event, 'at')->timestamp : 0)
            ->first();

        $recentThreshold = now()->subHours($this->recentWebhookHours);
        $recentCount = $eventRows
            ->filter(fn (array $event): bool => (data_get($event, 'at') instanceof CarbonInterface) && data_get($event, 'at')->greaterThanOrEqualTo($recentThreshold))
            ->count();

        $withMessageIdNoEvents = $rows
            ->filter(fn (array $row): bool => (bool) ($row['provider_accepted'] ?? false) && (int) ($row['webhook_event_count'] ?? 0) === 0)
            ->count();
        $awaitingOverdue = $rows->where('awaiting_webhook_overdue', true)->count();
        $failureEvents = $eventRows
            ->filter(fn (array $event): bool => $this->isFailureEvent((string) ($event['event'] ?? '')))
            ->count();

        $indicator = 'healthy';
        $hint = 'Webhook traffic looks healthy for recent delivery attempts.';

        if ($failureEvents > 0) {
            $indicator = 'failures_detected';
            $hint = 'Webhook events show delivery failures; review recipient-level outcomes.';
        } elseif ($awaitingOverdue > 0) {
            $indicator = 'missing_events';
            $hint = 'Some sends have provider IDs but no webhook events beyond the expected delay window.';
        } elseif ($withMessageIdNoEvents > 0) {
            $indicator = 'delayed';
            $hint = 'SendGrid accepted at least one send and webhook callbacks are still pending.';
        } elseif ($rows->isNotEmpty() && $eventRows->isEmpty()) {
            $indicator = 'missing_events';
            $hint = 'No webhook events are recorded yet for recent delivery attempts.';
        }

        return [
            'indicator' => $indicator,
            'hint' => $hint,
            'last_webhook_at' => data_get($lastWebhook, 'at'),
            'last_webhook_event' => data_get($lastWebhook, 'event'),
            'recent_webhook_count' => $recentCount,
            'deliveries_with_message_id_no_events' => $withMessageIdNoEvents,
            'deliveries_awaiting_webhook_overdue' => $awaitingOverdue,
            'failure_event_count' => $failureEvents,
        ];
    }

    /**
     * @param array<string,mixed> $readiness
     */
    protected function determineStatus(array $readiness, ?array $lastSmoke, array $webhookHealth): string
    {
        if (empty($readiness['smoke_test_recipient_email'])) {
            return 'needs_config';
        }

        $readinessStatus = (string) ($readiness['status'] ?? 'disabled');
        if (in_array($readinessStatus, ['disabled', 'misconfigured'], true)) {
            return 'needs_config';
        }

        if (! $lastSmoke) {
            return 'ready';
        }

        if ((bool) ($lastSmoke['failed'] ?? false)) {
            return 'error';
        }

        $healthIndicator = (string) ($webhookHealth['indicator'] ?? 'healthy');
        if ($healthIndicator === 'failures_detected') {
            return 'error';
        }

        if (($lastSmoke['provider_accepted'] ?? false) && empty($lastSmoke['last_webhook_at'])) {
            return 'awaiting_webhook';
        }

        if (! empty($lastSmoke['last_webhook_event'])) {
            return 'webhook_received';
        }

        return 'ready';
    }

    /**
     * @param array<string,mixed> $readiness
     * @param array<string,mixed>|null $lastSmoke
     * @param array<string,mixed> $webhookHealth
     */
    protected function overallHint(string $status, array $readiness, ?array $lastSmoke, array $webhookHealth): string
    {
        if ($status === 'needs_config') {
            if (empty($readiness['smoke_test_recipient_email'])) {
                return 'Set MARKETING_EMAIL_SMOKE_TEST_RECIPIENT to enable safe smoke-test verification.';
            }

            $readinessStatus = (string) ($readiness['status'] ?? 'disabled');
            if ($readinessStatus === 'disabled') {
                return 'Email sending is disabled. Enable MARKETING_EMAIL_ENABLED for live verification.';
            }

            $missing = (array) ($readiness['missing_reasons'] ?? []);
            if ($missing !== []) {
                return 'Email configuration is incomplete: ' . implode(', ', $missing) . '.';
            }

            return 'Email configuration needs review before this campaign can be verified.';
        }

        if ($status === 'awaiting_webhook') {
            return 'Smoke test send was recorded and is waiting for SendGrid webhook callbacks.';
        }

        if ($status === 'webhook_received') {
            return 'Smoke test and webhook activity are both present for this campaign.';
        }

        if ($status === 'error') {
            $failure = (string) ($lastSmoke['last_failure_event'] ?? '');
            if ($failure !== '') {
                return 'Recent smoke test shows a failure event: ' . Str::headline($failure) . '.';
            }

            $healthHint = (string) ($webhookHealth['hint'] ?? '');
            if ($healthHint !== '') {
                return $healthHint;
            }

            return 'Delivery diagnostics detected a failure condition that needs operator review.';
        }

        return 'Ready to run a smoke test and validate provider/webhook flow.';
    }

    protected function normalizedEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param array<int,string> $eventNames
     */
    protected function hasFailure(MarketingEmailDelivery $delivery, array $eventNames): bool
    {
        $status = strtolower(trim((string) ($delivery->status ?? '')));
        if (in_array($status, ['failed', 'undelivered', 'bounced', 'dropped'], true)) {
            return true;
        }

        foreach ($eventNames as $eventName) {
            if ($this->isFailureEvent((string) $eventName)) {
                return true;
            }
        }

        return false;
    }

    protected function isFailureEvent(string $event): bool
    {
        return in_array(strtolower(trim($event)), $this->failureEvents, true);
    }

    /**
     * @param array<int,string> $eventNames
     */
    protected function hasUnsubscribe(array $eventNames): bool
    {
        foreach ($eventNames as $eventName) {
            if (in_array((string) $eventName, $this->unsubscribeEvents, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $eventNames
     */
    protected function statusTone(string $status, array $eventNames): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['failed', 'undelivered', 'bounced', 'dropped'], true)) {
            return 'danger';
        }

        foreach ($eventNames as $eventName) {
            if ($this->isFailureEvent((string) $eventName)) {
                return 'danger';
            }
        }

        if (in_array($status, ['delivered', 'opened', 'clicked'], true)) {
            return 'success';
        }

        if (in_array($status, ['sent', 'sending'], true)) {
            return 'warning';
        }

        return 'neutral';
    }

    /**
     * @param Collection<int,array<string,mixed>> $events
     * @param array<string,mixed>|null $lastFailure
     */
    protected function rowHint(
        bool $providerAccepted,
        Collection $events,
        bool $awaitingWebhook,
        bool $awaitingWebhookOverdue,
        string $status,
        ?array $lastFailure
    ): ?string {
        if (! $providerAccepted && in_array($status, ['sent', 'sending'], true)) {
            return 'Message marked as sent but no SendGrid message ID was recorded.';
        }

        if ($awaitingWebhookOverdue) {
            return 'SendGrid accepted this send, but webhook events are overdue.';
        }

        if ($awaitingWebhook) {
            return 'SendGrid accepted this send; waiting for webhook callbacks.';
        }

        if ($lastFailure) {
            return 'Delivery failure event: ' . Str::headline((string) ($lastFailure['event'] ?? 'failed')) . '.';
        }

        if ($events->isEmpty() && in_array($status, ['failed', 'undelivered'], true)) {
            return 'Delivery failed before webhook callbacks were captured.';
        }

        return null;
    }

    protected function coerceDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            if (ctype_digit($text)) {
                return CarbonImmutable::createFromTimestamp((int) $text);
            }

            return CarbonImmutable::parse($text);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isOlderThan(mixed $value, int $minutes): bool
    {
        if (! $value instanceof CarbonInterface) {
            return false;
        }

        return $value->lessThanOrEqualTo(now()->subMinutes($minutes));
    }
}
