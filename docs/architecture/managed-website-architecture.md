# Everbranch Managed Website Architecture

## Status

Approved implementation contract. The capability is not enabled for any
tenant until the product, security, and rollback gates in this document have
been implemented and verified.

## Product boundary

`managed_website` is a disabled-by-default, purchasable Everbranch module.
Its product name is **Everbranch Managed Website** and its tenant navigation
label is **Website**. The intended commercial offer is $99/month plus a $499
setup fee, but access may be granted only by the existing verified and audited
commercial-fulfilment path. A checkout screen, request, or agreement is never
itself an entitlement.

V1 is a structured small-business site editor: Home, Services, About, Contact,
FAQ, and landing pages composed from approved sections. It supports a separate
live editor, drafts, preview, immutable published versions, rollback,
navigation, basic SEO, tenant-scoped leads, and an isolated native Website
catalog/cart/checkout lane. It does not support arbitrary HTML/CSS/JavaScript,
embedded scripts, external catalog sync, blogging, memberships, or arbitrary
custom code.

Website checkout uses Stripe Connect only as the regulated payment processor;
Everbranch owns the Website catalog, order snapshot, inventory, and
fulfillment record in its dedicated `website_*` lane. Existing Shopify/Square
checkout destinations remain separate and are never used as a fallback.

## Compatibility boundary: Modern Forestry

Modern Forestry's separate Shopify app and Shopify Checkout are a protected,
explicit exclusion. Managed Website work must not alter or invoke their UI,
routes, app configuration, credentials, OAuth grants, checkout behavior,
customer account, webhooks, orders, order lines, customers, rewards, imports,
provider connections, or workflow cursors. Modern Forestry is not the first
pilot and receives no automatic entitlement, setup, billing, or content change.

Regression coverage must prove website actions create no writes to protected
Modern Forestry tables and make no calls to its embedded-app, checkout,
customer-account, or webhook endpoints.

## Data and public-serving contract

All storage is additive and tenant-owned: one site per tenant, pages, draft
content, immutable page/site versions, structured section payloads, navigation,
media references, redirects, and publish/audit events. Every model-bound
operation resolves tenant ownership server-side from the active membership; no
tenant, host, page, media, or version ID from the client establishes access.

Published pages are immutable snapshots. A publish changes only the relevant
site's published-version pointer and cache keys; rollback repoints to a prior
published snapshot. Neither operation destroys drafts, versions, tenant forms,
or unrelated data.

Initial public URLs use `<workspace>.theeverbranch.com`. The managed-site host
resolver runs only after existing landlord, authenticated app, Modern Forestry
Shopify app, Shopify Checkout, customer-account, webhook, and established
public route ownership has been resolved. Unknown, inactive, and unverified
hosts fail closed. Custom domains are deferred until the subdomain pilot and a
separate DNS/TLS operating review pass.

## Rollout controls

The implementation must expose four independent, fail-closed controls, all
audited and available to operators without a destructive migration:

1. **Global availability gate** blocks new Managed Website purchase and
   entitlement fulfilment.
2. **Tenant rollout allowlist** removes a particular tenant from editor access
   without changing its stored content.
3. **Editor and publishing gate** freezes writes and publication while the last
   good published snapshot remains public.
4. **Public-render gate** disables only Managed Website hosts and serves a
   neutral maintenance response for a host-routing, isolation, or content
   security incident.

No control may alter existing Shopify, Square, workflow, customer, or Modern
Forestry application behavior. Controls must default closed until their
corresponding readiness evidence exists.

## Required rollout evidence

Release order is internal empty tenant, paid pilot on an Everbranch subdomain,
monitored canary, entitled general availability, then a separately approved
custom-domain pilot. Before general availability, perform the staged rollback
drill in `docs/operations/managed-website-rollback-runbook.md`, retain its
evidence, and verify `/up`, `/ready`, and the protected Modern Forestry smoke
paths before and after the drill.

Workflow Automations remains independent: `/workflows`, the v2 Studio, legacy
runner, connections, and legacy promotion path retain their existing contracts.
This capability must not widen workflow provider access or cause a website form
to trigger workflow, messaging, marketing, or customer creation in V1.
# Website Commerce extension (2026-07-27)

Website Commerce is an isolated native lane. `website_products`, variants,
inventory records, carts, shoppers, Website orders, payments, fulfillments,
and Stripe event receipts are tenant-owned and never share a model, migration,
query, or webhook path with legacy `orders`, `order_lines`, Shopify catalog,
Shopify checkout, or marketing customer data. A Website order is therefore not
a Modern Forestry order by another name.

The full-screen Website editor persists only ordinary immutable draft page
versions through `ManagedWebsiteService`; publish still copies draft snapshots
into immutable published versions. The editor may be frozen without taking the
last published page offline.

## Sales-channel reporting boundary

`SalesChannelSummaryService` is the only cross-channel seam introduced for
Website Commerce. It reads tenant-scoped aggregate summaries from independent
sources: existing legacy/provider orders remain in `orders`, while native
Website revenue comes only from confirmed `website_orders.paid_at` records.
The service does not join order rows, copy records, resolve customer identity,
call a provider, or write to either lane. This gives a multi-channel merchant a
single sales view without changing Modern Forestry's Shopify app or checkout.

## Theme snapshots, media, and preview (2026-07-27)

`tenant_site_versions` owns immutable site-wide theme settings, header/footer,
navigation, announcement, SEO defaults, source manifest, and thumbnail
reference. `tenant_sites.draft_site_version_id` and
`published_site_version_id` are pointers only. Publishing copies the current
draft snapshot before moving the published pointer, so global theme edits cannot
leak into a live site ahead of Publish.

`tenant_site_media` is a tenant-owned library for public website images. Files
are type/size validated and ownership is resolved server-side. It must never be
used as a route to field-service, customer, job, or workspace media.

The editor preview is `no-store, private` and uses the same renderer with draft
page plus draft site snapshots. Until a screenshot runtime is explicitly
provisioned, the Website overview shows a real framed draft preview rather than
a synthetic thumbnail.
