<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;

class SoccerDeliveryService
{
    public function __construct(private SoccerStore $store) {}

    public function run(int $tenant): void
    {
        $ids = $this->store->query('deliveries', $tenant)->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('created_at')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $candidate = $this->store->row('deliveries', $tenant, $id);
            $channel = $this->store->decrypt($candidate->payload)['channel'] ?? 'sms';
            if ($channel === 'sms' && (! config('soccer.sms_enabled') || ! $this->withinSendingHours() || config('soccer.notification_mode') !== 'sms')) {
                continue;
            }
            if ($channel !== 'sms' && config('soccer.notification_mode') !== 'email') {
                continue;
            }
            $claimed = $this->store->query('deliveries', $tenant)->where('id', $id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $d = $this->store->row('deliveries', $tenant, $id);
            $p = $this->store->decrypt($d->payload);
            $event = $this->store->row('events', $tenant, $d->event_id);
            if (! empty($p['invoice_id'])) {
                $invoice = $this->store->query('invoices', $tenant)->where('id', $p['invoice_id'])->first();
                if (! $invoice || $invoice->status !== 'unpaid' || CarbonImmutable::parse($invoice->expires_at)->lte(now())) {
                    $this->store->query('deliveries', $tenant)->where('id', $id)->update(['status' => 'suppressed', 'error' => 'invoice_closed', 'updated_at' => now()]);

                    continue;
                }
            }
            if ($channel !== 'sms') {
                $this->deliverNotification($tenant, $d, $event, $p, $channel);

                continue;
            }
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

    private function deliverNotification(int $tenant, object $delivery, object $event, array $payload, string $channel): void
    {
        $providerId = null;
        $error = null;
        $status = 'accepted';
        $conversation = null;
        $service = app(\App\Services\Marketing\MessagingConversationService::class);
        try {
            if ($channel === 'email') {
                $family = $this->store->row('families', $tenant, $event->family_id);
                if (! $family->verified_at || strtolower(trim((string) $family->email)) !== $payload['email']) {
                    $this->store->query('deliveries', $tenant)->where('id', $delivery->id)->update(['status' => 'suppressed', 'error' => 'family_email_changed', 'updated_at' => now()]);

                    return;
                }
                $conversation = $service->findOrCreateEmailConversation($tenant, null, null, $payload['email'], 'Wren Family Soccer registration', ['source_type' => 'soccer_waitlist']);
                $sent = \Illuminate\Support\Facades\Mail::to($payload['email'])->send(new \App\Mail\SoccerRegistrationMail($payload['body']));
                if (! $sent) {
                    throw new \RuntimeException('Email was not accepted by the configured mailer.');
                }
                $providerId = $sent->getMessageId();
            } elseif ($channel === 'groupme') {
                $settings = $this->store->settings($tenant);
                $actor = \App\Models\User::find($payload['user_id']);
                abort_unless($actor && app(SoccerAccess::class)->admin(\App\Models\Tenant::findOrFail($tenant), $actor) && ($settings['verified_group_user_id'] ?? null) == $actor->id && ! empty($payload['group_id']) && ($settings['groupme_groups']['head_coaches'] ?? null) === $payload['group_id'] && ($settings['verified_group_ids'] ?? []) === ($settings['groupme_groups'] ?? []), 403, 'GroupMe alert connection must be verified.');
                $providerId = app(SoccerGroupMeService::class)->send($tenant, $actor, $payload['group_id'], $payload['body'], 'soccer-alert-'.$delivery->id);
                $conversation = \App\Models\MessagingConversation::query()->forTenantId($tenant)->firstOrCreate(['tenant_id' => $tenant, 'channel' => 'groupme', 'source_type' => 'soccer_groupme', 'source_id' => $payload['group_id']], ['status' => 'open', 'subject' => 'Head Coaches', 'source_context' => ['group_id' => $payload['group_id']]]);
            } else {
                throw new \RuntimeException('Unsupported notification channel.');
            }
        } catch (\Throwable $e) {
            // SMTP failures may occur after acceptance. Do not automatically resend unknown outcomes.
            $status = $channel === 'email' ? 'uncertain' : 'failed';
            $error = $channel === 'email' ? 'email_delivery_uncertain' : 'groupme_unavailable';
        }
        $this->store->query('deliveries', $tenant)->where('id', $delivery->id)->update(['status' => $status, 'provider_message_id' => $providerId, 'attempts' => $delivery->attempts + 1, 'retry_at' => now()->addMinutes(5 * ($delivery->attempts + 1)), 'error' => $error, 'updated_at' => now()]);
        if ($conversation) {
            $service->appendMessage($conversation, ['channel' => $channel, 'direction' => 'outbound', 'provider' => $channel === 'email' ? 'mail' : 'groupme', 'provider_message_id' => $providerId, 'dedupe_hash' => hash('sha256', 'soccer-delivery:'.$delivery->id.':'.($delivery->attempts + 1)), 'body' => $payload['body'], 'to_identity' => $payload['email'] ?? $payload['group_id'] ?? '', 'sent_at' => $status === 'accepted' ? now() : null, 'delivery_status' => $status, 'metadata' => ['soccer_delivery_id' => $delivery->id, 'soccer_event_id' => $delivery->event_id]]);
        }
    }

    private function withinSendingHours(): bool
    {
        $hour = now('America/New_York')->hour;

        return $hour >= 8 && $hour < 20;
    }
}
