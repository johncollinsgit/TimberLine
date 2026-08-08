# Workspace Capability Policy

## Source of truth

`TenantAccessProfile.metadata.tenant_blueprint.workspace_profile` is the authoritative business context for a workspace. Connection state, imported data, marketing activity, and historical module state can describe data that exists; none may infer a business profile or grant a capability.

The six profiles are `retail_commerce`, `maker_production`, `field_service_trades`, `professional_services`, `appointment_inventory`, and `generic_custom`.

| Profile | Default scope | Never infer from a connection |
| --- | --- | --- |
| Retail commerce | Products, orders, product reviews, wishlists, loyalty/rewards | Retail from Shopify alone |
| Maker / production | Batches, materials, inventory, production | Retail without the retail pack |
| Field-service trades | Customers, jobs, dispatch, estimates, field inventory, service reputation | Retail/loyalty from marketing data |
| Professional services | Clients, matters/projects, documents, time, billing | Dispatch or retail workflows |
| Appointment / inventory | Classes, bookings, events, inventory, customer messaging | Product loyalty assumptions |
| Generic / custom | Customers, CRM, messaging, reporting, approved custom work | Any vertical pack |

Capability packs are explicit. `retail_commerce` is required for product reviews, wishlists, loyalty/rewards, retail referrals, birthdays, Shopify retail surfaces, and product enrichment. `service_reputation` enables service-review work for field-service tenants.

## Review and legacy boundary

An onboarding choice is a request for review, not an entitlement. Until the blueprint review status is `reviewed`, `TenantWorkspaceCapabilityService` resolves the safe `generic_custom` context with no packs. The two approved launch identities are deliberate exceptions: Modern Forestry is maker/production plus the retail pack and its `modern_forestry_legacy` overlay; Collins Electric is field-service with service reputation.

Growave data and jobs remain preserved for audit/recovery, but the `modern_forestry_legacy` overlay is identity-locked to tenant slug `modern-forestry`. It must not be made editable through integrations, connection state, or a user setting.

## Assignment process

1. New customers select the closest profile in first-login onboarding, or choose **Other / Custom** and describe their business vocabulary.
2. The initial workspace remains neutral while the selection is queued for operator review.
3. An operator confirms the profile and any explicit capability packs in the tenant blueprint.
4. The resolver, navigation, customer experience, Module Store, APIs, mobile bootstrap, direct routes, exports, and background execution must all use the same resolved context.

For an unclassified existing tenant, leave the blueprint unreviewed. It fails closed to generic/custom until an operator confirms it.

## Release checklist

- Confirm Modern Forestry retains approved retail, production, and legacy-history paths.
- Confirm Collins Electric has no retail rewards, product reviews, wishlists, birthdays, Shopify product metadata, or Growave payloads.
- Confirm an unreviewed Shopify-connected tenant remains generic/custom.
- Exercise navigation, Module Store, direct URLs, API responses, mobile bootstrap, search, exports, and relevant scheduled/command paths for each affected profile.
- Check customer views use server-provided compatible columns only.
