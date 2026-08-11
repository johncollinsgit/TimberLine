# Wholesale Email Messenger Runbook

## Scope

`/shopify/app/wholesale/messaging` is the embedded **MF Wholesale Backstage**
editor for the tenant/store-scoped draft named **Bring Modern Forestry to your
store**. It is available only to the configured wholesale Shopify installation
with `wholesale_operations` access.

The draft is stored in `wholesale_email_messenger_drafts`, never in browser
storage. Its approved sixteen content blocks are editable, while the
unsubscribe/privacy footer is appended server-side and cannot be removed in
the client. Product and image URLs remain part of the stored section snapshot.

## Sending safety

- This screen has no campaign or prospect-send capability.
- **Send test email** only sends to explicitly typed test addresses. It uses
  the established tenant email provider and records normal delivery evidence.
- Eventual campaign delivery must continue through the existing consent-aware
  campaign pipeline. That pipeline filters unsubscribed, bounced, and
  suppressed contacts; this editor must not add a bypass.
- Production must configure and verify `info@theforestrystudio.com` in the
  tenant's SendGrid sender settings before a live test can succeed. Set
  `MARKETING_EMAIL_FROM_EMAIL`, `MARKETING_EMAIL_FROM_NAME`, and
  `MARKETING_EMAIL_REPLY_TO_EMAIL` to the approved identity, keep
  `MARKETING_EMAIL_ENABLED=false` until verification is complete, then record
  the provider health check. Do not put an API key or verification status in
  this repository.

## Release smoke test

1. Open the route from Shopify Admin with a current embedded session.
2. Confirm Email Messenger appears before Suggestions and the default draft
   displays all sixteen editable blocks.
3. Save a harmless text edit, reload, and confirm it persists.
4. Switch desktop/mobile preview and confirm the compliance footer is visible.
5. After verified-sender readiness only, send one explicit test email to an
   operator-controlled mailbox. Do not send a campaign or contact prospects.
