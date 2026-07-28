# Managed Website Operations Runbook

## Preconditions

Managed Website remains disabled by default. Before enabling a tenant, verify
the canonical module catalog marks it as purchasable but default-disabled, the
tenant has verified/audited entitlement fulfilment, the tenant is deliberately
on the rollout allowlist, and all four rollback controls are operable.

The public-render gate controls whether the final custom-domain-only fallback
may render a published site. The fallback itself remains registered so unknown
application requests retain Laravel's normal 404 behavior rather than becoming
405 responses. Change the gate only through a normal Forge release/config
reload; never use a broad production cache clear as a substitute for a
controlled release.

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

## Custom-domain pilot

1. Confirm the site is already a tested, published non-Modern-Forestry pilot
   and that the canonical Everbranch public home still renders the platform
   page, not the pilot theme.
2. Enable only the custom-domain global gate and that tenant's allowlist. The
   separate activation gate stays off while the customer completes the Website
   wizard's TXT ownership proof.
3. Give the customer the generated `_everbranch-verify.<domain>` TXT name and
   `everbranch-site=<token>` value. Never collect a registrar password or
   replace existing MX, SPF, DKIM, or unrelated website records.
4. Verify the TXT record using the wizard, then complete the DNS, edge/TLS,
   and origin-host acceptance checks from the release record. Confirm the host
   returns only the intended tenant's immutable published snapshot.
5. Enable the one-time activation gate only for the reviewed cutover, activate
   the domain, smoke desktop/mobile, lead form, `/up`, `/ready`, landlord,
   normal tenant access, and the protected Modern Forestry Shopify/account/
   checkout paths. Disable the activation gate again after the pilot.

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

# Sales-channel reporting

Sales channels is read-only operational reporting. Confirm that a Website order
has `payment_status=paid` and `paid_at` before expecting it to appear. A missing
or delayed Website sale is a Website payment/webhook investigation; do not add
or edit a legacy Shopify order to repair the report. Likewise, a source-channel
discrepancy is resolved in that source system, not by merging orders or shoppers
in Everbranch.

## Theme, preview, and media checks

Before approving a theme, verify its authenticated draft preview shows the
expected navigation, footer, announcement, and page content. It is `no-store`
and not a public preview URL. Use only public-site-safe images in the Website
media library; customer, field-service, job, employee, and provider-export
media are excluded.

To prepare the Collins draft without publishing it, run:

`php artisan everbranch:prepare-managed-website-theme collins-electric collins-electric`

Confirm the output says `published=no`. Entitlement, editor access, and Publish
remain separate audited controls.
