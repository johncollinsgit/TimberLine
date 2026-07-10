# Mobile Catalog Services

## Everbranch Contract v2

`TenantMobileMessagingService` owns tenant-wide conversation discovery and delegates delivery, consent, suppression, metering, and App-thread behavior to the established messaging services. `TenantMobileResourceService` owns customer details and server-resolved order/job/client Work payloads. `TenantMobileLandlordService` is guarded by `MobileLandlordAccessService` and exposes audited, non-destructive triage only.

New mobile work uses Branch in presentation copy while retaining canonical module keys. A catalog entry is visible only when entitled and `mobile.status=ready|beta`; summary-only, placeholder, roadmap, unsafe, or unscoped entries fail closed. Contract v2 returns `branches` and temporarily retains the v1 `modules` alias.

Trade-profile Home metrics resolve from the tenant blueprint and tenant-scoped field jobs: jobs with `status=in_progress`, gross/contract value in job metadata, distinct assigned crew keys or users, and potential/estimate/quoted pipeline stages. The mobile Branches catalog supplies a purpose-specific icon for every visible entry, counts every non-active growth path, and exposes product/setup language for owned as well as available Branches.

Tenant bootstrap also returns a tenant-scoped `branding` block and `workspace_insights`. Branding resolves from `TenantDiscoveryProfile`, falls back to the tenant name, and uses the published Modern Forestry wordmark for tenant 1 until a profile logo is configured. Only the workspace `admin` role may update `/workspaces/{tenant}/branding`; updates are written to the discovery profile and audited. Normal tenant Home receives only its own users, active users, ready Branches, and 30-day work activity.

Landlord mode remains a distinct authorization context rather than a tenant membership. An authenticated authorized operator may switch between tenant and landlord payloads without exchanging a second token. Landlord bootstrap includes catalog-derived MRR, tenant/user/activity counts, tenant-type distribution, 12-month cumulative tenant growth, recent audited activity, access/support queues, and tenant drill-down with users and Branch readiness. The configured Everbranch support operator email is the fail-safe operator-email default when `TENANCY_LANDLORD_OPERATOR_EMAILS` is blank; role authorization still applies.

Tenant support is a core mobile service, not a paid Branch. `/workspaces/{tenant}/support-tickets` lets authenticated members create, list, open, and reply to tenant-scoped threads. `/landlord/tickets` is separately landlord-authorized and supports queue filters, assignment, replies, waiting/resolved states, and audited triage. Landlord mobile navigation is Home, Tenants, Tickets, Reports, and Account; tenant Branches and Work controls must never leak into it.

## Everbranch Tenant App Contract (2026-07-10)

This folder now owns two deliberately separate surfaces. The `ModernForestryMobile*` services feed the Modern Forestry customer shopping app. `TenantMobileModuleRegistry` feeds the cross-tenant Everbranch work app at `../everbranch-mobile`; do not reuse customer commerce session assumptions for tenant users.

Everbranch mobile entries fail closed. A module must be enabled by `TenantModuleAccessResolver` and declare `mobile.status` as `ready` or `beta` in `config/module_catalog.php`. Its declaration includes renderer, entry screen, contract version, minimum app version, navigation placement/icon, supported actions, and a stable purchase key when billable. Branches additionally requires `visibility.mobile_store=true`.

The registry accepts finite, versioned primitives only: dashboard, metrics, list/search, detail, form, action sheet, tabs, notice, empty, and error states. Providers may supply data/layout, never executable JavaScript or arbitrary remote UI. Existing renderer contracts can publish newly entitled modules without a binary release; new primitives require a client release and higher `min_app_version`.

Every action must validate that its action key is declared, resolve all resource IDs inside the current tenant, enforce the module entitlement and role, require a live connection, and return an explicit success/error. Never silently queue operational, customer, or billing writes.

The full addition and release checklist is in `docs/architecture/everbranch-mobile-platform.md` and the client `../everbranch-mobile/README.md`.

## Mobile Checkout + Home Bootstrap Performance (2026-06-29)

- Modern Forestry mobile checkout uses Shopify Storefront Cart API when the storefront access token is configured. The checkout service validates bag lines against Laravel product detail, creates a Shopify cart, applies buyer identity, attaches delivery address data when available, and returns Shopify `checkoutUrl`.
- Anonymous checkout is supported. Signed-in checkout should be treated as an enhancement that depends on a fresh backend/customer session.
- Shopify buyer identity phone handling is intentionally strict: only send customer phone when it can be normalized to E.164. Invalid or non-normalizable local phone values must be omitted so Shopify does not reject the cart with `Phone is invalid`.
- The app may appear signed in while backend/customer tokens are stale. Validate or refresh the mobile session before signed-in checkout, Candle Cash, or account-linked bag behavior.
- Home remains the heaviest mobile endpoint on cold cache. The service now returns a local Home shell immediately on a true cold cache, keeps stale Home payloads usable, and defers the full Shopify-backed refresh after the response.
- Product detail payloads are short-cached per handle for repeat access. Product detail still depends on Shopify Admin GraphQL for cold or expired cache lookups.
- Known next work: make the iOS bootstrap fully tolerate stale-while-revalidate Home behavior so bag, product, and shop screens do not feel blocked by Home timing.
- Deploy caution: GitHub Actions production deploy can fail during `vite build` with exit code 137 under memory pressure. Backend-only PHP fixes may be recovered with an SSH deploy that checks out latest `main`, runs Composer, migrations, `optimize:clear`, config/route/view cache rebuilds, `queue:restart`, and nginx/php-fpm reload when available. A failed asset build can leave the API feeling degraded or inconsistent until caches/processes are reset.

## Seasonal Cache + Mobile Shop Responsiveness (2026-06-26)

- `ModernForestryMobileProductCatalogService.php` now short-caches the most expensive seasonal catalog payloads:
  - canonical collections
  - seasonal collection nodes
  - collection product payloads
  - home payload assembly
- Goal: keep the iPhone app from re-paying the full Shopify GraphQL cost every time a customer reopens Bundles, Fall, Spring, or Home in a short window.
- The cache window is intentionally short so Shopify image/product changes still flow through without a long stale period.
- The iOS client also added in-session collection reuse and collection prewarming, but the source-of-truth performance win starts here on the Laravel side.

This folder owns the Laravel-side mobile catalog source of truth for the Modern Forestry iOS app.

## Variant Media Sync Notes (2026-06-23)

- The canonical Shopify media sync is `php artisan shopify:sync-modern-forestry-variant-media`.
- Use `--transport=cli` when you want the Shopify CLI-backed admin session, or keep the default `--transport=admin-token` for the installed-store Admin token path.
- The command now knows about `wood_wick_8oz` and `wood_wick_16oz`, and it maps those to `/Users/johncollins/Downloads/Wood Wick.png`.
- `wax melt`, `wax melts`, `melt`, `melts`, `wax tart`, `wax tarts`, `soy tart`, and `soy tarts` all normalize to `wax_melt`.
- If Shopify says media are not ready yet, the command polls for readiness before attaching them to variants and skips variants that are already linked.
- The live catalog response for Coffeehouse now proves the canonical image attachments exist on Shopify, including the wood-wick assets and wax-melt assets.
- Future edits should keep the variant payload fields stable because the iOS product page now depends on `imageUrl`, `compareAtPrice`, `available`, and `selectedOptions` to feel like a real shopping app.

## Home Payload + Session Contract (2026-06-21)

- `ModernForestryMobileProductCatalogService.php` now extends the mobile home payload with:
  - a `brand` block
  - hero slideshow metadata resolved from the live theme snapshot in `modernforestry-live-theme/templates/index.json`
  - the same featured collections and featured products contract used by the native app
- Collection detail queries now include collection image data so Shop headers and collection cards can stay in sync more reliably.
- `ModernForestryProductCatalogController.php` now exposes a lightweight `/api/mobile/v1/modern-forestry/session-status` endpoint for future mobile session checks.
- The fake-catalog tests were updated so the new slideshow/session contract stays covered.

## Native Hero + Routing Follow-up (2026-06-21)

- No new Laravel contract was required for the native-home portrait/routing pass in the iOS app.
- The existing slide URL fields remain the routing source of truth:
  - `ctaUrl`
  - `secondaryCtaUrl`
- The Swift client now interprets those URLs into native destinations on-device, so changes to hero behavior should usually happen in `modernforestry-build/` unless the slide content itself needs to change here.
- Keep this service focused on payload quality and stable storefront URLs; do not move native tab-routing logic into Laravel.

## What lives here

- `ModernForestryMobileProductCatalogService.php`: builds the collection-first catalog payload for the phone app.
- Related mobile catalog helpers and tests that keep the iOS Shop tab fed with curated collection data.

## What this folder does

- Resolves collections for the iOS app.
- Prefers collection artwork first, then best-selling product imagery when Shopify omits collection art.
- Keeps the mobile app focused on rendering and navigation instead of catalog logic.

## What this folder does not do

- It does not own the iOS UI.
- It does not replace Shopify as the commerce platform.
- It does not implement checkout or storefront web-session persistence.
- It does not own the native app shell, but it now serves native account, rewards, and support payloads for the phone app.
- It does not implement the Shopify storefront theme or Infinite Options settings that actually render bundle selectors on the website.

## Current mobile-pass notes

- The latest pass tightened image fallback so phone shelves stay polished even when Shopify collection art is missing.
- The mobile catalog remains collection-first so the app can open into the seasonal shop experience instead of a raw product feed.
- Home is now the first mobile surface that should be extended when brand, slideshow, or featured shelf content changes.
- Future catalog changes should start here before duplicating logic in the app.
- Bundle products are exact-count flows. `config/shopify_bundles.php` defines how many scent selections each bundle must carry, and the order ingestor rejects bundle lines that have too few or too many scent choices.
- The mobile account payload now includes:
  - native wishlist data from the Laravel wishlist system
  - lightweight notification preferences
  - per-customer insights used by the app's account and home personalization screens
- The mobile controller now exposes native wishlist and scent endpoints for the app:
  - `/api/mobile/v1/modern-forestry/wishlist/status`
  - `/api/mobile/v1/modern-forestry/wishlist/add`
  - `/api/mobile/v1/modern-forestry/wishlist/remove`
  - `/api/mobile/v1/modern-forestry/scents`
- Product-detail bundle payloads now include the required scent count plus the active scent list, so the SwiftUI bundle builder can enforce exact selections before add-to-bag or checkout.
- Checkout now preserves bundle scent choices as Shopify line attributes so the importer and Shopify order notes can retain the customer's selections.

## Catalog + Rewards Cleanup (2026-06-22)

- `ModernForestryMobileProductCatalogService.php` now returns canonical seasonal collections explicitly instead of relying on “first 4 collections.”
- Seasonal collection payloads now resolve in this order:
  - collection image
  - first active product image inside that collection
  - stable seasonal fallback image
- Collection-product payloads now:
  - filter inactive products out of the mobile response
  - accept a `sort` query parameter
  - support exactly `best_selling`, `newest`, `price_low_to_high`, and `price_high_to_low`
- Home featured products now rank by imported Modern Forestry order-line purchase quantity first, then use Shopify best-selling only as a fallback if fewer than six active, published, available products survive.
- Product summary payloads now expose `variantId` for the first available Shopify variant so Home, Shop, and collection cards can feed native bag/checkout flows safely.
- Mobile checkout is guest-capable; signed-in customer identity is optional and is attached only when the app has a valid Customer Account token.
- Customer Account OAuth public config lives at `/auth/config`, and token exchange lives behind Laravel at `/auth/token` so stores that require a confidential Customer Account client secret do not expose that secret in iOS. `/auth/token` validates the exchanged token against Customer Account GraphQL before the app treats the customer as signed in.
- The Customer Account flow now prefers Shopify discovery documents from `https://theforestrystudio.com/.well-known/openid-configuration` and `/.well-known/customer-account-api` when resolving auth/token/graphql endpoints in live environments.
- Shopify Customer Account OAuth is tied to the Shopify app config, not just the store's customer-account settings. Keep the mobile callback in `shopify.app.toml` under `[customer_authentication]`, release it with `shopify app deploy`, and make sure `SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_ID` and `SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_SECRET` are from the same released app. A mismatched pair returns Shopify `invalid_client` from `/oauth/token`.
- The Shopify token endpoint advertises `client_secret_basic`, but the live exchange still requires `client_id` in the form body. Keep both Basic Auth and body `client_id`; removing the body field causes the phone to flash through OAuth and then show “Shopify could not finish sign-in.”
- Customer Account GraphQL validates with the raw Customer Account access token in the `Authorization` header, not `Bearer <token>`. A Bearer-prefixed header returns `Invalid token, missing prefix shcat_` and the app shows “Shopify sign-in finished, but the customer session could not be verified.”
- Keep the env values in place as fallbacks, but treat discovery drift first when login says Shopify sign-in completed and session verification failed.
- If this error shows up again, inspect:
  - the live `.well-known` token + graph endpoints
  - whether `shopify.app.toml` includes the mobile `[customer_authentication]` callback and the app version has been released
  - whether the Customer Account client ID and secret are a matching Shopify app pair
  - whether Laravel has `SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_SECRET`
  - whether the returned Shopify customer identity can resolve to a tenant-1 `MarketingProfile`
- Product-detail lookups now fall back to a paginated active-catalog search when the exact handle misses or resolves to a non-customer-visible node.
- Product-detail payloads now include:
  - `mobileSummary`
  - `faq`
- Candle Club-style subscription FAQ content is currently derived in Laravel so the iOS app can place it directly below the selector without rebuilding reward logic client-side.
- Storefront URLs in this service now normalize to `https://theforestrystudio.com`, which is the resolvable customer storefront host the iOS app should use for auth, rewards, product links, and collection links.
- The mobile feature test file now covers:
  - explicit seasonal collections
  - active-only collection products
  - sorting
  - order-history featured product ordering
  - product-detail fallback lookup
  - Candle Club FAQ exposure

## Shopify Embedded App Content Bridge (2026-06-23)

- The native Home payload now reads published App Content from the Shopify embedded `Edit App` page before falling back to defaults.
- The `Edit App` page includes a Shopify-admin-side phone preview that uses draft Mobile Home fields plus live mobile Home API shelves for visual editing.
- Editable mobile fields currently include:
  - Home hero eyebrow, title, and subtitle
  - three hero slide titles, subtitles, image URLs, phone-crop URLs, button labels, and button URLs
- Draft content stays private until published; the native app only reads the published/effective snapshot.
- Product images, collection images, product availability, variants, sorting, and checkout purchaseability still come from Shopify-backed catalog/checkout services.
- Headless is not just an order route: Customer Account OAuth and Storefront checkout use Shopify customer-facing APIs, while catalog/media curation remains Laravel-owned via Shopify Admin GraphQL.

## Product Detail Variant Media + Pricing (2026-06-23)

- Product-detail variant payloads now include:
  - `imageUrl`
  - `compareAtPrice`
  - `available`
  - `selectedOptions`
- The mobile catalog queries Shopify Admin variant `media`, `selectedOptions`, `price`, `compareAtPrice`, and `availableForSale` so the iOS product page can update image, price, and purchaseability when a customer changes variants.
- `php artisan shopify:sync-modern-forestry-variant-media` audits and optionally attaches canonical size/form imagery to matching variants:
  - default mode is dry-run
  - `--apply` performs live Shopify media uploads and variant-media appends
  - `--image-dir` defaults to `/Users/johncollins/Downloads`
  - canonical files are `4oz.png`, `8oz.png`, `16oz.png`, and `Wax Melt.png`
- Variant title normalization treats `4oz`/`4 oz`, `8oz`/`8 oz`, `16oz`/`16 oz`, and `wax melt`/`wax melts`/`melts`/`wax tarts`/`soy tarts` as canonical matches.
- The command only adds missing canonical media. It does not delete or replace existing Shopify product or variant media.
- Local Shopify CLI was repaired by reinstalling Homebrew Node; `shopify app info` now works for `info@theforestrystudio.com`. The local Laravel retail Admin token currently returns Shopify `401 Invalid API key or access token`, so live command dry-runs require a refreshed `SHOPIFY_RETAIL_ACCESS_TOKEN`/installed-store token before using the Laravel command against Shopify.
- `shopify app execute --store modernforestry.myshopify.com --version 2026-01` was verified with a read-only query against live products. It confirmed live variants such as `16oz Cotton Wick`, `8oz Cotton Wick`, and `4oz Cotton Wick` currently have empty `variant.media.nodes`, so the media attachment problem is real on Shopify data, not an iOS rendering issue.
- Future agents should not spend time re-proving the GraphQL shape: Admin variant media reads work with `variants { nodes { selectedOptions { name value } media(first: 1) { nodes { ... on MediaImage { image { url altText } } } } } }`.
- The Laravel sync command is the repeatable production path once the app's stored Admin token is refreshed. Shopify CLI can prove/store access, but CLI mutations are dev-store only in this version, so do not depend on `shopify app execute` for production media writes.
