<?php

namespace App\Services\Retail;

class RetailUsagePricing
{
    /** Presenter reference only; never activates an add-on or invents an allowance for Standard. */
    public function presentation(): array
    {
        $rows = [];
        foreach (['messaging', 'sms', 'bulk_email_marketing'] as $key) {
            $addon = config('module_catalog.addons.'.$key);
            $package = config('marketing.messaging.platform.packages.'.$key);
            $rows[] = ['key' => $key, 'label' => $addon['display_name'], 'monthly_cents' => $addon['pricing']['recurring_price_cents'], 'setup_cents' => $addon['pricing']['setup_price_cents'],
                'included_units' => $package['included_sms_segments'] ?? $package['included_email_messages'],
                'unit' => $key === 'sms' ? 'SMS segments' : 'emails',
                'overage_micros' => $key === 'bulk_email_marketing' ? null : config('marketing.messaging.platform.buyer_rates_micros.'.($key === 'sms' ? 'sms' : 'email')),
                'note' => $key === 'bulk_email_marketing' ? 'Bulk overage pricing requires written confirmation before purchase.' : ($key === 'sms' ? 'Long or Unicode messages can use multiple segments. Provider verification and recipient permission are required.' : 'Verified sending domain and recipient permission are required.')];
        }
        $ai = [];
        foreach (['starter', 'growth', 'pro'] as $tier) {
            $addon = config('module_catalog.addons.ai_branch_'.$tier);
            $ai[] = ['label' => $addon['display_name'], 'monthly_cents' => $addon['pricing']['recurring_price_cents'], 'requests' => $addon['ai_limits']['responses'], 'provider_budget_cents' => $addon['ai_limits']['provider_budget_cents'], 'status' => 'Proposed pilot limits — commercial approval required; no purchase or activation here'];
        }

        return ['messaging' => $rows, 'ai' => $ai,
            'future_usage' => [
                ['label' => 'Phone voice connection and calls', 'status' => 'Pricing to be determined', 'note' => 'Separate connection/provider fees, usage unit, allowance and overage rate will be agreed before activation. No phone-service allowance is promised in the base subscription.'],
                ['label' => 'Customer-facing AI conversations', 'status' => 'Pricing to be determined', 'note' => 'Shopper conversations using the business catalog and approved customer context may have separate AI/provider usage fees. Connection fees, metering, allowance and overage rate will be agreed before activation; the tablet demonstration does not establish production pricing.'],
                ['label' => 'Other future connections', 'status' => 'Pricing to be determined', 'note' => 'New integrations can carry separate connection, subscription and usage fees. Each connection requires a written quote and approval before activation.'],
            ], 'mms_micros' => config('marketing.messaging.platform.buyer_rates_micros.mms'),
            'credits_cents' => config('marketing.messaging.platform.credit_packs_cents'),
            'base_note' => 'The $299 base does not include unlimited email, SMS or AI. These are separate options; the confirmed agreement controls included capacity. Usage stops when the approved allowance, credit balance or AI request/spending cap is reached unless an authorized arrangement permits more.',
            'ai_note' => 'AI request and provider-spend limits both apply; reaching either limit stops new requests. No automatic overage charge is established here. Voice/telephone use is separate from these text AI pilot tiers. Demo voice is capped at five minutes per conversation and six starts per hour.'];
    }
}
