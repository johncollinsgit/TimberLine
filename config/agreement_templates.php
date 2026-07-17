<?php

return [
    'front_yard_foods_launch_partner' => [
        'agreement_type' => 'launch_partner',
        'title' => 'Front Yard Foods LLC — Shopify Migration and Everbranch Launch Partner Agreement',
        'parties' => [
            'provider' => 'Evergrove Software',
            'platform' => 'Everbranch',
            'client' => 'Front Yard Foods LLC',
            'effective_date' => 'Date of electronic acceptance',
        ],
        'purpose' => 'Move Front Yard Foods from Squarespace to Shopify, continue Square for in-person sales, and use Everbranch as the central internal operating workspace for customers, orders, inventory context, classes, consultations, tasks, files, reporting, agreements, and authorized staff access.',
        'responsibilities' => [
            'Shopify' => [
                'Public website, pages, navigation, products, collections, retail and wholesale checkout',
                'Customer-facing inventory availability, preorders, porch pickup, local delivery, delivery fees, and future-market pickup',
                'Paid class registration, paid garden consultation checkout, customer accounts, and Front Yard Academy purchase/access entry',
            ],
            'Square' => [
                'In-person checkout, card-present payments, existing register operations, and in-person transaction records',
            ],
            'Everbranch' => [
                'Internal business operations and authorized staff access through the shared Everbranch application',
                'Unified Shopify and Square customer/order context without copying Square orders into Shopify',
                'Inventory costs, purchased plant lots, holds/reservations, wholesale workflow, classes, consultations, tasks, notes, files, grants, reporting, alerts, agreements, and subscription records',
            ],
        ],
        'scope_matrix' => [
            ['thing' => 'Move the website from Squarespace', 'surface' => 'Shopify', 'approach' => 'Move agreed pages, products, navigation, branding, and public information to Shopify.'],
            ['thing' => 'Manage products in one place', 'surface' => 'Shopify', 'approach' => 'Shopify becomes the primary sellable product catalog and inventory source of truth.'],
            ['thing' => 'Continue using Square', 'surface' => 'Square and Everbranch', 'approach' => 'Square remains the in-person point of sale; Everbranch normalizes internal visibility.'],
            ['thing' => 'Keep Shopify and Square inventory aligned', 'surface' => 'Shopify, Square, and Everbranch', 'approach' => 'Mapped Square sales may lower Shopify inventory through an idempotent Everbranch-managed integration with reconciliation.'],
            ['thing' => 'Avoid duplicating orders', 'surface' => 'Everbranch', 'approach' => 'Square orders are not recreated in Shopify; Everbranch may display both sources in one internal view.'],
            ['thing' => 'Retail and wholesale sales', 'surface' => 'Shopify and Everbranch', 'approach' => 'Shopify accepts orders; Everbranch supports wholesale customers, reservations, allocation, follow-up, and internal context.'],
            ['thing' => 'Purchased plant costs', 'surface' => 'Everbranch', 'approach' => 'Track vendor, quantity, total and unit cost, received date, and notes.'],
            ['thing' => 'Plant and plug preorders', 'surface' => 'Shopify and Everbranch', 'approach' => 'Shopify accepts orders and payments; Everbranch tracks windows, caps, expected dates, and fulfillment state.'],
            ['thing' => 'Hold products for customers', 'surface' => 'Everbranch', 'approach' => 'Authorized staff can create auditable holds that reduce available-to-promise and can expire, release, or convert.'],
            ['thing' => 'Porch, local-delivery, and market pickup', 'surface' => 'Shopify and Everbranch', 'approach' => 'Shopify presents checkout choices and fees; Everbranch may retain event and fulfillment context.'],
            ['thing' => 'Paid classes', 'surface' => 'Shopify and Everbranch', 'approach' => 'Shopify processes payment; Everbranch manages schedule, capacity, enrollments, reminders, and attendance.'],
            ['thing' => 'Garden consultations', 'surface' => 'Shopify, booking calendar, and Everbranch', 'approach' => 'Shopify/approved calendar handles public booking and payment; Everbranch manages the customer, consultation, notes, files, tasks, and follow-up.'],
            ['thing' => 'Front Yard Academy', 'surface' => 'Shopify/course app and Everbranch', 'approach' => 'Purchases and customer access begin in Shopify or an approved course app; Everbranch may mirror access context. No full LMS is included.'],
            ['thing' => 'Rooting In with Laura', 'surface' => 'Substack and Shopify', 'approach' => 'Substack initially owns publishing and delivery; Shopify presents links/signup and Everbranch may store appropriate consent/source context.'],
            ['thing' => 'Grant activity', 'surface' => 'Everbranch', 'approach' => 'Track deadlines, documents, contacts, notes, tasks, and next steps.'],
            ['thing' => 'Agreement and subscription records', 'surface' => 'Everbranch', 'approach' => 'Store immutable scope, pricing, acceptance, signature, billing authorization, receipts, amendments, termination, and export evidence.'],
        ],
        'scope_sections' => [
            ['title' => 'Shopify website migration', 'body' => 'Evergrove will assist with agreed Shopify setup, branding, navigation, pages, products, collections, checkout, pickup, delivery, market pickup, customer accounts, domain transition, and basic launch preparation. Exact content and files must be recorded in the accepted scope. Front Yard Foods supplies accurate content, photographs, pricing, quantities, and policies.'],
            ['title' => 'Data-use assurance', 'body' => 'Evergrove uses Front Yard Foods data, files, customer information, Shopify/Square/Substack/booking access, and related provider credentials only to deliver the approved migration, setup, support, reporting, security, legal compliance, and client-authorized integrations. Evergrove will not sell Front Yard Foods data, will not share it with unrelated third parties, and will not use it outside approved service delivery.'],
            ['title' => 'Inventory and product management', 'body' => 'Shopify is the source of truth for sellable products and inventory. Any Square-to-Shopify adjustment must prevent duplicate deductions and sync loops, preserve provider event identifiers, record errors, and support reconciliation. Integration delays and provider outages remain possible.'],
            ['title' => 'Inventory holds and reservations', 'body' => 'Reservations may identify the customer, variant, quantity, reason, expiration, status, notes, and creator. Active holds reduce available-to-promise. Negative availability is blocked unless an intentional preorder or oversell rule permits it.'],
            ['title' => 'Classes and events', 'body' => 'Paid class payment is handled by Shopify. Everbranch manages class operations and may confirm enrollment from paid Shopify order events idempotently. Capacity remains server-enforced. Free classes may use an Everbranch signup form.'],
            ['title' => 'Garden consultations', 'body' => 'Public booking and payment use Shopify and an approved calendar application. Everbranch may create or update the related customer, consultation, project, job, task, notes, files, photographs, meeting link, and follow-up. Third-party subscriptions are separate.'],
            ['title' => 'Front Yard Academy and newsletter', 'body' => 'Academy access begins through Shopify customer accounts and an approved course or membership app. Substack initially owns newsletter writing and delivery. This scope does not build a complete LMS or newsletter publishing platform in Everbranch.'],
            ['title' => 'Shared Everbranch application', 'body' => 'Everbranch is for Front Yard Foods internal business management only. Laura and approved employees use the shared Everbranch app. This scope does not include a separate Front Yard Foods App Store listing, a white-label app, customer mobile shopping, or customer ordering through Everbranch.'],
        ],
        'implementation_phases' => [
            ['phase' => '1', 'title' => 'Discovery, access, and launch plan', 'deliverables' => ['Confirm the accepted page, product, collection, policy, domain, pickup, delivery, class, consultation, and inventory scope.', 'Collect approved Shopify, Squarespace, Square, domain, content, photograph, product, pricing, tax, shipping, and policy access or files.', 'Record any separate implementation amount, payment schedule, client decision-maker, dependencies, exclusions, and launch acceptance checklist before implementation fees are charged.']],
            ['phase' => '2', 'title' => 'Shopify store foundation and migration', 'deliverables' => ['Configure the agreed Shopify store foundation, branding, navigation, pages, collections, products, policies, pickup/delivery choices, customer accounts, and domain transition.', 'Migrate only the pages, products, content, and files recorded in the accepted scope.', 'Keep Shopify subscription, processing, transaction, tax, theme, and paid-app expenses separate from Everbranch and implementation charges.']],
            ['phase' => '3', 'title' => 'Square, catalog, and inventory workflow', 'deliverables' => ['Keep Square as the in-person point of sale and preserve Square transactions as Square records.', 'Map the agreed Shopify variants to Square items without recreating Square orders in Shopify.', 'Validate idempotent inventory adjustments, loop prevention, error records, reconciliation, holds, purchased plant costs, preorders, wholesale context, and available-to-promise behavior before enabling automation.']],
            ['phase' => '4', 'title' => 'Classes, consultations, and booking setup', 'deliverables' => ['Configure the agreed Shopify products or approved booking/calendar tools for paid classes and consultations.', 'Configure Everbranch schedules, capacity, enrollment/consultation context, reminders, attendance, notes, files, tasks, and follow-up.', 'Test payment-to-enrollment behavior, capacity enforcement, consent, reminder readiness, and failure/replay behavior without enabling unverified live messages.']],
            ['phase' => '5', 'title' => 'Validation, training, and launch', 'deliverables' => ['Run tenant-scoped migration, checkout, Square, inventory, pickup/delivery, class, consultation, access, and mobile/internal-management acceptance checks.', 'Resolve launch-blocking findings, train approved staff on the shared Everbranch internal-management app, and record accepted exceptions or deferred work.', 'Obtain written launch approval before domain cutover or enabling any verified automation.']],
            ['phase' => '6', 'title' => 'Handoff and post-launch support', 'deliverables' => ['Provide the agreed handoff materials, account ownership confirmation, support path, provider-cost list, and open/deferred-work record.', 'Track approved changes outside the accepted scope at $50/hour only after written electronic authorization.', 'Preserve agreement, acceptance, subscription authorization, termination, and 30-day export evidence.']],
        ],
        'third_party_costs' => [
            'Shopify subscription, payment-processing charges, transaction fees, taxes, and paid Shopify applications',
            'Square processing/service fees',
            'Booking, calendar, course, membership, Substack, Zoom, domain, business-email, shipping, tax, and other approved software fees',
        ],
        'ownership' => [
            'client' => 'Front Yard Foods retains its business name, branding, Shopify store, Square account, domain, original content and photographs, products, Academy and Substack content, and client-owned customer/order data subject to law and provider terms. Evergrove will not sell, share with unrelated third parties, or use that data outside approved service delivery, support, reporting, security, legal compliance, and client-authorized integrations.',
            'provider' => 'Evergrove and Everbranch retain the platform, source code, reusable modules, integrations, scheduling, inventory, agreement, reporting, workflow, and general platform improvements. Front Yard Foods receives a limited right to use licensed Everbranch functionality while its subscription is active and in good standing.',
        ],
        'client_responsibilities' => 'Front Yard Foods supplies timely administrative access, accurate product/customer/business information, approved content and photographs, policies, schedules, requirements, and a representative authorized to make decisions. Delays or inaccurate information may delay the project.',
        'platform_availability' => 'Evergrove does not guarantee third-party approval, pricing, policies, APIs, uptime, review timing, sales, traffic, grant approval, attendance, bookings, growth, profitability, or a particular App Store result. Substantial provider-change work may be out of scope after approval.',
        'electronic_acceptance' => 'The authorized signer must provide legal name, title, email, typed signature, authority confirmation, and express acceptance of scope, pricing, subscription, hourly work, termination, and electronic records. Acceptance binds the exact immutable version and content hash. Later changes require a new version, amendment, addendum, or accepted change request.',
    ],
];
