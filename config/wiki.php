<?php

return [
    'categories' => [
        [
            'slug' => 'wholesale-processes',
            'title' => 'Wholesale Processes',
            'description' => 'Operational playbooks for wholesale onboarding, account setup, communication, and account lifecycle management.',
            'subcategories' => ['wholesale-special-cases'],
        ],
        [
            'slug' => 'wholesale-special-cases',
            'title' => 'Wholesale Special Cases',
            'description' => 'Account-specific exceptions and non-standard workflows.',
        ],
        [
            'slug' => 'wholesale',
            'title' => 'Wholesale',
            'description' => 'Wholesale account references and customer-specific operating documentation.',
        ],
        [
            'slug' => 'production',
            'title' => 'Production',
            'description' => 'Manufacturing, blend reference, and floor operations documentation.',
        ],
        [
            'slug' => 'shipping',
            'title' => 'Shipping',
            'description' => 'Shipping and fulfillment guides.',
        ],
        [
            'slug' => 'retail-ops',
            'title' => 'Retail Ops',
            'description' => 'Retail operations standards and support docs.',
        ],
        [
            'slug' => 'events',
            'title' => 'Events',
            'description' => 'Programs and event-specific records.',
        ],
        [
            'slug' => 'admin',
            'title' => 'Admin',
            'description' => 'Administrative workflows and controls.',
        ],
        [
            'slug' => 'hr',
            'title' => 'HR',
            'description' => 'People operations references and policies.',
        ],
        [
            'slug' => 'tools',
            'title' => 'Tools',
            'description' => 'Tooling references across internal systems.',
        ],
        [
            'slug' => 'policies',
            'title' => 'Policies',
            'description' => 'Policy definitions and compliance notes.',
        ],
    ],

    'articles' => [
        [
            'slug' => 'wholesale-processes',
            'title' => 'Wholesale Process Index',
            'excerpt' => 'One-page index of all wholesale workflows with daily, weekly, and quarterly rhythm guidance.',
            'category' => 'wholesale-processes',
            'path' => '/wiki/wholesale-processes',
            'featured' => true,
            'updated_at' => '2026-02-20',
            'published' => true,
        ],
        [
            'slug' => 'wholesale-overview',
            'title' => 'Wholesale Overview',
            'excerpt' => 'What wholesale is at Modern Forestry, systems used, and key order-type definitions.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'what-is-wholesale',
                    'title' => 'What Wholesale Is',
                    'paragraphs' => [
                        'Wholesale supports recurring business buyers that stock or distribute Modern Forestry products through their own channels.',
                        'Use [[wholesale-processes|Wholesale Process Index]] to navigate all process pages.',
                    ],
                ],
                [
                    'id' => 'systems-used',
                    'title' => 'Systems Used',
                    'paragraphs' => [
                        'Core systems: Shopify, Asana, Gmail labels, Google Sheets, and Mailchimp.',
                    ],
                    'quicklinks' => [
                        'Shopify: open Shopify Admin -> Customers -> search business name.',
                        'Asana: open Wholesale project workspace (see internal link).',
                        'Google Sheets: open current-year New Stores/Reorders sheet (see internal link).',
                        'Mailchimp: open Audience -> search contact by business email.',
                    ],
                ],
                [
                    'id' => 'definitions',
                    'title' => 'Definitions',
                    'paragraphs' => [
                        'Wholesale: approved resale accounts ordering at wholesale terms.',
                        'Business Gift: bulk gifting orders for businesses; discount tier applies but business gift customers pay tax.',
                        'Consignment: inventory-based arrangement with reconciled payout terms.',
                        'Fundraising: campaign-based sales for partner organizations.',
                    ],
                ],
                [
                    'id' => 'discount-summary',
                    'title' => 'Discount Summary',
                    'checklist' => [
                        'Business gift 50% discount for orders $300+.',
                        'Business gift 40% discount for orders $150-$299.',
                        'Under $150, use retail bulk pricing.',
                        'Business gift orders pay tax.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Wholesale approval email template', 'slug' => 'wholesale-onboarding-new-wholesalers'],
                        ['label' => 'Quarterly follow-up template guidance', 'slug' => 'wholesale-daily-responsibilities'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-daily-responsibilities',
                'wholesale-onboarding-new-wholesalers',
                'wholesale-asana-color-tiers',
            ],
        ],
        [
            'slug' => 'wholesale-daily-responsibilities',
            'title' => 'Daily Responsibilities',
            'excerpt' => 'Daily execution checklist for wholesale communication, order review, records, and follow-ups.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'daily-inbox-monitoring',
                    'title' => 'Daily Inbox and Channel Monitoring',
                    'checklist' => [
                        'Check emails, social messages, and voicemails.',
                        'Document communications in Asana and complete related tasks.',
                    ],
                ],
                [
                    'id' => 'daily-shopify-review',
                    'title' => 'Daily Shopify Order Review',
                    'checklist' => [
                        'Check Shopify for new wholesale/business gift orders using saved filters.',
                        'Review order items and notes for minimum order, quantity increments of 3, and required fees.',
                        'If updates are needed, contact customer and adjust while preserving minimum order guidelines.',
                    ],
                    'quicklinks' => [
                        'Shopify: open Admin -> Orders -> Wholesale/Business Gift filters.',
                        'Shopify: open each order -> verify tags, notes, and line item multiples of 3.',
                    ],
                ],
                [
                    'id' => 'daily-recordkeeping',
                    'title' => 'Daily Recordkeeping',
                    'checklist' => [
                        'Record order in current-year "New Stores/Reorders" Google Sheet.',
                        'Update Asana profile: preserve old Follow Up note by commenting, then add a new dated note with order details.',
                        'If local pickup, create follow-up scheduling task.',
                    ],
                    'quicklinks' => [
                        'Google Sheets: update New Stores/Reorders (current year tab).',
                        'Asana: update project description and add dated follow-up note.',
                    ],
                ],
                [
                    'id' => 'quarterly-followups',
                    'title' => 'Quarterly Follow-Ups',
                    'checklist' => [
                        'Pre-check social media presence, documents on file, and last order in Shopify.',
                        'Update relevant dates in Asana before outreach.',
                        'Choose outreach method and personalize from template stubs.',
                        'Include updates and ordering link in outbound message.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Quarterly follow-up draft stub', 'slug' => 'wholesale-daily-responsibilities'],
                        ['label' => 'Order correction request stub', 'slug' => 'wholesale-overview'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-overview',
                'wholesale-asana-profile-setup',
                'wholesale-shopify-account-setup',
            ],
        ],
        [
            'slug' => 'wholesale-onboarding-new-wholesalers',
            'title' => 'Onboarding New Wholesalers',
            'excerpt' => 'Step-by-step onboarding from first contact through first order readiness.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'onboarding-steps',
                    'title' => 'Onboarding Steps',
                    'checklist' => [
                        'Capture initial contact from application, email, social, or phone.',
                        'Invite applicant to fill out wholesale application.',
                        'Review criteria on [[wholesale-application-review|Approving or Declining Wholesale Applications]].',
                        'Send approval/decline email; attach guidelines for approved accounts.',
                        'Set up account in Shopify and send invite after documentation checks.',
                        'Set up profile in Asana (see [[wholesale-asana-profile-setup|Wholesale Profile Setup in Asana]]).',
                        'Create follow-up task.',
                        'Set up Gmail label/folder by account status.',
                        'Add to email list through Google Sheet + Mailchimp.',
                        'After first order, add brick-and-mortar location to store locator map.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Approval decision email stub', 'slug' => 'wholesale-application-review'],
                        ['label' => 'Welcome + guidelines email stub', 'slug' => 'wholesale-overview'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-application-review',
                'wholesale-asana-profile-setup',
                'wholesale-shopify-account-setup',
            ],
        ],
        [
            'slug' => 'wholesale-application-review',
            'title' => 'Approving or Declining Wholesale Applications',
            'excerpt' => 'Decision criteria, required documents, and escalation edge cases for wholesale applications.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'required-documents',
                    'title' => 'Required Documents',
                    'checklist' => [
                        'Require retail license number and resale certificate soft copy.',
                        'Do not finalize approval until required documentation is complete.',
                    ],
                ],
                [
                    'id' => 'territory-checks',
                    'title' => 'Territory and Conflict Checks',
                    'checklist' => [
                        'Check sales map exclusivity (5-10 mile radius for qualifying accounts).',
                        'For edge cases or different business types in same area, escalate to Ops Manager.',
                        'Run candle conflict check; if customer makes candles or sells similar, consult Becky or John.',
                        'Big-box candle brands are usually acceptable but still document review.',
                    ],
                ],
                [
                    'id' => 'online-only-rules',
                    'title' => 'Online-Only Requirements',
                    'checklist' => [
                        'Require EIN and sales certificate/license plus established website.',
                        'If account later moves to brick-and-mortar, re-run encroachment check before approving expansion.',
                    ],
                ],
                [
                    'id' => 'outcomes',
                    'title' => 'Approval Outcomes',
                    'paragraphs' => [
                        'If approved, continue to [[wholesale-onboarding-new-wholesalers|Onboarding New Wholesalers]].',
                        'If declined, document rationale and archive under declined applications label.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Approval email stub', 'slug' => 'wholesale-onboarding-new-wholesalers'],
                        ['label' => 'Decline email stub', 'slug' => 'wholesale-email-labels'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-onboarding-new-wholesalers',
                'wholesale-email-labels',
                'wholesale-shopify-account-setup',
            ],
        ],
        [
            'slug' => 'wholesale-asana-profile-setup',
            'title' => 'Wholesale Profile Setup in Asana',
            'excerpt' => 'Create and maintain standardized Asana projects for wholesale accounts.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'project-creation',
                    'title' => 'Project Creation Workflow',
                    'checklist' => [
                        'From Starred, duplicate the wholesale template.',
                        'Use project naming: "Business Name (ST)", "Business Name (Online Only)", or "Business Name (Business Gift)".',
                        'Check "Project Description" so template text carries over.',
                        'Create project and verify it appears in search.',
                    ],
                    'quicklinks' => [
                        'Asana: Starred -> Wholesale template -> Duplicate.',
                        'Asana: Search bar -> locate new project and open details.',
                    ],
                ],
                [
                    'id' => 'project-details',
                    'title' => 'Project Details and Notes',
                    'checklist' => [
                        'Fill application info in project details.',
                        'Copy customer email/personal info into description under "first order".',
                        'Assign to Wholesale Director; set due date (quarterly default).',
                        'Keep "Follow Up:" label and date-stamp all new notes.',
                        'Maintain last contact date and first order date.',
                    ],
                ],
                [
                    'id' => 'tags-files',
                    'title' => 'Tags and Attachments',
                    'checklist' => [
                        'Apply tags as needed: Wholesale, Business Gift, Custom Labels, Barcodes, Custom Scent, Ships To Home Address.',
                        'Attach retail license file to project records.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Follow-up note format stub', 'slug' => 'wholesale-daily-responsibilities'],
                        ['label' => 'Asana profile kickoff stub', 'slug' => 'wholesale-onboarding-new-wholesalers'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-asana-color-tiers',
                'wholesale-onboarding-new-wholesalers',
                'wholesale-daily-responsibilities',
            ],
        ],
        [
            'slug' => 'wholesale-shopify-account-setup',
            'title' => 'Wholesale Account Setup in Shopify',
            'excerpt' => 'Configure wholesale customer accounts in Shopify with required tags and tax rules.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'customer-record',
                    'title' => 'Locate and Update Customer Record',
                    'checklist' => [
                        'Open Shopify -> Customers and locate starter file from application.',
                        'Edit contact info and addresses.',
                        'Leave marketing settings unchanged unless account requests updates.',
                    ],
                    'quicklinks' => [
                        'Shopify: Admin -> Customers -> search applicant email/business.',
                    ],
                ],
                [
                    'id' => 'tax-and-access',
                    'title' => 'Tax and Access Rules',
                    'checklist' => [
                        'Apply tax exemptions only after retail license is provided.',
                        'Do not send account invite until license documents are received.',
                        'Add customer tag "wholesale" to grant access.',
                    ],
                ],
                [
                    'id' => 'invite-and-verify',
                    'title' => 'Invite and Verify Activation',
                    'checklist' => [
                        'Send invite from More actions -> Review Email -> Send Email.',
                        'Verify activation by confirming "disable account" and "reset password" actions appear.',
                    ],
                    'quicklinks' => [
                        'Shopify: Customer -> More actions -> Send account invite.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Account invite reminder stub', 'slug' => 'wholesale-onboarding-new-wholesalers'],
                        ['label' => 'Missing license request stub', 'slug' => 'wholesale-application-review'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-onboarding-new-wholesalers',
                'wholesale-application-review',
                'wholesale-email-labels',
            ],
        ],
        [
            'slug' => 'wholesale-email-labels',
            'title' => 'Wholesale Emails and Gmail Labels',
            'excerpt' => 'Standard label taxonomy for wholesale communication and retention consistency.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'label-system',
                    'title' => 'Required Labels',
                    'checklist' => [
                        'Active: currently ordering accounts.',
                        'Business Gift: business gift-only customers.',
                        'Consignment: consignment-specific communication.',
                        'Declined Applications: declined inquiries and rationale trail.',
                        'Fundraising: fundraising program accounts.',
                        'Inactive: former active accounts currently inactive.',
                        'Inactive Prospect: prospects with no ongoing activity.',
                        'Prospect: active pre-approval leads.',
                    ],
                ],
                [
                    'id' => 'retention-rationale',
                    'title' => 'Retention Rationale',
                    'paragraphs' => [
                        'The label system preserves lifecycle context and keeps account transitions auditable across onboarding, active status, and deactivation.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Label transition note stub', 'slug' => 'wholesale-account-deactivation'],
                        ['label' => 'Prospect follow-up stub', 'slug' => 'wholesale-daily-responsibilities'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-account-deactivation',
                'wholesale-onboarding-new-wholesalers',
                'wholesale-asana-color-tiers',
            ],
        ],
        [
            'slug' => 'wholesale-asana-color-tiers',
            'title' => 'Asana System and Color Tiers',
            'excerpt' => 'Color-tier definitions for wholesale account status management in Asana.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'purpose',
                    'title' => 'Purpose of Asana in Wholesale',
                    'paragraphs' => [
                        'Asana is the system of record for wholesale client management, follow-up cadence, and lifecycle state.',
                    ],
                ],
                [
                    'id' => 'color-tier-definitions',
                    'title' => 'Color Tier Definitions',
                    'checklist' => [
                        'Dark Gray: prospects.',
                        'Red: bottom tier active accounts.',
                        'Yellow: mid tier active accounts.',
                        'Dark Green: top tier active accounts.',
                        'Light Gray: inactive prospects.',
                        'Orange: consignment.',
                        'Teal: inactive accounts.',
                        'Pink: fundraisers.',
                    ],
                ],
                [
                    'id' => 'business-gift-note',
                    'title' => 'Business Gift Note',
                    'paragraphs' => [
                        'Business gift accounts should be tagged and tracked distinctly; tax requirement still applies for business gift orders.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Tier change note stub', 'slug' => 'wholesale-asana-profile-setup'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-asana-profile-setup',
                'wholesale-account-deactivation',
                'wholesale-email-labels',
            ],
        ],
        [
            'slug' => 'wholesale-account-deactivation',
            'title' => 'Wholesale Account Deactivation',
            'excerpt' => 'End-of-lifecycle workflow for inactive wholesale accounts across Gmail, Shopify, Asana, and mailing systems.',
            'category' => 'wholesale-processes',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'inactive-criteria',
                    'title' => 'Inactive Criteria',
                    'checklist' => [
                        'Account is inactive after 1 year with no orders and/or no communications.',
                        'Exceptions for health/family reasons can be documented with specific follow-up date.',
                    ],
                ],
                [
                    'id' => 'final-followup',
                    'title' => 'Final Follow-Up Timing',
                    'checklist' => [
                        'At 1-year mark, send personalized "Final follow up" draft.',
                        'If no response/order after 30 days, complete deactivation workflow.',
                    ],
                ],
                [
                    'id' => 'deactivation-workflow',
                    'title' => '30-Day No Response Workflow',
                    'checklist' => [
                        'Gmail: move folder to Wholesale - inactive.',
                        'Shopify: disable account, remove wholesale tag, remove tax exemptions, remove from sales map.',
                        'Google Sheets: move to stores closed tab, remove from email list, record date.',
                        'Mailchimp: archive contact.',
                        'Asana: note inactive/disabled/removed from map, set last contact date to final email date, change color to teal, remove due date and assignee.',
                    ],
                    'quicklinks' => [
                        'Shopify: Customers -> account -> disable account + remove tags.',
                        'Mailchimp: Audience -> archive contact.',
                        'Google Sheets: stores closed tab update (see internal link).',
                        'Asana: update status/tier and remove task due date.',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Final follow-up email stub', 'slug' => 'wholesale-account-deactivation'],
                        ['label' => 'Deactivation internal note stub', 'slug' => 'wholesale-email-labels'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-email-labels',
                'wholesale-asana-color-tiers',
                'wholesale-daily-responsibilities',
            ],
        ],
        [
            'slug' => 'wholesale-special-swamp-rabbit-cafe',
            'title' => 'Special Case: Swamp Rabbit Cafe',
            'excerpt' => 'Purchase-order-based workflow, net-30 handling, and delivery coordination for Swamp Rabbit Cafe.',
            'category' => 'wholesale-special-cases',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'workflow-summary',
                    'title' => 'Workflow Summary',
                    'checklist' => [
                        'Orders arrive via emailed PO.',
                        'Wholesale Director enters PO as Shopify draft order.',
                        'Mark payment terms as Net30.',
                        'Standard processing time for this account is 5 business days (vs standard 10+).',
                    ],
                ],
                [
                    'id' => 'draft-order-setup',
                    'title' => 'Shopify Draft Order Setup',
                    'checklist' => [
                        'Create draft order with shipping set to "No Shipping Required" at $0.',
                        'Coordinate delivery timing with John.',
                        'Email buyer Laura at buyer@swamprabbitcafe.com with delivery expectation.',
                    ],
                    'quicklinks' => [
                        'Shopify: Orders -> Drafts -> Create order.',
                        'Gmail: use Swamp Rabbit label and send delivery confirmation.',
                    ],
                ],
                [
                    'id' => 'delivery-payment-rules',
                    'title' => 'Delivery and Payment Rules',
                    'checklist' => [
                        'Delivery window: 8am-6pm Monday-Friday, no Wednesdays.',
                        'John handles delivery coordination.',
                        'Payment via Bill.com after delivery (typically 1-2 weeks).',
                    ],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'PO received acknowledgment stub', 'slug' => 'wholesale-special-swamp-rabbit-cafe'],
                        ['label' => 'Delivery expectation email stub', 'slug' => 'wholesale-special-swamp-rabbit-cafe'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-shopify-account-setup',
                'wholesale-daily-responsibilities',
                'wholesale-special-hope-box',
            ],
        ],
        [
            'slug' => 'wholesale-special-hope-box',
            'title' => 'Special Case: Hope Box',
            'excerpt' => 'Placeholder special-case page for Hope Box. Needs details before operational use.',
            'category' => 'wholesale-special-cases',
            'updated_at' => '2026-02-20',
            'published' => true,
            'needs_details' => true,
            'sections' => [
                [
                    'id' => 'pricing',
                    'title' => 'Pricing',
                    'paragraphs' => ['Needs details.'],
                ],
                [
                    'id' => 'processing-time',
                    'title' => 'Processing Time for Reorders',
                    'paragraphs' => ['Needs details.'],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Reorder response stub', 'slug' => 'wholesale-special-hope-box'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-special-lowes-foods',
                'wholesale-special-swamp-rabbit-cafe',
                'wholesale-overview',
            ],
        ],
        [
            'slug' => 'wholesale-special-lowes-foods',
            'title' => 'Special Case: Lowes Foods',
            'excerpt' => 'Placeholder special-case page for Lowes Foods. Needs details before operational use.',
            'category' => 'wholesale-special-cases',
            'updated_at' => '2026-02-20',
            'published' => true,
            'needs_details' => true,
            'sections' => [
                [
                    'id' => 'pricing',
                    'title' => 'Pricing',
                    'paragraphs' => ['Needs details.'],
                ],
                [
                    'id' => 'approved-scents',
                    'title' => 'Approved Scents',
                    'paragraphs' => ['Needs details.'],
                ],
                [
                    'id' => 'templates',
                    'title' => 'Templates',
                    'templates' => [
                        ['label' => 'Approved scent confirmation stub', 'slug' => 'wholesale-special-lowes-foods'],
                    ],
                ],
            ],
            'related' => [
                'wholesale-special-hope-box',
                'wholesale-special-swamp-rabbit-cafe',
                'wholesale-overview',
            ],
        ],
        [
            'slug' => 'oil-blends',
            'title' => 'Oil Blend Recipes',
            'excerpt' => 'Global blends and component ratios used in production.',
            'category' => 'production',
            'path' => '/wiki/oil-blends',
            'updated_at' => '2026-02-19',
            'published' => true,
        ],
        [
            'slug' => 'wholesale-custom-scents',
            'title' => 'Wholesale Custom Scents',
            'excerpt' => 'Account-specific names mapped to canonical scents.',
            'category' => 'wholesale',
            'path' => '/wiki/wholesale-custom-scents',
            'updated_at' => '2026-02-19',
            'published' => true,
        ],
        [
            'slug' => 'candle-club',
            'title' => 'Candle Club Scents',
            'excerpt' => 'Monthly Candle Club scent archive.',
            'category' => 'events',
            'path' => '/wiki/candle-club',
            'updated_at' => '2026-02-19',
            'published' => true,
        ],
        [
            'slug' => 'market-room',
            'title' => 'Market Room',
            'excerpt' => 'Operations playbook for unpacking returned events and packing current-week market events.',
            'category' => 'events',
            'updated_at' => '2026-02-20',
            'published' => true,
            'sections' => [
                [
                    'id' => 'location-and-start',
                    'title' => 'Operations -> Events / Market Room',
                    'checklist' => [
                        'Start this process once all events are returned.',
                    ],
                ],
                [
                    'id' => 'unpacking-previous-week',
                    'title' => 'Unpacking Previous Week\'s Events',
                    'checklist' => [
                        'Clean off event tables in preparation for packing the next week\'s events.',
                        'Leave the candles on the tables.',
                        'Cash boxes: put a post-it with the event team member\'s name on each cash box, then place cash boxes aside for Elissa.',
                        'Put DeWalt boxes back on the Event Supplies shelf.',
                        'Set wooden crates under the event table.',
                        'Consolidate bags into bundles of 25 and place on shelf.',
                    ],
                ],
                [
                    'id' => 'power-and-charging',
                    'title' => 'Power / Charging',
                    'checklist' => [
                        'Check charge on portable chargers and plug in if needed.',
                        'Charge Jackerys.',
                    ],
                ],
                [
                    'id' => 'linens',
                    'title' => 'Linens',
                    'checklist' => [
                        'Take out tablecloths and table runners, shake out, refold, and place back on shelf in the proper location.',
                        'Sort/store by 5ft tablecloths, 6ft tablecloths, and table runners.',
                        'If tablecloths are dirty, set aside and text John that they need dry cleaning.',
                    ],
                ],
                [
                    'id' => 'remaining-supplies',
                    'title' => 'Remaining Supplies',
                    'checklist' => [
                        'For remaining supplies (wagons, stair stands, wooden crate, table risers, etc.), either place under event table or return to proper storage location.',
                    ],
                ],
                [
                    'id' => 'packing-current-week-setup',
                    'title' => 'Packing the Current Week\'s Events - Setup',
                    'checklist' => [
                        'Set one event per table.',
                        'Fill out Event Packing Checklist information for each event and place clipboard on each table.',
                        'Start with the table closest to the Shipping room door.',
                        'Kari\'s events go on the table closest to the door.',
                    ],
                ],
                [
                    'id' => 'packing-candles-and-workflow',
                    'title' => 'Candles + Event Table Workflow',
                    'checklist' => [
                        'Take candles off the Upcoming Shelf and place on tables according to number of boxes on each Event Packing Checklist.',
                        'Use the Event Packing Checklist on each table to pack remaining supplies needed for that event.',
                        'Repeat for each event.',
                    ],
                ],
                [
                    'id' => 'additional-notes',
                    'title' => 'Additional Notes',
                    'checklist' => [
                        'Some events have unique needs (seasonal decor, specific scents, etc.).',
                        'Check Asana notes and communicate with event coordinator, pour room, or assigned event team member.',
                        'Florida Strawberry Festival needs at least two strawberry scents.',
                        'Some fall shows require Christmas decor.',
                    ],
                ],
                [
                    'id' => 'bundles-of-bags',
                    'title' => 'Bundles of Bags',
                    'paragraphs' => [
                        'Bundles of brown bags are sent to every event.',
                        'Create each bundle by placing 24 bags inside 1 bag.',
                    ],
                    'checklist' => [
                        'Half day event (3-4 hours): send 3-4 bundles.',
                        'One day event (more than 4 hours): send 5 bundles.',
                        'Two day event: send 6-8 bundles.',
                        'Three day event: send 8-10 bundles.',
                        'Four day event: send 10-12 bundles.',
                        'Longer than 4 days: check Asana notes/comments from previous years.',
                        '10+ day events (for example Gatlinburg Craftsmen Festival, Florida Strawberry Festival, Florida State Fair): send 4 boxes of bundled bags.',
                    ],
                ],
                [
                    'id' => 'bag-ordering',
                    'title' => 'Bag Ordering / Inventory Note',
                    'checklist' => [
                        'Bag ordering usually has a 3-week lead time.',
                        'Let Becky know when bags are low (good rule: around 5 boxes left, season depending).',
                    ],
                ],
                [
                    'id' => 'banners-tables-pipe-drape-tents',
                    'title' => 'Banners / Tables / Pipe & Drape / Tents',
                    'checklist' => [
                        'Banners: all TR Farmers Markets (April-September) need a white TR Farmers Market banner.',
                        'Tables: all events require at least two 6-foot tables; check Asana for additional table needs.',
                        'Tablecloths/runners: each table needs matching-size tablecloth; two table runners needed for 3+ day events.',
                        'Pipe and drape: use 3 drapes per wall.',
                        'Single booth pipe and drape: 3 walls and 4 feet to hold pipes.',
                        'Corner booth pipe and drape: 2 walls and 3 feet to hold pipes.',
                        'All Gilmore Classic and VMD events require pipe and drape.',
                        'Pack drapes and feet on event team member\'s table; event coordinator communicates pipe count in Asana.',
                        'Tents: all outdoor events require tents.',
                        'Some events require specific tent type/color; confirm in Asana.',
                        'Send tent sides if rain is forecast.',
                        'Send tent sides for multi-day events for security.',
                        'Weights are required for tents; check Asana for exact need.',
                        'Weight reference: flat weights are 5 lbs each; kettle bells are 30 lbs each; black fabric-wrapped bricks are about 8 lbs each.',
                    ],
                ],
                [
                    'id' => 'market-room-responsibilities',
                    'title' => 'Market Room Responsibilities',
                    'paragraphs' => [
                        'Events Coordinator owns market room duties, including but not limited to:',
                    ],
                    'checklist' => [
                        'Packing events.',
                        'Unpacking events.',
                        'Keeping market room clean and organized.',
                        'Sweeping or vacuuming floors.',
                        'Returning supplies to proper storage locations.',
                        'Sending dirty linens (tablecloths, table runners, drapes) with John for dry cleaning.',
                        'Cleaning tents and tent sides.',
                        'Labeling market candles.',
                        'Bundling brown shopping bags.',
                        'Checking supply levels and communicating needs to Becky.',
                    ],
                ],
            ],
            'related' => [
                'candle-club',
            ],
        ],
    ],
];
