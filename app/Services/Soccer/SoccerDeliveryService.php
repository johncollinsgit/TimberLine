<?php

namespace App\Services\Soccer;

class SoccerDeliveryService
{
    public function __construct(private SoccerStore $store) {}

    public function run(int $tenant): void
    {
        if (! config('soccer.sms_enabled')) {
            return;
        }
        $ids = $this->store->query('deliveries', $tenant)->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('created_at')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $claimed = $this->store->query('deliveries', $tenant)->where('id', $id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $d = $this->store->row('deliveries', $tenant, $id);
            $p = $this->store->decrypt($d->payload);
            $event = $this->store->row('events', $tenant, $d->event_id);
            if ($p['phone'] !== config('soccer.sms_recipient') && $event->family_id) {
                $family = $this->store->row('families', $tenant, $event->family_id);
                if (! $family->sms_consent_at || app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($this->store->decrypt($family->profile)['phone'] ?? '') !== $p['phone']) {
                    $this->store->query('deliveries', $tenant)->where('id', $id)->update(['status' => 'suppressed', 'error' => 'consent_withdrawn', 'updated_at' => now()]);

                    continue;
                }
            }
            $svc = app(\App\Services\Marketing\MessagingConversationService::class);
            $c = $svc->findOrCreateSmsConversation($tenant, null, null, $p['phone'], ['source_type' => 'soccer_waitlist', 'source_context' => ['event_id' => $d->event_id]]);
            try {
                $result = app(\App\Services\Marketing\Messaging\TenantMessagingGateway::class)->sendSms($tenant, $p['phone'], $p['body'], ['idempotency_key' => 'soccer:'.$id.':'.($d->attempts + 1), 'source_type' => 'soccer_waitlist']);
                $success = (bool) ($result['success'] ?? false);
                $providerId = $result['provider_message_id'] ?? data_get($result, 'payload.sid');
                $error = $result['error_code'] ?? ($success ? null : 'provider_failed');
            } catch (\Throwable $e) {
                $success = false;
                $providerId = null;
                $error = 'provider_unavailable';
            }
            $status = $success ? 'accepted' : (in_array($error, ['exception', 'provider_unavailable'], true) ? 'uncertain' : 'failed');
            $this->store->query('deliveries', $tenant)->where('id', $id)->update(['status' => $status, 'provider_message_id' => $providerId, 'attempts' => $d->attempts + 1, 'retry_at' => now()->addMinutes(5 * ($d->attempts + 1)), 'error' => $error, 'updated_at' => now()]);
            $svc->appendMessage($c, ['channel' => 'sms', 'direction' => 'outbound', 'provider' => 'twilio', 'provider_message_id' => $providerId, 'dedupe_hash' => hash('sha256', 'soccer-delivery:'.$id.':'.($d->attempts + 1)), 'body' => $p['body'], 'to_identity' => $p['phone'], 'sent_at' => $success ? now() : null, 'delivery_status' => $status, 'metadata' => ['soccer_delivery_id' => $id, 'soccer_event_id' => $d->event_id]]);
        }
    }
}
