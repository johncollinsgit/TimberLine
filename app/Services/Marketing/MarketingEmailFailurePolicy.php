<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use Carbon\CarbonInterface;

class MarketingEmailFailurePolicy
{
    /** A delivery failure is not, by itself, evidence of an invalid recipient. */
    public function classify(array $event): array
    {
        $kind = strtolower(trim((string) ($event['event'] ?? '')));
        $type = strtolower(trim((string) ($event['type'] ?? '')));
        $reason = trim((string) ($event['reason'] ?? $event['response'] ?? $event['error_message'] ?? ''));
        $text = strtolower($reason);
        $status = trim((string) ($event['status'] ?? ''));
        $classification = strtolower(trim((string) ($event['bounce_classification'] ?? '')));
        $decision = fn (?string $state, string $category, string $action): array => [
            'email_status' => $state, 'category' => $category, 'reason' => $reason,
            'smtp_status' => $status, 'action' => $action, 'suppress' => $state !== null,
        ];

        if (($event['error_code'] ?? null) === 'email_not_consented') {
            return $decision(null, 'consent_required', 'Do not send marketing emails until the customer gives email consent.');
        }
        if (($event['error_code'] ?? null) === 'email_unsubscribed') {
            return $decision('unsubscribed', 'unsubscribe', 'Stop future marketing emails; customer opted out.');
        }
        if (in_array($kind, ['unsubscribe', 'group_unsubscribe'], true) || $text === 'unsubscribed address') {
            return $decision('unsubscribed', 'unsubscribe', 'Stop future marketing emails; customer unsubscribed.');
        }
        if (in_array($kind, ['spamreport', 'spam_report'], true) || $text === 'spam reporting address') {
            return $decision('suppressed', 'spam_complaint', 'Stop future marketing emails; recipient reported spam.');
        }
        if ($text === 'bounced address') {
            return $decision('bounced', 'previous_hard_bounce', 'Stop future marketing emails; provider retains an address bounce.');
        }
        if (! in_array($kind, ['bounce', 'bounced', 'blocked', 'drop', 'dropped', 'deferred', 'send_failure'], true)) {
            return $decision(null, 'none', 'No address suppression required.');
        }
        $combined = $status.' '.$text;
        if ($kind === 'deferred' || preg_match('/\b4\d\d\b|\b4\.\d\.\d\b|\b5\.2\.2\b/', $combined)
            || preg_match('/mailbox (?:is )?full|quota exceeded|temporar|try again|rate.?limit|timeout|timed out/', $text)) {
            return $decision(null, 'temporary', 'Keep address eligible; retry after the temporary issue clears.');
        }
        if (preg_match('/\b5\.7\.\d+\b/', $combined)
            || in_array($classification, ['reputation', 'content', 'technical'], true)
            || preg_match('/sender|spf|dkim|dmarc|blacklist|blocklist|reputation|spam content|smtpapi|package quota|authentication|api key/', $text)) {
            return $decision(null, 'sender_or_provider', 'Keep address eligible; resolve the sender or provider problem.');
        }
        if ($classification === 'invalid address' || $text === 'invalid'
            || preg_match('/\b5\.1\.[1236]\b|\b5\.2\.1\b/', $combined)
            || preg_match('/no such (?:user|mailbox)|nosuchuser|user unknown|unknown user|recipient (?:address )?not found|mailbox (?:does not exist|disabled)|invalid (?:recipient|email|address)/', $text)
            || in_array((string) ($event['error_code'] ?? ''), ['invalid_recipient', 'recipient_not_found'], true)) {
            return $decision('bounced', 'invalid_recipient', 'Stop future marketing emails; address is invalid or disabled.');
        }
        // SendGrid explicitly distinguishes permanent type=bounce from temporary type=blocked.
        if ($type === 'bounce') {
            return $decision('bounced', 'hard_bounce', 'Stop future marketing emails; provider confirmed a permanent bounce.');
        }

        return $decision(null, 'needs_review', 'Keep address eligible; failure does not establish a permanent recipient problem.');
    }

    public function apply(MarketingEmailDelivery $delivery, array $event, CarbonInterface $occurredAt): array
    {
        $decision = $this->classify($event);
        if ($decision['suppress'] && (int) $delivery->tenant_id > 0) {
            $state = \App\Models\MessagingContactChannelState::query()->forTenantId((int) $delivery->tenant_id)
                ->where('email', strtolower(trim((string) $delivery->email)))->first();
            // A later bounce cannot erase an unsubscribe or complaint, nor explicit later reconsent.
            if ($state && (($state->email_status === 'unsubscribed' && $decision['email_status'] !== 'unsubscribed')
                || ($state->email_status === 'suppressed' && $decision['email_status'] === 'bounced')
                || ($state->email_status === 'subscribed' && $state->email_status_changed_at?->greaterThan($occurredAt)))) {
                return $decision;
            }
            app(MessagingContactChannelStateService::class)->markEmailStatus(
                tenantId: (int) $delivery->tenant_id, profile: $delivery->profile, email: $delivery->email,
                status: $decision['email_status'], reason: $decision['category'], providerSource: 'sendgrid_events',
                metadata: ['marketing_email_delivery_id' => (int) $delivery->id, 'provider_message_id' => $delivery->provider_message_id, 'event' => $event, 'failure_policy' => $decision],
                occurredAt: $occurredAt
            );
        }

        return $decision;
    }

    public function applySendResult(int $tenantId, string $email, array $result, array $options): array
    {
        if (($result['success'] ?? false) || ($result['dry_run'] ?? false) || ($options['dry_run'] ?? false)) {
            return $result;
        }
        $event = [...$result, 'event' => 'send_failure'];
        $decision = $this->classify($event);
        $existing = \App\Models\MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', strtolower(trim($email)))->first();
        if ($decision['suppress'] && ! (($existing?->email_status === 'unsubscribed' && $decision['email_status'] !== 'unsubscribed') || ($existing?->email_status === 'suppressed' && $decision['email_status'] === 'bounced'))) {
            app(MessagingContactChannelStateService::class)->markEmailStatus(
                tenantId: $tenantId, profile: null, email: $email, status: $decision['email_status'],
                reason: $decision['category'], providerSource: (string) ($result['provider'] ?? 'email_provider'),
                metadata: ['marketing_email_delivery_id' => $options['delivery_id'] ?? null, 'failure_policy' => $decision]
            );
        }

        return [...$result, 'failure_policy' => $decision];
    }
}
