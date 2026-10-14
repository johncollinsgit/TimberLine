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
                $this->queue($tenant, $id, $p['phone'], $familyText);
            }
        }

        return $id;
    }

    private function queue(int $tenant, string $event, string $phone, string $body): void
    {
        $phone = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($phone);
        if (! $phone) {
            return;
        }
        $this->store->insert('deliveries', $tenant, ['event_id' => $event, 'recipient_key' => hash('sha256', $phone), 'payload' => $this->store->encrypt(['phone' => $phone, 'body' => $body])]);
    }
}
