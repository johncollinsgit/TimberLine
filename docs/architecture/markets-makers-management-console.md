# Markets & Makers management console

## Source and capability map (2026-10-20 candidate)

The reviewed baseline is `29703b4c`; this candidate is stacked on the separate
onboarding/settings candidate without changing its source tip. The current user
brief supersedes the older tablet-showcase appearance direction for management.

- **Intention:** maker discovery, native online goods, real markets and eventually
  live selling. Everbranch is the retailer; makers are suppliers. The default 15%
  fee and immutable accepted terms remain authoritative. Supplier bank transfers
  happen outside the app and are reviewed/recorded, never automatic payouts.
- **Implemented:** approved makers, native listings/revisions, allocated stock,
  original-provider checkout/refunds, shipping and buyer-code pickup, event
  participation, reporting, supplier statements and shared storefront links.
  `MarketplaceAccess`, middleware and existing commands remain authoritative.
- **Entry points:** `/marketplace/preview` is account-allowlisted and fictional;
  `/marketplace/operations?tab=…` is dedicated merchant owner/admin management;
  the verified public host's `/maker/{id}` uses the separate maker identity and
  membership. Public shopping retains `marketplace.layout` and checkout.
- **Design primitives:** Teams' 244px forest sidebar, compact white header,
  paper canvas, quiet rows and focused details. Marketplace uses a small scoped
  presentation shell, not the Teams graph, runtime or tenant shell. Preview is
  passed static view data, never tenant context or operational models.
- **Bounds:** directory searches cover loaded records, not the whole database.
  Preserve the latest-2,000 matching order report, Eastern dates, live/test and
  refund distinctions. Pickup and attention queues have their own stated bounds.
- **Planned:** no implemented livestream workflow was found in Marketplace
  source/routes/services. A future session would reference approved makers,
  approved published products and optionally a market event; any resulting
  checkout/order would keep the native stock, immutable terms and payment gates.
  Video, chat, stream scheduling and stream purchasing are not working features.

## Authorization and presentation contract

The console layout accepts explicit role/context/navigation data. It does not
resolve tenants, stores, providers, module entitlements or maker memberships.
The account preview continues to reject tenant/store selectors, require the
exact allowlisted active verified account and use no-store/noindex responses.
It has no forms or mutation controls. Fictional rows are linked consistently
across tabs; search, filters and details operate only on those in-memory rows.

Operator and maker pages retain their existing authorized commands, CSRF,
UUIDs, acceptance, stock, review-hold and original-provider semantics. A maker
continues to work without a staff tenant or paid Everbranch subscription. The
public shopping shell and Teams styles are unchanged. No new schema, provider,
commercial rate, rollout activation or payment action is part of this redesign.

## Current interaction contract

The preview uses existing tab values and static related examples. Search/status/
detail are bounded scalar UI parameters; selection and filters round-trip in the
URL. Real directory filters explicitly describe their loaded subset. Native
`details` remain usable without JS, with enhanced single-record selection and
history. Maker hash links retain their original destinations and form redirects
inherit an explicit section fragment without changing command paths or fields.

The management asset is a separate Vite entry. Public Marketplace CSS/layout and
Teams styles/engine are unchanged. Existing merchant branding is read without
calling the profile initializer; confirmed specialty comes from the canonical
business context. Preview never invokes either real-context adapter.

A dedicated loopback-only rendered-fixture router supports cross-browser QA with
no Laravel bootstrap, data store or write. It is not an application route or a
production identity. Both CI and full deployment gates must export the opt-in
Marketplace fixtures before running `playwright.marketplace.config.ts`; retain
all guided-settings, voice and mandatory recovery checks during integration.
