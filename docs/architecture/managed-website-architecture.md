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
FAQ, and landing pages composed from approved hero, text/image, services,
testimonials, FAQ, form, and external CTA sections. It supports drafts,
preview, immutable published versions, rollback, navigation, basic SEO, and
tenant-scoped leads. It does not support arbitrary HTML/CSS/JavaScript,
embedded scripts, native checkout, catalog sync, blogging, memberships, or a
freeform drag-and-drop canvas.

Checkout and booking calls to action link to the tenant's external Shopify,
Square, Stripe, or booking destination. They do not create, copy, reconcile,
or process commerce records in Everbranch.

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
