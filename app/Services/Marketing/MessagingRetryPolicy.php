<?php

namespace App\Services\Marketing;

class MessagingRetryPolicy
{
    public function isRetryable(string $channel, ?string $errorCode, ?string $providerStatus = null): bool
    {
        $normalizedChannel = strtolower(trim($channel));
        $normalizedErrorCode = strtolower(trim((string) $errorCode));
        $normalizedStatus = strtolower(trim((string) $providerStatus));

        if ($normalizedChannel === 'sms') {
            $codes = array_map(
                static fn (mixed $code): string => strtolower(trim((string) $code)),
                (array) config('marketing.messaging.sms.retryable_error_codes', [])
            );

            if ($normalizedErrorCode !== '' && in_array($normalizedErrorCode, $codes, true)) {
                return true;
            }

            return in_array($normalizedStatus, ['timeout', 'failed', 'undelivered', 'canceled'], true)
                && in_array('exception', $codes, true);
        }

        return in_array($normalizedStatus, ['timeout', 'failed'], true);
    }

    public function nextBackoffSeconds(string $channel, int $attemptNumber): int
    {
        $normalizedChannel = strtolower(trim($channel));
        $backoffSeries = $normalizedChannel === 'sms'
            ? (array) config('marketing.messaging.sms.retry_backoff_seconds', [20, 90, 300])
            : (array) config('marketing.messaging.email.retry_backoff_seconds', [30, 120, 420]);

        $resolvedSeries = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(1, (int) $value),
            $backoffSeries
        )));

        if ($resolvedSeries === []) {
            $resolvedSeries = [60, 180, 480];
        }

        $index = max(0, $attemptNumber - 1);
        if (isset($resolvedSeries[$index])) {
            return (int) $resolvedSeries[$index];
        }

        return (int) $resolvedSeries[count($resolvedSeries) - 1];
    }
}
