<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotEvent;
use App\Models\TenantPaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionPilotWebhookService
{
    public function receive(string $raw, string $header): ?SubscriptionPilotEvent
    {
        $secret = (string) config('subscriptions.pilot_webhook_secret');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', $piece, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]][] = $pair[1];
            }
        }
        $time = $parts['t'][0] ?? '';
        abort_unless($secret !== '' && strlen($header) < 2048 && strlen($raw) <= 2097152 && ctype_digit($time) && abs(time() - (int) $time) <= 300, 401);
        $signature = hash_hmac('sha256', $time.'.'.$raw, $secret);
        abort_unless(collect($parts['v1'] ?? [])->contains(fn ($candidate) => strlen($candidate) === 64 && hash_equals($signature, $candidate)), 401);
        $event = json_decode($raw, true, 64);
        abort_unless(is_array($event) && preg_match('/^evt_[A-Za-z0-9]+$/D', $event['id'] ?? '') && is_bool($event['livemode'] ?? null)
            && preg_match('/^acct_[A-Za-z0-9]+$/D', $event['account'] ?? ''), 400);
        $object = data_get($event, 'data.object', []);
        $type = $event['type'] ?? '';
        abort_unless(is_array($object) && is_string($type) && is_array($object['metadata'] ?? []), 400);
        $metadata = $object['metadata'] ?? [];
        $cycle = null;
        $enrollment = null;
        if (in_array($type, ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'], true) && is_string($object['payment_intent'] ?? null)) {
            $cycle = SubscriptionPilotCycle::query()->where('payment_intent_id', $object['payment_intent'])->first();
            if (! $cycle) {
                $observation = SubscriptionPilotEvent::query()->where('provider', 'stripe')->where('kind', 'payment')
                    ->where('provider_reference', $object['payment_intent'])->where('verification', 'stripe_read')->latest('id')->first();
                if ($observation && data_get($observation->evidence, 'additional_payment') === true) {
                    $cycle = SubscriptionPilotCycle::query()
                        ->forTenantId($observation->tenant_id)->find(data_get($observation->evidence, 'cycle_id'));
                }
            }
            if (! $cycle) {
                return null;
            }
            $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->enrollment_id);
        } elseif (($metadata['purpose'] ?? null) !== 'everbranch_subscription_pilot') {
            return null;
        } elseif ($type === 'checkout.session.completed' && Str::isUuid($metadata['enrollment'] ?? '')) {
            $enrollment = SubscriptionPilotEnrollment::query()->where('public_id', $metadata['enrollment'])->firstOrFail();
        } elseif (str_starts_with($type, 'payment_intent.') && Str::isUuid($metadata['pilot_cycle'] ?? '')) {
            $cycle = SubscriptionPilotCycle::query()->where('public_id', $metadata['pilot_cycle'])->firstOrFail();
            $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->enrollment_id);
        } else {
            return null;
        }
        $merchant = $enrollment->terms['merchant'];
        abort_unless($event['account'] === $merchant['account_id'] && $event['livemode'] === $merchant['livemode']
            && is_string($object['id'] ?? null), 409, 'The event belongs to a different merchant context.');
        $evidence = ['event_id' => $event['id'], 'event_type' => $type, 'payload_hash' => hash('sha256', $raw), 'object_id' => $object['id'],
            'account_id' => $merchant['account_id'], 'livemode' => $event['livemode'], 'enrollment_id' => $enrollment->id, 'cycle_id' => $cycle?->id,
            'payment_intent_id' => str_starts_with($type, 'payment_intent.') ? $object['id'] : ($object['payment_intent'] ?? $cycle?->payment_intent_id)];
        $key = SubscriptionEvidence::digest('stripe_webhook|'.$merchant['account_id'].'|'.$event['id']);

        return DB::transaction(function () use ($enrollment, $evidence, $event, $key) {
            $row = SubscriptionPilotEvent::query()->forTenantId($enrollment->tenant_id)->firstOrCreate(
                ['tenant_id' => $enrollment->tenant_id, 'member_id' => $enrollment->member_id, 'event_key' => $key],
                ['provider' => 'stripe', 'kind' => 'webhook', 'provider_reference' => $event['id'], 'status' => 'received', 'verification' => 'stripe_signature',
                    'occurred_at' => now(), 'observed_at' => now(), 'evidence' => $evidence]);
            abort_unless($row->evidence === $evidence, 409, 'The original event envelope differs.');

            return $row;
        });
    }

    public function process(SubscriptionPilotEvent $receipt): void
    {
        abort_unless($receipt->provider === 'stripe' && $receipt->kind === 'webhook' && $receipt->verification === 'stripe_signature', 409);
        $evidence = $receipt->evidence;
        $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($receipt->tenant_id)->findOrFail($evidence['enrollment_id']);
        abort_unless($enrollment->member_id === $receipt->member_id && $enrollment->terms['merchant']['account_id'] === $evidence['account_id']
            && $enrollment->terms['merchant']['livemode'] === $evidence['livemode'], 409);
        $execution = app(SubscriptionPilotExecutionService::class);
        if ($evidence['event_type'] === 'checkout.session.completed') {
            $current = $execution->reconcileAuthorization($enrollment, $evidence['object_id']);
            if ($current->authorized_at && $evidence['livemode']) {
                $account = TenantPaymentAccount::query()->forTenantId($receipt->tenant_id)->firstOrFail();
                abort_unless($account->provider_account_id === $evidence['account_id'], 409);
                $account->update(['metadata' => array_merge($account->metadata ?? [], ['subscription_pilot_callback' => [
                    'account_id' => $account->provider_account_id, 'livemode' => true, 'event_id' => $receipt->provider_reference,
                    'setup_session_id' => $current->setup_session_id, 'verified_at' => now()->toIso8601String()]])]);
            }
        } else {
            $cycle = SubscriptionPilotCycle::query()->forTenantId($receipt->tenant_id)->where('enrollment_id', $enrollment->id)->findOrFail($evidence['cycle_id']);
            $execution->receivePayment($cycle, $evidence['payment_intent_id']);
        }
    }
}
