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
            'notifications' => $this->notifications($tenant, $user, $areaId, $actor),
            'scanner' => ['configured' => (bool) config('team_work.scanner.enabled'), 'live_scan_verified' => false,
                'status' => config('team_work.scanner.enabled') ? 'ClamAV adapter configured; uploads remain quarantined unless the exact bytes receive a clean response.'
                    : 'A reachable approved ClamAV service is required. Uploaded files remain quarantined.'],
            'as_of' => now()->toIso8601String()];
    }

    /** Readiness is scoped to this actor; it never authorizes a draft or resolves an existing send. */
    private function notifications(Tenant $tenant, User $user, string $areaId, array $actor): array
    {
        $canWrite = config('enterprise.writes_enabled') && WorkspaceAccess::permits($actor, $areaId, true);
        $enabled = (bool) config('enterprise.email_drafts_enabled');
        $configured = false;
        $ready = false;
        if ($enabled && $canWrite) {
            $gateway = app(MailboxGateway::class);
            foreach ($gateway->mailboxes($tenant, $user, $areaId) as $mailbox) {
                try {
                    $binding = $gateway->authorize($tenant, $user, $areaId, $mailbox['id']);
                    $configured = true;
                    $ready = $gateway->readyFor($binding) || $ready;
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                    // A listing or global transport flag is never this user's current sender grant.
                }
            }
        }

        return [
            'in_app' => ['mode' => 'native_workspace', 'available' => true, 'reason_code' => 'current_authorized_workspace'],
            'email' => ['mode' => 'own_mailbox', 'configured' => $configured, 'dispatch_enabled' => $ready,
                'automatic_notifications' => false, 'requires_confirmation' => true, 'live_delivery_verified' => false,
                'reason_code' => ! $enabled ? 'drafts_disabled' : (! $canWrite ? 'read_only_scope' : (! $configured ? 'own_mailbox_required'
                    : ($ready ? 'exact_confirmation_required' : 'sender_dispatch_disabled')))],
            'sms' => ['mode' => 'unavailable', 'configured' => false, 'dispatch_enabled' => false,
                'automatic_notifications' => false, 'requires_confirmation' => true, 'live_delivery_verified' => false,
                'reason_code' => 'no_native_sms_adapter'],
            'controlled_trial' => ['mode' => 'separate_approved_session', 'evaluated' => false,
                'reason_code' => 'separate_trial_session_required'],
        ];
    }
}
