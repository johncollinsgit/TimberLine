<?php

namespace App\Services\Agreements;

use App\Models\Tenant;

class ManagedWebsiteAgreementTemplate
{
    /** @return array<string,mixed> */
    public function build(
        Tenant $tenant,
        int $onboardingAmountCents = 29900,
        int $launchPartnerAmountCents = 8900,
        int $standardAmountCents = 14900,
        ?string $additionalScope = null,
    ): array {
        $client = trim((string) $tenant->name) ?: 'Client';
        $additionalScope = trim((string) $additionalScope);
        $content = [
            'agreement_type' => 'launch_partner',
            'title' => $client.' — Everbranch Managed Website Launch Partner Agreement',
            'parties' => [
                'provider' => 'Evergrove Software',
                'platform' => 'Everbranch',
                'client' => $client,
                'effective_date' => 'Date of electronic acceptance',
            ],
            'purpose' => 'Provide '.$client.' with a managed Everbranch website, a private editing workspace, customer inquiry tools, and bounded ongoing help publishing client-supplied photographs and content.',
            'responsibilities' => [
                $client => [
                    'Supply accurate business details, approved wording, prices, policies, and photographs the client has the right to use.',
                    'Review drafts and approve publication, customer-facing claims, and requested changes.',
                    'Respond to customer inquiries and remain responsible for quotes, orders, payments, fulfillment, taxes, warranties, and customer service.',
                ],
                'Everbranch' => [
                    'Provide the tenant workspace, managed website editor, draft previews, safe publishing history, hosted Everbranch address, and tenant-owned inquiry records described below.',
                    'Apply the included client-supplied content updates within the monthly limits and keep unpublished work private until an authorized workspace administrator publishes it.',
                    'Use client data and photographs only for approved service delivery, support, security, legal compliance, and client-authorized integrations.',
                ],
            ],
            'scope_matrix' => [
                ['thing' => 'Managed website and editor', 'surface' => 'Everbranch', 'approach' => 'One managed website, one Everbranch-hosted address, draft preview, mobile preview, page editor, version history, and rollback controls for authorized workspace users.'],
                ['thing' => 'Launch foundation', 'surface' => 'Everbranch Website', 'approach' => 'Initial workspace setup, one starter design, up to six standard pages, verified business contact details, and one quote-only offering.'],
                ['thing' => 'Client-supplied photos and catalog content', 'surface' => 'Everbranch Website', 'approach' => 'Up to ten new or replacement photographs, gallery entries, product examples, or comparable content items per calendar month, using files and accurate details supplied by the client.'],
                ['thing' => 'Monthly revisions', 'surface' => 'Everbranch support', 'approach' => 'Up to two consolidated content-update requests per calendar month, with one reasonable revision round for each request. Unused allowances do not roll over.'],
                ['thing' => 'Customer inquiries', 'surface' => 'Everbranch Website', 'approach' => 'One quote/contact flow and a tenant-owned leads inbox. The client handles estimates, order confirmation, payment collection, production, delivery, returns, and customer communication.'],
                ['thing' => 'Workspace access', 'surface' => 'Everbranch', 'approach' => 'Up to three named client users with role-based access while the subscription is active and in good standing.'],
                ['thing' => 'Support timing', 'surface' => 'Everbranch support', 'approach' => 'Evergrove targets acknowledgment of complete update requests within three business days. This is a service target, not a guaranteed completion deadline or uptime commitment.'],
                ['thing' => 'Excluded work', 'surface' => 'Separate written work order', 'approach' => 'Custom code, a major redesign, professional photography, original logo or brand design, ecommerce checkout, payment processing, custom integrations, advertising, ongoing SEO campaigns, and work above the stated limits require separate written approval and pricing.'],
                ['thing' => 'Agreement and recurring payment', 'surface' => 'Everbranch and Stripe', 'approach' => 'The accepted agreement authorizes the stated one-time setup charge and recurring monthly subscription through tenant-linked Stripe Checkout.'],
            ],
            'scope_sections' => [
                ['title' => 'Launch foundation', 'body' => 'Evergrove will prepare the '.$client.' Everbranch workspace and private website draft, apply one supported starter design, configure up to six standard pages, add verified business contact details, and configure one quote-only offering. Publication and domain changes remain separate deliberate actions.'],
                ['title' => 'What the monthly service includes', 'body' => 'While the subscription is active and in good standing, the monthly fee includes the managed editor, hosting at one Everbranch address, draft and mobile previews, version history and rollback, the tenant-owned leads inbox, access for up to three named client users, up to ten client-supplied photograph or catalog-content additions or replacements per calendar month, and up to two consolidated content-update requests per calendar month with one reasonable revision round per request. Unused monthly allowances do not roll over.'],
                ['title' => 'Photographs and content supplied by the client', 'body' => $client.' may send photographs or inspiration images for approved website updates. The client must identify what each image represents, supply accurate product or service details, and confirm it owns or has permission to use every submitted asset. The monthly service does not include professional photography, image licensing, extensive retouching, original brand design, or researching missing product facts.'],
                ['title' => 'Quote and custom-order inquiries', 'body' => 'The included website flow collects customer contact and request details for follow-up. It does not by itself create a binding order, calculate a final price, charge the customer, promise inventory, schedule production, arrange delivery, or accept a deposit. '.$client.' remains responsible for reviewing each request and separately confirming all order terms.'],
                ['title' => 'Changes above the included limits', 'body' => 'Requests above the monthly limits, custom development, additional pages, a major redesign, ecommerce, payment processing, custom integrations, advertising, or other excluded work require a separate written work order before charges or implementation begin. Approved out-of-scope work is $50 per hour unless a signed work order states a different fixed price.'],
                ['title' => 'Data-use assurance', 'body' => 'Evergrove will use '.$client.' business data, customer inquiries, files, and photographs only to provide the approved website, support, billing, security, legal compliance, and client-authorized integrations. Evergrove will not sell that data or share it with unrelated third parties.'],
            ],
            'implementation_phases' => [
                ['phase' => '1', 'title' => 'Workspace and private draft', 'deliverables' => ['Confirm the business name, contact details, website address, initial design, and authorized users.', 'Create the private draft and initial quote-only offering without publishing or charging customers.', 'Record missing content, photographs, policies, and client decisions.']],
                ['phase' => '2', 'title' => 'Content review and launch', 'deliverables' => ['Apply the approved launch content and client-supplied photographs within the stated foundation.', 'Review desktop and mobile drafts and correct agreed launch-blocking issues.', 'Publish only after an authorized workspace administrator approves the draft and the required commercial gates are satisfied.']],
                ['phase' => '3', 'title' => 'Ongoing managed service', 'deliverables' => ['Process complete update requests within the monthly limits.', 'Keep client-requested changes in draft until reviewed when practical.', 'Use separate written work orders for work above the included limits or outside scope.']],
            ],
            'third_party_costs' => [
                'Stripe processing fees and applicable taxes',
                'Custom domain registration, renewal, DNS, business email, paid fonts, stock media, and other approved provider costs',
                'Advertising, professional photography, shipping, ecommerce, payment-processing, and custom-integration services',
            ],
            'ownership' => [
                'client' => $client.' retains its business name, branding, original content and photographs, customer inquiries, product information, and exported business records, subject to applicable law and third-party terms.',
                'provider' => 'Evergrove and Everbranch retain the platform, source code, reusable themes, modules, integrations, workflows, and general product improvements. The client receives a limited right to use licensed Everbranch functionality while its subscription is active and in good standing.',
            ],
            'client_responsibilities' => $client.' supplies timely decisions, accurate content, usable files, rights-cleared photographs, product and policy details, and a representative authorized to approve drafts and publication. Delays or incomplete submissions may delay updates.',
            'platform_availability' => 'Evergrove does not guarantee sales, traffic, search ranking, customer conversion, third-party approval, provider pricing, uninterrupted availability, or a particular business outcome. Maintenance, provider outages, and security response may temporarily affect service.',
            'electronic_acceptance' => 'The authorized signer must provide legal name, title, email, typed signature, authority confirmation, and express acceptance of scope, pricing, subscription, hourly work, termination, and electronic records. Acceptance binds the exact immutable version and content hash. Later changes require a new version, amendment, addendum, or accepted change request.',
        ];

        if ($additionalScope !== '') {
            $content['scope_sections'][] = ['title' => 'Additional agreed scope', 'body' => $additionalScope];
        }

        $pricing = [
            'currency' => 'USD',
            'cost_categories' => [
                'everbranch_service' => ['label' => 'Everbranch setup and managed website service', 'description' => 'Workspace, website launch foundation, licensed platform access, and bounded ongoing service collected securely through Stripe after acceptance.'],
                'third_party' => ['label' => 'Third-party services', 'description' => 'Provider fees and excluded services remain separate unless expressly included.'],
            ],
            'cards' => [
                ['key' => 'everbranch_onboarding', 'cost_category' => 'everbranch_service', 'label' => 'Managed website launch foundation', 'amount_cents' => $onboardingAmountCents, 'frequency' => 'one_time', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'due_on_acceptance', 'detail' => 'One-time workspace and private-draft setup, supported starter design, up to six standard pages, verified contact details, and one quote-only offering.'],
                ['key' => 'everbranch_launch_partner', 'cost_category' => 'everbranch_service', 'label' => 'Founder Launch Partner managed website service', 'amount_cents' => $launchPartnerAmountCents, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_current', 'detail' => 'First six consecutive billing cycles with the monthly access and service limits stated in scope.'],
                ['key' => 'everbranch_standard', 'cost_category' => 'everbranch_service', 'label' => 'Standard managed website service', 'amount_cents' => $standardAmountCents, 'frequency' => 'month', 'owner' => 'Everbranch', 'collectible_by_everbranch' => true, 'payment_timing' => 'recurring_future', 'detail' => 'Begins with billing cycle seven and continues month to month with the same stated service limits until changed by a later accepted agreement.'],
                ['key' => 'approved_third_party_services', 'cost_category' => 'third_party', 'label' => 'Approved provider costs', 'amount_cents' => null, 'display_amount' => 'Provider-priced', 'frequency' => 'as_charged', 'owner' => 'Third-party providers', 'collectible_by_everbranch' => false, 'detail' => 'Domain, business email, paid media, Stripe processing, taxes, and other approved provider expenses remain subject to their applicable terms.'],
                ['key' => 'out_of_scope', 'cost_category' => 'everbranch_service', 'label' => 'Approved work above the included limits', 'amount_cents' => 5000, 'frequency' => 'hour', 'owner' => 'Evergrove implementation', 'collectible_by_everbranch' => false, 'payment_timing' => 'supplemental_work_order', 'detail' => 'Only after separate written electronic approval.'],
            ],
            'tax_disclosure' => 'Prices are stated before applicable taxes. Stripe processes Everbranch charges and Everbranch mirrors Stripe-confirmed invoice, subscription, and receipt amounts. Live collection remains blocked until required billing and tax readiness checks pass.',
        ];

        $subscription = [
            'billing_lane' => 'stripe_direct',
            'provider' => 'stripe',
            'purchase_key' => 'everbranch.managed_website_launch_partner',
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
            ],
        ];

        return [
            'agreement_type' => 'launch_partner',
            'title' => $content['title'],
            'content' => $content,
            'scope' => ['matrix' => $content['scope_matrix'], 'sections' => $content['scope_sections'], 'implementation_phases' => $content['implementation_phases'], 'additional_scope' => $additionalScope !== '' ? $additionalScope : null],
            'pricing' => $pricing,
            'subscription' => $subscription,
            'termination' => [
                'notice_days' => 30,
                'export_window_days' => 30,
                'terms' => [
                    $client.' keeps its business records, branding, original content and photographs, customer inquiries, and exported website records.',
                    'Everbranch workspace access, hosting, editing, support, forms, and provider connections stop on the effective termination date.',
                    'Operational data may be requested for export during the 30-day window; agreement, billing, audit, security, and legal records may be retained as required.',
                ],
            ],
        ];
    }
}
