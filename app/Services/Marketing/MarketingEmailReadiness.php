<?php

namespace App\Services\Marketing;

class MarketingEmailReadiness
{
    public function summary(): array
    {
        $enabled = (bool) config('marketing.email.enabled', false);
        $dryRun = (bool) config('marketing.email.dry_run', false);
        $sendGridKey = trim((string) (config('services.sendgrid.api_key') ?? config('services.sendgrid_api_key') ?? ''));
        $fromEmail = trim((string) config('marketing.email.from_email', ''));
        $fromName = trim((string) config('marketing.email.from_name', ''));

        $missingReasons = [];
        if ($sendGridKey === '') {
            $missingReasons[] = 'SendGrid API key not configured';
        }
        if ($fromEmail === '') {
            $missingReasons[] = 'Marketing from email is missing';
        }
        if ($fromName === '') {
            $missingReasons[] = 'Marketing from name is missing';
        }

        $status = 'disabled';
        if (! $enabled) {
            $status = 'disabled';
        } elseif ($missingReasons !== []) {
            // only misconfigured when enabled but missing required fields
            $status = 'misconfigured';
        } elseif ($dryRun) {
            $status = 'dry_run_only';
        } else {
            $status = 'ready_for_live_send';
        }

        $smokeTestEmail = trim((string) config('marketing.email.smoke_test_recipient_email', ''));

        return [
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'sendgrid_key_present' => $sendGridKey !== '',
            'from_email_present' => $fromEmail !== '',
            'from_name_present' => $fromName !== '',
            'smoke_test_recipient_email' => $smokeTestEmail,
            'smoke_test_configured' => $smokeTestEmail !== '',
            'missing_reasons' => $missingReasons,
            'status' => $status,
        ];
    }

    public function isLiveReady(array $summary): bool
    {
        return $summary['status'] === 'ready_for_live_send';
    }
}
