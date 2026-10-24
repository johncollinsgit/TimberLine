<?php

namespace App\Services\Soccer;

class SoccerEvents
{
    public function __construct(private SoccerStore $store) {}

    public function record(int $tenant, ?string $season, ?string $family, string $action, array $data, ?int $actor = null, ?string $key = null, bool $waitlist = false, ?string $familyText = null): string
    {
        $key ??= (string) \Illuminate\Support\Str::uuid();
        if ($existing = $this->store->query('events', $tenant)->where('event_key', $key)->value('id')) {
            return $existing;
        }
        if ($waitlist && $family) {
            $f = $this->store->row('families', $tenant, $family);
            $parent = $this->store->decrypt($f->profile)['parentName'] ?? 'Family';
            $data['summary'] = $parent.': '.($data['summary'] ?? 'Review in Everbranch.');
        }
        $id = $this->store->insert('events', $tenant, ['season_id' => $season, 'family_id' => $family, 'actor_id' => $actor, 'action' => $action, 'event_key' => $key, 'data' => $this->store->encrypt($data)]);
        if ($season && $family && ! empty($data['registration_ids']) && in_array($action, ['family_registration_submitted', 'payment_confirmed', 'organizer_fee_waived'], true)) {
            foreach (array_unique(config('soccer.admin_notification_emails', [])) as $recipient) {
                $email = strtolower(trim($recipient));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->queueNotification($tenant, $id, 'admin_email:'.$email, ['channel' => 'admin_email', 'email' => $email]);
                }
            }
        }
        $emailMode = config('soccer.notification_mode') === 'email';
        if ($emailMode && $waitlist && $family && ! $familyText) {
            $familyText = 'Wren Soccer: your family registration has an update. Sign in to review each child’s placement and any next steps: '.config('soccer.origin').'/spring';
        }
        if ($waitlist && $emailMode && config('soccer.groupme_notifications_enabled')) {
            $settings = $this->store->settings($tenant);
            $group = $settings['groupme_groups']['head_coaches'] ?? null;
            $user = $settings['verified_group_user_id'] ?? null;
            // Shared alerts contain no family names, medical details, invoices, or manual reasons.
            $this->queueNotification($tenant, $id, 'groupme:'.$group, ['channel' => 'groupme', 'group_id' => $group, 'user_id' => $user, 'body' => 'Wren waitlist update: '.str_replace('_', ' ', $action).'. Review the details in Everbranch: '.config('app.url').'/soccer/messages?tenant=wren-family-soccer']);
        } elseif ($waitlist && ! $emailMode) {
            $this->queue($tenant, $id, config('soccer.sms_recipient'), 'Wren waitlist: '.str_replace('_', ' ', $action).'. '.($data['summary'] ?? 'Review the change in Everbranch.').' '.config('app.url').'/soccer?tenant=wren-family-soccer');
        }
        if ($familyText && $family) {
            $f = $this->store->row('families', $tenant, $family);
            $p = $this->store->decrypt($f->profile);
            $invoiceId = in_array($action, ['initial_registration', 'waitlist_offer', 'manual_waitlist_offer', 'resumed_registration', 'payment_reminder'], true) ? ($data['invoice_id'] ?? null) : null;
            if ($invoiceId && $this->store->query('invoices', $tenant)->where('id', $invoiceId)->value('status') === 'waived') {
                $invoiceId = null;
            }
            if ($emailMode && $f->verified_at && filter_var($f->email, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower(trim($f->email));
                $this->queueNotification($tenant, $id, 'email:'.$email, array_filter(['channel' => 'email', 'email' => $email, 'body' => $familyText, 'invoice_id' => $invoiceId]));
            } elseif (! $emailMode && $f->sms_consent_at && ! empty($p['phone'])) {
                $this->queue($tenant, $id, $p['phone'], $familyText, $invoiceId);
            }
        }

        return $id;
    }

    private function queueNotification(int $tenant, string $event, string $recipient, array $payload): void
    {
        $this->store->insert('deliveries', $tenant, ['event_id' => $event, 'recipient_key' => hash('sha256', $recipient), 'payload' => $this->store->encrypt($payload)]);
    }

    private function queue(int $tenant, string $event, string $phone, string $body, ?string $invoiceId = null): void
    {
        $phone = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($phone);
        if (! $phone) {
            return;
        }
        $existing = $this->store->query('deliveries', $tenant)->where('event_id', $event)->where('recipient_key', hash('sha256', $phone))->first();
        if ($existing) {
            $payload = $this->store->decrypt($existing->payload);
            $payload['body'] .= "\n".$body;
            if ($invoiceId) {
                $payload['invoice_id'] = $invoiceId;
            }
            $this->store->query('deliveries', $tenant)->where('id', $existing->id)->update(['payload' => $this->store->encrypt($payload), 'updated_at' => now()]);

            return;
        }
        $this->store->insert('deliveries', $tenant, ['event_id' => $event, 'recipient_key' => hash('sha256', $phone), 'payload' => $this->store->encrypt(array_filter(['phone' => $phone, 'body' => $body, 'invoice_id' => $invoiceId]))]);
    }
}
