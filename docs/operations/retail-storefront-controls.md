# Native retail storefront controls

This follow-on source adds shopper controls to the existing native Website lane.
It does not activate commerce, move Shopify/Modern Forestry authority, import a
catalog, change payment/rewards/stock adapters or establish live merchant acceptance.
The core trial/signup/publishing release is independent of this scope.

## Options, price and availability

Both native designs use the same server-rendered variant control. A product GET
may select `?variant=ID`; the ID must belong to that active product, tenant and
site. Without a selection the first sellable variant is chosen, falling back to
an unavailable option when nothing is sellable. Every existing supported variant
remains selectable, up to the catalog service's 100-variant limit. This does not
lower that limit or change the admin editor's existing add-control limit.

The ordinary select updates displayed price, compare-at price, availability,
quantity limit and cart action from escaped server-provided choices. No client
price or total is submitted. Without JavaScript, **View option** performs the
same product GET and renders the selected option before adding it. Selection
does not create an order, hold stock or contact a provider.

Cart POSTs resolve the original variant and its current owning product/site/tenant
on the server; the optional product ID from the new form must also match. Existing
callers using the original variant route remain compatible. Current availability,
quantity and the existing server-priced checkout/reservation boundary still apply.
The page's stock message is not a reservation or a guarantee against later sales.

## Collections and search

Public shop browsing uses existing active Website collections and their explicit
membership/position relation. Draft, archived, foreign and wrong-site collections
cannot be selected. The selector lists at most 100 active collections, retaining
a directly selected active collection outside that list. Results contain at most
24 products per page. Search is limited to 100 characters and matches product
title/description or an owned variant SKU; SQL wildcard characters are literal.
Pagination retains search and selected collection.

Sawyer's original category URLs and plural category metadata remain supported.
Those categories are not silently converted to the separate native collection
relationship or the admin's free-text category. Existing product handles, variant
IDs, customer records, order history and Sawyer branding/policies are retained.

## Admission and retained work

The included-address `everbranch_subdomain` choice remains quote-only. Direct
new-cart and new-checkout POSTs now enforce the same restriction as the product
GETs; Stripe and Shop Pay new starts share that checkout handler. Existing carts
are not cleared, and their review/quantity editing, original checkout resume and
cancellation remain on their existing recovery paths. No stock is released or
payment state changed by this address check.

The canonical guard admits only contact-form POSTs on externally managed custom
hosts, including direct custom hosts without the relay. The signed relay also
excludes shopper-account routes. Product options may be browsed there, but the
new controls and navigation offer contact guidance instead of cart/account
actions. Existing host/relay middleware continues to reject prohibited POSTs. See
`website-domain-self-service.md` for its route and signature boundary.

Quote products use the same custom-host restriction: they point to the site's
published contact page, a valid published business email, or direct-contact text
instead of rendering a quote POST that the host guard will reject. A missing or
unpublished contact page does not become a new broken link. Included-address
quote requests keep their established submission route.

Elsewhere, product options can remain visible while commerce is paused; cart
actions are hidden and the page explains how to contact the business. A ready
catalog does not imply payment readiness. Existing merchant, Connect, dedicated
signed webhook, tax, wallet, shipping and notification gates retain authority.

## Retail starter flow (isolated follow-on source)

The maker/apparel starters retain six private pages and relevant owned example
photographs. Their Home/Collections product sections resolve only the site's real
catalog. No products, prices, quantities, orders or provider connections are seeded.
An empty private section points back to **Your shop** on Website; the empty public
section is omitted. Product photos come from actual catalog records, with a clear
missing-photo state instead of substituting an industry photograph for an item.

The paid retail guide links products/photos, collection covers, private preview,
menu editing, payment setup and explicit publication. When an actual active product
exists, **Add Shop to menu** adds `/shop` to the ordinary versioned draft navigation.
It uses the existing autosave/CAS path; it never alters published navigation by
itself. Menu changes and new website content still require an explicit publish.
Existing saved Shop entries remain editable. Native storefront branding uses its
chosen snapshot, font and palette; Sawyer keeps its original design and public URLs.

Private catalog GET previews use owned numeric product IDs, draft settings and
explicit private URLs. They may show owned active/draft products and collection
status, including physical items hidden by a published quote-only choice. They
never expose cart, account, quote or payment POSTs, activate the catalog, refresh a
provider or publish a site. Query/search/collection choices remain tenant/site
scoped and bounded. Variant selection updates server-provided prices and stock
text even with no cart action; an enabled cart retains the same-origin action
fence. Private preview is not merchant acceptance.

`WebsiteStorefrontPresentation` is a config/DB-only projection of existing gates
and bounded real cards (eight products/eight collections, up to 100 owned variants
per product). Controllers retain all actual authority checks. The legacy quote-only
restriction is the exact published `settings.domain_choice` value, **not every
included hostname**: neutral starters do not currently copy setup.domain_choice
into that snapshot. Custom-host/relay write restrictions remain separate. This
source does not change either policy or enable shopper collection. Ordinary
settings/menu saves preserve an existing trusted `everbranch_subdomain` restriction
from the current owned draft, published snapshot or compatibility server state.
Client settings cannot add, remove or replace that restriction; neutral starters
remain neutral. No historical backfill or broader included-host restriction is added.

New durably paid self-service workspaces may reach owner/admin payment **setup**
only after the separately default-off `TENANT_PAYMENT_SELF_SERVICE_SETUP_ENABLED`
and existing setup/global gates are authorized. Legacy lists retain their original
behavior. Setup does not grant collection: merchant approval, account ownership,
verified callbacks, tax and existing checkout gates remain independent. Genuine
isolated Website merchant TEST payment/callback/replay/refund acceptance is still
required; the earlier Everbranch SaaS TEST purchase proves a different lane.

## Focused verification

`WebsiteRetailPresentationTest` creates a genuine disposable signed fake-provider
SaaS purchase through the normal fulfillment service, then checks an empty retail
starter, real uploaded product/collection photos, owned preview, deliberate draft
Shop navigation and publication. It checks exact host/quote-only semantics, bounded
cards and malformed/foreign membership exclusion without provider requests.
`WebsiteStorePreviewTest` and `SelfServiceMerchantSetupTest` cover private HTTP
admission, retained trial reading, actor isolation and setup-versus-collection gates.

`WebsiteRetailStorefrontTest` exercises both full rendered designs, non-first and
unavailable variants, all 100 choices, server-price freshness, quantity rejection,
foreign/product mismatch, active collection/search/pagination, literal wildcard
search, original Sawyer category URLs, paused commerce, retained included-address
carts and the actual signed relay write denial. It uses disposable fixtures with
stray provider requests prohibited.

`website-retail-controls.test.mjs` renders the actual shared Blade control and
loads shipped JS/CSS over a fictional loopback endpoint. Chromium and WebKit cover
both design hooks at 320, 390 and 1440 pixels, keyboard choice, live price/stock,
100 choices, quantity clamping, correct variant POST without price fields,
touch targets/overflow, no-JS fallback and gated browsing. Its POST recorder is
synthetic; the PHP suite proves actual application authorization and cart behavior.
The existing website-editor browser entrypoint imports it, preserving all editor
and history cases without changing CI workflows or thresholds.

The font regression renders the complete actual native public Blade layout as a
private preview. Phone/desktop Chromium and WebKit assert computed body/heading
fonts and valid CSS for neutral starters, native sans, explicit serif and Sawyer.
The sans stack avoids quoted family names inside escaped Blade style text;
otherwise `&quot;` makes `var(--font)` invalid and browsers fall back to Times.
Neutral starters reuse the existing Inter/system-sans token without adding a
font download/dependency. Explicit serif and legacy Sawyer fonts remain intact.

Reuse catalog, commerce safety, payment recovery, Sawyer and relay regression
suites. Passing source checks is not real payment, carrier, reward or deployment
proof. Merchant-specific original-source acceptance is still required.
