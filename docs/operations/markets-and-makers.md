# Markets and Makers

Markets and Makers is a dedicated Everbranch merchant workspace and independent
US/USD storefront. It sells approved Upstate South Carolina makers’ goods and
pays makers as suppliers. Everbranch owns the catalog, allocated stock, checkout
snapshots, orders, fulfillment, and supplier statements in `marketplace_*` tables.
Standalone makers do not need an Everbranch subscription. Native checkout is the
default (`MARKETPLACE_CHECKOUT_PROVIDER=everbranch`). Shopify/Wix reseller
publishing and dropshipping remain phase two.

## Release state and appearance

All availability and collection flags default off. This change does not connect
an actual merchant, create a business entity, change DNS, deploy production, or
verify real payments on customer devices. The local preview contains fictional
makers, products, and markets, and checkout stays disabled.

Public shopping keeps the Android tablet Everbranch Showcase presentation.
Management follows the user's newer Teams preference: a scoped forest sidebar,
compact white header, warm canvas, loaded-list search and expandable details.
The account preview, operator and maker surfaces share presentation only, never
the Teams task runtime or a tenant-resolving shell. Read the
[management capability map](../architecture/markets-makers-management-console.md).
Source photographs are documented in `public/images/marketplace/README.md`.
Browser checks use emulated viewports; no physical Android tablet was attached.

Live selling is planned, not implemented. A future session would reference
approved makers, published products and optional market events; resulting orders
would still use native stock, accepted terms and original-provider payment gates.
There is no working video, chat, stream schedule or live-session checkout.

## Workspace, domain, and access

### Private account preview

`/marketplace/preview` is a separate, read-only example-data console on the
canonical app host. Verified active accounts explicitly listed by numeric ID in
`MARKETPLACE_ACCOUNT_PREVIEW_USER_IDS` receive a **Markets & Makers** Switch Console
entry. The source default is empty. Resolve the exact existing user from actual
data before granting one ID; never infer access from an email pattern or role.
An account must use normal Everbranch login. Inactive/unverified/unlisted accounts
are denied; tenant/store query parameters cannot select another workspace.

This preview does not read merchant/customer/provider records, select a tenant,
create memberships or entitlements, connect providers, or accept mutations. It
does not grant the merchant operations routes. Keep `MARKETPLACE_ENABLED`, all
collection flags and the verified host unchanged. Deployment of this route alone
does not grant anyone access. An authorized operator may set the exact account
allowlist and rebuild the active release's cached configuration; verify the
resolved IDs, unchanged runtime guards and actual account response afterward.
Remove the ID and rebuild configuration to withdraw only this preview.

### Merchant workspace (separate launch)

Prepare the dedicated workspace using a verified existing owner:

```sh
php artisan marketplace:prepare-store --owner=EXISTING_USER_ID
```

The command requires the exact independently verified configured host. It creates
or binds the dedicated tenant, verified store binding, active owner membership,
and audited internal Markets and Makers module entitlement without creating a
subscription or enabling checkout. Binding an existing tenant requires an existing
owner/admin relationship; this command is not a way to acquire another workspace.

- Set `MARKETPLACE_VERIFIED_HOST` only after domain control, protected application
  routing, and TLS are verified. Public ownership requires both the exact configured
  host and saved verified Store. Unknown domains never select a fallback tenant.
- Staff use normal Everbranch authentication at `/marketplace/operations` on the
  canonical app host. Active verified owner/admin membership of the dedicated
  merchant tenant is required. Landlord status or email similarity grants no access.
- Shoppers and standalone makers use optional single-use email links, isolated
  from staff and Website Commerce identities. GET only previews a link; POST
  consumes it. Configure actual email delivery before the pilot.
- Shoppers see only their own purchases, tracking, pickup instructions and support.
  Makers see only their own catalog, stock, fulfillment details, earnings and terms.
  Private supplier business details are encrypted and available to the maker and
  authorized merchant operators. Makers never receive other makers’ order lines.
- An operator who also manages the seller workspace can request a new application under their own verified email with `php artisan marketplace:apply-workspace-maker --operator=USER_ID --tenant=WORKSPACE_ID --story="Public maker story"`. Supply `--city` when the local city is known. The command records the workspace authorization and active maker membership, leaves the application pending for normal review, and does not select products, accept rate terms or invite the maker to Studio. An existing independent application must use its owner’s normal connection flow.
- An existing Everbranch maker must authorize a current owner/admin workspace
  session and explicitly choose native products. Imports are drafts and recheck
  permissions. Email matches cannot connect workspaces.

## Merchant operations

The owner view includes live sales by Eastern date, maker and customer; merchandise,
marketplace-funded discounts, quantities/refunds, customers, orders, maker business
profiles and contacts, products and stock, supplier statements and bank references,
market/show schedules, maker participation and booths, pickups, shared storefront
links, and integration activity. Operational order review can include test orders;
test orders never contribute to live reporting or eligible bank payments. Reports
are bounded to 2,000 orders and disclose that limit; narrow the dates for complete
results. Other operational lists are also bounded for the initial maker pilot.

Create markets/shows as drafts or published events. Only operator-approved maker
participation can enable pickup at a market. Published pickup locations require a
structured US tax address. Pickup ordering stops at the event cutoff/end; that does
not cancel already paid purchases. Public lineups show approved makers only.
Changes to a paid order’s market schedule/location or maker participation create
a financial/fulfillment review hold. Maker withdrawal immediately marks uncollected
pickup orders for owner review. Contact the customer and record a reschedule or refund; saving an event
does not send customer messages. A checkout snapshot preserves the original pickup
instructions. Operators can save revised instructions on the order after contact.

Shared Shopify, Wix, Etsy, Square, WooCommerce and other storefront links are
explicitly marked as links without catalog sync. They do not grant credentials or
reseller publishing. The existing authorized Everbranch source connection supports
explicit native product selection and inventory allocation.

## Payments

Connect the Markets and Makers business through its Everbranch Accept payments
screen. The shared payment setup remains behind its existing global and exact
`TENANT_PAYMENT_SETUP_TENANTS` rollout gates and canonical module entitlement.
Do not reuse Modern Forestry, Evergrove, another tenant’s account, or old tokens.

### Cards and device wallets

The native adapter uses the dedicated tenant’s ready Stripe direct merchant
account, a frozen platform/account/environment context, Stripe-hosted checkout,
US shipping, USD and automatic tax. Cards and eligible Apple Pay/Google Pay use
that merchant account; wallet display still depends on actual device eligibility.
For pickup, a merchant-bound Customer records the frozen physical pickup shipping
address so automatic tax uses that address. Canonical payment reads verify it.
No Website Commerce or legacy order/customer record is created by this lane.

Configure `MARKETPLACE_STRIPE_WEBHOOK_SECRET` for signed connected-account events at
`/webhooks/marketplace/stripe` on the canonical application host. Raw-body signatures,
timestamp tolerance, merchant account, environment, nonce, captured funds, line
items, currency and totals are verified. Browser returns never record payment.
Live collection additionally requires the existing direct-payment readiness gate,
`MARKETPLACE_STRIPE_LIVE_VERIFIED`, and a genuinely signed reconciled test payment
for this Store and connected account. Confirm the actual merchant’s tax settings,
payout setup, US delivery and payment acceptance before enabling collection.

### Shop Pay

Shop Pay is a separate merchant-specific Wallet connection for the Everbranch
store. It does not host the catalog or own marketplace stock. Complete Shopify’s
[Shop Pay platform onboarding](https://shopify.dev/docs/api/commerce-components/pay),
register the verified HTTPS storefront origin, configure automatic capture and
USD, and obtain the approved Wallet client and merchant-owned API credentials.
Use the operator’s encrypted credential form. It verifies API merchant identity;
that alone does not prove customer checkout readiness.

Subscribe the installed merchant app to `orders/create`,
`order_transactions/create`, `orders/paid`, `orders/updated`, `refunds/create`,
`fulfillments/create`, `fulfillments/update`, and `app/uninstalled`. Dispute callbacks
`shopify_payments/disputes/create` and `/update` require the appropriate approved
access. The form displays the saved connection-specific signed webhook endpoint.
Callbacks bind the exact merchant and event ID. Old purchases retain their original
encrypted merchant credential revision.

`MARKETPLACE_SHOP_PAY_ENABLED` independently gates offering Wallet. Session and
payment totals are generated on the server. Merchant-scoped Stripe Tax calculates
shipping/pickup quotes; the signed short-lived quote is frozen before submission.
A one-time Wallet payment method token cannot change prices, quantities, fees or
tax. Receipt reconciliation requires the exact checkout source identifier,
captured original merchant order, lines and totals. Stock settles once. Tax sales
and refund reversals have durable UUIDs and block earnings until reconciled.
Unknown submissions stay held and cannot be cancelled into a replacement charge.
Live Wallet requires genuine signed test-payment proof for the same merchant and
signing secret. Actual SDK, pickup, discount, tax, fulfillment, refund and device
acceptance remain required with the dedicated merchant; local HTTP fakes are not
provider acceptance.

### PayPal buyers

Native PayPal approval/capture and original-account refunds are implemented behind
independent default-off gates. Connect this Store's business PayPal account in the
shared payment setup. New order/capture requests recheck its current partner
permissions and original merchant context. Tax and USD amounts are server-generated;
the provided US delivery address is frozen before approval. A browser return never
records payment. Canonical capture, merchant, currency, items, address and breakdown
checks settle inventory once. Pending/unknown payments remain held; definitive
canonical declines release allocated availability once. Approved unpaid attempts
may be explicitly cancelled before any capture starts.

Configure the app's /webhooks/marketplace/paypal endpoint and webhook ID for
CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED/PENDING/DENIED/REFUNDED,
PAYMENT.REFUND.COMPLETED/PENDING/FAILED, and CUSTOMER.DISPUTE events. Signature
verification uses PayPal's verification API; merchant-bound events are encrypted
and deduplicated. Signed callbacks and canonical reads continue while collection
is off. Refunds require the original capture, amount, immutable local refund
invoice reference and canonical capture link. Lost responses are reconciled without
replaying money movement. Native tax transactions/reversals are durable and supplier
payment remains held while provider/tax work is unresolved.

MARKETPLACE_PAYPAL_ENABLED, MARKETPLACE_PAYPAL_WEBHOOK_VERIFIED, and a configured
webhook ID gate test-mode buyer availability. Live additionally requires the
approved platform integration, MARKETPLACE_PAYPAL_LIVE_VERIFIED, exact
MARKETPLACE_PAYPAL_LIVE_MERCHANT_ID, signed sandbox test proof for the dedicated
Store, and matching live tax/payment environments. These controls do not assert
actual PayPal approval, seller onboarding, buyer acceptance or device verification;
those remain external launch evidence. No real PayPal credentials or payments were
created during implementation. See the official [partner checkout guide](https://developer.paypal.com/platforms/checkout/standard/integrate/).

An explicit optional `shopify` checkout provider retains a separate hosted-catalog
adapter and its original signed reconciliation tests. It is not the default native
store architecture and must not be enabled implicitly. Its merchant credentials,
publication, inventory, shipping and tax setup require a separate reviewed rollout.

## Listings, stock, prices, and supplier obligations

Operators approve makers and initial listings. Material edits return to review;
pauses and reductions apply immediately. Native product imports require explicit
selection and dedicated allocations. Allocation locks the originating variant,
excludes active Website reservations, reduces source availability and records the
movement in the same transaction. Native checkout reserves only allocated units.
Verified payment commits that reservation once; confirmed unpaid expiry restores
marketplace availability once, without returning those units to the original
channel. Refunds do not silently restock inventory.

The cart shows separate shipping and pickup-location payment groups. Multiple
makers may share shipping; pickup items must share one eligible pickup location.
Paid groups are cleared once while other groups remain. Pending changed terms
cannot create an overlapping replacement checkout. Unknown outcomes stay visible
for reconciliation rather than assuming payment failed.

The default fee is 15% of merchandise before marketplace-funded discounts, excluding
shipping and tax, with processing included and no monthly/listing fee. Product
rates override maker rates, then the global rate. Percentages use basis points;
fixed fees and all calculations use integer cents. Fees above item price fail.
Every applicable new rate requires maker acceptance before new sales. Immutable
checkout snapshots preserve prices, effective terms, fees, supplier earnings,
delivery choices and authorized promotion funding.

Marketplace-funded promotions preserve agreed maker earnings. The combined
discount and shipping subsidy cannot exceed the marketplace margin plus the
operator’s explicit saved additional subsidy limit. Default shipping is charged
once per maker, with per-product overrides. Makers buy labels and provide tracking.
Free-shipping promotions require an explicit positive per-maker label credit and
operator funding authorization; checkout freezes that credit and approval. Shipping
credits balance collected shipping plus approved marketplace funding.

Supplier statements contain fulfilled items, shipping credits, and refund
adjustments. Shipping allocation and financial reviews block release. Pickup
collection requires the buyer’s code. Operators approve the current positive
statement, make the bank payment outside Everbranch, and record the exact amount
and actual bank reference. V1 does not move supplier money automatically.

Refunds go through the original payment account, with original paid amounts/tax and
durable request IDs. Confirmed refunds reverse original supplier merchandise
obligations once; shipping refunds and retained actual processing costs are separate.
Refunds reopen approved unpaid statements. Adjustments after bank payment carry
forward without rewriting historical bank payments. External unmapped Stripe refunds
are held for original line/supplier reconciliation and cannot be dismissed by a
generic review checkbox.

## Recovery, validation, and pilot release

`marketplace:reconcile` runs every five minutes, including while checkout is off.
Activity shows native payment events, checkout failures, signed Wallet/Shopify events,
operator audit and durable provider mutations. Stored event retries perform canonical
reads. Operations marked `sending` or `unknown` cannot be blindly resent. Confirm the
original provider’s payment/refund/stock evidence before an audited correction.
An interrupted Stripe creation without a known session or an ambiguous Wallet/tax
mutation may require manual provider reconciliation; no timeout implies failure.
Preserve encrypted original credentials for historical reconciliation/refunds.

Run marketplace/application tests, migration lint, real MySQL partial-DDL recovery,
data-free schema baseline/fingerprint checks, previous-release upgrade rehearsal,
and frontend build. Browser checks cover 390/1024/1440 widths and normal account
flows. Automated tests cover native and optional hosted adapters, tenant/maker
isolation, stock/checkout retries, canonical signed payments, refunds, promotion
funding, original supplier earnings, and market permissions. Real merchant and
customer-present device checks are separate launch evidence.

Launch only through GitHub’s test/build gate and the protected Forge atomic-release
process with `/ready` verification. Configure real mail, merchant accounts, signed
callbacks, tax, delivery, approved policies and pilot makers first. Keep both
`MARKETPLACE_CHECKOUT_ENABLED=false` and the operator switch off until accepted.
To stop new purchases, disable checkout while retaining orders, statements,
credentials, fulfillment and reconciliation. Never delete tables to roll back.

For a local fictional preview only, use the exact SQLite database
`markets-makers-preview.sqlite`, `MARKETPLACE_DEMO=true`, local mail logging and
checkout off. `marketplace:demo` refuses other environments. Normal staff
authentication and dedicated Store owner/admin membership still apply.

## Video shopping catalogs

An invited, approved maker opens **Video shopping catalog** from the video editor. For Shopify, register a dedicated, non-embedded merchant-distributed app with the `read_products` scope, the authorization code grant, and expiring offline access. Register `https://<verified-marketplace-host>/integrations/video-catalog/shopify/callback` as an allowed redirect. Configure `MARKETPLACE_VIDEO_SHOPIFY_CLIENT_ID` and `MARKETPLACE_VIDEO_SHOPIFY_CLIENT_SECRET` server-side. The maker enters their permanent myshopify.com domain, completes Shopify authorization, browses published variants, then selects the items they want in the editor. The marketplace stores credentials encrypted, serializes token refresh, and checks variant publication and availability with a 45-second cache. Disconnecting the shop immediately removes its purchase links. [Shopify authorization code grant](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/authorization-code-grant) · [Shopify product variants](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/productVariants)

For Everbranch, the maker links their workspace and a workspace owner or administrator uses **Share in maker videos** beside each published retail variant. The resulting product URL preselects that variant in the seller’s store. A matching approved marketplace allocation uses the marketplace purchase route when checkout, stock, and the maker’s accepted terms allow it. Disconnecting a catalog preserves the video.

For a made-to-order Everbranch product, an owner or administrator can share a published quote product with one available option. **Request a quote** opens that exact product on the seller’s published site. A changed destination requires the owner to share the option again. A disconnected site or an additional available option disables the link until an exact quote destination is available. The visit counts as a product click. It creates no order, stock reservation, referral charge, or verified sale.

During the pilot, a direct Shopify or Everbranch visit earns the marketplace a 0% referral fee. The maker’s store keeps payment, fulfillment, and refund authority. External referrals report clicks. Marketplace video sales derive from reconciled, non-test order lines after discounts and refunded units, excluding orders held for review. A fully refunded line contributes zero sales and revenue. Checkout continues to apply the maker’s accepted marketplace rate once. Production services and sponsorships are separate bookings.

### Watch and maker Studio

Watch is public at `/watch`. Customers, market organizers and other makers can browse **Starting a business**, **Behind the scenes** and **Maker showcases**, then filter short or long videos within a theme. Each post identifies the maker and links to their public business profile, videos, goods and approved upcoming market appearances. The channel has no public posting or commenting endpoint.

A marketplace operator grants Studio access to an approved maker in **Watch review queue**. Only an active owner of that invited maker can create or submit a video at `/maker/{id}/studio`. Studio supports camera capture, file upload, editable English WebVTT, trim points and timed variant tags. The reviewer sees the trimmed output and can return it for changes or schedule publication in the marketplace time zone. Editing a published video withdraws it pending review. The source recording is private.

For a PC-made episode, choose **Import a Windows Studio export** and select its approved public revision folder. The browser verifies the selected MP4 and caption file against their size and SHA-256 in `studio-package.json`. The maker reviews the proposed catalog matches and chooses the Watch theme. Draft, members-only and private manifests are ineligible. Available signed-in owners can download their product keys from **Manage your video catalog**.

The app and long-running worker must share persistent private `storage/app/marketplace-video` storage. The maker editor uploads videos over 1 MiB in sequential 1 MiB parts over HTTPS. Allow at least 1 MiB per file and enough request-body space for multipart overhead. The application enforces `MARKETPLACE_VIDEO_UPLOAD_MB` on the completed video (512 MiB by default). Private staging supports same-session retries, verifies each part and the final source, and expires after 24 hours. Run the application's scheduler to prune expired staging. Install FFmpeg with H.264/AAC and FFprobe on the worker; `MARKETPLACE_VIDEO_FFMPEG` and `MARKETPLACE_VIDEO_FFPROBE` accept executable paths. Run `php artisan queue:work maker_video --queue=maker-video --timeout=7200 --tries=2` with a supervisor stop grace period longer than the job. This dedicated database queue uses a 7500-second retry interval. Worker, codec and upload limits must be exercised with a real pilot upload before launch.

The review queue summarizes the preceding 60 days of first submissions and attributed paid orders. The team can enter production minutes and costs on each video. Processing costs include only recorded fees on orders whose merchandise was entirely video-attributed. The summary marks missing or shared fees and unrecorded production spend so the operator can reconcile the result.
