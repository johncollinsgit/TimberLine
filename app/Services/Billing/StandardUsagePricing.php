<?php

namespace App\Services\Billing;

use Illuminate\Validation\ValidationException;

/** New Standard rate card only; never rewrites legacy add-on rates or grants access. */
class StandardUsagePricing
{
    public const SERVICES = ['email', 'sms', 'ai', 'customer_ai', 'phone_voice'];

    public function presentation(): array
    {
        $policy = (array) config('module_catalog.offers.standard.usage_policy');
        $multiplier = (int) $policy['ai_provider_cost_multiplier'];

        return [...$policy,
            'messaging_rates_micros' => (array) config('marketing.messaging.platform.buyer_rates_micros'),
            'credit_packs_cents' => (array) config('marketing.messaging.platform.credit_packs_cents'),
            'currency' => 'USD',
            'costs_checked_at' => '2026-10-18',
            'ai_examples' => [
                ['model' => 'gpt-6.1-sol', 'label' => 'Example text task', 'input_tokens' => 5000, 'output_tokens' => 500, 'provider_cost_micros' => 15000, 'customer_cost_micros' => 15000 * $multiplier, 'assumption' => 'Standard short-context rates, uncached input. An illustration, not a flat fee per question.'],
                ['model' => 'gpt-realtime-2.1', 'label' => 'Current voice model', 'provider_audio_micros_per_balanced_minute' => 48000, 'customer_audio_micros_per_balanced_minute' => 48000 * $multiplier],
                ['model' => 'gpt-realtime-2.1-mini', 'label' => 'Lower-cost voice option', 'provider_audio_micros_per_balanced_minute' => 15000, 'customer_audio_micros_per_balanced_minute' => 15000 * $multiplier],
            ],
            'ai_source' => 'https://developers.openai.com/api/docs/pricing',
            'voice_assumption' => 'One elapsed minute split equally between user and AI speech. Audio only; text/context, repeated history, transcription and phone/carrier costs are additional. Actual provider usage determines the charge.',
            'tax_note' => 'Platform operating costs and business overhead are covered by the agreed prices and margin. Applicable customer sales tax is calculated separately by Stripe Tax; no arbitrary tax surcharge is added.',
        ];
    }

    public function quote(array $services, int $preloadCents): array
    {
        if (array_diff($services, self::SERVICES) !== [] || count($services) !== count(array_unique($services))) {
            throw ValidationException::withMessages(['services' => 'Choose only the listed services, once each.']);
        }
        $packs = array_map('intval', (array) config('marketing.messaging.platform.credit_packs_cents'));
        if ($preloadCents !== 0 && ! in_array($preloadCents, $packs, true)) {
            throw ValidationException::withMessages(['preload_cents' => 'Choose a listed usage preload.']);
        }
        sort($services);
        $offer = (array) config('module_catalog.offers.standard');
        $quote = ['offer_version' => $offer['version'], 'usage' => $this->presentation(), 'selected_services' => $services,
            'setup_cents' => $offer['setup_cents'], 'first_month_cents' => $offer['monthly_cents'],
            'preload_cents' => $preloadCents, 'subtotal_cents' => $offer['setup_cents'] + $offer['monthly_cents'] + $preloadCents,
            'recurring_cents' => $offer['monthly_cents'], 'sms_setup_cents' => 0,
            'quote_required' => in_array('phone_voice', $services, true) ? ['Phone connection and any fixed managed-service fees'] : [],
            'activation_note' => 'Selection does not activate a service. Provider setup, agreed scope and capacity remain required. Prepaid usage is funded only after verified payment. The first month is already included; monthly renewal must start after that covered period.',
        ];
        $quote['hash'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));

        return $quote;
    }
}
