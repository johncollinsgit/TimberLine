<?php

$productName = (string) env('EVERBRANCH_PRODUCT_NAME', 'Everbranch');
$contactDomain = strtolower(trim((string) env('PRODUCT_CONTACT_EMAIL_DOMAIN', 'theeverbranch.com')));
$contactDomain = $contactDomain !== '' ? $contactDomain : 'theeverbranch.com';
$salesContact = 'sales@'.$contactDomain;
$upgradesContact = 'upgrades@'.$contactDomain;
$helloContact = 'hello@'.$contactDomain;

return [
    'promo' => [
        'eyebrow' => 'Built for busy small businesses',
        'headline' => 'One app for the work that keeps slipping through the cracks.',
        'summary' => $productName.' brings customers, tasks, notes, follow-ups, messages, and next steps into a simple workspace your team can use every day.',
        'how_it_works' => [
            [
                'title' => 'Bring the messy workflow',
                'description' => 'Start with the customers, jobs, notes, follow-ups, messages, and daily work your team is already trying to manage.',
            ],
            [
                'title' => 'Set up your workspace',
                'description' => 'Give the work a home so your team knows where to look and what needs attention next.',
            ],
            [
                'title' => 'Run the day from one place',
                'description' => 'Track next steps, follow up with customers, and move work forward with fewer dropped balls.',
            ],
        ],
        'preview_profiles' => [
            [
                'key' => 'landscaper',
                'label' => 'Landscaper',
                'summary' => 'Keep customers, jobs, crew notes, materials, photos, and follow-ups in one workspace.',
                'signals' => [
                    'Jobs and crew notes',
                    'Materials and job costs',
                    'Photos and follow-ups',
                ],
            ],
            [
                'key' => 'electrician',
                'label' => 'Electrician',
                'summary' => 'Track service calls, customers, parts, notes, tasks, estimates, and follow-ups.',
                'signals' => [
                    'Service calls and job notes',
                    'Parts, receipts, and estimates',
                    'Reminders after the visit',
                ],
            ],
            [
                'key' => 'soap_maker',
                'label' => 'Soap Maker',
                'summary' => 'Connect orders, batches, materials, inventory, wholesale approvals, and repeat customers.',
                'signals' => [
                    'Batches and materials',
                    'Orders, products, and inventory',
                    'Repeat customer ideas',
                ],
            ],
        ],
        'preview_flow' => [
            [
                'title' => 'See Everbranch in action',
                'description' => 'Look at a guided example of customers, work, notes, and next steps living together.',
            ],
            [
                'title' => 'Share what you need',
                'description' => 'Tell us how your team works today, where information lives, and what keeps getting missed.',
            ],
            [
                'title' => 'Start with one workspace',
                'description' => 'Your team gets a simple place to see what is ready, what needs setup, and what to do next.',
            ],
        ],
        'plan_order' => [
            'starter',
            'growth',
            'pro',
        ],
        'ctas' => [
            'install' => [
                'label' => 'Shopify setup',
                'href' => '/shopify/reinstall/retail',
            ],
            'demo' => [
                'label' => 'See Everbranch in action',
                'href' => '/platform/demo',
            ],
            'start_client' => [
                'label' => 'Start as a client',
                'href' => '/platform/start',
            ],
            'plans' => [
                'label' => 'Compare plans',
                'href' => '/platform/plans',
            ],
            'contact' => [
                'label' => 'Talk to sales',
                'href' => '/platform/contact?intent=sales',
            ],
        ],
        'disclaimer' => 'Modules marked coming soon remain roadmap-visible only and are not represented as fully live capabilities.',
    ],

    'access_request' => [
        'review_email' => env('WHOLESALE_APPLICATION_REVIEW_EMAIL', 'modernforestryteam@gmail.com'),
        'review_email_by_tenant_slug' => [
            'modern-forestry' => env(
                'WHOLESALE_APPLICATION_REVIEW_EMAIL_MODERN_FORESTRY_WHOLESALE',
                env('WHOLESALE_APPLICATION_REVIEW_EMAIL', 'modernforestryteam@gmail.com')
            ),
            // Legacy alias for applications created before the wholesale
            // storefront was correctly modeled as a store on tenant 1.
            'modern-forestry-wholesale' => env(
                'WHOLESALE_APPLICATION_REVIEW_EMAIL_MODERN_FORESTRY_WHOLESALE',
                env('WHOLESALE_APPLICATION_REVIEW_EMAIL', 'modernforestryteam@gmail.com')
            ),
        ],
        'wholesale_storefront_tenant_slug' => env(
            'WHOLESALE_APPLICATION_STOREFRONT_TENANT_SLUG',
            'modern-forestry'
        ),
        'business_types' => [
            'landscaper' => 'Landscaper',
            'electrician' => 'Electrician',
            'soap_maker' => 'Soap maker',
            'retail' => 'Retail',
            'wholesale' => 'Wholesale',
            'agency' => 'Agency / services',
            'other' => 'Other',
        ],
        'team_sizes' => [
            '1_5' => '1–5 people',
            '6_20' => '6–20 people',
            '21_50' => '21–50 people',
            '51_plus' => '51+ people',
        ],
        'timelines' => [
            'asap' => 'ASAP',
            '30_days' => 'Within 30 days',
            '60_90_days' => '60–90 days',
            'researching' => 'Just researching',
        ],
        'import_paths' => [
            'shopify' => 'Shopify',
            'square' => 'Square',
            'csv' => 'CSV import',
            'manual' => 'Manual entry',
            'other' => 'Other',
            'undecided' => 'Undecided',
        ],
        'mobile_interests' => [
            'none' => 'No mobile app needed yet',
            'android' => 'Android',
            'ios' => 'iOS',
            'both' => 'Android and iOS',
            'undecided' => 'Undecided',
        ],
    ],

    'onboarding' => [
        'welcome_title' => 'Start Here',
        'welcome_body' => 'This workspace combines plan access, feature setup, and upgrade cues so teams can activate live modules first and track roadmap modules separately.',
        'orientation_points' => [
            'Shopify flagship functionality remains live and unchanged while onboarding surfaces expand.',
            'Module state comes from workspace access plus setup status, not ad hoc page-level conditionals.',
            'Locked and coming-soon modules stay visible for planning, but do not pretend to be complete.',
        ],
        'module_order' => [
            'customers',
            'rewards',
            'birthdays',
            'reviews',
            'wishlist',
            'campaigns',
            'reporting',
            'integrations',
            'uploads',
            'settings',
            'onboarding',
            'quickbooks',
            'wix',
            'mobile_connection',
            'ai',
        ],
        'recommended_actions' => [
            [
                'title' => 'Verify customer + {{rewards_label}} operations',
                'description' => 'Confirm customers, {{rewards_label}}, and {{birthdays_label}} are configured for your current tenant context.',
                'href' => '/shopify/app/customers/manage',
                'module_key' => 'customers',
            ],
            [
                'title' => 'Tune {{rewards_label}} settings',
                'description' => 'Review earn/redeem rules and birthday behavior in the embedded {{rewards_label}} surfaces.',
                'href' => '/shopify/app/rewards',
                'module_key' => 'rewards',
            ],
            [
                'title' => 'Confirm provider and tenant settings',
                'description' => 'Validate email readiness and tenant-level settings before expansion into new modules.',
                'href' => '/shopify/app/settings',
                'module_key' => 'settings',
            ],
            [
                'title' => 'Review plans and add-ons',
                'description' => 'See locked modules, add-on candidates, and upgrade placeholders without billing coupling.',
                'href' => '/shopify/app/plans',
                'module_key' => 'onboarding',
            ],
        ],
        'future_prompts' => [
            'Integrations placeholders should direct users to safe manual/import fallback while sync connectors are incomplete.',
            'AI and mobile connection remain future capability tracks and should stay clearly labeled as roadmap modules.',
        ],
    ],

    'plans' => [
        'headline' => 'Plans & Add-ons',
        'subtitle' => 'Informational access profile view built from workspace access and module state. Billing writes are intentionally deferred.',
        'billing_note' => 'Billing and payment-method capture are currently handled with the team. Plan and add-on availability in the app remains review-controlled.',
        'recommended_plan_key' => 'launch_partner',
        'plan_order' => [
            'starter',
            'launch_partner',
            'growth',
            'pro',
        ],
        'cards' => [
            'starter' => [
                'name' => 'Starter',
                'price_display' => 'From $149/mo',
                'summary' => 'Core platform foundation with reviews, lead capture, and baseline diagnostics for one store/channel.',
                'highlights' => [
                    'Core platform + reviews + intake/lead capture',
                    'Basic moderation and diagnostics',
                    '1 store/channel included',
                ],
                'cta' => [
                    'label' => 'Install / Reinstall',
                    'href' => '/shopify/reinstall/retail',
                ],
            ],
            'launch_partner' => [
                'name' => 'Launch Partner',
                'price_display' => '$59/mo for 6 months',
                'summary' => 'Limited first-10-businesses partner offer with discounted onboarding and direct feedback access while Everbranch is shaped in the field.',
                'highlights' => [
                    '$299 onboarding',
                    '$149/mo after the first 6 months',
                    'Priority partner support and feedback calls',
                ],
                'cta' => [
                    'label' => 'Become a launch partner',
                    'href' => '/platform/start#tab-start-pricing',
                ],
            ],
            'growth' => [
                'name' => 'Growth',
                'price_display' => 'From $249/mo',
                'summary' => 'Adds loyalty/rewards, birthdays, and eligibility for marketing/bulk email operations.',
                'highlights' => [
                    'Loyalty/rewards + birthday lifecycle',
                    'Campaign and email-readiness expansion',
                    'Add-on ready packaging model',
                ],
                'cta' => [
                    'label' => 'Request Upgrade',
                    'href' => '/platform/contact?intent=upgrade',
                ],
            ],
            'pro' => [
                'name' => 'Pro',
                'price_display' => 'From $399/mo',
                'summary' => 'Highest standard tier with wishlist and advanced diagnostics/reporting/export troubleshooting.',
                'highlights' => [
                    'Wishlist module included',
                    'Advanced diagnostics and exports',
                    'No bundled support tier or custom-work bundle',
                ],
                'cta' => [
                    'label' => 'Book Pro Review',
                    'href' => '/platform/contact?intent=pro',
                ],
            ],
        ],
        'addons' => [
            'referrals' => [
                'name' => 'Referrals',
                'price_display' => '+$79/mo',
                'summary' => 'Referral workflow packaging as launch-relevant pieces graduate from placeholder state.',
            ],
            'sms' => [
                'name' => 'SMS',
                'price_display' => '+$99/mo + $99 setup',
                'summary' => 'A private business number, shared messaging workspace, and 1,000 monthly SMS segments. Additional segments are $0.025 each.',
            ],
            'messaging' => [
                'name' => 'Messaging',
                'price_display' => '+$19.99/mo',
                'summary' => 'A shared customer inbox with 5,000 monthly emails. Additional email is $1.50 per 1,000.',
            ],
            'additional_channels' => [
                'name' => 'Additional Stores/Channels',
                'price_display' => '+$59/mo per channel',
                'summary' => 'Starter includes one store/channel. Additional channels are separately priced add-ons.',
            ],
            'bulk_email_marketing' => [
                'name' => 'Bulk Marketing Email',
                'price_display' => '+$129/mo',
                'summary' => 'The messaging workspace plus 50,000 monthly marketing emails. Additional email is $1.50 per 1,000.',
            ],
            'future_niche_modules' => [
                'name' => 'Future Niche Modules',
                'price_display' => 'Custom',
                'summary' => 'Reserved placeholder for future vertical packages without forking the core architecture.',
            ],
        ],
        'upgrade_ctas' => [
            'primary' => [
                'label' => 'Request Upgrade',
                'href' => '/platform/contact?intent=upgrade',
            ],
            'secondary' => [
                'label' => 'Book Demo',
                'href' => '/platform/contact?intent=demo',
            ],
        ],
    ],

    'demo' => [
        'eyebrow' => 'Live Demo',
        'headline' => 'See '.$productName.' in action.',
        'summary' => 'Request access to a safe demo workspace. We keep demo and production flows separate and honest.',
        'intent_note' => 'Demo access is for evaluation and does not convert demo tenants directly into production tenants.',
        'submit_label' => 'Request demo access',
        'footnote' => 'Demo access is granted manually. You will receive an email with a password-setup link once approved.',
    ],

    'start_client' => [
        'headline' => "Simplify your life,\nGet more time with your family.",
        'submit_label' => 'Request production access',
        'plan_comparison' => [
            'eyebrow' => 'One platform. Everything you need.',
            'title' => 'Launch partner pricing',
            'subtitle' => 'Starter includes everything. Growth gives you more capacity.',
            'savings_note' => 'Launch partners save over $680 in the first 6 months compared with regular Starter onboarding and monthly pricing.',
            'recommended' => 'launch_partner',
            'plans' => [
                'starter' => [
                    'label' => 'Starter',
                    'descriptor' => 'Regular',
                    'price' => '$149',
                    'cadence' => '/mo',
                    'badge' => null,
                ],
                'launch_partner' => [
                    'label' => 'Launch Partner',
                    'descriptor' => 'First 10 businesses',
                    'price' => '$59',
                    'cadence' => '/mo',
                    'badge' => 'Limited to 10',
                ],
                'growth' => [
                    'label' => 'Growth',
                    'descriptor' => 'More capacity',
                    'price' => '$249',
                    'cadence' => '/mo',
                    'badge' => null,
                ],
            ],
            'features' => [
                ['label' => 'Onboarding', 'starter' => '$499 one-time', 'launch_partner' => '$299 one-time', 'growth' => '$999 one-time'],
                ['label' => 'First 6 months', 'starter' => '$149/mo', 'launch_partner' => '$59/mo', 'growth' => '$249/mo'],
                ['label' => 'After 6 months', 'starter' => '$149/mo', 'launch_partner' => '$149/mo', 'growth' => '$249/mo'],
                ['label' => '6-month total', 'starter' => '$1,393', 'launch_partner' => '$653', 'growth' => '$2,493'],
                ['label' => 'Team members', 'starter' => 'Up to 3 users', 'launch_partner' => 'Up to 3 users', 'growth' => 'Unlimited users'],
                ['label' => 'Email contacts', 'starter' => 'Up to 2,000 contacts', 'launch_partner' => 'Up to 2,000 contacts', 'growth' => 'Up to 15,000 contacts'],
                ['label' => 'Automation workflows', 'starter' => 'Basic automations', 'launch_partner' => 'Basic automations', 'growth' => 'Advanced automations'],
                ['label' => 'Custom branding', 'starter' => 'Basic branding', 'launch_partner' => 'Basic branding', 'growth' => 'Full custom branding'],
                ['label' => 'Customer support', 'starter' => 'Email support', 'launch_partner' => 'Priority partner support', 'growth' => 'Priority support'],
                ['label' => 'Text messaging', 'starter' => 'Available as an add-on', 'launch_partner' => 'Available as an add-on', 'growth' => 'Available as an add-on'],
            ],
            'partner_terms' => [
                'Join a 30-minute feedback call each month',
                'Report bugs and share honest feedback',
                'Allow us to feature your business with permission',
                'Commit to at least 6 months',
            ],
        ],
    ],

    'integrations' => [
        'headline' => 'Integrations',
        'subtitle' => 'Placeholder-first connection page. States follow workspace access and remain read-only in this phase.',
        'description' => 'No live connector sync runs from this page yet. Use fallback paths to stay operational while integrations mature.',
        'categories' => [
            'commerce' => 'Commerce',
            'marketing' => 'Marketing',
            'accounting' => 'Accounting',
            'import' => 'Imports & Manual Intake',
            'mobile' => 'Mobile & Future Channels',
        ],
        'upgrade_cta' => [
            'label' => 'Upgrade to unlock',
            'href' => '/shopify/app/plans',
        ],
        'contact_cta' => [
            'label' => 'Talk to sales',
            'href' => '/platform/contact?intent=integrations',
        ],
        'cards' => [
            'shopify_orders' => [
                'key' => 'shopify_orders',
                'module_key' => 'shopify',
                'title' => 'Shopify Orders',
                'description' => 'Use Shopify order/customer context as the primary commerce feed for lifecycle and rewards operations.',
                'category' => 'commerce',
                'availability' => 'available',
                'fallback_mode' => 'manual_import',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'starter',
                'ctas' => [
                    'connect_label' => 'Connect (Placeholder)',
                    'manual_label' => 'Continue with manual intake',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'direct',
                    'source_label' => 'Shopify embedded app context',
                    'is_mocked' => true,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Confirm the Shopify app is opened from Shopify Admin for the correct store.',
                        'Review customer and order visibility in embedded Customers and Rewards surfaces.',
                        'Validate fallback import/manual paths before relying on live sync.',
                    ],
                    'required_fields' => [
                        'Shop domain context',
                        'Tenant access profile',
                        'Operator verification in embedded admin',
                    ],
                    'fallback_options' => [
                        'Use manual intake from Start Here tasks.',
                        'Continue operations with existing storefront proxy flows.',
                        'Run CSV/manual workflows while connector sync remains placeholder-only.',
                    ],
                    'notes' => [
                        'This surface does not trigger any connector API writes in this phase.',
                    ],
                    'upgrade_message' => 'Shopify connector guidance is available under Starter/Growth/Pro access profiles.',
                ],
            ],
            'square' => [
                'key' => 'square',
                'module_key' => 'square',
                'title' => 'Square',
                'description' => 'Prepare Square transaction and customer ingestion as a commerce expansion path.',
                'category' => 'commerce',
                'availability' => 'available',
                'fallback_mode' => 'csv_upload',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'growth',
                'ctas' => [
                    'connect_label' => 'Connect (Placeholder)',
                    'manual_label' => 'Upload CSV fallback',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'direct',
                    'source_label' => 'Placeholder direct connector',
                    'is_mocked' => true,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Review commerce data expectations for Square order/customer imports.',
                        'Map fallback CSV columns to customer profile fields before launch.',
                        'Keep manual reconciliation steps documented in operations.',
                    ],
                    'required_fields' => [
                        'Store/location identifier',
                        'Order reference format',
                        'Customer identifier mapping notes',
                    ],
                    'fallback_options' => [
                        'Upload Square exports as CSV.',
                        'Use manual entry for priority records.',
                        'Continue without Square connector until sync implementation is ready.',
                    ],
                    'notes' => [
                        'Square setup is guidance-only and does not open OAuth flows yet.',
                    ],
                    'upgrade_message' => 'Square integration guidance may require a higher plan tier depending on tenant profile.',
                ],
            ],
            'klaviyo' => [
                'key' => 'klaviyo',
                'module_key' => 'email',
                'title' => 'Klaviyo',
                'description' => 'Future marketing connector for campaign orchestration while preserving customer profile ownership.',
                'category' => 'marketing',
                'availability' => 'available',
                'fallback_mode' => 'manual_import',
                'fallback_href' => '/shopify/app/settings',
                'plan_requirement' => 'bulk_email_marketing',
                'ctas' => [
                    'connect_label' => 'Connect (Placeholder)',
                    'manual_label' => 'Configure manually',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'direct',
                    'is_mocked' => true,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Confirm email provider readiness in embedded Settings.',
                        'Define lifecycle trigger expectations for campaign send events.',
                        'Plan fallback operator workflow if provider routing is not ready.',
                    ],
                    'required_fields' => [
                        'Provider selection',
                        'From address strategy',
                        'Tenant send-readiness confirmation',
                    ],
                    'fallback_options' => [
                        'Use existing manual campaign operations.',
                        'Use provider-agnostic email readiness diagnostics.',
                        'Continue without Klaviyo-specific sync until available.',
                    ],
                    'notes' => [
                        'No external provider API calls are performed from this integrations page.',
                    ],
                    'upgrade_message' => 'Klaviyo guidance is available when the related module is entitled for the tenant.',
                ],
            ],
            'workflow_automations' => [
                'key' => 'workflow_automations',
                'module_key' => 'workflow_automations',
                'title' => 'Workflow Automations',
                'description' => 'Create Zap-style trigger/action workflows inside Everbranch to replace external task-based automations.',
                'category' => 'marketing',
                'availability' => 'available',
                'fallback_mode' => 'manual_import',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'starter',
                'ctas' => [
                    'connect_label' => 'Open automation setup',
                    'manual_label' => 'Use setup checklist',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'direct',
                    'source_label' => 'First-party workflow engine',
                    'is_mocked' => false,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Configure Asana and Google Calendar credentials in environment settings.',
                        'Enable the workflow and run a dry-run command to verify mapping.',
                        'Schedule the automation command and monitor run status.',
                    ],
                    'required_fields' => [
                        'Asana project GID',
                        'Google Calendar target calendar ID',
                        'OAuth refresh token for Calendar write access',
                    ],
                    'fallback_options' => [
                        'Keep using manual calendar updates until credentials are ready.',
                        'Run one-off dry-run previews to validate task-to-event mapping.',
                        'Disable workflow execution without impacting other modules.',
                    ],
                    'notes' => [
                        'This workflow is run by scheduled Artisan jobs, not by third-party task billing.',
                    ],
                    'upgrade_message' => 'Automation access follows workspace module access and environment readiness.',
                ],
            ],
            'sms_gateway' => [
                'key' => 'sms_gateway',
                'module_key' => 'sms',
                'title' => 'SMS Provider',
                'description' => 'Route SMS lifecycle sends through tenant-configured providers with shared event reporting.',
                'category' => 'marketing',
                'availability' => 'available',
                'fallback_mode' => 'none',
                'fallback_href' => null,
                'plan_requirement' => 'sms',
                'ctas' => [
                    'connect_label' => 'Configure in Settings',
                    'manual_label' => 'Continue without SMS',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'direct',
                    'is_mocked' => false,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Open Settings to confirm tenant SMS provider readiness.',
                        'Verify sender profile defaults and status labels.',
                        'Define operational fallback for no-send scenarios.',
                    ],
                    'required_fields' => [
                        'Provider enabled state',
                        'Sender configuration',
                        'Operational fallback owner',
                    ],
                    'fallback_options' => [
                        'Continue with email-only lifecycle communication.',
                        'Use manual customer outreach while SMS is unavailable.',
                        'Proceed without SMS connector dependency.',
                    ],
                    'notes' => [
                        'This setup drawer is informational and does not send messages.',
                    ],
                    'upgrade_message' => 'SMS access follows workspace access and provider readiness configuration.',
                ],
            ],
            'quickbooks' => [
                'key' => 'quickbooks',
                'module_key' => 'quickbooks',
                'title' => 'QuickBooks',
                'description' => 'Guided, read-only QuickBooks connection for operational imports and owner reporting.',
                'category' => 'accounting',
                'availability' => 'available',
                'fallback_mode' => 'csv_upload',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'workspace_owner',
                'ctas' => [
                    'connect_label' => 'Connect QuickBooks',
                    'manual_label' => 'Upload accounting CSV',
                    'upgrade_label' => 'Ask an owner to enable',
                    'coming_soon_label' => 'Guided setup required',
                ],
                'status' => [
                    'setup_mode' => 'guided',
                    'source_label' => 'QuickBooks Online Accounting API',
                    'is_mocked' => false,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Enable the QuickBooks Branch for the workspace.',
                        'Authorize the correct QuickBooks company as a workspace owner.',
                        'Review a dry-run audit before importing tenant data.',
                    ],
                    'required_fields' => [
                        'QuickBooks Online administrator',
                        'Everbranch workspace owner',
                        'Approved import scope',
                    ],
                    'fallback_options' => [
                        'Upload accounting CSV exports.',
                        'Track reconciliation manually in operations tools.',
                        'Continue without QuickBooks connector.',
                    ],
                    'notes' => [
                        'The beta connection is read-only, tenant-scoped, and on demand.',
                        'Team members do not receive QuickBooks financial access.',
                    ],
                    'upgrade_message' => 'QuickBooks requires workspace-owner enablement and guided setup during beta.',
                ],
            ],
            'csv_import' => [
                'key' => 'csv_import',
                'module_key' => 'uploads',
                'title' => 'CSV Import',
                'description' => 'Import-first fallback for businesses that are not ready for direct connector sync yet.',
                'category' => 'import',
                'availability' => 'available',
                'fallback_mode' => 'csv_upload',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'starter',
                'ctas' => [
                    'connect_label' => 'Open import flow (Placeholder)',
                    'manual_label' => 'Upload CSV',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'csv',
                    'source_label' => 'CSV upload fallback',
                    'is_mocked' => false,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Collect source export files from current business systems.',
                        'Validate required columns before upload.',
                        'Run incremental imports and verify results in Everbranch modules.',
                    ],
                    'required_fields' => [
                        'Customer identifier column',
                        'Transaction/event date columns',
                        'Source-system reference IDs',
                    ],
                    'fallback_options' => [
                        'Use manual entry for urgent records.',
                        'Stage partial CSV imports by priority.',
                        'Continue operations without connector sync.',
                    ],
                    'notes' => [
                        'CSV import remains the primary fallback path for non-connected tenants.',
                    ],
                    'upgrade_message' => 'CSV guidance remains available as a fallback even when connector sync is locked.',
                ],
            ],
            'manual_entry' => [
                'key' => 'manual_entry',
                'module_key' => 'uploads',
                'title' => 'Manual Entry',
                'description' => 'Continue without live integrations by entering records through internal operational workflows.',
                'category' => 'import',
                'availability' => 'available',
                'fallback_mode' => 'manual_import',
                'fallback_href' => '/shopify/app/start',
                'plan_requirement' => 'starter',
                'ctas' => [
                    'connect_label' => 'Open workflow (Placeholder)',
                    'manual_label' => 'Use manual intake',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Coming soon',
                ],
                'status' => [
                    'setup_mode' => 'manual',
                    'source_label' => 'Built-in manual workflow',
                    'built_in_connected' => true,
                    'is_mocked' => false,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Define which records require manual intake first.',
                        'Assign an operator owner for daily data entry checks.',
                        'Verify downstream module visibility after entry.',
                    ],
                    'required_fields' => [
                        'Operator assignment',
                        'Data validation checklist',
                        'Record audit cadence',
                    ],
                    'fallback_options' => [
                        'Manual customer/profile updates.',
                        'Manual rewards/campaign reconciliation.',
                        'Continue without external connector dependencies.',
                    ],
                    'notes' => [
                        'Manual entry guidance ensures the platform remains usable without integrations.',
                    ],
                    'upgrade_message' => 'Manual-entry readiness can operate independently of connector availability.',
                ],
            ],
            'mobile_connection' => [
                'key' => 'mobile_connection',
                'module_key' => 'mobile_connection',
                'title' => 'Mobile App Connection',
                'description' => 'Future mobile channel for field and owner workflows connected to the same workspace data.',
                'category' => 'mobile',
                'availability' => 'coming_soon',
                'fallback_mode' => 'none',
                'fallback_href' => null,
                'plan_requirement' => 'additional_channels',
                'ctas' => [
                    'connect_label' => 'Connect (Placeholder)',
                    'manual_label' => 'Continue without mobile',
                    'upgrade_label' => 'Upgrade to unlock',
                    'coming_soon_label' => 'Join waitlist',
                ],
                'status' => [
                    'setup_mode' => 'placeholder',
                    'source_label' => 'Roadmap placeholder',
                    'is_mocked' => true,
                ],
                'setup' => [
                    'setup_steps' => [
                        'Define target mobile workflows and role-specific needs.',
                        'Confirm which modules require mobile visibility first.',
                        'Use web/admin + manual fallback until mobile connector is ready.',
                    ],
                    'required_fields' => [
                        'Mobile user role definitions',
                        'Required module scope',
                        'Fallback operating plan',
                    ],
                    'fallback_options' => [
                        'Continue with embedded admin web surfaces.',
                        'Use manual updates from field notes.',
                        'Defer mobile rollout without blocking core operations.',
                    ],
                    'notes' => [
                        'Mobile connection is roadmap-visible and not currently operational.',
                    ],
                    'upgrade_message' => 'Mobile connection is a future module and may require an integrations add-on.',
                ],
            ],
        ],
    ],

    'contact' => [
        'headline' => 'Talk with the '.$productName.' team',
        'summary' => 'Book a demo, ask about plans, or get rollout guidance for production, shipping, and customer workflows.',
        'channels' => [
            [
                'label' => 'Book a demo',
                'value' => $salesContact,
                'href' => 'mailto:'.$salesContact.'?subject=Platform%20Demo%20Request',
            ],
            [
                'label' => 'Discuss plan upgrades',
                'value' => $upgradesContact,
                'href' => 'mailto:'.$upgradesContact.'?subject=Plan%20Upgrade%20Request',
            ],
            [
                'label' => 'General questions',
                'value' => $helloContact,
                'href' => 'mailto:'.$helloContact.'?subject=Platform%20Inquiry',
            ],
        ],
    ],
];
