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

        return ['ai' => app(Intelligence::class)->status($tenant), 'mailbox' => app(ConnectedMailboxGateway::class)->status(),
            'scanner' => ['configured' => (bool) config('team_work.scanner.enabled'), 'live_scan_verified' => false,
                'status' => config('team_work.scanner.enabled') ? 'ClamAV adapter configured; uploads remain quarantined unless the exact bytes receive a clean response.'
                    : 'A reachable approved ClamAV service is required. Uploaded files remain quarantined.'],
            'as_of' => now()->toIso8601String()];
    }
}
