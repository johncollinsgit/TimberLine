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
- Forms remain tenant-scoped form submissions. V1 does not create customers,
  send messages, update marketing audiences, or invoke workflows.
- External CTA links may open a tenant-approved Shopify, Square, Stripe, or
  booking destination, but do not send order data back to Everbranch.

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
