<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;

/** Configuration readiness only, never a claim of successful provider or hardware acceptance. */
class IntelligenceStatus
{
    public function forUser(Tenant $tenant, User $user, string $areaId): array
    {
        $access = app(WorkspaceAccess::class);
        $actor = $access->actor($tenant, $user);
        $access->area($tenant, $actor, $areaId);

        $smtp = app(ConnectedMailboxGateway::class)->status();
        $gmail = app(GmailConnections::class)->status($tenant, $user, $areaId);

        return ['ai' => app(Intelligence::class)->status($tenant),
            'mailbox' => ['configured' => $gmail['configured'] || $smtp['configured'],
                'send_enabled' => $gmail['send_enabled'] || $smtp['send_enabled'],
                'status' => $gmail['status'].' Native SMTP: '.$smtp['status'],
                'gmail' => $gmail, 'smtp' => $smtp, 'live_delivery_verified' => false],
            'scanner' => ['configured' => (bool) config('team_work.scanner.enabled'), 'live_scan_verified' => false,
                'status' => config('team_work.scanner.enabled') ? 'ClamAV adapter configured; uploads remain quarantined unless the exact bytes receive a clean response.'
                    : 'A reachable approved ClamAV service is required. Uploaded files remain quarantined.'],
            'as_of' => now()->toIso8601String()];
    }
}
