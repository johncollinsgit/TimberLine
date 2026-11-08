# Native Website LTL quoting and booking

This adds a tenant-owned ShipStation v2 freight adapter to the native Website
order freight workspace. It does not touch connected Shopify, legacy or POS
orders. Existing manual freight records remain available. This document contains
generic operating instructions; tenant account and financial evidence belongs
in private operations records.

## Setup and operating flow

1. Open a native physical order → Freight. The active shipping manager also needs
   financial access and access to the site's Managed Website editor.
2. Connect the tenant's freight provider in ShipStation and accept its billing
   and carrier agreements there. An API subscription alone is insufficient.
3. Save a tenant-owned v2 API key in Freight account. Everbranch calls
   `GET /v2/freight/provider_accounts`. One active account is selected automatically;
   multiple accounts require a selection and another verification. An empty list
   remains pending with setup guidance. Secrets are encrypted, hidden and never
   flashed into form/session errors. Every saved credential set is a new record;
   previous credentials remain available for original-account recovery.
   This adapter accepts production keys only. ShipStation API sandbox keys use
   the documented `TEST_` prefix and are rejected before sending order data;
   sandbox results must never become real-order booking or fulfillment evidence.
4. Save reviewed identical, non-hazardous US pallets, contacts, company/household
   names, phones, pickup date, freight class/NMFC and accessorials. The form stores
   total packed weight; the adapter divides by pallet count because the provider
   multiplies the weight of one handling unit by quantity. Explicit inch/pound
   units follow the API schema. Residential surcharges remain explicit even when
   a destination is also limited access. No cargo insurance is purchased by this
   flow; review carrier liability and arrange needed coverage separately.
5. Request current quotes. POSTing quotes does not book freight. Offers retain
   the exact request, original connection, credential fingerprint, provider offer,
   total USD cost and expiry in encrypted order evidence. Prices are sorted by
   carrier cost; final carrier adjustments remain possible. Re-quoting replaces
   earlier API offers and clears approval, retaining the old evidence in events.
6. Approve an exact, current cost. Set the customer's shipping charge and delivery
   terms separately through the existing reviewed quote/invoice workflow. This
   does not make a parcel checkout cart eligible for freight or mixed carts.
7. Booking requires a verified paid, unfulfilled USD order, no existing parcel
   shipment/fulfillment, the exact approved offer, an origin-local pickup window,
   and explicit shipment/cost confirmation. Booking also requires both
   `MANAGED_WEBSITE_FREIGHT_BOOKING_ENABLED=true` and the tenant ID in
   `MANAGED_WEBSITE_FREIGHT_BOOKING_TENANT_IDS`. Both default closed. Setup,
   quoting, original status reads, documents and cancellation do not depend on
   the booking rollout. Deployment does not activate a tenant or fund an account.
8. The single claimed POST dispatches carrier pickup. A canonical GET on the
   original account confirms the booking, BOL/PRO and documents. Download/reprint
   the existing BOL and pallet PDFs; download errors never create another booking.
   The protected proxy accepts only official provider `/v2/downloads/*.pdf`
   locations, refuses redirects/credentials/query strings, limits PDF size, and
   never sends an API key to the browser.
9. Check original booking status to refresh canonical status and carrier tracking.
   Observations retain timestamps. Booking/tracking do not mark the order shipped
   or delivered, change inventory/payment truth, or send notifications. Use
   **Confirm physical carrier handoff** after the carrier accepts all goods. It
   requires a current active original-account shipment read, paid-order checks,
   an explicit staff confirmation and retained signed BOL/pickup evidence. It
   creates one native fulfillment with physical order-line quantities and marks
   fulfillment complete. Evidence stays encrypted; delivery remains separate.
10. Request cancellation once; a subsequent GET must confirm shipment cancellation.
   Staff-confirmed handoff or known transit/delivery blocks API cancellation.
   Pickup cancellation is separate: a cancelled shipment without confirmed pickup
    cancellation requires direct provider follow-up. Reconcile final carrier
    invoices, cancellation fees, adjustments and customer refunds independently.

## Duplicate prevention and recovery

Site/order locks and a version check save a durable booking claim before network
I/O. The client never retries a booking or cancellation POST/DELETE and never
follows redirects. Concurrent clicks, a crash, lost response, carrier/system 400,
429, 5xx and malformed success leave the original operation blocked. A definite
ShipStation field-validation rejection allows review and a new quote/approval,
but not reuse of the rejected quote. No logic branches on provider message text.

If a shipment ID survived, recovery uses canonical GETs only with the original
tenant/site account and exact credential fingerprint. A newer account cannot
retarget it. Missing or changed original credentials require restoration. No ID
is accepted from a user for automatic adoption. If the provider lost its response
before returning an ID, keep the attempt blocked and ask the original provider to
resolve its `EB-<operation UUID without hyphens>` reference. Provider-assisted
resolution of these unknown attempts is outstanding; there is no unsafe reset or
"try another booking" button. Cancelled bookings remain retained and cannot be
silently replaced. Replacement shipment/account-recovery tooling needs a separate
reviewed extension.

Manual edits and invoice revisions cannot erase an in-flight API operation.
Parcel purchase is also blocked while freight evidence exists. All specs,
quotes, attempts, tracking and before/after order events use existing encrypted
order/event fields; no schema migration is added.

## Evidence and launch acceptance

`WebsiteFreightProviderTest` exercises fictional HTTP responses for pricing,
per-pallet weight, accessorials, exact approval, original-account recovery,
durable claims, uncertainty, cancellation, document protection and permissions.
`npm run test:freight` renders those real Blade views and exercises desktop/mobile
forms with every network request intercepted. Run `npm run build` first.
Screenshots are optional through `FREIGHT_SCREENSHOT_DIR`. These tests establish
source behavior, not provider sandbox or physical carrier acceptance.

Before an authorized tenant rollout, privately retain account/billing eligibility,
an accepted quote for representative actual freight, a separately reviewed
recipient/shipment/cost for the first real booking, a valid printed BOL and pallet
labels, carrier pickup acceptance, tracking/delivery proof, cancellation response
and final invoice reconciliation. No real booking is required for source tests.
International, hazmat, dissimilar pallets, automated cart freight checkout,
automatic customer notifications, background freight polling, automatic invoice
imports and replacement bookings remain outside this initial adapter.

The read-only `website:audit-shipping` command separates `manual_freight` and
`api_freight`. API approved quote totals are distinct from recorded final invoices;
they are not booked accounting expenses or evidence of cash settlement.

## Primary API references reviewed

- [Quote and book freight](https://docs.shipstation.com/freight/quote-and-book)
- [Freight quote API schema](https://docs.shipstation.com/apis/openapi/freight/get_freight_quotes)
- [Booking API schema](https://docs.shipstation.com/apis/openapi/freight/book_freight_shipment)
- [Handling-unit semantics](https://docs.shipstation.com/freight/overview)
- [Retrieval, tracking, documents and cancellation](https://docs.shipstation.com/freight/manage-shipments)
- [Production and sandbox credential boundaries](https://docs.shipstation.com/authentication)

The overview describes fixed inch/pound units, while the quote API schema declares
explicit required unit fields. Requests include the explicit fields. The booking
schema resolves the provider connection from `offer_id`; quote-only account,
shipment-date and accessorial fields are omitted when booking inline. Real
provider acceptance must verify these documented contracts before rollout.
