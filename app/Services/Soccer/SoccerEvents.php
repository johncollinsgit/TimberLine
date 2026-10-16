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
        if ($waitlist) {
            $this->queue($tenant, $id, config('soccer.sms_recipient'), 'Wren waitlist: '.str_replace('_', ' ', $action).'. '.($data['summary'] ?? 'Review the change in Everbranch.').' '.config('app.url').'/soccer?tenant=wren-family-soccer');
        }
        if ($familyText && $family) {
            $f = $this->store->row('families', $tenant, $family);
            $p = $this->store->decrypt($f->profile);
            if ($f->sms_consent_at && ! empty($p['phone'])) {
                $invoiceId = in_array($action, ['initial_registration', 'waitlist_offer', 'manual_waitlist_offer', 'payment_reminder'], true) ? ($data['invoice_id'] ?? null) : null;
                $this->queue($tenant, $id, $p['phone'], $familyText, $invoiceId);
            }
        }

        return $id;
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
