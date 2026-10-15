# Subscription provider reads

Phase 2 adds an installation-specific, query-only Shopify Admin API adapter,
pinned to `2026-10`. This document describes the implementation; it does not
authorize enabling production reads, changing Shopify, charging a card, sending
email, registering webhooks, or selling the add-on. Provider execution,
background reads and self-serve purchasing remain disabled.

## Setup and access

Every explicit read requires an active entitled workspace owner/admin/manager,
`subscriptions.provider_reads_enabled=true`, and that exact tenant ID in
`subscriptions.provider_read_tenant_ids`. Both configuration gates default
closed. Page rendering and readiness inspection make no HTTP requests.

Setup selects an existing, explicitly tenant-owned `ShopifyStore` row. An
operator supplies the independently reviewed Shop, App and AppInstallation GIDs
and public client ID. Only that selected row's encrypted access token is copied
into a pending credential revision; global retail/wholesale configuration and
environment credentials are never fallback authority. The fixed identity query
must match the expected tuple, canonical domain and actual response API version
before verification. Granted scopes are read from Shopify. The current document
selects own contracts, masked payment instruments and policy anchors, requiring
own-contract read/write, customer read/write, payment-method read, and product
read/write grants. Missing scopes are a setup gap, never an empty inventory.

`verifyInstallation(tenantId, actor, shopifyStoreId, expectedIdentity, requestUuid)`
does initial verification or a same-identity access-token rotation.
`probe(tenantId, actor, installationId, requestUuid)` rechecks the pinned active
revision. A changed app, installation, shop or public client ID cannot rebind a
verified installation. Credentials are encrypted, hidden, immutable revisions;
rotation only switches the active pointer after successful verification and
retains old evidence. A token-only rotation carries forward the already pinned
same-installation signing secret/reference/revision/expiry unchanged. It never
copies a global app secret or invents a signing-secret expiry.

An initial expected-ID typo has a separate local recovery operation:
`correctPendingSetup(...)`. It requires no verified credential or installation,
accepted resource or live verification lease. It preserves encrypted before/
after audit evidence, terminally blocks unfinished/failed old probe UUIDs, and
requires a fresh explicit verification UUID. Verified identities cannot use
this correction path. Historical accepted observations cannot be reclassified.

The narrow command has no token/secret option and no default tenant, actor,
installation or global-store selection:

```text
php artisan subscriptions:provider-read readiness --tenant=ID --actor=ID --installation=ID
php artisan subscriptions:provider-read verify --tenant=ID --actor=ID --store=ID --request=UUID --shop-gid=GID --app-gid=GID --installation-gid=GID --client-id=PUBLIC_ID
php artisan subscriptions:provider-read probe --tenant=ID --actor=ID --installation=ID --request=UUID
php artisan subscriptions:provider-read sync --tenant=ID --actor=ID --installation=ID --request=NEW_UUID --cycle-start=1 --cycle-end=12
```

The `correct-setup` command uses installation/expected-identity options and a
new correction UUID. It records a local correction only, with fresh verification
still required. Console failures expose generic guidance and safe operation
status, never HTTP exceptions or decrypted envelopes.

## Resumable observations

Each `syncOwnedContracts(...)` call processes a bounded slice, staging private
resources in encrypted operation results. The installation holds only bounded
cursors, one page of pending contract identities, count/hash, generation and
active slice fence. The default slice reads two contracts; the installation has
no 100-contract limit. A new UUID resumes the existing generation. Reusing a
completed UUID returns that slice without HTTP; a safe transient failed UUID
can reclaim its original generation. Changed bounds, credentials or inconsistent
source evidence require an explicit reviewed restart (`--restart` for CLI).

The first pass fully paginates owned-contract identities and every selected
contract child connection. Each contract is checked against the bound app and
its exact unsigned revision at every child page and at a final core read.
Attempts/cycles each have two complete matching observations because they can
change independently of the contract revision. A second complete paginated
index pass compares ordered identity/revision count and digest. Only after that
pass succeeds does one short, fenced database transaction promote staged
resources, update last-complete count/time, and complete the final operation.
There are no network requests inside that transaction.

A slice's `status=completed` means that **read slice** completed. Its separate
`sync_complete=false` does not publish a total. `readiness.sync_progress` explains
the collect/verify/restart-required state. `owned_contract_count` remains null
until a whole generation completes; a fully verified empty result is valid zero.
Failures never publish a capped total, advance the accepted watermark, or erase
previous evidence. Resources absent from the latest complete observation are
retained with `not_in_latest_observation`; absence never means cancelled/deleted.

The active slice/generation/credential and lease are rechecked when accepting
results. An expired worker cannot overwrite a newer worker's attempt state or
stale its accepted result. Resource observation timestamps remain the original
read times. Overall freshness uses both generation start and completion, so a
long sync cannot rejuvenate old payment evidence. Stable consecutive reads are
a bounded observation, not a Shopify transactional snapshot or authority for
later financial execution.

Each individual contract must still finish its selected child pagination within
the explicit page/row/runtime/response/evidence-size limits (default 100 pages,
3000 observed rows, 75 seconds; explicit maximum 250/10000/90). Extremely large
individual contracts fail truthfully and retain the resume point; page-level
nested-contract continuation is a later scaling improvement. Encrypted staged
operation results are bounded to 262 KiB. A smaller slice can avoid aggregating too
much evidence; changing bounds requires a restart. Completed generations retain
their private operation evidence; an audited retention policy is needed before
high-volume rollout, and financial idempotency evidence must not be silently
evicted.

The adapter verifies actual response API version, rejects partial/errors, uses
fixed documents/validated variables, disallows redirects, canonicalizes the
Shopify host, limits streamed responses, and retries throttled safe reads once
with bounded delay. Raw JSON decodes unsigned64 values as decimal strings; no
float/int conversion or lexical revision ordering is used. Unexpected provider
errors become safe failure codes without chaining HTTP exceptions.

## Private resource and readiness limits

Trusted resource types are contract, contract_line, payment_method,
billing_attempt, cycle and order_link. Payment methods are customer-scoped per
installation, not reparented to one contract. Cycles use contract+cycle index;
order links use contract+actual order ID so two contracts may share one order.
Only provider-confirmed success-state order links are projected. A deleted/null
order remains a successful attempt with unavailable order evidence. Masked
instrument details and action URLs stay encrypted and are not ordinary UI
links. Unknown/redacted payment fields do not prove there is no saved method.
Source decimal money/currency and exact billing instants are preserved, and
selected contract currency amounts must agree. Historical local subscription
rows, customer/payment vaults, Shopify orders and Website shipping are untouched.

Offline `readiness(tenantId, installationId)` returns safe identity, credential,
scope, freshness, complete count, progress and blocker fields. Selling-plan
counts remain unknown; historical provider observations are not runtime
defaults. `webhook_signing_secret_configured` only means pinned local material
exists. `webhook_setup_complete` stays false until reviewed registration/API
version/delivery evidence exists. No registration calls are made by this adapter.

Automatic expiring-offline-token acquisition/refresh, scope acquisition,
reinstallation, customer-facing servicing, retries/charges, contract changes,
payment-update emails, cross-provider adoption, public-app distribution billing,
scheduled reads and merchant acceptance remain separate gates. A legacy token
with unknown expiry is explicitly reported as such, not called expiry-ready.

## Workspace and webhook intake

`GET /subscriptions` requires a verified Everbranch sign-in, tenant access and an
active entitled manager membership. It displays saved installation prerequisites,
last-complete counts and contract references/statuses/observation times, with
tenant-scoped pagination, reference search and status filters. Paused/cancelled
history is retained. Failed or incomplete reads remain unknown rather than zero;
an in-progress sync retains the previous accepted total. Opening or filtering the
page never contacts Shopify, reveals credential/snapshot payloads or enables an
operation. Customer-facing history and detailed servicing are later work.

`POST /webhooks/subscriptions/{publicId}` accepts a verified installation's opaque
UUID and exact canonical shop header. It verifies raw bytes against only that
installation's pinned current signing secret or explicitly approved previous
secret with a maximum 15-minute rotation window. Access-token revisions sharing
one signing revision do not displace the previous approved signing key. There is
no global secret fallback. Initial token-only setup returns setup-incomplete
until signing material is separately configured through a reviewed process.

The unique inbox key is installation plus delivery ID; the provider event ID is
correlation only. Encrypted verification evidence retains the exact signing
reference/revision. Conflicting deliveries preserve the original body and its
signer plus the first conflicting body's signer, and quarantine the record.
Authentic unsupported-version, malformed or paused deliveries remain encrypted
quarantined evidence. Intake does not project resources, dispatch work, send mail
or call a provider. This endpoint has not been registered with Shopify or proven
with an actual signed delivery. Earlier legacy webhook evidence is retained.

## Validation and release

The staged implementation passed 154 subscription tests (845 assertions), real
MySQL partial-DDL/retry/concurrency recovery (2 tests, 21 assertions), migration
lint and frontend build. Independent review found no remaining P1/P2 findings.
Native browser QA used fictional isolated data at 1440 and 390 pixels, including
verified empty, retained paused/cancelled history, pagination/filtering and sync
progress states. No live customer, card, message or provider mutation was tested.
Only the disposable schema-baseline workflow may generate the five-table schema
addition; exact-head CI and the protected production migration gate are required
before release. Gates remain disabled after deployment until reviewed acceptance.

## Provider references

- [AppInstallation](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/AppInstallation), [App](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/App), [Shop](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/Shop)
- [API versioning](https://shopify.dev/docs/api/usage/versioning)
- [SubscriptionContract](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionContract), [owned-contract query](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/subscriptionContracts)
- [SubscriptionLine](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionLine), [SubscriptionManualDiscount](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionManualDiscount)
- [Billing attempts](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionBillingAttempt), [billing cycles](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/subscriptionBillingCycles)
- [CustomerPaymentMethod](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/CustomerPaymentMethod), [SellingPlanAnchor](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SellingPlanAnchor)
- [Access tokens](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens), [refresh recovery](https://shopify.dev/changelog/posts/more-resilient-refreshes-for-expiring-offline-access-tokens)
