# Shared customer profiles: source candidate

November 7, 2026. Everbranch Core, Website accounts, Teams and Pay reuse the
canonical MarketingProfile and explicit source links. This increment adds
behavior, declared preferences, event value and personal-message preparation
without adding another customer, payment, rewards or delivery ledger. Source
implementation is not production activation or complete lifetime coverage.

## Customer access and purchase behavior

The Customers navigation entry, Find customer action and global search open
`/workspace/customers?tenant=...`. Active verified tenant members can read Core
customer profiles independently of global Marketing roles. Search reuses
`TenantCustomerLookup` for literal names, normalized phone numbers and canonical
aliases; global search returns at most five safe summaries. The directory pages
20 customers at a time and can use a reviewed event context. Archived profiles,
foreign tenants and the Soccer-specific workspace remain excluded. Marketing
imports, internal notes and campaign controls retain their separate permissions.

`CustomerCommerceSnapshotService` reloads the canonical identity before reading
original linked purchases. `CustomerBehaviorProfile` normalizes supported order
lines and frozen POS bundle components, retaining source IDs and locations.
Product identities require stable original IDs; scents require explicit reviewed
identities. A product title, cardholder name, phone similarity or arbitrary SKU
does not connect identities or infer scents. Explicit scent splits take priority
over a parent line. Inconsistent splits withhold scent ranking.

The shared authenticated component shows online/in-person channel observations,
products, most-purchased scents and original purchase details on Core and Website
profiles; Teams and Pay use the same snapshot. Refunded, unconfirmed or unknown
refund allocation does not contribute invented item quantities. The current
projection is bounded to retained linked history, normally the latest 100
purchases; it does not claim complete lifetime totals. Source freshness and
purchase dates are separate, and missing item identities remain visible gaps.

Pay additionally exposes a default-off current-catalog recommendation endpoint.
It orders by distinct eligible purchase occasions, then units, then original
catalog order across linked channels. Only current assigned-register products
and reviewed tenant mappings qualify. Declared avoided scents stay visible but
are not promoted. Declared favorites never rewrite observed purchase frequency.
See `customer-event-value.md` for exact evidence and client fences.

Native standalone lookup uses a separate model and separate capture/enrollment
journals. Browsing, cancelling and lookup authentication expiry do not mutate
the shopping model, payment recovery, basket or rewards proof. Only an explicit
Shop action can copy a selected customer after matching the current session,
origin, register and idle payment state. Recommendations promote eligible rows
only; absent evidence retains the normal product order.

Lookup capture clears its exact retained journal only after a known pre-create
rejection or successful original-request recovery. Unknown failures and recovery
404s remain retained. New journals store the original API origin; explicit
GET-only capture recovery after reauthentication additionally requires that exact
origin, workspace and register. The server's request key binds the authenticated
actor and device, so another account cannot recover the original. This does not
replay a POST. Older originless capture journals remain session-bound. Enrollment
has no request-status GET and remains sealed after session replacement; staff
resolution for that case is still a required recovery extension.

## Declared choices and consent

`CustomerPreferenceService` stores the current tenant-owned preference snapshot
in existing settings and before/after evidence in the append-only operator audit.
Commands require an immutable UUID, optimistic version and explicit changes.
Aliases, concurrent updates and replay are handled under CustomerAccountFence.

Supported declared choices include favorite/avoided scents, preferred channel,
receipt email/text choices and rewards communications. Null remains unknown;
false remains an explicit decline. Purchases and gifts never populate declared
favorites. Staff changes require customer confirmation; the Website account
surface uses the existing authenticated customer identity. Positive marketing
opt-in requires existing verified evidence, and verified contact changes retain
their original review authorities. Selecting no marketing channel revokes both
marketing channels. Receipt preferences do not enroll a customer in marketing.

## Event acquisition, value and membership

The seven accepted integration additions have these source surfaces:

| Requirement | Current surface and limit |
| --- | --- |
| Distinct acquisition, purchase and membership attribution | Core journey and Teams event customer-value panel; original evidence plus explicit owner review, no causal inference |
| 30/90/365-day value | Separate currency windows, refunds/tax basis, elapsed maturity and incomplete coverage; missing contribution costs remain unknown |
| Purchases versus declared preferences | Separate observed behavior and explicit favorites/avoids; purchase frequency is never a preference declaration |
| One customer preference center | Authenticated Website account and authorized staff surface share versioned preferences and consent evidence |
| Outreach outcomes and contact limits | Frozen draft review shows original approval/delivery records; published tenant limits share email/SMS evidence. Experiment assignment and causal campaign lift are not implemented |
| Visible data quality and duplicate suppression | Canonical aliases, explicit original-source identities, source coverage and missing/mapping/refund warnings |
| Explanation and audit history | Frozen preparation evidence, independent content hash, policy/preference versions, current admission decisions and review audit |

Captured contact, confirmed event purchaser, newly created record and a reviewed
new customer are distinct. Event summaries cover the displayed page only, at most
100 customers; changing pages replaces that scope. Subsequent online orders and
verified original subscription starts can be reviewed against an event without
claiming the event caused them. See `customer-event-value.md` for review commands,
source history, classifications and reporting denominators.

## Personalized message workflow

Current verified tenant owners/admins with Customers and Campaigns access can
prepare one-customer drafts from a Core profile. Supported placeholders draw from
current preferences, rewards and recorded purchase behavior. Email sections use
the existing safe formatter, escaped text and HTTPS buttons. Unavailable tokens
require review before saving. There is no AI/provider request in preparation.

Saving creates an existing campaign, one frozen variant and one recipient.
Replaying the request returns the original draft. The dedicated review page
shows content, current checks, original approvals and original delivery evidence.
Approval does not send. Explicit send uses a durable handoff reservation and
the existing sender, which rechecks identity, contact, current preferences,
content integrity, suppression, frequency, readiness and approval. An interrupted
or unknown handoff retains the original evidence and cannot become a replacement
send through this page.
The exact outgoing HTML, text and subject must match the content validated under
the admission lock. Edited/rehashed content cannot acquire approval against a
different immutable preparation record, even if mutable render hashes match.

Both CUSTOMER_PROFILE_EMAIL_DISPATCH_ENABLED and
CUSTOMER_PROFILE_SMS_DISPATCH_ENABLED remain false. Existing transport retry and
provider-reconciliation acceptance is required before activation. Receipts remain
separate transactional flows. No messages, policy publications or customer consent
changes occurred outside disposable fictional fixtures.

## Verification and remaining acceptance

Focused integration, safety, preference, discovery, event value, recommendation,
policy, admission and review tests run against disposable SQLite and MySQL. The
dedicated Teams workflow also runs real independent-process cross-channel cap,
same-recipient and legacy unsubscribe races with recording provider fakes.
CustomerAccountFence acquires its MySQL mutex before any consistent SELECT;
an information-schema probe before a lock wait previously established a stale
REPEATABLE READ view and is now avoided. Provider handoff remains outside locks.
Nested identity imports also use current locking reads for reviewed account
profiles and canonical alias chains; an outer transaction's older snapshot
cannot silently invalidate a concurrently restored or merged account mapping.
Both writer orders, nested/plain imports, alias changes and missing-schema
recovery have dedicated disposable MySQL regressions alongside the existing
identity/QR, policy/descriptor and fully redeemed wallet checks.

`node tests/e2e/customer-profile-live.browser.mjs` exercises real Fortify/Laravel
HTTP against a private disposable SQLite database with fictional records. Build
the current Vite assets first. It verifies directory and global phone search,
observed scents versus declared favorites, null versus false receipt choices,
sandboxed formatted previews, replay-safe draft/save/review/approval, default-off
send blockers, CSRF and member/tenant isolation. Desktop (1440×1000) and mobile
(390×844) screenshots include the rendered email, with no page overflow or runtime
errors. The guarded fixture/router blocks provider HTTP and outgoing mail; final
checks retain one campaign/approval and zero deliveries or marketing consent
events. JSON/screenshots default to `test-results/customer-profile-browser`;
`CUSTOMER_PROFILE_EVIDENCE` can select another local evidence directory. This is
local browser evidence, not provider delivery or physical-device acceptance.

Live source mappings, full history pagination/indexed reporting, real provider
timeout/reconciliation acceptance, campaign experiments and causal outcomes,
physical-device accessibility/recovery, signed app distribution and protected
production rollout remain open. Modern Forestry stays Asana-authoritative during
2026; none of these customer additions enables a migration or cutover.
