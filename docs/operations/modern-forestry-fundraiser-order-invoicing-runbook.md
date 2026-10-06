# Modern Forestry fundraiser order invoicing runbook

## Current state

The verified Modern Forestry retail Shopify app has a dedicated **Fundraising**
tab. It reads BSF-tagged imported Shopify orders, shows recent order-month
proceeds, purchased product titles and quantities, and a separate invoice
review queue. The Shopify order ID is
the durable link. An hourly detector queues orders from September 1, 2026
onward. Earlier orders, including the already-created August QuickBooks
invoice, are deliberately not backfilled into another payable invoice.

The queued candle amount is Shopify's discounted order total less recorded
customer shipping and tax. It is already the amount owed for candles; do not
apply another 50% reduction. Shopify checkout shipping is not the purchased
label cost. The hourly detector reads Shopify's `shipping_labels` report and
links purchased-label cost by exact Shopify order ID. A missing report row or
permission leaves the order in review for a receipt-backed manual entry.
Staff still approves every order. An automatically linked label cost is
rechecked against Shopify before approval and QuickBooks creation or sending.
The app checks the live Shopify BSF tag, amount, cancellation, refund, currency,
and tax before approval and again before QuickBooks creation or sending.

The first-of-month 9:00 AM ET job prepares approved prior-month packages but
does not create or send a QuickBooks invoice. Staff uses separate **Create
QuickBooks draft** and **Send** controls. Both are enabled for the verified
Modern Forestry production QuickBooks connection via GitHub deployment; the
environment overrides remain emergency kill switches. The verified IDs are
customer `100000001`, candle item `58`, and shipping item `59`. The controlled
invoice delivery address is `info@theforestrystudio.com`. The connected
QuickBooks company must report name `Modern Forestry` and company email
`info@theforestrystudio.com`, and its Backstage connection must have been made
by `johncollinsemail@gmail.com`. These checks reject the Collins Upstate
Electric connection before an invoice write. The QuickBooks customer mapping
remains the verified fundraiser customer; changing the delivery address does
not change the customer. The Create action sets both
online-payment flags to false to avoid QuickBooks' automatic send-on-import
condition. The separate Send action enables card and ACH payments using a
sparse invoice update, checks the live invoice number, amount, customer, email,
payment flags, and Intuit-hosted customer link, then invokes QuickBooks send.
The link is exposed in Everbranch only after that send succeeds. An existing
QuickBooks document number blocks a duplicate. QuickBooks is authoritative
for payment status. Everbranch cannot verify QuickBooks' card-surcharge setting;
staff must check that separately before sending if card-fee passthrough is
desired. Do not claim the fee is configured or apply it to ACH.

The fundraiser queue's encrypted personal and line-item fields require the
additive `2026_10_02_190000_repair_fundraiser_encrypted_column_storage.php`
migration before production writes. It converts incompatible MySQL JSON and
short string columns to encrypted-text-capable storage and is safe to retry.

The **Fundraiser Order Invoicing** card in the verified Modern Forestry retail
Shopify Settings app records
the fundraiser company, accounts-payable contact, internal notification email,
invoice grouping, payment terms, and the intended source-shipping/tax posture.

`info@theforestrystudio.com` is the approved invoice delivery and internal
notification address for this workflow. Staff must review the QuickBooks
customer and invoice lines before the separate send approval.

The same verified surface can generate a one-time Zapier token. It enables only
the dedicated fundraiser order intake and accounting-review queue. It does not
create a customer record, Shopify order, Stripe invoice, QuickBooks invoice,
payment collection, invoice email, or recipient tracking event.

The settings row description must fit the existing 255-character
`tenant_marketing_settings.description` column. The saved configuration and
Zapier token are blocked if that insert fails.

## Zapier intake contract

Configure **Webhooks by Zapier** as a JSON `POST` to the webhook URL shown in
Modern Forestry Shopify Settings. Add header
`X-Everbranch-Fundraiser-Token` with the generated token. Each request must
contain one order with:

- `external_order_id` and optional `order_reference`;
- `recipient.name`, optional recipient email/phone, and a full
  `shipping_address` (`line1`, `city`, `region`, `postal_code`, `country_code`;
  `line2` optional);
- ISO `currency`; `subtotal_cents`, `discount_cents`, `shipping_cents`,
  `tax_cents`, and `total_cents`; and
- `items[]`, where every item has a description, quantity, and
  `unit_amount_cents`.

Everbranch recomputes the subtotal and total. A retry with the same external ID
and identical source details is safe and returns the existing order; a retry
with different amounts is rejected for manual review. Recipient/shipping data,
line items, and source payload are encrypted at rest. Never place a tenant,
store, host, secret, or QuickBooks identifier in the Zapier payload.

## Operating boundary

- Read the existing tenant-scoped legacy `orders` import for BSF detection, but
  do not mutate Shopify customers/orders/checkout, Website Commerce, or
  platform `tenant_direct_invoices` for fundraiser billing.
- Imported order data is tenant-scoped to Modern Forestry and idempotent. The
  endpoint does not accept a Zapier-supplied tenant, store, host, QuickBooks
  customer, or payment target.
- Never estimate shipping or make a taxability decision. Preserve only an
  explicitly supplied shipping/tax amount for approved later review.
- The fundraiser company (or its explicit accounts-payable contact) must be
  the payer. The internal notification mailbox is informational only.

## Manual accounting-package workflow

1. Review Shopify's linked purchased-label cost, or enter a receipt-backed
   cost when Shopify has no row, and approve each queued order. A live Shopify
   amount/tag check is required for Shopify rows. The linked order and review
   queue show product titles and quantities from the imported Shopify order.
2. Select approved orders (one order when cadence is `per_order`) and prepare
   the package.
3. Review the package lines and purchased products, QuickBooks customer, product/service, income
   account, tax code, and card-surcharge setting. Create the non-payable draft
   in QuickBooks from the Fundraising tab, inspect it in QuickBooks, then
   explicitly send. The verified customer payment link appears after sending.

When the cadence is **Monthly review and gated invoice on the 1st**,
Everbranch runs at 9:00 AM America/New_York on the first calendar day. The
command defaults to the previous calendar month, and operators may rerun a
specific month with `--month=YYYY-MM`. It groups only approved fundraiser orders
whose source date is in that prior month, keeps currencies separate, and creates
the same immutable review package. It does not approve orders. Existing packages
with the same month reference are reused on a rerun. Previously saved
`monthly_last_day` settings are interpreted as this first-day cadence.
QuickBooks write and send gates control the later provider actions
independently. They are enabled only for this verified production deployment;
non-production defaults remain off.

The package begins at `review_required`, `not_sent`, and `not_available`. The
normal schedule does not write to QuickBooks. A staff click, with the write
gate and exact customer/candle/shipping item IDs configured, creates a
deterministic `BSF-MMM-YYYY` invoice through the tenant-owned OAuth connection.
With the separate send gate enabled, a second explicit action enables online
payment and invokes Intuit's invoice-send endpoint, storing the provider
invoice ID, document number, and created/sent timestamps. The dedicated
send-to setting must match the package payer email. Replays reuse the provider
invoice. The CLI monthly command has no send option; it only queues review.

## Required checks before the first production send

1. Obtain the payer's legal name, accounts-payable email, and billing address.
2. Confirm a real Zapier sample against the above contract and a production
   replay test.
3. Confirm an approved tax decision and how supplied tax/shipping values are
   reconciled.
4. Recheck the exact QuickBooks customer, candle item, and shipping item IDs if
   the account mappings change.
5. Create a controlled non-payable draft, inspect it in QuickBooks, and verify
   the card surcharge is configured as intended before clicking Send. A draft
   is never sent by the scheduled job.

## Delivery/open status

Everbranch records a send only after Intuit's send endpoint succeeds. Payment
and recipient-open telemetry are not connected; do not infer either state.
