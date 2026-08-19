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
  Commerce, QuickBooks write-back, or platform `tenant_direct_invoices` for
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

The package shows `review_required`, `not_sent`, and `not_available` for
tracking. Those are honest status values, not provider telemetry.

## Prerequisites before enabling delivery or QuickBooks write-back

1. Obtain the payer's legal name, accounts-payable email, and billing address.
2. Confirm a real Zapier sample against the above contract and a production
   replay test.
3. Confirm an approved tax decision and how supplied tax/shipping values are
   reconciled.
4. Formally expand the QuickBooks integration from its current read-only
   contract with explicit customer, product/service, income-account, tax-code,
   permissions, audit, error/replay, send, webhook, and rollback approvals.
5. Use a controlled test invoice before production delivery.

## Delivery/open status

QuickBooks send/payment/webhook telemetry is not connected to this lane. Do not
claim an invoice was sent, opened, or paid from Everbranch until a separately
approved QuickBooks write-back and telemetry contract exists. If open visibility
is later required, document the provider telemetry and customer disclosure
before enabling it.
