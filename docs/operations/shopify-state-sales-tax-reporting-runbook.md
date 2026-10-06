# Shopify State Sales Tax Reporting Runbook

## Purpose

The embedded **Sales Tax Reports** workspace provides two tenant-scoped,
read-only reconciliation presets for imported Shopify orders:

1. **State Sales Tax Summary** groups delivery-address records by state.
2. **State Sales Tax Detail** lists the delivery address, order date, sales
   proxy, refunds, and tax collected for jurisdiction verification.

It is designed to make the source data reviewable before a return is prepared.

## What the report does

- Reads only the current tenant's imported Shopify orders for the embedded
  store and selected date range.
- Shows delivery state, city, postal code, and address-level detail.
- Calculates the displayed sales proxy as imported order subtotal less recorded
  refund total.
- Shows the imported tax total separately.

## What the report does not do

- It does not decide taxability, infer county/municipality/local tax, calculate
  a filing liability, create a return, submit a return, or make a payment.
- It does not mutate Shopify, Square, QuickBooks, orders, customers, or any
  provider connection.
- It does not use Website Commerce data or merge Shopify data into another
  commerce lane.

## Operational use

1. Open **Sales Tax Reports** in Modern Forestry Backstage.
2. Set the filing period and, when reconciling a state return, enter its state
   code (for example, `SC`).
3. Compare the summary to Shopify's tax report.
4. Use the detail addresses to verify county and municipality with the tax
   authority's source of truth before entering a filing worksheet.
5. Resolve missing delivery addresses in Shopify/import history; do not replace
   them with inferred jurisdictions.

## Data refresh

Newly imported Shopify orders retain city, province/state code, postal code,
and country code from the source delivery address. Existing imported orders
gain these fields on their next normal read-only order import. Do not run an
unscheduled import solely to populate this report.
