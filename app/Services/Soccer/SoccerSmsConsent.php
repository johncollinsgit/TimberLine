<?php

namespace App\Services\Soccer;

class SoccerSmsConsent
{
    public const VERSION = 'wren-operational-sms-2026-10-26';

    public const TEXT = 'I agree to receive automated Wren Family Soccer texts from Watershed Fellowship Upstate LLC at my primary mobile number about my family’s registration, waitlist, available-place offers, payment deadlines and season logistics. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help. Consent is optional and is not a condition of registration or purchase. This does not include marketing texts.';

    public const STOP_KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'OPTOUT', 'CANCEL', 'END', 'REVOKE', 'QUIT'];

    public function __construct(private SoccerStore $store) {}

    public function record(int $tenant, string $familyId, string $phone, bool $consented, string $source, ?string $providerId = null): array
    {
        if ($providerId && ($existing = $this->store->query('events', $tenant)->where('event_key', 'sms-consent:'.$providerId.':'.$familyId)->first())) {
            return $this->store->decrypt($existing->data);
        }
        $evidence = ['sender' => 'Wren Family Soccer / Watershed Fellowship Upstate LLC', 'purpose' => 'soccer_registration_updates', 'phone' => $phone, 'consented' => $consented, 'version' => self::VERSION, 'text' => self::TEXT, 'source' => $source, 'recorded_at' => now()->toIso8601String()];
        app(SoccerEvents::class)->record($tenant, null, $familyId, $consented ? 'sms_consent_granted' : 'sms_consent_revoked', $evidence, key: $providerId ? 'sms-consent:'.$providerId.':'.$familyId : null);

        return $evidence;
    }

    public function revokePhone(int $tenant, string $phone, string $providerId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $phone, $providerId) {
            foreach ($this->store->query('families', $tenant)->lockForUpdate()->get() as $family) {
                $profile = $this->store->decrypt($family->profile);
                if (app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($profile['phone'] ?? '') !== $phone) {
                    continue;
                }
                $profile['sms_consent_evidence'] = $this->record($tenant, $family->id, $phone, false, 'twilio_opt_out', $providerId);
                $this->store->query('families', $tenant)->where('id', $family->id)->update(['profile' => $this->store->encrypt($profile), 'sms_consent_at' => null, 'updated_at' => now()]);
            }
        });
    }
}
