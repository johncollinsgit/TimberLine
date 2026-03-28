# Multi-Tenant Inventory (2026-03-27)

Status: Operator-facing inventory from current code + docs.  
Scope: Audit of agnostic vs tenant-specific behavior for launch/stabilization.

## Buckets

### Truly Multi-Tenant Now
- Landlord/tenant host resolution and safety:
  - `app/Services/Tenancy/PreAuthTenantContextResolver.php`
  - `app/Http/Middleware/ResolveHostTenantContext.php`
  - landlord host lock in `routes/web.php` (`/landlord*` domain routes only)
  - unknown hosts resolve to `none`, no first-tenant fallback
- Post-auth tenant access enforcement:
  - `app/Http/Middleware/EnsureTenantAccess.php`
  - user membership enforced through `tenant_user` for tenant-scoped routes
- Tenant entitlement/access foundation:
  - `tenant_access_profiles`, `tenant_access_addons`, `tenant_module_states`
  - `App\Services\Tenancy\TenantModuleAccessResolver`
  - `App\Support\Tenancy\TenantModuleUi`
- Tenant email provider settings/readiness path:
  - `tenant_email_settings`
  - `App\Services\Marketing\Email\TenantEmailSettingsService`
  - `App\Services\Marketing\Email\TenantEmailProviderResolver`
  - `App\Services\Marketing\Email\TenantEmailDispatchService`

### Partially Tenant-Aware
- Canonical identity pipeline (correct canonical path, but not complete isolation in all downstream tables):
  - `marketing_profiles`, `customer_external_profiles`, `marketing_profile_links`
  - `App\Services\Marketing\MarketingProfileSyncService`
  - tenant context is present and reused, but not every downstream marketing domain table is fully tenant-keyed
- Shopify storefront proxy flows:
  - `routes/web.php` `/shopify/marketing/*` and `/shopify/marketing/v1/*`
  - `MarketingShopifyIntegrationController` resolves store context and frequently derives tenant scope
  - still depends on domain-specific joins for some reward/reporting reads
- Shopify embedded dashboard/customers/settings:
  - entitlement-aware shell + tenant context exists
  - query/service paths still require continued tenant-hardening audits
- Candle Cash / loyalty:
  - canonical first-party ledger exists and is active
  - strong tenant-awareness through profile-linked context, but still mixed because key tables are profile-linked rather than uniformly tenant-keyed
- Birthdays:
  - tenant-aware paths in issuance/reporting/dispatch are in place
  - still mixed where historical joins rely on profile relations and legacy assumptions
- Reviews and wishlist:
  - first-party systems exist and are tenant-aware in many paths
  - still require continued parity and isolation review across all admin/reporting views
- Campaigns / groups / segments / recommendations:
  - domain exists, tenant access middleware exists
  - not uniformly hardened as complete tenant-isolated SaaS behavior
- SMS / Twilio:
  - provider integration exists with tenant-aware campaign context
  - sender/global fallback assumptions still appear in parts of the path

### Still Global / Single-Tenant
- Core internal operations systems:
  - inventory, pouring, shipping, events/markets, wiki
  - remain primarily operations-oriented and not productized tenant modules
- Social login credentials:
  - login provider credentials remain app-level global config
- Some diagnostics/reporting aggregations:
  - strong tenant-aware progress exists, but not every report path is guaranteed tenant-isolated by contract yet

### Internal-Only By Design
- Inventory operations
- Pouring/manufacturing workflows
- Shipping operations
- Events/markets planning tools
- Internal wiki/process tooling

### Mixed / Unsafe / Quarantine
- Referral/VIP/notifications embedded surfaces:
  - visible and routeable in navigation
  - still placeholder/thin and not launch-complete parity modules
- Social-login remnants in rewards/marketing context:
  - treat as integration-adjacent and not a tenant-ready module
- Integrations page:
  - intentionally read-only placeholder surface
  - no live connector OAuth/sync writes from `/shopify/app/integrations`
- Billing/checkout:
  - intentionally not activated
  - Stripe/Braintree are configuration-readiness only

## Domain-by-Domain Audit Summary
- Landlord/admin surfaces: host-locked and usable; directory is read-only, commercial configuration writes are intentionally limited scope.
- Tenant resolution and access: strong foundation exists and is enforced in middleware; still expanding across all business domains.
- Canonical identity: correct canonical model/service set is reused; no parallel profile system should be introduced.
- Shopify storefront proxy flows: production-critical and preserved; tenant hardening is incremental.
- Embedded dashboard/customers/settings: live and stable, with continued tenant query hardening needed.
- Candle Cash/loyalty: live and launch-critical, but still partially profile-linked instead of uniformly tenant-keyed.
- Birthdays: operational and tenant-aware in key paths; complete isolation parity is still being hardened.
- Reviews: first-party and active; parity/reporting hardening continues.
- Wishlist: first-party and active; admin/reporting parity hardening continues.
- Campaigns/groups/segments/recommendations: significant domain exists; full tenant product-hardening is still in progress.
- Email settings/dispatch: tenant-aware and operational through canonical provider services.
- SMS/Twilio: integrated with campaign paths; some global sender/fallback assumptions remain.
- Internal ops systems: intentionally internal-first and not current tenant-plan modules.

## Operator Interpretation
- This repo has a real tenant-safe foundation and live tenant-aware modules.
- It is not yet complete “everything-isolated SaaS” across all domains.
- Launch messaging should explicitly distinguish:
  - live + stable
  - partially tenant-aware
  - placeholder or quarantined
