<?php

namespace App\Services\Billing;

use Illuminate\Validation\ValidationException;

/** New Standard rate card only; never rewrites legacy add-on rates or grants access. */
class StandardUsagePricing
{
    public const SERVICES = ['email', 'sms', 'ai', 'customer_ai', 'phone_voice', 'memory'];

    public function presentation(): array
    {
        $policy = (array) config('module_catalog.offers.standard.usage_policy');
        $multiplier = (int) $policy['ai_provider_cost_multiplier'];

        return [...$policy,
            'messaging_rates_micros' => (array) $policy['messaging_rates_micros'],
            'credit_packs_cents' => (array) config('marketing.messaging.platform.credit_packs_cents'),
            'currency' => 'USD',
            'costs_checked_at' => '2026-10-18',
            'ai_examples' => [
                ['model' => 'gpt-6.1-sol', 'label' => 'Example text task', 'input_tokens' => 5000, 'output_tokens' => 500, 'provider_cost_micros' => 15000, 'customer_cost_micros' => 15000 * $multiplier, 'assumption' => 'Standard short-context rates, uncached input. An illustration, not a flat fee per question.'],
                ['model' => 'gpt-realtime-2.1', 'label' => 'Current voice model', 'provider_audio_micros_per_balanced_minute' => 48000, 'customer_audio_micros_per_balanced_minute' => 48000 * $multiplier],
                ['model' => 'gpt-realtime-2.1-mini', 'label' => 'Lower-cost voice option', 'provider_audio_micros_per_balanced_minute' => 15000, 'customer_audio_micros_per_balanced_minute' => 15000 * $multiplier],
            ],
            'memory_example' => ['status' => 'Planned release', 'model' => 'gpt-6.1-sol', 'extra_input_tokens_per_reply' => 2000, 'provider_cost_micros_per_reply' => 4000, 'customer_cost_micros_per_reply' => 4000 * $multiplier, 'assumption' => 'Additional uncached text input for retrieved memories. Memory is a Standard feature when released with no separate access fee. Additional summarization/model usage uses the approved Everbranch model rate; retention and capacity follow the confirmed scope. No release date is promised.'],
            'ai_source' => 'https://developers.openai.com/api/docs/pricing',
            'voice_assumption' => 'One elapsed minute split equally between user and AI speech. Audio only; text/context, repeated history, transcription and phone/carrier costs are additional. Measured usage at the approved Everbranch rates determines the charge.',
            'tax_note' => 'Platform operating costs and maintenance are covered by the agreed prices. Applicable customer sales tax is calculated separately by Stripe Tax; no arbitrary tax surcharge is added.',
        ];
    }

    /** Customer rates and estimates; provider costs and margin stay server-side. */
    public function customerPresentation(): array
    {
        $usage = $this->presentation();
        unset($usage['ai_provider_cost_multiplier'], $usage['voice_provider_cost_multiplier'], $usage['memory_provider_cost_multiplier']);
        foreach ($usage['ai_examples'] as &$example) {
            unset($example['provider_cost_micros'], $example['provider_audio_micros_per_balanced_minute']);
        }
        unset($example);
        unset($usage['memory_example']['provider_cost_micros_per_reply'], $usage['ai_source']);

        return $usage;
    }

    /** Planning illustration only: not a five-seat entitlement, prepaid purchase or invoice charge. */
    public function teamUsageExample(): array
    {
        $usage = $this->presentation();
        $rates = $usage['messaging_rates_micros'];
        $rows = [
            ['key' => 'email', 'label' => 'Marketing emails', 'quantity' => 5000, 'unit' => 'emails', 'cost_micros' => 5000 * $rates['email'], 'planned' => false],
            ['key' => 'sms', 'label' => 'Text alerts', 'quantity' => 300, 'unit' => 'SMS segments', 'cost_micros' => 300 * $rates['sms'], 'planned' => false],
            ['key' => 'ai', 'label' => 'Example AI tasks', 'quantity' => 500, 'unit' => 'tasks', 'cost_micros' => 500 * $usage['ai_examples'][0]['customer_cost_micros'], 'planned' => false],
            ['key' => 'voice_mini', 'label' => 'Future Mini voice · audio only', 'quantity' => 250, 'unit' => 'balanced minutes', 'cost_micros' => 250 * $usage['ai_examples'][2]['customer_audio_micros_per_balanced_minute'], 'planned' => true],
            ['key' => 'memory', 'label' => 'Future long-term memory · additional context', 'quantity' => 500, 'unit' => 'replies', 'cost_micros' => 500 * $usage['memory_example']['customer_cost_micros_per_reply'], 'planned' => true],
        ];
        $total = (int) round(array_sum(array_column($rows, 'cost_micros')) / 10000);
        $offer = (array) config('module_catalog.offers.standard');

        return ['team_size' => 5, 'rows' => $rows, 'usage_total_cents' => $total,
            'base_cents' => $offer['monthly_cents'], 'setup_cents' => $offer['setup_cents'],
            'regular_month_cents' => $offer['monthly_cents'] + $total,
            'first_month_cents' => $offer['setup_cents'] + $offer['monthly_cents'],
            'initial_invoice_cents' => $offer['setup_cents'] + $offer['monthly_cents'],
            'note' => 'Illustrative usage, not additional charges on the initial invoice or a five-user access grant. Actual usage draws from prepaid credit. Planned voice and memory require release and agreed scope; memory summarization/model usage, phone transport and applicable tax can add cost. Memory and voice have no separate feature access fee; final voice rates and applicable components require approval before activation.',
        ];
    }

    public function invoiceUsageFooter(): string
    {
        $example = $this->teamUsageExample();
        $usd = fn (int $cents): string => '$'.number_format($cents / 100, 2, '.', ',');
        $lines = ['USAGE EXAMPLES — INFORMATIONAL ONLY; NOT ADDED TO THIS INVOICE', 'Example monthly use for a five-person team | Estimated charge'];
        foreach ($example['rows'] as $row) {
            $lines[] = $row['label'].' | '.number_format($row['quantity']).' '.$row['unit'].' | '.$usd((int) round($row['cost_micros'] / 10000));
        }
        $lines[] = 'Usage estimate: '.$usd($example['usage_total_cents']).' | Regular month incl. $299 base: '.$usd($example['regular_month_cents']);
        $lines[] = 'FIRST PAYMENT: '.$usd($example['initial_invoice_cents']).' setup + month 1. No usage fees or automatic preload. Later optional top-ups are separate purchases. Do not collect the first service month twice.';
        $lines[] = 'Premium marketing usage: email $1.49/1,000; SMS $0.05/segment; AI at the approved Everbranch model rates. AI task: 5,000 input + 500 output tokens (GPT-6.1 Sol). Future memory: 2,000 additional uncached input tokens/reply, about $0.008/reply, not a fixed subscription fee. Voice is balanced audio only.';
        $lines[] = $example['note'];

        return implode("\n", $lines);
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
        $quote = ['offer_version' => $offer['version'], 'usage' => $this->customerPresentation(), 'selected_services' => $services,
            'setup_cents' => $offer['setup_cents'], 'first_month_cents' => $offer['monthly_cents'],
            'preload_cents' => $preloadCents, 'subtotal_cents' => $offer['setup_cents'] + $offer['monthly_cents'], 'preload_timing' => 'separate_optional_purchase_after_initial_payment',
            'recurring_cents' => $offer['monthly_cents'], 'sms_setup_cents' => 0, 'feature_access_cents' => array_fill_keys($services, 0),
            'quote_required' => in_array('phone_voice', $services, true) ? ['Voice provider mix and itemized Everbranch usage rates must be approved before activation'] : [],
            'activation_note' => 'Selection does not activate a service. Provider setup, agreed scope and capacity remain required. Prepaid usage is funded only after verified payment. The first month is already included; monthly renewal must start after that covered period.',
        ];
        $quote['hash'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));

        return $quote;
    }
}
