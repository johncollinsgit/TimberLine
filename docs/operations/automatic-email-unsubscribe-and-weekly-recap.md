# Automatic marketing unsubscribe and weekly Everbranch recap

## Plan and implemented flow

1. Replace the birthday email's mailto link with a recipient-specific HTTPS link.
   The opaque encrypted token binds one tenant and normalized email address.
   GET shows a single Unsubscribe button; POST records the opt-out immediately.
   Browsing or a security scanner's GET cannot unsubscribe someone. No login,
   email reply, reason, or staff action is required.
2. Add `List-Unsubscribe` and `List-Unsubscribe-Post` for RFC 8058 mail-client
   one-click POST. Both HTML and plaintext contain the link. Verify delivered
   messages' DKIM signature covers these headers before claiming the inbox UI
   will offer one-click; the footer works independently of the inbox UI.
3. Reuse existing contact channel states and consent events. Update all matching
   profiles inside the same tenant, preserve SMS consent, and record transitions
   once. Repeated POSTs succeed without duplicate consent events. Verified
   explicit re-consent updates the matching profiles; imports cannot restore a
   suppressed address. Provider opt-out events use the same service.
4. Recheck address-level suppression immediately before both legacy and platform
   marketing provider sends. Include historical explicit profile opt-outs as a
   fallback so an older opted-out profile cannot be bypassed by a new duplicate.
   Operational and transactional email do not acquire marketing unsubscribe
   behavior; the recap is an internal operational report.
5. Expand the existing weekly rewards/wishlist summary. It sends to
   `info@theforestrystudio.com` Monday at 08:00 America/New_York, replacing the
   former 08:30 summary. No additional Codex reminder or duplicated email job is
   needed. The native Laravel scheduler owns this recurring report.

## Recap

The report covers the preceding Monday 00:00 through this Monday 00:00 Eastern,
with an exclusive upper bound converted to UTC for database reads. This preserves
calendar weeks across daylight saving time. It includes distinct customers newly
recorded in Everbranch (including imports, excluding pre-existing duplicate
addresses), distinct recorded email opt-outs and the customer/email/time list,
birthday rewards issued and redeemed, birthday discount face values, Candle Cash
credited, codes issued and actually redeemed, email sends/failures, wishlist
additions and storefront health. Applying a code is not a checkout redemption.
Candle Cash credited and code issuance are separate measures, not additive totals.

The HTML follows the October 12 Trajectory review email: green Everbranch branding,
a pale background, a rounded white card, a concise activity table and a green
application link. HTML and plaintext are provided. The recap intentionally contains
unsubscribe identities and is sent only to the requested internal inbox. Lists
are capped at 100 addresses while totals include all records.

The scheduled command holds a cache lock and stores an `operator_recap` delivery
record keyed by tenant, recipient and exact reporting window. Replays skip sent
or in-progress records. An ambiguous transport failure stays in `sending` and
requires provider reconciliation before a retry, preventing accidental duplicate
emails. Log/array mailers do not count as production delivery.

```sh
php artisan marketing:send-weekly-rewards-wishlist-summary --tenant=modern-forestry --email=info@theforestrystudio.com --calendar-week --dry-run
```

## Historical email requests

Already delivered October 2 messages retain their original mailto links. The
four verified inbox requests (Jackie, Jessie, Joyce and Lindsey) should be
reconciled through `MessagingContactChannelStateService::markEmailStatus`, using
their verified mailbox message IDs and original request timestamps as metadata.
Do not send a new birthday cohort or reset reward history. Joyce's remaining
subscribed duplicate is the confirmed repair target.

Email replies can only be processed automatically if the legacy inbox is routed
into authenticated SendGrid inbound parsing. This deployment does not reroute
Gmail or modify MX records. Marketing inbound processing fails closed with HTTP 503
when its secret is missing; the existing landlord-only prospect reply capture
continues through its original path. A normal reply quoting an unsubscribe footer must
not be classified as an opt-out. The new HTTPS unsubscribe flow requires no
inbound domain or Gmail forwarding and is the primary route for new messages.

## Validation and release

- Test token tampering, no-session POST, harmless GET, repeated POST, duplicate
  profiles, tenant isolation, SMS preservation, import suppression and explicit
  re-consent. Check both provider sending boundaries and provider headers.
- Test distinct weekly counts, true reward redemption, exact week boundaries,
  DST, one scheduled Monday 08:00 event and replay suppression.
- Run the Modern Forestry regression suite, broader marketing tests, PHP lint
  and the normal GitHub test/build and Migration Safety gates. No schema change
  is required. Deploy only through GitHub/Forge atomic releases and verify
  `/ready` against the merged commit.
- Preview the real previous-week recap before sending. Verify a delivered test
  message's HTML, plaintext, unsubscribe headers and DKIM; verify production GET
  and POST using a known requested opt-out, then verify the next send is blocked.
- Confirm the native scheduler lists Monday 08:00 America/New_York. The first
  regular report after the October 12 implementation is October 19 at 08:00 EDT.
  Never run the birthday catchup or a customer campaign merely to test this change.

## References

- [RFC 8058 one-click unsubscribe](https://www.rfc-editor.org/rfc/rfc8058.html)
- [SendGrid suppression groups](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/suppressions)
