# Wholesale application reliability

## Scope and contract

- Classification: repair of the existing wholesale operations capability and tenant configuration.
- Tenant scope: the configured wholesale Shopify store and its owning tenant; applications, review recipients, and decisions remain tenant scoped.
- Billing: existing wholesale_operations entitlement; no billing change.
- Reuse CustomerAccessRequest, TenantFormSubmissionService, ShopifyWholesaleCustomerApprovalService, operator audit, and existing scheduler/queue.
- Other wholesale tenants use the same services with their store and recipient configuration; Modern Forestry defaults belong in configuration.
- Wholesale buyers receive Shopify storefront access only. Approval must never grant an internal operator account, tenant membership, or Everbranch password reset.
- Application and form record commit together. A durable receipt is returned only after persistence. Review and decision delivery have persistent state and scheduled retry.
- Approval synchronizes the Shopify wholesale tag before reporting success. Provider failure leaves the decision pending and retryable. Rejection never grants access.
- Navigation target: home → application → submit; notification → application → approve/deny; application inbox → details → approve/deny.

## Audit baseline — 2026-09-15

Production held four June 30 applications (IDs 2–5): one pending and three rejected. No form-submission mirrors existed. The notification defaults and production mapping pointed to the former Gmail inbox, while Shopify shop email was already info@theforestrystudio.com. The live theme differs from the GitHub backup and was backed up before editing.

Safari initially reported script integrity mismatches from Shopify CDN. Private browsing reached login. After cache clearing and renewed navigation, both Shopify Admin and the wholesale application detail rendered successfully. The sequence does not isolate cache clearing as the sole cause.

## Account origin audit

Shopify customer event timelines attribute the August 4 Kelli account to Shop.
The Bonnie (August 4) and Megan (August 2) accounts match abandoned checkout
creation timestamps exactly. Both checkouts held one candle. None of these three
has the wholesale tag or a backend application. The live account login exposes
Shop sign-in; `/account/register` renders an apply-first message. Newsletter
signup in the active footer is disabled. Shopify-hosted checkout and Shop account
creation are distinct from application capture; a customer record is not proof
of a submitted or approved application.

Mailbox searches across the wholesale and owner inboxes, including spam/trash,
found June 30 test application notifications and no new application notices
from July 1 onward. July 1 threads were follow-ups on older applications.
This establishes the last observed successful capture; it does not establish
that every attempted application since then failed.

## Delivery and operation

- `wholesale:deliver-applications` runs each minute. `metadata.delivery` records
  pending, failed, sent, attempt count, and next retry. Backoff caps at one hour;
  a failed message remains retryable. `sent` means accepted by the mail provider,
  not proof of inbox delivery. SMTP cannot guarantee exactly-once delivery if
  the process dies between provider acceptance and recording success.
- Existing records are preserved. Do not mass-approve or send decisions to past
  applicants during verification. Backfill form mirrors separately if needed.
- Production review email can be overridden by tenant access-profile metadata or
  `WHOLESALE_APPLICATION_REVIEW_EMAIL_MODERN_FORESTRY_WHOLESALE`; verify both at
  release. The requested Modern Forestry destination is info@theforestrystudio.com.
- UI changes publish only the application section, its two assets, and the legacy long-form template. Retain the
  downloaded live theme for rollback; the old GitHub theme backup was stale.
- Verify with two explicitly marked internal test applications, one approved and
  one denied. Confirm saved form, staff mail, Shopify tag on approved only,
  customer mail, duplicate suppression, and no internal staff access.

The legacy long-form template delegates to the canonical section. The published
page sitemap and rendered forms were checked: the current application and
general contact page are the active capture paths; registration directs to apply.
Three legacy utility pages returned intermittent Shopify 503 responses.

Local release checks: 2,573 tests / 18,288 assertions, Modern Forestry 150 tests /
805 assertions, Pint and frontend build passed.
