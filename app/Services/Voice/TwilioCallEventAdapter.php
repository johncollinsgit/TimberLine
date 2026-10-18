<?php

namespace App\Services\Voice;

use App\Services\Voice\Contracts\CallEventAdapter;

/** Strict scalar-form callback compiler. It exposes no live HTTP ingress. */
class TwilioCallEventAdapter implements CallEventAdapter
{
    public function key(): string
    {
        return 'twilio';
    }

    public function capabilities(): array
    {
        return ['verified_form_events' => true, 'live_dispatch' => false, 'number_provisioning' => false, 'porting' => false,
            'recording' => false, 'emergency_calling' => false, 'usage_reconciliation' => false];
    }

    public function verifies(string $canonicalUrl, array $form, string $signature, string $secret): bool
    {
        $url = parse_url($canonicalUrl);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || $secret === '' || $signature === '') {
            return false;
        }
        $parts = $form;
        ksort($parts, SORT_STRING);
        $signed = $canonicalUrl;
        foreach ($parts as $key => $value) {
            // Repeated/nested fields and JSON require their own ingress contract.
            if (! is_string($key) || ! is_string($value)) {
                return false;
            }
            $signed .= $key.$value;
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $signed, $secret, true)), $signature);
    }

    public function event(array $identity, string $canonicalUrl, array $form, string $signature, string $secret): array
    {
        abort_unless(($identity['provider'] ?? '') === $this->key() && ($identity['verified'] ?? false) === true
            && is_int($identity['tenant_id'] ?? null) && $identity['tenant_id'] > 0 && ($identity['active'] ?? false) === true, 403);
        abort_unless($this->verifies($canonicalUrl, $form, $signature, $secret), 403);
        $account = $identity['account_id'] ?? '';
        abort_unless(preg_match('/^AC[a-f0-9]{32}$/i', $account) && ($form['AccountSid'] ?? '') === $account, 403);
        $inbound = ($form['Direction'] ?? '') === 'inbound';
        abort_unless($inbound || in_array($form['Direction'] ?? '', ['outbound-api', 'outbound-dial'], true), 422);
        $owned = $inbound ? ($form['To'] ?? '') : ($form['From'] ?? '');
        abort_unless(preg_match('/^\+[1-9][0-9]{7,14}$/', (string) ($identity['number'] ?? '')) && $owned === $identity['number'], 403);
        $call = (string) ($form['CallSid'] ?? '');
        abort_unless(preg_match('/^CA[a-f0-9]{32}$/i', $call), 422);
        $statuses = ['queued' => 'queued', 'initiated' => 'queued', 'ringing' => 'ringing', 'in-progress' => 'active', 'completed' => 'completed',
            'busy' => 'busy', 'failed' => 'failed', 'no-answer' => 'unanswered', 'canceled' => 'cancelled'];
        $status = $statuses[$form['CallStatus'] ?? ''] ?? null;
        abort_unless($status, 422, 'Unsupported provider call status.');
        $sequence = $form['SequenceNumber'] ?? null;
        abort_unless($sequence === null || (ctype_digit($sequence) && strlen($sequence) <= 9), 422);
        $duration = $form['CallDuration'] ?? null;
        abort_unless($duration === null || (ctype_digit($duration) && strlen($duration) <= 6 && (int) $duration <= 604800), 422);

        return ['version' => 1, 'tenant_id' => $identity['tenant_id'], 'provider' => 'twilio', 'account_id' => $account,
            'external_call_id' => $call, 'event_key' => hash('sha256', json_encode([$account, $call, $sequence === null ? 'status:'.$status : 'sequence:'.$sequence], JSON_THROW_ON_ERROR)),
            'status' => $status, 'sequence' => $sequence === null ? null : (int) $sequence,
            'duration_seconds' => $duration === null ? null : (int) $duration,
            // Duration is not an invoice or a verified provider charge.
            'provider_cost_micros' => null, 'currency' => null, 'direction' => $inbound ? 'inbound' : 'outbound', 'simulated' => false];
    }
}
