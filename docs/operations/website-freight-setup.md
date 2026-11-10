# Native freight setup and maintenance

## Setup before the first order

Open Pickup and shipping → Set up pallet freight (`/website/commerce/freight`).
This store-scoped screen requires an active shipping manager, Website editor
access and financial authority. It works before any order exists and while
booking is paused. GET renders saved local state without provider requests.

Account verification sends only GET `/v2/freight/provider_accounts`. Empty or
multiple results save pending setup; choose a returned active provider and
verify again. Each save creates a new encrypted credential set; old quote and
booking accounts are retained. Validation never flashes keys or provider bodies.
No account save changes rollout, books freight, collects payment or creates an
order. Existing order setup calls the same authorization/connection path.

ShipStation's current documented dashboard path is Settings → Shipping → Carriers
→ Freight Providers. Supported options are WWEX and Unishippers. Existing broker
accounts use SpeedShip Client ID/Client Secret in ShipStation. Creating a WWEX
account requires a US account, no other connected freight provider, reviewed
business/billing details, a payment method, automatic-charge authorization and
terms acceptance. Do not disconnect an original account to replace an open
shipment's recovery context. The account owner must complete identity,
commercial acceptance and funding; Everbranch does not infer them from an API key.

Use the v2 key appropriate for `https://api.shipstation.com/v2`, with freight
endpoint access. The separate ShipStation API / ShipEngine product has different
accounts/keys. Inventory-only access is insufficient. Plan names and prices vary;
verify the actual account entitlement before a purchase. Official sources checked
November 10, 2026:

- [Freight API requirements](https://docs.shipstation.com/freight/overview)
- [Existing broker connection](https://help.shipstation.com/hc/en-us/articles/53777198753563-Worldwide-Express-WWEX)
- [New WWEX account and billing](https://help.shipstation.com/hc/en-us/articles/54549682761371-Create-a-Worldwide-Express-WWEX-Freight-Account)
- [API access and owner-only keys](https://help.shipstation.com/hc/en-us/articles/360025856212-ShipStation-API)
- [API product boundaries](https://docs.shipstation.com/api-overview)

## Launch acceptance

Preserve the global and tenant booking gates until a funded original account,
reviewed shipment and operator are available. Compare identical physical pallet
measurements, class/NMFC, lane, dates and accessorials. An active connection does
not establish the cheapest total cost, funding or successful booking. Require
the exact protected source release, account lookup, authenticated real quotes,
approved real cost/recipient/pickup, single booking, usable BOL/pallet documents,
physical acceptance and invoice reconciliation. Physical evidence is separate
from HTTP-faked and browser-intercepted tests. Never book fictional fixtures.

## Routine operations

| Frequency / trigger | Owner and required work |
| --- | --- |
| Each shipment | Shipping operator reviews measurements, contact details, location surcharges and local pickup window. Approve the current exact cost. Print existing BOL/pallet documents and retain pickup proof. |
| Daily while open | Operator checks the original booking and tracking in the order's Freight screen. These are on-demand authenticated GET requests; background polling and push tracking are not implemented. Never rebook an uncertain attempt. |
| Each final invoice | Finance reconciles original account/quote, accessorials, reweigh/reclassification, cancellations and actual credits. Record the final invoice separately from customer shipping charges and refunds. |
| Account / key / plan change | Owner checks billing and endpoint access, then verifies setup again. New connections serve future quotes. Existing operations retain original account/fingerprint and require reviewed original-account recovery if credentials change. |
| Packing / product change | Operator remeasures the packed pallet and confirms classification with the broker. Never infer NMFC or use an old quote for a changed load. |
| Provider API change | Engineering rechecks schemas, account access, quote expiry, no-retry ambiguity handling, document host allowlist and cancellation/pickup semantics, then runs protected release checks. |

The first workflow covers identical non-hazardous US pallets on one native
physical order. Mixed-size pallets, split freight consignments, insurance
selection, international freight, claims, invoice ingestion and customer-facing
freight checkout remain separate work. Shopify keeps its original fulfillment
authority. Cancellation confirmation does not prove pickup cancellation or a
cash credit. A handoff is staff-confirmed physical evidence, not delivery.
