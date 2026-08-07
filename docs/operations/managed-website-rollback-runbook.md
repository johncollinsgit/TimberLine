# Managed Website Rollback Runbook

## Purpose and scope

Use this runbook for a Managed Website incident. The target is to contain the
website capability without touching core Everbranch services or Modern
Forestry's separate Shopify app and Shopify Checkout. Do not delete website
versions, forms, OAuth grants, workflow history, customer data, or unrelated
tenant data during an incident.

## Immediate containment

1. Record the incident time, affected tenant/host, active release ID, and
   operator in the incident record. Do not include raw customer form content,
   credentials, tokens, or checkout data.
2. Disable the editor and publishing gate. This stops new drafts, edits, and
   publishes while preserving the current public snapshot for ordinary product
   defects.
3. Remove the affected tenant from the rollout allowlist. For a widespread
   issue, disable the global availability gate to stop new purchase and
   entitlement fulfilment.
4. If host routing, tenant isolation, content sanitization, or public exposure
   is suspect, disable the public-render gate for Managed Website hosts. Serve
   the neutral maintenance response only there; do not alter the app, landlord,
   Modern Forestry Shopify app, Shopify Checkout, customer-account, webhook,
   or other public host behavior.
5. For a custom-domain-only incident, disable that `tenant_site_domains`
   record first. This takes only the affected external hostname offline and
   leaves the immutable published snapshot available on its Everbranch
   subdomain. Do not delete the domain proof, page versions, forms, or DNS
   records while investigating.

## Recovery decision

- For an editor or publication defect, keep the last known-good snapshot live
  and repair in staging. Re-enable the single tenant only after confirmation.
- For a bad published page, repoint that tenant's published-version pointer to
  its prior immutable version. Do not overwrite or delete version history.
- For a code or schema compatibility defect, use the retained prior Forge
  release through the standard atomic-release procedure. Migrations must be
  additive and backward-compatible; never restore unrelated data or run a
  destructive schema reversal during emergency containment.
- For a suspected isolation/security issue, keep public rendering disabled
  until a tenant-boundary review, audit review, and staging proof are complete.

## Verification before re-enable

1. Confirm the applicable gate is still closed for every non-test tenant.
2. Check `https://app.theeverbranch.com/up` and `/ready` and record the release
   ID.
3. Smoke the landlord app, normal tenant switching, and a non-mutating core
   Everbranch page.
4. Smoke Modern Forestry's existing Shopify embedded app, Shopify Checkout,
   and customer-account paths without creating an order or changing customer
   data. Compare protected-table counts/checksums captured before containment;
   Managed Website operations must have made no changes.
5. On a staging pilot only, verify the last known-good public website,
   published pointer, access isolation, form handling, and external CTA link.
6. Re-enable one tested tenant first, then the editor/publishing gate, then
   global availability only if the commercial path was affected. Each action is
   explicit and audited.

## Mandatory staged rollback drill

Run before general availability and after any material change to host routing,
publishing, rollout controls, or public rendering:

1. Use an internal non-customer pilot tenant with a known published snapshot.
2. Freeze its editor/publishing gate and prove its public snapshot remains
   available.
3. Disable its Managed Website public host and prove a neutral maintenance
   response appears only for that host.
4. Verify `/up`, `/ready`, landlord access, normal tenant access, and Modern
   Forestry Shopify app, Checkout, and customer-account smoke paths remain
   healthy.
5. Re-enable only the test tenant and confirm its expected snapshot returns.
6. Capture timestamps, release ID, gate state, smoke evidence, and any issue
   in the release record. A failed drill blocks general availability.

## Follow-up

Keep the gates conservative until root cause, impact, and remediation are
reviewed. Billing refunds, entitlement revocation, and customer communication
are separate explicit, audited operator decisions; rollback itself does not
automatically perform them.
# Website Commerce rollback

Disable `MANAGED_WEBSITE_COMMERCE_ENABLED` first to stop new Website checkout
sessions. This does not delete carts, Website orders, Stripe receipts, or the
last public page. For an isolation/security incident, also disable public
Managed Website rendering; do not alter Shopify, Modern Forestry, legacy
orders, customers, rewards, or provider connections. Re-enable only the tested
tenant after the ordinary Forge release and readiness checks pass.

For a native shipping incident, also disable the shipping feature gate or
remove only the affected tenant from its shipping allowlist. Preserve native
shipment and event evidence, then verify no EasyPost request targeted a Modern
Forestry account. Do not void, create, or edit a Shopify/Modern Forestry
shipment as part of the rollback.

For a connected-import incident, disable the imports gate or remove the
affected tenant from its import allowlist. Pause the import run, preserve its
mapping and audit events, and verify no record was written outside the
read-only commerce lane. Do not delete source snapshots until incident evidence
and the tenant retention policy have been reviewed.
