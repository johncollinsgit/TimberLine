<?php

namespace App\Support\Marketing;

class MarketingSectionRegistry
{
    /**
     * @return array<string,array{label:string,route:string,description:string,hint_title:string,hint_text:string,coming_next:array<int,string>}>
     */
    public static function sections(): array
    {
        return [
            'overview' => [
                'label' => 'Marketing Overview',
                'route' => 'marketing.overview',
                'description' => 'Stage 1 foundation map for identity, messaging, rewards, reviews, and attribution.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Treat this as the current-state dashboard for the Marketing subsystem. Stage 1 is schema + navigation + access groundwork.',
                'coming_next' => [
                    'Operational readiness checks before campaign tools are enabled.',
                    'Visibility into profile linking quality and conflict resolution flow.',
                    'Rollout sequencing for campaign, rewards, and review features.',
                ],
            ],
            'customers' => [
                'label' => 'Customers',
                'route' => 'marketing.customers',
                'description' => 'Unified marketing customer index spanning identity, linked sources, and operational order context.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Profiles are derived from source order/shopify data and linked conservatively through exact normalized email/phone rules.',
                'coming_next' => [
                    'Campaign/message history population from outbound systems.',
                    'Customer-level enrichment from additional source adapters (Square, reviews).',
                    'Scoring and recommendation signals once identity coverage increases.',
                ],
            ],
            'identity-review' => [
                'label' => 'Identity Review',
                'route' => 'marketing.identity-review',
                'description' => 'Queue management for profile-link conflicts and explicit manual identity resolutions.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Conflicts are intentionally blocked from auto-merge and must be resolved to existing/new profiles or dismissed with notes.',
                'coming_next' => [
                    'Bulk review tools and reviewer assignment workflow.',
                    'Richer conflict diagnostics and confidence explainability.',
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'route' => 'marketing.orders',
                'description' => 'Marketing-oriented order visibility layer over existing operational order data.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Order marketing analytics will read from existing order data; Stage 1 does not replace order operations.',
                'coming_next' => [
                    'Channel and recency order cohorts for campaign targeting.',
                    'Attribution-ready order/event summary panels.',
                ],
            ],
            'groups' => [
                'label' => 'Groups',
                'route' => 'marketing.groups',
                'description' => 'Manual customer list management for curated outreach cohorts outside rule-based segments.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Groups are explicit, admin-curated lists. They can be imported, edited, and layered with segments during campaign recipient preparation.',
                'coming_next' => [
                    'List-level deliverability and engagement rollups.',
                    'Saved import mappings and recurring audience refresh controls.',
                ],
            ],
            'segments' => [
                'label' => 'Segments',
                'route' => 'marketing.segments',
                'description' => 'Rule-based audience segmentation workspace built on profile behavior, source channels, event signals, and consent.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Segments are explainable JSON-backed rules with live previews. Keep rules conservative and use previews before campaign recipient preparation.',
                'coming_next' => [
                    'Expanded operators and nested group editing UX.',
                    'Scheduled segment snapshots for campaign audit trails.',
                ],
            ],
            'campaigns' => [
                'label' => 'Campaigns',
                'route' => 'marketing.campaigns',
                'description' => 'Campaign orchestration hub for SMS/email targeting, approval workflow, Twilio/SendGrid execution, and delivery/conversion visibility.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Campaigns prepare recipients, route approvals, and execute approved sends through Twilio SMS or SendGrid email with delivery tracking and retry controls.',
                'coming_next' => [
                    'Automated scheduling and throttled send orchestration.',
                    'Richer conversion attribution diagnostics and revenue drill-downs.',
                ],
            ],
            'automations' => [
                'label' => 'Automations',
                'route' => 'marketing.automations',
                'description' => 'Lifecycle automation definitions driven by events, orders, and profile state.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Automation execution is still deferred. Use Campaigns + Recommendations approval queues for controlled, human-reviewed workflows.',
                'coming_next' => [
                    'Trigger/action automation builder and run logs.',
                    'Safety controls for pause/resume and eligibility checks.',
                ],
            ],
            'message-templates' => [
                'label' => 'Message Templates',
                'route' => 'marketing.message-templates',
                'description' => 'Template library for reusable campaign copy with variable rendering previews.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Templates are reusable copy blocks for variants. Validate variable rendering before queueing recipients for approval.',
                'coming_next' => [
                    'Template compliance checks and approval lifecycle.',
                    'Channel-specific formatting helpers and preview states.',
                ],
            ],
            'recommendations' => [
                'label' => 'Recommendations',
                'route' => 'marketing.recommendations',
                'description' => 'Rule-based recommendation center with recipient approvals and explainable next-best-action suggestions.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Recommendations are transparent rule outcomes fed by real delivery/conversion performance, not autonomous sends. Review and approve/reject explicitly.',
                'coming_next' => [
                    'Recommendation impact tracking tied to conversion outcomes.',
                    'Model-assisted scoring inputs layered on top of rule rails.',
                ],
            ],
            'candle-cash' => [
                'label' => 'Candle Cash',
                'route' => 'marketing.candle-cash',
                'description' => 'Candle Cash rewards ledger, issued-code lifecycle, and Shopify/Square redemption reconciliation visibility.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Codes are issued first, then reconciled during Shopify/Square order ingestion or staff-assisted workflows. Use Reconciliation Operations for unresolved storefront/public issues and audit-safe manual fixes.',
                'coming_next' => [
                    'Automated storefront redemption validation feedback loops.',
                    'Reward-assisted conversion drill-downs tied to campaign performance.',
                ],
            ],
            'reviews' => [
                'label' => 'Reviews',
                'route' => 'marketing.reviews',
                'description' => 'Review program workspace for provider integrations and engagement loops.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Review ingestion/sending is not implemented yet. This screen marks where review systems will be coordinated.',
                'coming_next' => [
                    'Provider sync visibility and review prompt pipelines.',
                    'Review sentiment + conversion impact rollups.',
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'marketing.settings',
                'description' => 'Marketing engine controls for attribution windows, quiet hours, and feature toggles.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Initial key/value settings are seeded now. Future stages will expose editable controls and audit trails.',
                'coming_next' => [
                    'Editable marketing setting controls with validation.',
                    'Change history and deployment-safe defaults.',
                ],
            ],
            'providers-integrations' => [
                'label' => 'Providers / Integrations',
                'route' => 'marketing.providers-integrations',
                'description' => 'Square sync controls, legacy import tooling, and event attribution source mapping.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Run additive sync/import operations here, then clean unmapped values so attribution and identity stay reliable. Shopify widget endpoints must use verified signatures; public Laravel routes stay minimal event utilities.',
                'coming_next' => [
                    'Provider health checks and scheduled sync jobs.',
                    'Expanded source adapters for review and messaging providers.',
                ],
            ],
            'suppression-consent' => [
                'label' => 'Suppression / Consent',
                'route' => 'marketing.suppression-consent',
                'description' => 'Consent guardrail area for opt-outs, quiet hours, and suppression governance.',
                'hint_title' => 'How to use this page',
                'hint_text' => 'Consent is enforced again at SMS send time. Manual profile-level consent updates and consent event history are available from customer detail.',
                'coming_next' => [
                    'Storefront consent-capture flows with auditable confirmation events.',
                    'Suppression list management and compliance-specific audit exports.',
                ],
            ],
        ];
    }

    /**
     * @return array<string>
     */
    public static function keys(): array
    {
        return array_keys(self::sections());
    }

    /**
     * @return array{label:string,route:string,description:string,hint_title:string,hint_text:string,coming_next:array<int,string>}|null
     */
    public static function section(string $key): ?array
    {
        return self::sections()[$key] ?? null;
    }
}
