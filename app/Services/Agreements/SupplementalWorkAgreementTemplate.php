<?php

namespace App\Services\Agreements;

use App\Models\Agreement;

class SupplementalWorkAgreementTemplate
{
    /** @return array<string,mixed> */
    public function build(Agreement $parent, string $description, int $amountCents, ?float $approvedHours = null, string $agreementType = 'supplemental_work'): array
    {
        $isMilestone = $agreementType === 'milestone';
        $title = ($isMilestone ? 'Implementation Milestone' : 'Supplemental Work Order').' — '.$parent->tenant->name;
        $calculation = $approvedHours !== null
            ? number_format($approvedHours, 2).' approved hours × $50.00/hour'
            : 'Approved fixed price';
        $content = [
            'title' => $title,
            'parties' => ['provider' => 'Evergrove Software', 'platform' => 'Everbranch', 'client' => $parent->tenant->name, 'effective_date' => 'Date of electronic acceptance'],
            'purpose' => $isMilestone ? 'Authorize the implementation milestone payment already defined in the accepted parent agreement.' : 'Authorize the additional work and separate payment described below without changing the original agreement.',
            'responsibilities' => [
                'Evergrove' => [$description],
                'Client' => ['Review this exact scope and price before accepting and paying.'],
                'Everbranch' => ['Keep the immutable approval, payment status, invoice, and receipt linked to the client workspace.'],
            ],
            'third_party_costs' => ['No Shopify or third-party provider charge is included unless expressly listed in this work order.'],
            'ownership' => (array) data_get($parent->currentVersion?->content_payload, 'ownership', []),
            'client_responsibilities' => 'Provide timely access, decisions, content, and approvals needed for this supplemental work.',
            'platform_availability' => 'The provider limitations and exclusions in the parent agreement remain unchanged.',
            'electronic_acceptance' => 'Acceptance authorizes only this exact supplemental scope and amount. Payment requires a separate customer action; no saved payment method is silently charged.',
        ];
        $pricing = [
            'currency' => 'USD',
            'cost_categories' => ['evergrove_implementation' => ['label' => 'Evergrove supplemental services', 'description' => 'A separate charge approved under the parent agreement.']],
            'cards' => [[
                'key' => $isMilestone ? 'implementation_milestone' : 'supplemental_work', 'cost_category' => 'evergrove_implementation', 'label' => $isMilestone ? 'Approved implementation milestone' : 'Approved supplemental work',
                'amount_cents' => $amountCents, 'frequency' => 'one_time', 'owner' => 'Evergrove implementation',
                'collectible_by_everbranch' => true, 'payment_timing' => 'due_on_acceptance', 'detail' => $description.' — '.$calculation.'.',
            ]],
            'implementation_payment_schedule' => ['due_on_acceptance_cents' => 0, 'due_before_launch_cents' => null],
            'shopify_plan_disclosure' => 'This work order does not change or collect Shopify store expenses.',
            'tax_disclosure' => 'Prices are before applicable tax. Stripe-calculated tax remains disabled until the required tax decision and registrations are confirmed.',
        ];
        $subscription = [
            'billing_lane' => 'stripe_direct', 'provider' => 'stripe', 'purchase_key' => $isMilestone ? 'evergrove.implementation_milestone' : 'evergrove.supplemental_work',
            'pricing_model' => 'agreement_specific', 'currency' => 'USD', 'billing_interval' => 'one_time',
            'onboarding_amount_cents' => 0, 'promotional_amount_cents' => 0, 'promotional_cycles' => 0, 'standard_amount_cents' => 0,
            'activation_requirements' => ['accepted_active_agreement', 'verified_provider_payment'],
            'activation_status' => 'disabled_pending_verified_payment',
            'authorized_line_items' => [['key' => $isMilestone ? 'implementation_milestone' : 'supplemental_work', 'amount_cents' => $amountCents, 'frequency' => 'one_time']],
        ];

        return [
            'agreement_type' => $agreementType, 'title' => $content['title'], 'content' => $content,
            'scope' => ['matrix' => [], 'sections' => [['title' => 'Approved supplemental scope', 'body' => $description]], 'implementation_phases' => [], 'additional_scope' => $description],
            'pricing' => $pricing, 'subscription' => $subscription,
            'termination' => (array) ($parent->currentVersion?->termination_payload ?? []),
        ];
    }
}
