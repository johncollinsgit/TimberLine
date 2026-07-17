<?php

namespace App\Services\Agreements;

use InvalidArgumentException;

class FrontYardFoodsAgreementTemplate
{
    /** @return array<string,mixed> */
    public function build(?int $implementationAmountCents = null, ?int $dueOnAcceptanceCents = null, ?int $dueBeforeLaunchCents = null, ?string $additionalScope = null): array
    {
        $content = (array) config('agreement_templates.front_yard_foods_launch_partner', []);
        if ($content === []) {
            throw new InvalidArgumentException('Front Yard Foods agreement template is not configured.');
        }
        $additionalScope = trim((string) $additionalScope);
        if ($additionalScope !== '') {
            $content['scope_sections'][] = ['title' => 'Additional agreed scope', 'body' => $additionalScope];
        }

        $pricing = [
            'currency' => 'USD',
            'cost_categories' => [
                'shopify_store' => ['label' => 'Shopify store expenses', 'description' => 'Paid directly to Shopify and controlled by Shopify.'],
                'third_party' => ['label' => 'Third-party apps and services', 'description' => 'Paid directly to each approved provider and never included unless the agreement expressly says otherwise.'],
                'everbranch_service' => ['label' => 'Everbranch setup and monthly service', 'description' => 'Everbranch access and service prices agreed with Evergrove and collected securely through Stripe after acceptance.'],
                'evergrove_implementation' => ['label' => 'Evergrove implementation services', 'description' => 'Project work for migration, configuration, integration, testing, training, launch, and approved changes.'],
            ],
            'cards' => [
                ['key' => 'shopify_basic_monthly', 'cost_category' => 'shopify_store', 'label' => 'Shopify Basic', 'amount_cents' => 3900, 'frequency' => 'month', 'owner' => 'Shopify', 'collectible_by_everbranch' => false, 'detail' => '$29/month effective rate when Shopify is billed annually. Paid directly to Shopify.'],
                ['key' => 'approved_third_party_apps', 'cost_category' => 'third_party', 'label' => 'Approved apps and services', 'amount_cents' => null, 'display_amount' => 'Provider-priced', 'frequency' => 'as_charged', 'owner' => 'Third-party providers', 'collectible_by_everbranch' => false, 'detail' => 'Booking, calendar, course, membership, email, domain, shipping, tax, or other approved tools are separate expenses.'],
                ['key' => 'everbranch_onboarding', 'cost_category' => 'everbranch_service', 'label' => 'Everbranch one-time setup', 'amount_cents' => 29900, 'frequency' => 'one_time', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'due_on_acceptance', 'detail' => 'Due on electronic acceptance unless a written payment arrangement is approved.'],
                ['key' => 'everbranch_launch_partner', 'cost_category' => 'everbranch_service', 'label' => 'Everbranch Launch Partner service', 'amount_cents' => 5900, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_current', 'detail' => 'First six consecutive billing cycles.'],
                ['key' => 'everbranch_standard', 'cost_category' => 'everbranch_service', 'label' => 'Everbranch ongoing service', 'amount_cents' => 14900, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_future', 'detail' => 'Begins with billing cycle seven.'],
                ['key' => 'shopify_implementation', 'cost_category' => 'evergrove_implementation', 'label' => 'Shopify/Square implementation project', 'amount_cents' => $implementationAmountCents, 'display_amount' => 'To be agreed', 'frequency' => 'one_time', 'owner' => 'Evergrove implementation', 'collectible_by_everbranch' => true, 'payment_timing' => 'scheduled', 'detail' => 'Agreed one-time project amount and payment schedule configured before the proposal is sent.'],
                ['key' => 'out_of_scope', 'cost_category' => 'evergrove_implementation', 'label' => 'Approved out-of-scope work', 'amount_cents' => 5000, 'frequency' => 'hour', 'owner' => 'Evergrove implementation', 'collectible_by_everbranch' => false, 'payment_timing' => 'supplemental_work_order', 'detail' => 'Only after written electronic approval.'],
            ],
            'implementation_payment_schedule' => [
                'due_on_acceptance_cents' => $dueOnAcceptanceCents,
                'due_before_launch_cents' => $dueBeforeLaunchCents,
            ],
            'shopify_plan_disclosure' => 'Front Yard Foods is expected to begin on Shopify Basic at $39 per month month-to-month or a $29 per month effective rate when billed annually. Shopify is paid directly and is not included in Everbranch onboarding, Everbranch subscription, or implementation fees. Processing charges, transaction fees, taxes, and paid Shopify apps are additional. Shopify controls pricing and the current price will be confirmed before activation.',
            'tax_disclosure' => 'Prices are stated before any applicable taxes unless the charging provider says otherwise. Shopify determines taxes on Shopify store expenses. Each approved third-party provider determines its own invoice and taxes. Stripe processes Everbranch and Evergrove charges; Everbranch mirrors Stripe-confirmed tax, invoice, and receipt amounts and does not independently calculate them. Live tax collection remains blocked until the required tax decision and registrations are confirmed.',
        ];

        $subscription = [
            'billing_lane' => 'stripe_direct',
            'provider' => 'stripe',
            'purchase_key' => 'everbranch.launch_partner',
            'canonical_plan_key' => 'starter',
            'pricing_model' => 'agreement_specific',
            'onboarding_amount_cents' => 29900,
            'promotional_amount_cents' => 5900,
            'promotional_cycles' => 6,
            'standard_amount_cents' => 14900,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'activation_requirements' => ['accepted_active_agreement', 'explicit_billing_lane_decision', 'approved_billing_lane', 'verified_provider_subscription', 'audited_entitlement_fulfillment'],
            'activation_status' => 'disabled_pending_verified_payment',
            'authorized_line_items' => [
                ['key' => 'everbranch_onboarding', 'amount_cents' => 29900, 'frequency' => 'one_time'],
                ['key' => 'everbranch_launch_partner', 'amount_cents' => 5900, 'frequency' => 'month', 'cycles' => 6],
                ['key' => 'everbranch_standard', 'amount_cents' => 14900, 'frequency' => 'month', 'starts_cycle' => 7],
            ],
        ];

        $termination = [
            'notice_days' => 30,
            'export_window_days' => 30,
            'terms' => [
                'Front Yard Foods keeps its Shopify store, Square account, domain, branding, content, and client-owned data.',
                'Everbranch workspace access, tenant-specific integrations, workflows, reminders, APIs, modules, and Square-to-Shopify synchronization stop on the effective termination date.',
                'The shared Everbranch application remains in the App Store; termination does not remove it.',
                'Operational data may be requested for export during the 30-day window and is not hard-deleted immediately on cancellation.',
                'Agreement, billing, acceptance, audit, security, and legal records may be retained as required.',
                'Third-party subscriptions remain the client’s responsibility unless Evergrove expressly agrees to cancel them.',
            ],
        ];

        return [
            'agreement_type' => (string) $content['agreement_type'],
            'title' => (string) $content['title'],
            'content' => $content,
            'scope' => ['matrix' => $content['scope_matrix'], 'sections' => $content['scope_sections'], 'implementation_phases' => $content['implementation_phases'], 'additional_scope' => $additionalScope !== '' ? $additionalScope : null],
            'pricing' => $pricing,
            'subscription' => $subscription,
            'termination' => $termination,
        ];
    }
}
