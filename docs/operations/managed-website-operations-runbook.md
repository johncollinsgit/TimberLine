# Managed Website Operations Runbook

## Preconditions

Managed Website remains disabled by default. Before enabling a tenant, verify
the canonical module catalog marks it as purchasable but default-disabled, the
tenant has verified/audited entitlement fulfilment, the tenant is deliberately
on the rollout allowlist, and all four rollback controls are operable.

The public-render gate controls registration of the public-site fallback at
application boot. Change it only through a normal Forge release/config reload;
never use a broad production cache clear as a substitute for a controlled
release.

Do not enable Modern Forestry as the first pilot or infer access from its
Shopify app, existing checkout, customer records, or provider connections.

## Safe operating model

- Editors work only inside their resolved tenant and write drafts. Publishing
  creates immutable snapshots and updates only that site's pointer/cache keys.
- Public rendering serves only immutable published snapshots for active,
  verified Managed Website hosts. Existing landlord, app, checkout, Shopify,
  customer-account, webhook, and public route ownership stays ahead of it.
- Forms remain tenant-scoped form submissions and do not update marketing
  audiences or invoke workflows. Website checkout creates only isolated
  `website_*` shoppers/orders after the commerce readiness gates pass.
- Website products/services use native Website checkout through Stripe Connect;
  existing Shopify, Square, and booking destinations are not fallback systems
  of record.

## Pilot checklist

1. Use an internal empty tenant, then one paid non-Modern-Forestry pilot on an
   Everbranch subdomain.
2. Confirm desktop/mobile preview, draft autosave, publish, rollback, SEO
   metadata, navigation, form receipt, and external CTA behavior.
3. Confirm unknown/unverified hosts fail closed and tenant users cannot access
   another tenant's site, page, version, form, or media IDs.
4. Run the rollback drill and attach its evidence before widening the allowlist.
5. Monitor publish failures, form failures, host-resolution failures, and gate
   changes without logging page form payloads, tokens, credentials, or checkout
   data.

## Escalation

For a suspected host, isolation, or content-security issue, follow
`managed-website-rollback-runbook.md` immediately. For code recovery, use the
Forge atomic-release runbook; direct deploy, destructive reset, broad cache
clear, and production data rewrite remain prohibited.
# Website Commerce readiness

Before enabling native Website checkout for a tenant, verify the global Website
Commerce gate, Website entitlement and editor allowlist, Stripe Connect account
(`ready`, charges and payouts enabled), signed Website webhook secret, Stripe
Connect webhook verification, and confirmed tax decision. Test a low-value
pickup order on an isolated pilot. Do not enable this path for Modern Forestry.

If payment confirmation or tax readiness is uncertain, leave catalog editing
available but keep checkout gated. Do not substitute a Shopify checkout or
modify legacy orders as a workaround.
