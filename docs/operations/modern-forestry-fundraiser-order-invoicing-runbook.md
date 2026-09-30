# Modern Forestry fundraiser order invoicing runbook

## Current state

The **Fundraiser Order Invoicing** card in the verified Modern Forestry retail
Shopify Settings app records
the fundraiser company, accounts-payable contact, internal notification email,
invoice grouping, payment terms, and the intended source-shipping/tax posture.

`info@theforestrystudio.com` is the default internal notification address. It
is not the invoice payer and must not be used as a substitute for the
fundraiser's accounts-payable address.

The same verified surface can generate a one-time Zapier token. It enables only
the dedicated fundraiser order intake and accounting-review queue. It does not
create a customer record, Shopify order, Stripe invoice, QuickBooks invoice,
payment collection, invoice email, or recipient tracking event.

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

- Do not use legacy `orders`, Shopify customers/orders/checkout, Website
  Commerce or platform `tenant_direct_invoices` for
  fundraiser orders.
- Imported order data is tenant-scoped to Modern Forestry and idempotent. The
  endpoint does not accept a Zapier-supplied tenant, store, host, QuickBooks
  customer, or payment target.
- Never estimate shipping or make a taxability decision. Preserve only an
  explicitly supplied shipping/tax amount for approved later review.
- The fundraiser company (or its explicit accounts-payable contact) must be
  the payer. The internal notification mailbox is informational only.

## Manual accounting-package workflow

1. Verify the supplied shipping/tax amounts and approve each queued order.
2. Select approved orders (one order when cadence is `per_order`) and prepare
   the package.
3. Download the CSV and manually confirm QuickBooks customer, product/service,
   income account, and tax code before creating and sending the actual
   QuickBooks invoice in QuickBooks.

When the cadence is **Monthly review package on the last day of the month**,
Everbranch runs at 5:00 PM America/New_York on the final calendar day. It groups
only approved Zapier orders whose source date is in that calendar month, keeps
currencies separate, and creates the same immutable review package. It does not
approve orders. Default-off QuickBooks write and send gates control the later
provider actions independently.

The package begins at `review_required`, `not_sent`, and `not_available`. With
the write gate and exact customer/candle/shipping item IDs configured, the
command creates a deterministic `BSF-MMM-YYYY` invoice through the tenant-owned
OAuth connection. With the separate send gate enabled, it invokes Intuit's
invoice-send endpoint and stores the provider invoice ID, document number, and
created/sent timestamps. Delivery is currently forced to the controlled
`info@theforestrystudio.com` address through the dedicated send-to setting;
replays reuse that provider invoice.

## Prerequisites before enabling delivery or QuickBooks write-back

1. Obtain the payer's legal name, accounts-payable email, and billing address.
2. Confirm a real Zapier sample against the above contract and a production
   replay test.
3. Confirm an approved tax decision and how supplied tax/shipping values are
   reconciled.
4. Configure the exact QuickBooks customer, candle item, and shipping item IDs.
5. Enable the write gate for a controlled draft test, verify it in QuickBooks,
   and only then enable the separate send gate.

## Delivery/open status

Everbranch records a send only after Intuit's send endpoint succeeds. Payment
and recipient-open telemetry are not connected; do not infer either state.
