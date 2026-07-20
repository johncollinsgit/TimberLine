<?php

namespace App\Services\Agreements;

use InvalidArgumentException;

class CollinsElectricAgreementTemplate
{
    /** @return array<string,mixed> */
    public function build(
        int $onboardingAmountCents = 29900,
        int $launchPartnerAmountCents = 5900,
        int $standardAmountCents = 14900,
        ?string $additionalScope = null,
    ): array {
        $content = (array) config('agreement_templates.collins_electric_launch_partner', []);
        if ($content === []) {
            throw new InvalidArgumentException('Collins Electric agreement template is not configured.');
        }

        $additionalScope = trim((string) $additionalScope);
        if ($additionalScope !== '') {
            $content['scope_sections'][] = ['title' => 'Additional agreed scope', 'body' => $additionalScope];
        }

        $pricing = [
            'currency' => 'USD',
            'cost_categories' => [
                'everbranch_service' => ['label' => 'Everbranch setup and service', 'description' => 'Everbranch access, implementation, and ongoing service collected securely through Stripe after acceptance.'],
                'third_party' => ['label' => 'Third-party services', 'description' => 'QuickBooks, Stripe processing, SMS carrier/provider charges, and other approved providers remain separate unless expressly included.'],
            ],
            'cards' => [
                ['key' => 'everbranch_onboarding', 'cost_category' => 'everbranch_service', 'label' => 'Completed launch foundation and onboarding', 'amount_cents' => $onboardingAmountCents, 'frequency' => 'one_time', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'due_on_acceptance', 'detail' => 'Covers the completed workspace foundation, historical QuickBooks import/reporting setup, customer and job organization, multi-workspace access, and initial field-service configuration described in scope.'],
                ['key' => 'everbranch_launch_partner', 'cost_category' => 'everbranch_service', 'label' => 'Launch Partner service', 'amount_cents' => $launchPartnerAmountCents, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_current', 'detail' => 'First six consecutive billing cycles, including the payroll-hours and equipment-maintenance launch modules.'],
                ['key' => 'everbranch_standard', 'cost_category' => 'everbranch_service', 'label' => 'Ongoing service', 'amount_cents' => $standardAmountCents, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_future', 'detail' => 'Begins with billing cycle seven.'],
                ['key' => 'included_messaging', 'cost_category' => 'everbranch_service', 'label' => 'Included monthly messaging', 'amount_cents' => 0, 'display_amount' => 'Included', 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'included_with_subscription', 'detail' => 'Includes 250 outbound SMS segments and 1,000 outbound emails per calendar month. Unused allowance does not roll over.'],
                ['key' => 'sms_overage', 'cost_category' => 'everbranch_service', 'label' => 'SMS usage above the allowance', 'amount_cents' => null, 'amount_micros' => 50000, 'display_amount' => '$0.05 per segment', 'frequency' => 'sms_segment', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'monthly_in_arrears', 'detail' => 'Each SMS segment above 250 in a calendar month is metered and invoiced after that month closes. Multi-segment messages count as multiple segments.'],
                ['key' => 'email_overage', 'cost_category' => 'everbranch_service', 'label' => 'Email usage above the allowance', 'amount_cents' => null, 'amount_micros' => 5000, 'display_amount' => '$0.005 per email', 'frequency' => 'email', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'monthly_in_arrears', 'detail' => 'Each outbound email above 1,000 in a calendar month is metered and invoiced after that month closes.'],
                ['key' => 'approved_third_party_services', 'cost_category' => 'third_party', 'label' => 'Approved provider costs', 'amount_cents' => null, 'display_amount' => 'Provider-priced', 'frequency' => 'as_charged', 'owner' => 'Third-party providers', 'collectible_by_everbranch' => false, 'detail' => 'QuickBooks subscription, carrier registration fees, Stripe processing, taxes, and other approved services remain subject to their applicable terms.'],
                ['key' => 'out_of_scope', 'cost_category' => 'everbranch_service', 'label' => 'Approved out-of-scope work', 'amount_cents' => 5000, 'frequency' => 'hour', 'owner' => 'Evergrove implementation', 'collectible_by_everbranch' => false, 'payment_timing' => 'supplemental_work_order', 'detail' => 'Only after separate written electronic approval.'],
            ],
            'tax_disclosure' => 'Prices are stated before applicable taxes. Stripe processes Everbranch charges and Everbranch mirrors Stripe-confirmed invoice, tax, and receipt amounts. Live collection remains blocked until the required billing and tax readiness checks pass.',
        ];

        $subscription = [
            'billing_lane' => 'stripe_direct',
            'provider' => 'stripe',
            'purchase_key' => 'everbranch.launch_partner',
            'canonical_plan_key' => 'starter',
            'pricing_model' => 'agreement_specific',
            'onboarding_amount_cents' => $onboardingAmountCents,
            'promotional_amount_cents' => $launchPartnerAmountCents,
            'promotional_cycles' => 6,
            'standard_amount_cents' => $standardAmountCents,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'activation_requirements' => ['accepted_active_agreement', 'explicit_billing_lane_decision', 'approved_billing_lane', 'verified_provider_subscription', 'audited_entitlement_fulfillment'],
            'activation_status' => 'disabled_pending_verified_payment',
            'authorized_line_items' => [
                ['key' => 'everbranch_onboarding', 'amount_cents' => $onboardingAmountCents, 'frequency' => 'one_time'],
                ['key' => 'everbranch_launch_partner', 'amount_cents' => $launchPartnerAmountCents, 'frequency' => 'month', 'cycles' => 6],
                ['key' => 'everbranch_standard', 'amount_cents' => $standardAmountCents, 'frequency' => 'month', 'starts_cycle' => 7],
                ['key' => 'sms_overage', 'amount_micros' => 50000, 'frequency' => 'sms_segment', 'included_units_per_calendar_month' => 250, 'billing_timing' => 'monthly_in_arrears'],
                ['key' => 'email_overage', 'amount_micros' => 5000, 'frequency' => 'email', 'included_units_per_calendar_month' => 1000, 'billing_timing' => 'monthly_in_arrears'],
            ],
            'messaging_usage' => [
                'period' => 'calendar_month',
                'unused_allowance_rolls_over' => false,
                'sms' => ['included_segments' => 250, 'overage_rate_micros' => 50000],
                'email' => ['included_messages' => 1000, 'overage_rate_micros' => 5000],
                'invoice_timing' => 'after_period_close',
            ],
        ];

        return [
            'agreement_type' => (string) $content['agreement_type'],
            'title' => (string) $content['title'],
            'content' => $content,
            'scope' => ['matrix' => $content['scope_matrix'], 'sections' => $content['scope_sections'], 'implementation_phases' => $content['implementation_phases'], 'additional_scope' => $additionalScope !== '' ? $additionalScope : null],
            'pricing' => $pricing,
            'subscription' => $subscription,
            'termination' => [
                'notice_days' => 30,
                'export_window_days' => 30,
                'terms' => [
                    'Collins Electric keeps its business records, branding, QuickBooks account, customer data, equipment data, service photographs, and exported payroll-hours records.',
                    'Everbranch workspace access, reminders, integrations, workflows, modules, and provider connections stop on the effective termination date.',
                    'Operational data may be requested for export during the 30-day window; agreement, billing, audit, security, and legal records may be retained as required.',
                ],
            ],
        ];
    }
}
