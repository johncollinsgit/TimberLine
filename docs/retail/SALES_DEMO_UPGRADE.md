# Retail tablet sales upgrade — 2026-10-16

This upgrades the existing Retail PWA in place. The Galaxy package is
`org.chromium.webapk.a38770087122f0610_v2`; Studio, Field and Showcase are separate
apps. Keep the Retail manifest ID, start URL, origin and browser data. No new app,
uninstall, schema migration, production release, live merchant activation, or
payment-provider setup is part of this change.

## Offer verification

The previous showroom offer read Managed Website's $99/month plus $499 setup.
The requested Retail offer is a separate named entry in the canonical
`module_catalog.launch_offers.retail_launch_partner` configuration:

| Item | Terms |
|---|---|
| Months 1–6 after activation | $89/month |
| Month 7 onward | $149/month |
| One-time setup | $499 |
| Eligibility | First 10 qualifying businesses; operator review and written confirmation |

Both monthly prices appear side by side. The expiry is explicit, including on the
final signup review and confirmation email. The existing weekly strategy call and
twenty storefront demonstrations remain. Managed Website add-on and existing
customer billing prices are unchanged. No Stripe price, subscription, usage
entitlement, or reservation is created. Do not invent a remaining-place counter.
Qualifying/activating a business and enforcing its written offer must occur through
the authorized commercial/onboarding process before any payment or access grant.

## Signup

**Get Started** collects business/contact name, email and phone, selects the launch
offer, then shows a second review screen with both rates, setup and eligibility.
The customer must accept the displayed terms before requesting activation.

The owner-only, CSRF-protected, rate-limited server route uses the existing
`CustomerAccessRequestService` with production intent and no requested tenant slug.
New user records are inactive. There is no membership or demonstration-tenant
access. An existing request for another business or an approved account requires
operator review. The configured owner row serializes retries, including first
inserts. Repeated requests reuse the receipt and do not send another email.
Changed offer versions cannot silently replace previously accepted terms.

The existing prospect CRM receives a linked request. Existing research, contact
records and notes are preserved. The server records a no-billing-impact audit.
The browser never persists the registration in local/session storage, service
worker caches, an offline queue or application history. Forms clear on close,
backgrounding and navigation. Offline registration is disabled. There is no card
entry, password collection, payment or automatic tenant provisioning.

`RetailSignupConfirmationMail` includes the recorded offer and pending status.
Enable `RETAIL_SIGNUP_CONFIRMATION_ENABLED` only with an approved real transactional
mail transport. Array/log mailers never report a successful send. A claimed mail
attempt prevents duplicate submissions from sending again; failures or an
interrupted sending state need operator follow-up rather than automatic resend.
Provider submission does not prove inbox delivery. The local showroom still uses
the array mailer, so it truthfully reports **confirmation pending**. No actual
prospect registration or email was used for UI testing.

## Live OpenAI

The **AI assistant** section contains voice, email, SMS, virtual-assistant and
personalized-assistance demonstrations. Social Media Marketing stays **Coming Soon**.
The private assistant route reuses `services.openai.api_key` (with the established
AI Branch/Bud credential fallbacks), calls OpenAI Responses and returns a bounded
reply and an allowlisted page suggestion. No secret or provider error body reaches
the tablet. No third-party JavaScript calls OpenAI directly.

Configuration:

- `RETAIL_SHOWROOM_AI_ENABLED=true` for the approved demo server only.
- `RETAIL_SHOWROOM_AI_MODEL`, falling back to the configured AI Branch model or
  `gpt-4.1-mini`. Use a model supporting Responses structured output.
- Existing protected server-side OpenAI credential; never paste it into a chat,
  frontend, code, screenshot, backup or tablet storage.
- Thirty attempts per configured owner per hour, an independent route rate limit,
  bounded input/history and 700 output tokens. No automatic provider retries and
  no customer AI invoice/overage is created by the demonstration.

Only the selected demo's public catalog sample and canonical offer provide server
business context. Free text/history are untrusted input; they cannot select a live
tenant, grant permissions or invoke a tool. No customer records, private prospect
research, operational financials or other tenant data are attached. Conversation
history is transient and limited to four turns; closing/navigation clears it.
The provider request uses `store: false`. This setting is not a promise about
provider-wide abuse-monitoring retention.

The **Talk with AI** mode now adds an explicit, context-aware Realtime audio
conversation. It checks internet/server/provider readiness before microphone access
and ends audio on close, backgrounding, connection loss or its five-minute limit.
See [VOICE_DEMO.md](VOICE_DEMO.md) for configuration, privacy and verification.
The separate typed/browser-speech fallback retains reviewed input, allowlisted page
navigation and optional read-aloud. Email/SMS outputs remain drafts only; sending,
audience consent and live activation retain their existing controls.

Guided examples are clearly labeled and remain usable when live AI is unavailable.
Provider failures never masquerade as live AI. A genuine OpenAI draft was verified
through the local web UI using the owner's existing hosted credential. The
credential was loaded into the demo server process memory only; no hosted
application settings or production files were changed.

## Offline and tablet operation

Cache v6 includes the public sales and voice modules and removes prior shell caches. Use
**Prepare offline demos / Refresh offline demos** to refresh the sanitized pack.
Only public shell/assets and fictional sample data are cached; signup, AI and other
private APIs remain excluded. The updated assistant and pricing reopen offline;
live AI and signup remain unavailable until the session is verified online.

The existing localhost install still needs this Mac's server and an ADB reverse
connection for live operations. Live AI additionally needs internet access. This
upgrade does not publish an owner demo to production or make a local install
independent of the Mac. Keep the Mac awake and the verified server running.

The Samsung SM-T577U was identified through ADB. Its existing installed Retail app
opened the new pricing and signup form during physical testing. Wi-Fi changed from
the hotspot to another LAN and the debugging transport dropped before the final
signup-touch/AI/offline checks. Do not report those physical checks complete.
The owner's preference is to keep using Wi-Fi; do not ask to switch to USB again.
Discover the current `_adb-tls-connect._tcp` service, verify the Galaxy identity,
then restore the task's reverse/DevTools forwards. Do not remove pairing records,
uninstall apps, disable security or clear tablet data to reconnect.

## Verification and release

Focused PHP tests cover request isolation, deduplication, stale prices, injected
tenant/card fields, mail readiness, OpenAI owner access, missing configuration,
quotas, untrusted navigation and safe provider failures. Browser checks cover all
twenty retained storefronts, landscape/portrait pricing, signup review/privacy,
pending status, six assistant features, real OpenAI response, typed navigation,
and offline fallback with private API exclusions. UI signup submission is mocked;
the server flow uses synthetic records in the isolated PHPUnit database.

Commands:

```sh
php artisan test tests/Feature/Retail
node scripts/retail/sales-upgrade-check.mjs
RETAIL_QA_LIVE_AI=1 node scripts/retail/sales-upgrade-check.mjs
RETAIL_QA_LIVE_AI=1 RETAIL_QA_DEVICE=1 node scripts/retail/sales-upgrade-check.mjs
vendor/bin/pint --dirty
```

The live flag deliberately makes one paid provider request with sample text.
Headless checks use Playwright's installed Chromium by default; set
`RETAIL_QA_BROWSER_EXECUTABLE` if this workstation uses a separately installed
compatible browser. The existing private local-access fixture is required.
The device flag attaches to the already-open Retail page through the task's local
DevTools forward. It reviews signup without submitting a real registration.
Android's soft keyboard is dismissed before the review action; final touch
validation is pending reconnection. Evidence stays in ignored
`output/retail/sales-upgrade/` and contains no credentials.

The Modern Forestry guard passed **156 tests / 845 assertions**. Its first run used
the local showroom host and failed one signed-link fixture expecting the production
hostname. Re-running with the fixture's canonical host variables passed; no Shopify,
rewards, finance-report source or test expectation was changed.

The final Retail suite passed **59 tests / 633 assertions**, scoped Pint passed,
and the final guided/offline browser run passed **9 scenario groups** with zero
page errors. A separate earlier browser run verified a real OpenAI response.

This remains on draft PR396 with the existing Showcase dependency. Use the normal
GitHub test/build and protected Forge atomic-release process for any later approved
publication. No production deployment, billing activation or merchant send was
performed.
