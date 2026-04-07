# Shopify Embedded Messaging Workspace

## Overview
- Adds a tenant-gated `Messages` workspace inside the existing Shopify embedded Backstage shell.
- Supports:
  - 1:1 messaging to customers
  - saved group creation/editing and group sends
  - optional `All Subscribed` audience targeting
  - SMS via existing Twilio path
  - Email via existing tenant email/send pipeline
  - preview/confirmation before group send dispatch
  - channel-aware audience diagnostics (displayed vs query candidates vs resolved sendable)

## Commercial Modeling
- Canonical module/add-on configuration:
  - `config/module_catalog.php`
    - capability: `messaging.workspace`
    - module: `messaging`
    - add-on mapping: `addons.messaging.modules = ['messaging']`
  - `config/commercial.php`
    - add-on entry: `addons.messaging`
    - Stripe lookup mapping:
      - `addons.messaging.product_lookup_key = addon_messaging`
      - `addons.messaging.recurring_price_lookup_key = addon_messaging_monthly`

## Tenant Gating And Default Enablement
- Navigation visibility and API access rely on `TenantModuleAccessResolver` (`module_key = messaging`).
- Non-enabled tenants:
  - do not see the Messaging tab in embedded top nav
  - receive `403 messaging_module_locked` on Messaging APIs
- Modern Forestry default:
  - migration `2026_04_03_091000_seed_modern_forestry_messaging_entitlement.php`
  - seeds `tenant_module_entitlements` for tenant slug `modern-forestry` with enabled messaging entitlement.

## Embedded Navigation Registration
- Page registration:
  - `app/Services/Shopify/ShopifyEmbeddedPageRegistry.php`
  - page key: `messaging`
  - label: `Messages`
  - route: `shopify.app.messaging`
  - `requires_enabled_access = true`
- Shell filtering:
  - `app/Services/Shopify/ShopifyEmbeddedShellPayloadBuilder.php`
  - `pageVisibleForNavigation()` hides pages requiring enabled access when module access is false.

## Workspace UI And Routes
- Controller: `app/Http/Controllers/ShopifyEmbeddedMessagingController.php`
- View: `resources/views/shopify/messaging.blade.php`
- Routes:
  - page: `GET /shopify/app/messaging`
  - bootstrap: `GET /shopify/app/api/messaging/bootstrap`
    - lightweight startup payload (`groups` + `templates` only; heavy audience/history loading is deferred)
  - audience summary: `GET /shopify/app/api/messaging/audience-summary`
  - customer search: `GET /shopify/app/api/messaging/customers/search`
  - group preview: `POST /shopify/app/api/messaging/preview/group`
  - groups list/detail/create/update
  - send individual/group
  - history
- All API routes use strict Shopify bearer-token verification via `ShopifyEmbeddedAppContext::resolveAuthenticatedApiContext`.

## UX Structure
- Default workflow is group messaging with a compact, left-side audience selector ordered high-to-low by audience size.
- `All Subscribed` is selectable/deselectable and never applied automatically.
- Group editor is hidden by default and only shown when explicitly opened.
- Send controls live in a full-width bottom card.
- Clicking send first opens preview/confirmation; only confirmation dispatches.
- Auto-audience counts are not part of the initial HTML response and now load on demand when the operator starts interacting with audience cards/details, so opening or leaving the page is not blocked by passive auth fetches.
- Campaign history loads on demand when the operator opens `Completed runs` or reaches the final send step, and polling only starts after history is visible.
- Email-only tools:
  - conditional email template editor
  - live preview pane
  - hidden entirely for SMS.

## Customer Search Reuse
- Messaging search reuses embedded Customers query behavior instead of introducing a separate picker implementation.
- Shared provider:
  - `app/Services/Shopify/ShopifyEmbeddedCustomersGridService.php`
  - method: `searchProfilesForMessaging()`
- Workspace adapter:
  - `app/Services/Shopify/ShopifyEmbeddedMessagingWorkspaceService.php`
  - method: `searchCustomers()`

## Groups And Persistence
- Group model/table:
  - `marketing_message_groups`
  - migration extension: `2026_04_03_090000_extend_marketing_message_groups_for_tenant_workspace.php`
  - fields added: `tenant_id`, `is_system`, `system_key`
- Member table:
  - `marketing_message_group_members`
- Workspace save/update flows:
  - `ShopifyEmbeddedMessagingWorkspaceService::createGroup()`
  - `ShopifyEmbeddedMessagingWorkspaceService::updateGroup()`
- Tenant isolation:
  - group queries and profile membership resolution are tenant-scoped
  - cross-tenant group access returns not found/validation failures.

## Automatic Audience Rule (`All Subscribed`)
- Exposed as an automatic audience in the Messaging workspace.
- Optional audience (not auto-selected by default).
- Effective consent rule: include profiles with both:
  - channel sendability (reachable identity), and
  - either canonical consent flag OR legacy-import subscribed signal without a newer opt-out/revoked event.
- Legacy import consent sources honored:
  - `yotpo_contacts_import`
  - `square_marketing_import`
  - `square_customer_sync`
- Channel-specific eligibility:
  - SMS: effective consent + valid phone normalization/E.164
  - Email: effective consent + normalized valid email
- Summary API reports:
  - `sms`, `email`, `overlap`, `unique`
  - plus diagnostics for each channel:
    - `displayed_audience_count`
    - `query_candidate_count`
    - `effective_consent_count`
    - `resolved_sendable_count`

## Send Pipelines And History
- Direct messaging orchestration:
  - `app/Services/Marketing/MarketingDirectMessagingService.php`
- SMS path:
  - `TwilioSmsService::sendSms()`
  - logs `marketing_message_deliveries` + delivery events
- Email path:
  - `SendGridEmailService::sendEmail()`
  - delegates to tenant email dispatch service
  - logs `marketing_email_deliveries`
- Embedded history:
  - `ShopifyEmbeddedMessagingWorkspaceService::history()`
  - shows records produced by `source_label` prefixed with `shopify_embedded_messaging_`.

## Tests
- Primary coverage:
  - `tests/Feature/ShopifyEmbeddedMessagingTest.php`
  - `tests/Feature/ShopifyEmbeddedNavigationTest.php`
  - `tests/Unit/ShopifyEmbeddedPageRegistryTest.php`
  - tenancy/commercial consistency tests for module catalog and add-on mapping
- Key assertions include:
  - tab visibility gating
  - Modern Forestry default entitlement seed
  - tenant-scoped search/groups/send behavior
  - all-subscribed counts including legacy-import effective consent handling
  - group preview endpoint estimate behavior
  - lightweight bootstrap contract (deferred heavy loads)
  - SMS and email send-path logging.

## Operational Requirements
- SMS configuration:
  - `MARKETING_SMS_ENABLED`
  - `TWILIO_*` settings (or sender config)
  - optional dry-run: `marketing.sms.dry_run=true`
- Email configuration:
  - existing tenant/fallback email provider settings used by current send pipeline
- No additional provider wrappers are required for Messaging workspace sends.

## Enabling Future Tenants
- Enable Messaging by setting/overriding tenant entitlement for module key `messaging`:
  - `availability_status = available`
  - `enabled_status = enabled`
- This automatically unlocks:
  - embedded nav visibility
  - page access
  - Messaging API actions.
