<?php

namespace App\Services\Voice;

/** Pure reducer; persistence/receipt locking belongs to the future ingress. */
class CallLifecycle
{
    private const TERMINAL = ['completed', 'busy', 'failed', 'unanswered', 'cancelled'];

    public function apply(?array $state, array $event): array
    {
        abort_unless(($event['version'] ?? null) === 1 && in_array($event['status'] ?? '', ['queued', 'ringing', 'active', ...self::TERMINAL], true)
            && is_int($event['tenant_id'] ?? null) && $event['tenant_id'] > 0 && is_bool($event['simulated'] ?? null)
            && is_string($event['provider'] ?? null) && preg_match('/^[a-z_]{2,32}$/', $event['provider'])
            && is_string($event['account_id'] ?? null) && $event['account_id'] !== '' && strlen($event['account_id']) <= 128
            && is_string($event['external_call_id'] ?? null) && $event['external_call_id'] !== '' && strlen($event['external_call_id']) <= 128
            && is_string($event['event_key'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $event['event_key'])
            && array_key_exists('sequence', $event) && ($event['sequence'] === null || is_int($event['sequence']) && $event['sequence'] >= 0)
            && array_key_exists('duration_seconds', $event) && ($event['duration_seconds'] === null || is_int($event['duration_seconds']) && $event['duration_seconds'] >= 0), 422);
        $identity = array_intersect_key($event, array_flip(['tenant_id', 'provider', 'account_id', 'external_call_id', 'simulated']));
        if ($state === null) {
            $state = [...$identity, 'status' => 'new', 'sequence' => null, 'duration_seconds' => null, 'seen' => [], 'requires_review' => false, 'needs_follow_up' => false];
        }
        abort_unless(array_intersect_key($state, $identity) === $identity, 403, 'Call scope mismatch.');
        $fingerprint = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $known = $state['seen'][$event['event_key']] ?? null;
        if ($known !== null) {
            if (! hash_equals($known, $fingerprint)) {
                $state['requires_review'] = true;
            }

            return $state;
        }
        if (count($state['seen']) >= 32) {
            return [...$state, 'requires_review' => true];
        }
        $state['seen'][$event['event_key']] = $fingerprint;
        // A stale callback cannot resurrect a completed call.
        if ($event['sequence'] !== null && $state['sequence'] !== null && $event['sequence'] < $state['sequence']) {
            return $state;
        }
        if (in_array($state['status'], self::TERMINAL, true)) {
            if (in_array($event['status'], self::TERMINAL, true) && ($state['status'] !== $event['status']
                || ($event['duration_seconds'] !== null && $state['duration_seconds'] !== null && $event['duration_seconds'] !== $state['duration_seconds']))) {
                $state['requires_review'] = true;
            }

            return $state;
        }
        $rank = ['new' => -1, 'queued' => 0, 'ringing' => 1, 'active' => 2];
        if (isset($rank[$event['status']]) && $rank[$event['status']] < ($rank[$state['status']] ?? 3)) {
            return $state;
        }
        $state['status'] = $event['status'];
        if ($event['sequence'] !== null) {
            $state['sequence'] = $event['sequence'];
        }
        if ($event['status'] === 'completed') {
            $state['duration_seconds'] = $event['duration_seconds'];
        }
        $state['needs_follow_up'] = in_array($event['status'], ['busy', 'failed', 'unanswered'], true);
        $state['provider_cost_micros'] = null;

        return $state;
    }
}
