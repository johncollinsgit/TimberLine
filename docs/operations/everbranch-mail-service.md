# Everbranch mail service rollout

## Current product contract

Everbranch's Email Branch now has tenant-owned domains, shared addresses, user grants, inbox/sent/trash views, search, compose, read/unread, and stars. Customers can open **Set up Mail**, record an address, and request Email Branch access without an active entitlement. The address stays pending. SendGrid remains an available transport. Direct mail is a gated option backed by a dedicated Stalwart server; Everbranch submits over authenticated TLS and syncs incoming mail through JMAP. The app never presents a pending address as able to send.

The Wren workspace is tenant slug `wren-family-soccer`. Its desired address is `info@easleyfamilysoccer.com`. Stage it after this code is deployed with:

```sh
php artisan mailboxes:prepare wren-family-soccer info@easleyfamilysoccer.com --transport=direct
```

This creates only the pending Everbranch record and grants existing active workspace admins access. The same record can be created from the tenant's **Set up Mail** page. It does **not** alter DNS, create an external mail account, or claim that the address receives mail.

The customer setup page generates a unique `_everbranch-mail` TXT ownership record. For a Cloudflare-hosted domain, an admin can enter the zone ID and a token scoped to that zone with **Zone Read** and **DNS Write**. The token is used for the request and never stored. The action creates the TXT proof idempotently. It creates MX only when the selected transport is provisioned, there is at least one provisioned direct mailbox (or a verified SendGrid sender and inbound secret), and Cloudflare has no other MX. Existing MX is preserved and the UI asks for a migration plan. DNS at other providers uses the same displayed records and a manual check. A Cloudflare OAuth or Domain Connect flow could later remove the token-entry step, but requires provider app/template registration and review.

## Dedicated mail host

Use a separate VM with a static IP and a hostname such as `mail.theeverbranch.com`; do not bind Stalwart's 443 port on the live web droplet. The Compose file in `ops/mail` binds SMTP submission and IMAPS, while JMAP/admin HTTPS is reverse proxied to localhost port 8443. Keep port 8080 local and remove the bootstrap recovery credential after setting a permanent administrator. Restrict SSH and management access, patch the host and pinned Stalwart version, and back up both persistent volumes with restore tests.

Stalwart Community Edition supports the mail protocols and management API. Its native multi-tenant isolation is an Enterprise feature; Everbranch must retain its own tenant and mailbox access checks, and the shared server administrator remains a privileged cross-tenant operator.

The mail host must be provisioned and tested before setting `EVERBRANCH_MAIL_DIRECT_ENABLED=true`. Configure:

- `EVERBRANCH_MAIL_HOST` and `EVERBRANCH_MAIL_MANAGEMENT_URL` (HTTPS);
- a scoped `EVERBRANCH_MAIL_MANAGEMENT_API_KEY` with Domain/Account create rights;
- `EVERBRANCH_MAIL_SUBMISSION_HOST` and `EVERBRANCH_MAIL_SUBMISSION_PORT` (587 with TLS);
- `EVERBRANCH_MAIL_DIRECT_ENABLED` only after the checks below.

The management key stays on the Everbranch server. Each mailbox gets its own random 64-character submission/JMAP password, encrypted in the Everbranch database. Never copy those credentials into the Site repository or browser. The app's admin controls then provision the domain and address on Stalwart.

## DNS and delivery checks per customer domain

Before activating `easleyfamilysoccer.com` or any later domain:

1. Confirm who controls the domain and whether any existing MX is in use. Replacing an MX routes all incoming mail for that domain to the new host.
2. Publish the unique ownership TXT record and verify it. Then point the domain MX to the mail host only after it is accepting mail; make the host's A/AAAA and reverse PTR agree. Ensure inbound TCP 25 and outbound TCP 25 work from the VM.
3. Publish the SPF record for the sending host, Stalwart's generated DKIM records, and a DMARC policy. Get exact DKIM values from the server's domain zone output; never invent keys.
4. Install a valid TLS certificate for SMTP/JMAP and test submission on 587, inbound delivery from an unrelated provider, replies, and external delivery to Gmail and Microsoft.
5. Check SMTP queue, bounces, spam classification, log volume, rate limits, reputation, and blacklist status. Keep backup/restore and on-call alerting active.
6. Only then set the direct transport flag, run the Mail admin **Check DNS & sender** action, and send a controlled end-to-end message.

No MX record was published for `easleyfamilysoccer.com` at inspection on 2026-10-06. The address must stay pending until the DNS and delivery checks pass.

## Monitoring and recovery

Run `mailboxes:sync` every minute; it is scheduled by Laravel only when direct mail is enabled. Logs include tenant and mailbox IDs on failures, without passwords or message bodies. Check that `last_synced_at` advances and the SMTP queue drains. A sync run stops with an error if more than 10,000 messages meet its query window, so a large backlog is visible rather than silently skipped.

Before a transport cutover, lower DNS TTL, back up mailbox data, and send/receive from a test address. Keep the old transport accepting mail during propagation. Rollback by restoring MX and disabling direct delivery; do not delete either provider's stored messages during rollback.

## Known launch gaps

The current Everbranch UI stores and displays plain-text bodies. Attachments, drafts, labels, spam review, reply threading headers, advanced search, and delivery-event receipts still need implementation before positioning this as a full replacement for a general-purpose mail provider. No production domain or mail server has been activated by this code change.
