# Modern Forestry Agentic Discovery + Brand Graph

## What Was Built

This implementation adds a backend-first, tenant-scoped discovery system that can act as the canonical source of truth for machine-readable brand/discovery metadata.

Implemented components:

- New tenant-scoped persistence:
  - `tenant_discovery_profiles`
  - `tenant_discovery_pages`
- New models:
  - `App\Models\TenantDiscoveryProfile`
  - `App\Models\TenantDiscoveryPage`
- Tenant relations:
  - `Tenant::discoveryProfile()`
  - `Tenant::discoveryPages()`
- New discovery services:
  - `TenantDiscoveryProfileService`
  - `BrandDiscoveryGraphService`
  - `DomainCanonicalResolver`
  - `DiscoveryStructuredDataService`
  - `DiscoverySitemapService`
  - `DiscoveryDomainAuditService`
- New public endpoints:
  - `GET /.well-known/brand-discovery.json`
  - `GET /api/public/discovery/brand/{tenant}`
  - `GET /api/public/discovery/structured/{tenant?}`
  - `GET /sitemaps/discovery.xml`
- New audit command:
  - `php artisan modern-forestry:audit:domains`
- Modern Forestry bootstrap integration:
  - `ModernForestryAlphaBootstrapService` now seeds discovery profile/page defaults idempotently for the Modern Forestry tenant.

## Why This Improves Discovery + Recommendation Readiness

- Domain intent is explicit and machine-readable (retail vs wholesale vs admin identity).
- Audience routing is explicit (`retail_customer`, `wholesale_buyer`, `stockist_retailer`, etc).
- South Carolina relevance is represented as first-class geography/page metadata (state-level, no city fabrication).
- Policy, trust, and merchant-signal data now has a backend contract consumable by:
  - JSON discovery docs
  - JSON-LD builders
  - canonical resolver
  - sitemap export
  - future feed/API exports
- LocalBusiness emission is guarded: no schema is emitted without complete real location data.
- FAQ schema emission is guarded: FAQPage emits only when real FAQ items exist.

## Source Of Truth + Fallback Strategy

`TenantDiscoveryProfileService` resolves data in this order:

1. `tenant_discovery_profiles` / `tenant_discovery_pages`
2. Existing tenant systems (email settings, Shopify store mapping, native review aggregates)
3. Safe defaults/placeholders (non-fabricated)

Where real values are missing, placeholder markers are returned in `placeholders` (for operator completion).

## Canonical Strategy

`DomainCanonicalResolver` provides page-type canonical decisions and avoids retail/wholesale cannibalization:

- Retail-intent pages canonically map to `retail_storefront`
- Wholesale-intent pages canonically map to `wholesale_storefront`
- Brand-story pages map to `brand_story_site`
- Admin-only surfaces are marked non-indexable canonical targets

This supports explicit canonical URL output for homepage, wholesale landing, policy/FAQ, and audience-targeted pages.

## Discovery Audit Command

Run:

```bash
php artisan modern-forestry:audit:domains
php artisan modern-forestry:audit:domains --tenant-id=1 --timeout=8
```

The audit reports:

- canonical URL detection/conflicts
- robots/noindex/nosnippet flags
- title/meta/schema presence
- domain role drift
- stale custom-domain signals for `theforestrystudio.com`
- wholesale metadata sufficiency signals for `modernforestrywholesale.com`
- well-known discovery endpoint reachability

Exit behavior:

- `drift` -> non-zero exit
- `warning`/`ok` -> success exit

## Public Verification Steps

Use these endpoints:

- `GET /.well-known/brand-discovery.json`
- `GET /api/public/discovery/brand/modern-forestry`
- `GET /api/public/discovery/structured/modern-forestry`
- `GET /sitemaps/discovery.xml`

## Placeholder Fields To Fill

Fill tenant-specific production values in `tenant_discovery_profiles` / `tenant_discovery_pages` for:

- support phone
- verified social profile URLs
- logo URL
- return policy URL
- shipping policy URL
- FAQ URL
- domestic/international shipping availability flags
- product categories carried
- minimum order / stockist requirements / lead time notes (if applicable)
- non-US order policy mode and notes
- page-level FAQ items (only when real and published)

## Operational / Outside-Backend Note

The known stale custom-domain issue on `theforestrystudio.com` remains operational and may require CDN/Shopify-host caching/routing intervention.

This backend release adds diagnostics and drift detection for that case, but does not assume backend-only changes can purge or override custom-host stale rendering behavior.

## Operator Checklist

1. Run `php artisan migrate`.
2. Run `php artisan modern-forestry:audit:domains`.
3. Check `/.well-known/brand-discovery.json` for:
   - wholesale path = `modernforestrywholesale.com`
   - retail path = `theforestrystudio.com`
   - South Carolina signals present
4. Fill remaining `placeholders` fields in tenant discovery profile/page rows.
5. Verify canonical routing intent:
   - retail pages canonical to retail domain
   - wholesale pages canonical to wholesale domain
6. Verify structured output:
   - no LocalBusiness without complete address
   - FAQPage only when FAQ items exist
7. Verify crawler accessibility:
   - robots does not block discovery-critical pages
   - key pages are not noindex/nosnippet by mistake
8. Re-run `php artisan modern-forestry:audit:domains` and confirm status is `ok` or acceptable `warning` state.
