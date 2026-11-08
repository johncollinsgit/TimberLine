# Retail shipping implementation candidate — November 8, 2026

This candidate starts at `df0c12bfc7c59c7112fda3cfa0f722362a2bf0f9`.
It changes source and tests only. It does not activate merchant checkout, accept
provider agreements, configure production accounts, buy real postage, book a
truck, or establish physical carrier acceptance.

## Tenant setup

`/website/commerce/shipping` now supports saved setup while shipping is paused.
The guide follows origin → product weights → measured packages → destinations,
services and customer prices → tenant carrier account → tracking configuration.
It links directly to each incomplete step and printing calibration. Existing
permission, entitlement, editor and production rollout checks remain in place.
Saving policy/package data no longer requires a ready carrier account.

Locations and packages can be edited, deactivated and restored. All IDs resolve
against both the current tenant and site inside the transaction. Historical paid
order plans, shipments and packing rules are retained; no preset is deleted.
Updating an unpaid quote's origin, package, account or policy requires new rates.

EasyPost remains the parcel adapter. Supported optional services now include
USPS Ground Advantage/Priority/Express; UPS Ground/3 Day Select/2nd Day Air/Next
Day Air; and FedEx Ground/Home Delivery/Express Saver/2Day/Standard Overnight/
Priority Overnight. Existing tenants keep their saved services until they save
new choices. Selecting a service does not connect or fund a carrier account.
FedEx requires full shipper/recipient names of at least three characters and
10-digit domestic contact numbers. New quote inputs normalize ordinary US phone
formatting, without changing legacy paid-address fingerprints. Missing contacts
exclude FedEx; approved USPS/UPS options remain available. The setup guide flags
missing ship-from contacts. The tenant must finish eligible carrier linking, agreement acceptance and billing
in the provider portal. No embedded platform/resale agreement is assumed.

The optional `shipping.package_selection=lowest_cost` compares the first fitting
preferred package with up to two additional fitting presets, inspecting at most
20 alternatives ordered by outside volume. This is bounded comparison, not a
global optimum or a general cartonization solver. Every candidate uses the same
fit rules, contents, measured dimensions, tare and merchant account. Its quotes
retain their exact package plan; all returned quotes are ordered by postage,
then transit estimate. Packaging materials, labor, insurance and later carrier
adjustments are not included in that ordering. Legacy policies retain preferred
package behavior until explicitly changed.

Customer controls include home/business classification, estimated carrier transit
after handoff, and rate expiry recovery. Expired rates disable payment locally;
the server independently rejects expiry and changed addresses/contents. Pickup
remains available under its original rules. Quotes never promise arrival dates.

## Original account and postage recovery

New carts use the latest connected credential set. A paid order's first label now
resolves its accepted original connection, just as existing shipment recovery
already did. A newer incomplete connection cannot retarget or block that original
account. The original credential fingerprint, connected status, webhook secret,
payment confirmation and global/tenant rollout are still required for new buys.
Removing/changing the original key fails closed with recovery guidance.
Uncertain purchases remain GET-only. Reprints reuse purchased label evidence.
The unused-label request window is 30 days for USPS and 90 days for UPS/FedEx;
submission is distinct from provider refund confirmation and actual cash credit.

## Freight operations

`/website/orders/{order}/freight` provides a staff workspace for native Website
physical orders, quotes and draft invoices. It requires an active shipping
manager with financial access and rejects connected Shopify/legacy/POS records.
It stores versioned evidence in the existing encrypted `service_request.freight`
and encrypted staff order events; no migration is required.

The supported planning case is identical non-hazardous domestic pallets. Staff
record origin/destination contacts, requested pickup date, packed dimensions and
total weight, provider-verified freight class/NMFC and required accessorials. They
record competing all-in quotes with expiry and evidence references, approve an
exact cost, then book directly with their provider and retain its confirmation,
BOL and PRO. Booking uncertainty must be resolved with that same provider before
another booking is attempted. This workspace does not send any booking request,
generate carrier documents, or claim that staff-entered tracking is authenticated.

Staff record final invoice adjustments and provider-confirmed cancellations
separately. Those entries do not charge/refund customers, change financial totals,
reserve inventory, mark orders shipped/delivered or send messages. Customer
delivery charges and terms remain explicit quote/invoice decisions. Concurrent
stale saves fail with 409. Duplicate booking submissions cannot overwrite original
evidence. Changing an unbooked specification retires prior quotes/approvals while
retaining history. Invoice revisions invalidate unbooked freight specifications;
booked freight prevents a silent draft revision. Public parcel checkout still
rejects freight and mixed parcel/freight carts with actionable guidance.

Automatic LTL quoting/booking requires an actual tenant freight provider account,
commercial terms and integration acceptance. ShipStation API supports a freight
provider account → quote → book workflow, but an API subscription alone is not
a freight contract. Do not invent a provider account, classification or rate.
Multiple different pallet sizes, split freight consignments, automated booking,
pickup scheduling, provider-issued BOL downloads, canonical tracking, claims,
reclassification disputes and invoice ingestion remain separate integration work.

## Read-only backend export

Run on the exact protected release, using the normal approved operations access:

```sh
php artisan website:audit-shipping --tenant=carolina-barrel-co --since=2026-08-10 --until=2026-11-07 > shipping-audit.json
php artisan website:audit-shipping --all --since=2026-08-10 --until=2026-11-07 > shipping-audit-all.json
```

Choose exactly one scope. Dates use the configured application timezone and must
be complete calendar days. Defaults are the latest 90 complete days. JSON includes
release ID, local readiness/worker evidence, credential-presence booleans, native
production-mode label costs grouped by service, excluded test/unknown-mode label
counts, distinct associated-order original customer
shipping charges, confirmed-refund label value, and recorded freight invoice
coverage. No API keys, webhook secrets, addresses, shopper identities or label
URLs are exported. No provider requests or mutations run.

The gross label-cost/charge difference is not a reconciled subsidy or profit.
Provider adjustments, actual refund credits, insurance, API fees, packaging,
handling and customer refunds need independent reconciliation. Shopify/legacy
costs require their own exports and must not be added as period-aligned native
revenue. Preserve provider/account and original financial evidence in any future
migration. The production command has not yet been executed for this candidate.

## Commercial decision and private operational evidence

Historical shipping costs, account-state observations and tenant-specific audit
findings belong in the owner's private audit package, not the source repository.
The connected Carolina renderer is quote-first according to its existing runbook;
do not treat its demo fixtures as genuine shipment or billing evidence, or migrate
inquiries into native orders automatically.

Retain the existing parcel adapter for this candidate and compare eligible carrier
rates and measured packages after merchant acceptance. EasyPost, Shippo and
ShipStation API platform economics require equivalent production shipments and
eligible agreements before selecting the cheapest provider. No savings percentage
or commercial acceptance is established by this source change. Pirate Ship remains
a manual benchmark, not an API integration.

The pre-existing shipping instructions contain a GoShip-specific dashboard path.
That is a candidate workflow, not proof of an active merchant account or historical
freight billing. GoShip's currently linked technical guide describes quote retrieval
and order preparation followed by vendor-portal payment/booking. Its newer marketing
material advertises broader automation; confirm the exact supported contract before
implementing a financial booking adapter. The manual freight workspace can retain
evidence from that provider without pretending an API booking occurred.

## Acceptance and release

Source acceptance covers tenant isolation, setup while paused, measured package
comparison, address classification, carrier allowlists, original-account rotation,
payment → label → explicit packing/handoff for USPS/UPS/FedEx, duplicate purchase
recovery, encrypted freight evidence, stale revisions and booking records. Existing
label tests cover repeat printing without another purchase. Browser acceptance
intercepts every request and uses fictional rates on desktop and 390px mobile,
including expiry, changed-address races, empty rates and pickup recovery.

Use `npm run test:shipping`, the ManagedWebsite Pest suite, Pint and `npm run build`.
GitHub runs the browser checks after installing Chromium. Local Windows execution
of the full Website suite has one unrelated existing POSIX permission assertion
in `ShopifyCatalogSnapshotImporterTest` (0600 expected, Windows reports 0666); the
protected Linux CI is authoritative for that check. This candidate adds no schema
migration. Physical labels, actual API sandbox responses, live funding, signed
provider tracking, carrier handoff/delivery, freight contracts and booking/BOL
acceptance remain merchant-specific launch evidence, separate from source tests.

Official references checked November 8, 2026:

- Shopify setup: https://help.shopify.com/en/manual/fulfillment/setup
- EasyPost shipments: https://docs.easypost.com/docs/shipments
- EasyPost UPS: https://docs.easypost.com/carriers/ups-byoca-guide
- EasyPost FedEx: https://docs.easypost.com/carriers/fedex-byoca-guide
- EasyPost refunds: https://docs.easypost.com/docs/shipments/shipping-refund
- ShipStation freight: https://docs.shipstation.com/freight/quote-and-book

- FedEx contact requirements: https://support.easypost.com/hc/en-us/articles/37602064294541-FedEx-SOAP-to-REST-Migration
