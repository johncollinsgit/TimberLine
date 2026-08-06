# Modern Forestry Production Guard

## Audit baseline and active remediation — 2026-08-06

This document records a read-only production audit of the Modern Forestry
tenant. It is an operational baseline for future Everbranch work, not an
authorization to mutate Shopify, rewards, customers, subscriptions, or
production configuration.

| Area | Verified state |
| --- | --- |
| Everbranch | `/up` and `/ready` returned HTTP 200; `/ready` reported release `9e019818d6345ce0241b0e41c46600571bdf4f89`. |
| Storefronts | `theforestrystudio.com` and `modernforestrywholesale.com`, including a basic candle search, returned HTTP 200. |
| Shopify imports | Both retail and wholesale stores are installed and had successful order imports at 18:00 EDT; the 18:14 EDT read-only health check reported both healthy. |
| Candle Cash | 9,003 legacy candidates were tagged, zero required correction, and zero balance-table/ledger mismatches were found. |
| Birthdays | 2,891 preserved birthday profiles were present (2,847 with full dates); 466 birthday rewards were claimed and 21 redeemed. |
| Candle Club mirror | One active subscriber/Candle Club contract and one upcoming order were present; no failed billing attempts were recorded. |
| Staff embedded apps | **Broken during this audit.** Fresh launches from both Shopify Admin installations returned `invalid_hmac`; this is separate from storefront and import health. |

## Finding: embedded Shopify staff access is broken

The visual Shopify Admin check found both **Modern Forestry Backstage** and
**MF Wholesale Backstage** rejecting a fresh, signed embedded launch.

- Retail's Developer Dashboard secret matches production. Shopify now includes
  `admin_theme=admin` in the signed launch payload, but
  `ShopifyEmbeddedContextQuery` omitted it before the HMAC check. This is a
  source regression caused by the overly narrow allowlist introduced in
  `a73a79b3` (**Preserve Shopify embedded customer context**, 2026-03-18).
  It is not a Candle Club, birthday, points, import, or storefront-search
  regression.
- Wholesale has the same omitted-parameter defect and its current Developer
  Dashboard secret does **not** match the secret configured in production.
  The retained production releases all contain the same stale value, so no
  recent Everbranch feature deployment changed it. The wholesale app secret
  was created on 2026-06-29; its first released app version was 2026-06-30.
  The provisioning change `269d3350` (**Fix wholesale embedded app
  bootstrap**, 2026-06-30) introduced the new wholesale application
  configuration but did not result in the corresponding production secret
  replacement.

The source repair retains `admin_theme` for HMAC verification and also checks
the complete scalar Shopify launch query, so a future signed Shopify metadata
field will not be dropped by the compact navigation-context allowlist. It has
regression tests for Shopify's current and a future launch shape. The
production cutover still requires: (1) release that repair, (2) replace only
`SHOPIFY_WHOLESALE_CLIENT_SECRET` with the existing Developer Dashboard value
through the approved secret-management path, and (3) manually open each app
from its own Shopify Admin installation and confirm an authorized dashboard.
Never print, commit, or place either client secret in documentation.

## Finding: Candle Club is intentionally pre-cutover

The production Candle Club workspace is not broken by later Everbranch work.
It was introduced by `49ec59d3` (**Add Shopify subscriptions module
foundation**, 2026-07-02) as a guarded migration/workspace foundation and was
extended by `6d664fd1` for mobile voting/reviewer access. Its original contract
is still in force:

- Modern Forestry is entitled to use the subscription mirror and staff UI.
- There is no `tenant_module_states` row marking subscriptions configured.
- `subscription_module_settings` is `setup` for the retail store and its
  billing scheduler is disabled.
- There is no approved subscription migration batch, active Candle Club poll,
  or monthly scent schedule.
- Admin and customer pause, cancel, swap, address, billing, and similar
  requests are audit records (`intent_recorded`), not Shopify subscription
  mutations.

The visible active contract therefore remains safe to inspect, but live
subscription servicing has not been switched over to Everbranch. Marking the
module configured or enabling its scheduler without a reviewed Shopify/Recharge
cutover would be unsafe and is not a corrective action.

## Required development guard

For a change that could affect Modern Forestry retail/wholesale Shopify,
Candle Cash, birthdays, rewards/redemption, customer account/mobile, or
embedded search/navigation, run:

```bash
composer test:modern-forestry
```

It covers the Candle Cash lifecycle and legacy compatibility, birthday import
and Shopify reward synchronization, reward redemption, both store imports and
health, mobile rewards recovery, and strict retail/wholesale embedded-surface
isolation, including the signed `admin_theme` Shopify launch regression. GitHub Actions runs the complete Pest suite before its Forge atomic
production release, so these tests are also a required production deployment
gate.

The cross-surface boundary is enforced by
`EnforceShopifyEmbeddedSurface`: verified wholesale sessions redirect from
retail HTML and receive `403` for retail APIs/mutations; retail and mixed
stores cannot open wholesale operations. Managed Website work is separately
forbidden from calling or changing Modern Forestry Shopify, Checkout, customer,
rewards, import, connection, or webhook lanes.

## Read-only production checks

Run these only when a production status check is needed. They do not import,
reconcile, reset, or modify data:

```bash
php artisan shopify:import-health --no-record --stale-after=90
php artisan marketing:candle-cash-compatibility-readiness --json
php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=0
```

Do not use a local `SHOPIFY_ADMIN_TOKEN` as production proof: local developer
tokens can expire independently. The installed production store credentials and
per-store import health are the operational evidence for live Shopify access.
