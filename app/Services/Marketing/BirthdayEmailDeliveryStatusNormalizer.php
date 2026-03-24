<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;

class BirthdayEmailDeliveryStatusNormalizer
{
    /**
     * @return array{
     *   normalized_status:'attempted'|'sent'|'delivered'|'opened'|'clicked'|'failed'|'bounced'|'unsupported',
     *   attempted:bool,
     *   sent:bool,
     *   delivered:bool,
     *   opened:bool,
     *   clicked:bool,
     *   failed:bool,
     *   bounced:bool,
     *   unsupported:bool,
     *   failure_reason:?string
     * }
     */
    public function normalize(MarketingEmailDelivery $delivery): array
    {
        $status = strtolower(trim((string) ($delivery->status ?? '')));
        $provider = strtolower(trim((string) ($delivery->provider ?? '')));
        $metadata = is_array($delivery->metadata) ? $delivery->metadata : [];
        $rawPayload = is_array($delivery->raw_payload) ? $delivery->raw_payload : [];
        $errorCode = strtolower(trim((string) ($metadata['error_code'] ?? data_get($rawPayload, 'provider_result.error_code') ?? '')));
        $errorMessage = trim((string) ($metadata['error_message'] ?? data_get($rawPayload, 'provider_result.error_message') ?? ''));
        $lastEvent = strtolower(trim((string) data_get($rawPayload, 'last_event.event', '')));

        $unsupported = in_array($errorCode, ['unsupported_provider_action', 'not_implemented'], true)
            || ($provider !== '' && in_array($provider, ['shopify_email', 'custom'], true) && $status === 'failed');

        $bounced = in_array($lastEvent, ['bounce', 'bounced', 'blocked', 'drop', 'dropped', 'spamreport', 'spam_report'], true)
            || $status === 'bounced'
            || $errorCode === 'unauthorized_sender';

        $clicked = $delivery->clicked_at !== null || $status === 'clicked';
        $opened = $clicked || $delivery->opened_at !== null || $status === 'opened';
        $delivered = $opened || $delivery->delivered_at !== null || in_array($status, ['delivered'], true);
        $sent = $delivered || $delivery->sent_at !== null || in_array($status, ['sent'], true);
        $failed = in_array($status, ['failed'], true) || $delivery->failed_at !== null || $unsupported || $bounced;

        $normalizedStatus = 'attempted';
        if ($unsupported) {
            $normalizedStatus = 'unsupported';
        } elseif ($bounced) {
            $normalizedStatus = 'bounced';
        } elseif ($failed) {
            $normalizedStatus = 'failed';
        } elseif ($clicked) {
            $normalizedStatus = 'clicked';
        } elseif ($opened) {
            $normalizedStatus = 'opened';
        } elseif ($delivered) {
            $normalizedStatus = 'delivered';
        } elseif ($sent) {
            $normalizedStatus = 'sent';
        }

        $failureReason = $failed
            ? ($errorCode !== '' ? $errorCode : ($errorMessage !== '' ? $errorMessage : ($lastEvent !== '' ? $lastEvent : 'unknown_failure')))
            : null;

        return [
            'normalized_status' => $normalizedStatus,
            'attempted' => true,
            'sent' => $sent && ! $failed,
            'delivered' => $delivered && ! $failed,
            'opened' => $opened && ! $failed,
            'clicked' => $clicked && ! $failed,
            'failed' => $failed,
            'bounced' => $bounced,
            'unsupported' => $unsupported,
            'failure_reason' => $failureReason,
        ];
    }

    /**
     * @param 'all'|'attempted'|'sent'|'delivered'|'opened'|'clicked'|'failed'|'bounced'|'unsupported' $statusFilter
     * @param array{
     *   normalized_status:string,
     *   attempted:bool,
     *   sent:bool,
     *   delivered:bool,
     *   opened:bool,
     *   clicked:bool,
     *   failed:bool,
     *   bounced:bool,
     *   unsupported:bool
     * } $normalized
     */
    public function matchesStatusFilter(string $statusFilter, array $normalized): bool
    {
        $filter = strtolower(trim($statusFilter));
        if ($filter === '' || $filter === 'all') {
            return true;
        }

        return match ($filter) {
            'attempted' => (bool) ($normalized['attempted'] ?? false),
            'sent' => (bool) ($normalized['sent'] ?? false),
            'delivered' => (bool) ($normalized['delivered'] ?? false),
            'opened' => (bool) ($normalized['opened'] ?? false),
            'clicked' => (bool) ($normalized['clicked'] ?? false),
            'failed' => (bool) ($normalized['failed'] ?? false),
            'bounced' => (bool) ($normalized['bounced'] ?? false),
            'unsupported' => (bool) ($normalized['unsupported'] ?? false),
            default => true,
        };
    }
}
