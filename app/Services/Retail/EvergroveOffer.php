<?php

namespace App\Services\Retail;

class EvergroveOffer
{
    public function presentation(): array
    {
        $pricing = (array) config('module_catalog.offers.standard');

        return [
            ...$pricing,
            'usage_policy' => array_diff_key($pricing['usage_policy'], array_flip(['ai_provider_cost_multiplier', 'voice_provider_cost_multiplier', 'memory_provider_cost_multiplier'])),
            'key' => 'standard',
            'quote_version' => hash('sha256', json_encode($pricing, JSON_THROW_ON_ERROR)),
            'monthly_cents' => (int) $pricing['monthly_cents'],
            'eligibility_note' => 'Standard offer for new businesses. Scope and capacity are confirmed before activation; a request does not activate billing.',
            'included' => [
                ['title' => 'A website that feels like your business', 'body' => 'A considered design, mobile and tablet layouts, your approved branding, and clear paths for customers to shop or get in touch.'],
                ['title' => 'Setup with a person who understands the work', 'body' => 'A conversation about your business, a starting catalog or service collection, and a private preview for your approval before launch.'],
                ['title' => 'Your business in Everbranch', 'body' => 'A managed website and workspace together, with customer requests and the context needed to follow up. Your team uses individual sign-ins and assigned permissions.'],
                ['title' => 'A strategic partner, every week', 'body' => 'A one-hour video call each week with Evergrove. We talk through marketing, work on photos, and explore potential prospects and opportunities for your business.'],
            ],
            'retail' => ['Product choices, variants, and availability', 'Shopping bags, pickup, and merchant-specific shipping rules', 'In-person sales and order history', 'Customer profiles and private staff notes', 'Rewards earning and redemption', 'Individual team access'],
            'partnership' => ['One-hour video call every week', 'Marketing conversations and practical next steps', 'Photo work and creative direction', 'Potential prospects and business opportunities'],
            'review_note' => 'Retail features shown in this showroom are demonstrations. We confirm the catalog, shipping, payments, rewards, team access, and launch scope with you before enabling them for your business.',
            'additional' => 'Payment processing, postage, domains, messaging usage, third-party services, and custom development are discussed and priced in your written scope when applicable.',
            'usage' => app(RetailUsagePricing::class)->presentation(),
            'contact_url' => 'https://evergrovesoftware.com/book',
        ];
    }
}
