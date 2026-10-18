<?php

namespace App\Services\Agreements;

use App\Models\Tenant;

/** New standard offers only. Existing accepted/issued templates remain immutable. */
class StandardServicesAgreementTemplate
{
    public function build(Tenant $tenant, ?string $additionalScope = null): array
    {
        $offer = config('module_catalog.offers.standard');
        $client = trim($tenant->name) ?: 'Client';
        $scope = trim((string) $additionalScope);
        $hourly = (int) collect(app(ManagedWebsiteAgreementTemplate::class)->build($tenant)['pricing']['cards'])->firstWhere('key', 'out_of_scope')['amount_cents'];
        $content = [
            'agreement_type' => 'launch_partner', 'title' => $client.' — Everbranch Standard Services Agreement',
            'parties' => ['provider' => 'Evergrove Software LLC', 'platform' => 'Everbranch', 'client' => $client, 'effective_date' => 'Date of electronic acceptance'],
            'purpose' => 'Provide the client with Everbranch Standard and a guided setup for the business workflow described in the agreed launch scope. This agreement is separate from existing client contracts and does not reprice them.',
            'responsibilities' => [
                $client => ['Supply accurate product, price, stock, shipping, warranty and business information and rights-cleared photographs.', 'Review the launch scope, verify imports and approve publication and any customer-facing claims.', 'Remain responsible for knife sales, tax decisions, stock accuracy, manufacturing specifications, fulfillment, warranty service and lawful customer communications.'],
                'Evergrove' => ['Configure the approved standard workflow and keep unapproved work in draft.', 'Provide licensed access and maintenance for the agreed Standard plan while active.', 'Use client data only for agreed service, support, security, billing, legal obligations and authorized integrations.'],
            ],
            'scope_matrix' => [
                ['thing' => 'Guided setup', 'surface' => 'Everbranch', 'approach' => 'One business workspace, initial configuration of the supported standard workflow, one initial review and a handoff walkthrough. Imports, integrations and custom work require a confirmed written scope.'],
                ['thing' => 'Monthly Standard service', 'surface' => 'Everbranch', 'approach' => 'Licensed access, standard platform maintenance and support for the confirmed plan limits. This price does not grant every module, unlimited capacity or unlimited implementation work.'],
                ['thing' => 'Product and operations demonstration', 'surface' => 'Independent concept', 'approach' => 'Knife finder, passport, inventory, production, order and shipping screens currently illustrate the proposed workflow. Demo stock, customers and orders do not become live records automatically. Production features and integrations require the agreed launch scope and readiness checks.'],
                ['thing' => 'Communications and AI', 'surface' => 'Authorized providers', 'approach' => 'Availability depends on provider verification, consent and the selected service. AI, email/SMS usage, carrier labels and other third-party costs are separate unless expressly included in the agreed scope.'],
                ['thing' => 'Custom development and changes', 'surface' => 'Separate work order', 'approach' => 'Bespoke code, major redesign, additional implementation, integrations and work outside the confirmed scope require separate written pricing and approval before work or charges begin. The existing default rate is $50/hour unless an approved work order states a different fixed price.'],
            ],
            'scope_sections' => [
                ['title' => 'Launch scope and capacity', 'body' => $scope ?: 'Before implementation begins, both parties must confirm the supported modules, user/contact/channel limits, content, imports, integrations and launch acceptance criteria in writing. A demo or payment alone does not activate unavailable or separately priced capabilities.'],
                ['title' => 'First invoice and subsequent months', 'body' => 'The initial invoice includes the one-time setup fee and the first service month. Subsequent service months are billed monthly at the stated Standard price. The service start date and first monthly renewal date are recorded at handoff. The first month must not also be collected through a second checkout or subscription invoice.'],
                ['title' => 'Month-to-month service and cancellation', 'body' => 'Service continues month to month at the stated price unless cancelled. Either party may give written cancellation notice to john@evergrovesoftware.com before the next monthly renewal; cancellation takes effect at the end of the current paid service period. There is no multi-month minimum or early-termination penalty. Agreed completed setup work and approved third-party costs remain payable. No automatic card charge is authorized until a separate payment-provider authorization is completed.'],
                ['title' => 'Client data and ownership', 'body' => 'The client retains its branding, supplied content, product details and business records. Evergrove retains the platform, reusable software and general improvements. Reasonable export and handoff arrangements will be agreed on cancellation; third-party licenses remain subject to their own terms.'],
                ['title' => 'Availability and approvals', 'body' => 'Provider outages, maintenance and security response may affect service. No sales, ranking, conversion, uninterrupted availability or future feature date is guaranteed. Real messages, payments, publishing and carrier purchases require the applicable verified provider and approval controls.'],
            ],
            'third_party_costs' => ['Applicable taxes, payment processing, domains and provider fees', 'AI, email/SMS usage and approved carrier/postage costs', 'Separately approved custom work and integrations'],
            'ownership' => ['client' => 'Client-owned business information, records and supplied content remain the client’s.', 'provider' => 'Evergrove retains its platform, source code and reusable components.'],
            'electronic_acceptance' => 'The authorized signer accepts this exact version, its scope, pricing, monthly service, separate-work approval rule, cancellation and electronic records. Changes require a new accepted version or work order.',
        ];
        $setup = (int) $offer['setup_cents'];
        $monthly = (int) $offer['monthly_cents'];

        return [
            'agreement_type' => 'launch_partner', 'title' => $content['title'], 'content' => $content,
            'scope' => ['matrix' => $content['scope_matrix'], 'sections' => $content['scope_sections'], 'additional_scope' => $scope ?: null],
            'pricing' => ['currency' => $offer['currency'], 'cost_categories' => ['everbranch_service' => ['label' => 'Everbranch Standard', 'description' => 'Setup and monthly service are separate line items.']],
                'cards' => [
                    ['key' => 'everbranch_onboarding', 'cost_category' => 'everbranch_service', 'label' => 'One-time setup', 'amount_cents' => $setup, 'frequency' => 'one_time', 'owner' => 'Evergrove', 'collectible_by_everbranch' => true, 'payment_timing' => 'due_on_acceptance', 'detail' => 'Guided setup within the confirmed launch scope.'],
                    ['key' => 'everbranch_standard', 'cost_category' => 'everbranch_service', 'label' => 'Everbranch Standard', 'amount_cents' => $monthly, 'frequency' => 'month', 'owner' => 'Evergrove', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_current', 'detail' => 'Month-to-month service. First month is included in the initial setup-and-first-month invoice.'],
                    ['key' => 'out_of_scope', 'cost_category' => 'everbranch_service', 'label' => 'Separately approved additional work', 'amount_cents' => $hourly, 'frequency' => 'hour', 'owner' => 'Evergrove', 'collectible_by_everbranch' => false, 'payment_timing' => 'supplemental_work_order', 'detail' => 'Existing default work-order rate; only after separate written approval. A separately approved fixed quote may apply.'],
                ], 'tax_disclosure' => 'Prices are before applicable tax. Provider-confirmed amounts control the payment record. Separately quoted costs require approval.'],
            'subscription' => ['billing_lane' => 'stripe_direct', 'provider' => 'stripe', 'purchase_key' => 'everbranch.standard', 'canonical_plan_key' => $offer['fulfillment_plan_key'], 'offer_version' => $offer['version'], 'pricing_model' => 'standard', 'onboarding_amount_cents' => $setup, 'standard_amount_cents' => $monthly, 'currency' => $offer['currency'], 'billing_interval' => 'month', 'initial_invoice_amount_cents' => $setup + $monthly, 'initial_collection_method' => 'manual_invoice', 'activation_status' => 'disabled_pending_scope_payment_and_readiness', 'activation_requirements' => ['confirmed_scope_and_capacity', 'accepted_agreement', 'verified_payment', 'approved_billing_lane', 'audited_entitlement_fulfillment'], 'authorized_line_items' => [['key' => 'everbranch_onboarding', 'amount_cents' => $setup, 'frequency' => 'one_time'], ['key' => 'everbranch_standard', 'amount_cents' => $monthly, 'frequency' => 'month']]],
            'termination' => ['export_window_days' => 30, 'terms' => ['The client retains its records and supplied content. Platform access and managed services end on the agreed termination date.', 'Operational records may be requested for export during the 30-day export window. Billing, signed agreements and legal/audit records may be retained as required.'], 'minimum_term_months' => 0, 'notice' => 'Written notice before the next monthly renewal to john@evergrovesoftware.com.', 'effective' => 'End of the current paid service period.', 'early_termination_fee_cents' => 0, 'out_of_scope_work' => 'Separate written quote and approval; the existing $50/hour work-order rate applies unless an approved fixed quote states otherwise.'],
        ];
    }
}
