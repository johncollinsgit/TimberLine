<?php

namespace App\Services\Voice;

use App\Services\Voice\Contracts\CallEventAdapter;

/** Fictional events only; no credentials, network requests or live receipt. */
class FakeCallEventAdapter implements CallEventAdapter
{
    public function key(): string
    {
        return 'fake';
    }

    public function capabilities(): array
    {
        return ['practice_events' => true, 'live_dispatch' => false, 'number_provisioning' => false, 'porting' => false,
            'recording' => false, 'emergency_calling' => false, 'usage_reconciliation' => false];
    }

    public function event(array $identity, string $canonicalUrl, array $form, string $signature, string $secret): array
    {
        $tenantId = $identity['tenant_id'] ?? 0;
        abort_unless(is_int($tenantId) && $tenantId > 0 && ($identity['provider'] ?? '') === 'fake' && ($identity['account_id'] ?? '') === 'fictional-'.$tenantId
            && ($identity['simulated'] ?? false) === true && $canonicalUrl === 'fictional://practice' && $signature === 'fictional-only' && $secret === '', 403);
        abort_unless(in_array($form['status'] ?? '', ['queued', 'ringing', 'active', 'completed', 'busy', 'failed', 'unanswered', 'cancelled'], true)
            && is_int($form['sequence'] ?? null) && $form['sequence'] >= 0 && is_string($form['call_id'] ?? null) && preg_match('/^fictional-[a-f0-9-]{36}$/', $form['call_id']), 422);
        $duration = $form['duration_seconds'] ?? null;
        abort_unless($duration === null || is_int($duration) && $duration >= 0 && $duration <= 604800, 422);

        return ['version' => 1, 'tenant_id' => $tenantId, 'provider' => 'fake', 'account_id' => $identity['account_id'],
            'external_call_id' => $form['call_id'], 'event_key' => hash('sha256', $tenantId.'|'.$form['call_id'].'|'.$form['sequence']),
            'status' => $form['status'], 'sequence' => $form['sequence'], 'duration_seconds' => $duration,
            'provider_cost_micros' => null, 'currency' => null, 'direction' => 'inbound', 'simulated' => true];
    }
}
