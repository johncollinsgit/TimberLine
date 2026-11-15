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

Retail starters still have their six contact-led pages. A default Shop entry for
new included/custom-relayed retail sites remains pending until the product policy
and those merchant-host acceptance gates explicitly support shopping. Do not add
a misleading Shop/cart link or widen rollout to clear that task.

## Focused verification

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
