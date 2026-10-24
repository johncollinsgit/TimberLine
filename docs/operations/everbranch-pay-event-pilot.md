# Everbranch Pay event register

October 24, 2026 source candidate. Collection is default-off. This document
describes the staff web/Stripe reader pilot; the Square native handoff remains
available independently. Code, CI, an installed preview, a deployed backend and
physical merchant acceptance are separate evidence categories.

## Entry points and authority

The retail register is `/pos/staff/{tenant-slug}/terminal`; the isolated schema
and explicitly configured Enterprise runtime use
`/enterprise/pos/staff/{tenant-slug}/terminal`. Enable `POS_LIVE_ENABLED`,
`POS_PILOT_ENABLED` and an explicit `POS_LIVE_TENANT_IDS` allowlist only after
the independent `pos` entitlement is granted. Enterprise configuration uses
`ENTERPRISE_POS_*` exclusively and does not onboard retail payment connections.
The existing `/pos/staff/{tenant-slug}` still prepares native Square handoffs.

No public storefront scrape, browser preview, fictional sale, sample tax rate
or preview device UUID is imported. The approved artwork is an original
text-free business-card strip. Production products/images come from reviewed
server catalog bindings. Modern Forestry remains Shopify-authoritative; Alpha
uses reviewed native items and immutable accepted printing quotes.

Owners/admins use Company setup to create/link canonical staff profiles,
review the reporting timezone, enroll browsers, assign staff/registers to
events, bind a verified reader, revoke devices or pause collection. Existing
verified user IDs can link to a profile without changing its identity. Staff
role changes and linked-account revocation are checked fresh. PIN changes
invalidate active PIN unlocks. Company reports and account setup require an
individual owner/admin sign-in, never a PIN grant.

Every enrollment logs out the owner, invalidates the original session and
rotates CSRF. Individual staff then sign in normally on the enrolled browser.
Shared enrollment issues a separate server-side restricted register grant. Only its opaque normal session
cookie reaches the browser. Named staff unlock with six-digit hashed PINs;
five failures lock that profile/device for five minutes. Five minutes without
deliberate interaction locks the register; background polling does not renew
the idle timer. Grants expire after twelve hours. Staff switching and shifts
are audited. An owner can re-enroll a browser at another register after its
quoted/uncertain or unsynchronized sales are resolved; its device identity and
historic sale attribution remain unchanged.

Browser enrollment identifies an approved browser installation, not hardware
attestation. Resetting its encrypted device cookie requires re-enrollment.

## Event and inventory setup

Provision reviewed registers with `pos:provision` before browser enrollment.
The existing owner-authorized Accept payments flow connects the merchant's
Standard Stripe account. Bind a Terminal location and S710 from that same
account/environment using the reader setup form. Binding clears collection
and acceptance. A register with historical sales cannot change processor or
payment connection; provision another register instead.

Each simultaneous event needs its own approved Shopify inventory location,
physical/tax address and routing review. Separate events cannot overlap on
the same Shopify store/location, even through different internal location IDs. Registers sharing an event must share its
stock authority. Event addresses must match the reviewed tax address; tax
evidence must remain valid through the event. No flat rate is inferred from
the website or preview. Configure event locations outside ordinary online
fulfillment and prove pending orders route to that exact location. Incorrect
routing blocks collection; the app never silently substitutes another location.

Record a complete opening physical count, then open the event. Starting a
shift selects one assigned event, operator, device and register. Collection
freezes those identities, event version, tax revision, quote terms, build,
provider/account/environment and connection. Changing context cannot resume
an old quote as a new sale. Saved baskets reserve no stock; one device claims
their stable request, and resumed selections receive fresh server prices/tax.
Favorites and keyboard-style barcode lookup are included; camera scanning is
deferred. Stock refresh and low-stock warnings are advisory; preparation
performs the authoritative inventory check.

Reserve exclusive reader use before Shopify/native stock preparation. Shopify
creates one correlated pending order with inventory-policy enforcement. Verify
its totals and fulfillment location before payment; after verified payment,
record an external manual bookkeeping transaction and fulfill that order once.
Lost creation replies recover the original tag, never a replacement order.
Cancellation performs lookup/cancellation only and retains stock until the
original payment is definitively unpaid. Printing services consume no stock.
Physical counts record variances without inventory writes; transfers and
manual corrections remain in Shopify for this pilot.

## Payment, recovery and refunds

Required Stripe configuration is `POS_STRIPE_TERMINAL_ENABLED`,
`POS_STRIPE_SECRET`, `POS_STRIPE_PLATFORM_ACCOUNT`, `POS_STRIPE_ENVIRONMENT`,
`POS_STRIPE_WEBHOOK_SECRET`, `POS_STRIPE_WEBHOOKS_VERIFIED` and
`POS_STRIPE_SETUP_ENABLED` for owner binding. Do not copy retail credentials
into Enterprise. Secrets remain server-side. Runtime HTTP verifies the platform
identity and uses the original connected account, even after key rotation.

`POS_WEB_BUILD` must equal the actual deployed `RELEASE_ID` for live collection.
The server supplies the build/device context; client headers cannot choose it.
Stripe readiness does not depend on Apple entitlement/distribution flags.
Square readiness retains those independent requirements.

One PaymentIntent, USD/card_present/automatic capture, no application fee,
destination transfer, saved-card reuse or tips. Persist encrypted immutable
creation payload and key before HTTP, then persist the returned intent before
reader dispatch. Reader acknowledgement is pending. Fresh authenticated
retrieval validates identity, metadata, amount, currency, environment and
captured charge before success. The provider registry selects the original
processor for recovery/refunds; no automatic failover exists.

Declines offer explicit retry of the same intent. A lost creation response can
be searched read-only; explicit resume can replay the identical payload/key
only within 23 hours. Older uncertainty requires provider review and keeps the
reader claim. Background recovery never dispatches a reader payment. Browser
storage holds opaque request/sale/register/refund references only; no prices,
customer contact, credentials or card data. Reload checks the original request.

The retail callback is `/api/pos/stripe/webhook`; Enterprise registers its own
isolated `/pos/stripe/webhook` callback under the Enterprise API prefix. Verify
the raw-body signature, five-minute timestamp, account and environment.
Duplicate/reordered events enqueue idempotent recovery only. Merchant pause
stops new collection/retry while status, recovery, cancellation and authorized
refunds remain available when servicing is enabled.

Full/partial refunds use the original account and serialized remaining budget.
Managers explicitly choose restock quantities or no restock and a reason before
provider HTTP. Decisions cannot change on replay. An uncertain refund blocks a
replacement refund; inspect/recover its original identifier. A request proven
not dispatched is retired, never dispatched later under a cleared client key.
Confirmed money, bookkeeping and stock effects have separate states. Native
stock increments once; Shopify restocks only through the correlated manual
refund transaction. No processor is invoked a second time for Shopify bookkeeping.

## Reports, closeout and release

Activity filters day/month/year, company timezone, event and enrolled device or
canonical staff identity. Company scope requires owner/admin; managers remain
assigned-event scoped; cashiers see their own or approved-device records.
Bars/lines share server-computed buckets and clickable transaction filtering.
Gross includes tax; sales before tax, tax, confirmed refunds, net collected,
average sale, product/size rankings and comparisons are labeled separately.
Net collected is not a payout. Refunds belong to their own confirmation date.
CSV export is manager/owner-only and neutralizes spreadsheet formulas.
Historical unknown attribution is excluded and reported, never fabricated.
Provider records and Shopify orders represent one sale; unrelated online
orders are outside company POS totals.

Stop new event sales before recording a closing count. Closeout remains
provisional for uncertain payments/refunds, unfinished outbox work, missing
counts or nonzero latest count variances. Counts have a serialized sequence,
including multiple counts in the same second. A later payment/refund or stock
effect invalidates the closing count and makes the report provisional until
a new closing count is reviewed. Finalization closes shifts but
retains all records and original-payment servicing.

Use `pos:readiness --tenant=<id>` for saved, provider-specific blockers. It does
not contact providers or establish physical readiness. Owner-private merchant
review files for `pos:activate` include the actual rate, original merchant and
location, zero Everbranch markup and `everbranch_application_fee_minor: 0` for
Stripe. A bounded acceptance session requires exact build, enrolled device,
named `--acceptance-operator`, owner actor, expiry and amount cap. Configuration
alone never enables ordinary collection.

For Stripe, `pos:accept` requires a private mode-0600 evidence JSON containing
`sale_id`, `build`, `reader_id`, `physical_reader_observed: true`,
`receipt_shared_or_printed: true`, `stock_and_refund_reviewed: true` and
`payout_ids`. Physical observation is owner-attested; original-account payment,
refund and paid-payout charge evidence are freshly provider-verified. Acceptance
is saved against that exact reader/build only with `--apply`. A native preview
installation does not satisfy this gate.

Run POS/Square/payment-core/Enterprise regressions, MySQL contention and partial
migration recovery, native contracts/builds and both browser engines. CI owns
release authority; verify `/ready` on the exact release. Alerts originate from
`pos:reconcile` for unknown payments, retry/review effects, stock conflicts and
failed reconciliation. Disabling new collection never deletes payment evidence.

Start with named Modern Forestry staff, one event and one physical reader.
Verify payment, receipt, original-account refund, stock and settlement before
ordinary sales. Verify two separate events before simultaneous activation.
Alpha requires separate merchandise/printing quote acceptance, including
online/counter contention. Expand after three discrepancy-free trading days.

Hardware purchase, merchant authorizations, live Shopify location/tax review,
physical acceptance and settlement are external prerequisites, not implied by
passing CI. Offline/queued collection, cash, tips, deposits, gifts, new discounts,
native Stripe Tap to Pay, Android and broader Enterprise activation are deferred.
Recheck the shared [provider terms/publishing record](provider-terms-and-publishing.md)
before release. No provider data is repurposed for AI training or marketing.

References: [Stripe server-driven integration](https://docs.stripe.com/terminal/payments/setup-integration?terminal-sdk-platform=server-driven),
[Stripe idempotency](https://docs.stripe.com/api/idempotent_requests),
[Shopify inventory locations](https://help.shopify.com/en/manual/fulfillment/setup/locations/assigning-inventory-to-locations).
