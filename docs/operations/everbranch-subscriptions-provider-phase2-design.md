# Subscriptions Phase 2: verified provider reads and durable evidence

Status: implementation design, reviewed 2026-10-15. No Phase 2 adapter,
credential migration, provider operation, billing worker or cutover is enabled by
this document. Implement after the separate subscription foundation release and
review through the normal test, schema and deployment gates.

Implementation status: the foundation is deployed at `f8bf74f3` through PR #401.
The initial bounded Phase 2 implementation is staged; the operational companion
`everbranch-subscriptions-provider-reads.md` is authoritative for actual CLI,
table names, implemented limits and remaining acceptance work. This design also
describes later reconciliation/refresh capabilities not yet implemented.

## Scope and verified limits

Phase 2 should make owned Shopify subscriptions visible and explain their
freshness, identity and readiness. It must keep contract changes, billing,
payment-update emails, new selling plans and migration cutover disabled. It is a
separate Subscriptions lane, not native Website commerce or the native catalog
snapshot importer.

The coordinator's read-only provider evidence supplied for this design reports:

- Current app: `gid://shopify/App/324577984513`.
- Current-app owned subscription contracts: **0**.
- Current-app owned selling-plan groups: **0**.
- The all-app selling-plan-group result contains **6** groups with source
  `appId: 294517`, separately verified by canonical app lookup as **Recharge
  Subscriptions**. Its nullable installation result does not establish billing,
  uninstall or migration state.
- The current app has read/write **own** subscription-contract scopes,
  `read_customer_payment_methods` and `write_customers`.

These counts are observations from that evidence, not a new live probe performed
for this document. Provider identity came from an explicit canonical app lookup,
not a numeric app ID, product name or local migration field. All-app groups
are not evidence that the current app owns their contracts, that any contracts
exist, or that Everbranch may service them. An empty, completely paginated
owned-contract result must render as a valid empty result with an observation
timestamp. Access-denied, partial or failed reads must never render as zero.

Recharge remains a separately reviewed source/migration concern. Existing local
Recharge identifiers, Shopify-looking GIDs and a migration approval checkbox do
not establish contract ownership or payment-vault portability. There is no
automatic adoption or billing cutover in this phase.

## Existing foundation to retain

The inspected foundation is in the separate `codex/subscription-operations`
worktree. Its current files may still be awaiting release; rebase on the released
foundation before implementation rather than copying its uncommitted files.

- `SubscriptionAccessService` uses the canonical add-on entitlement, active
  tenant membership and owner/admin/manager checks. It freezes local tenant,
  Shopify-store, domain and configured public-client identity, and hard-blocks
  execution. Reuse those authorization boundaries.
- `SubscriptionModuleService` stores encrypted local request/evidence envelopes.
  Preserve all historical lifecycle and migration evidence. Historical
  `intent_recorded` rows must not become executable when a new worker appears.
- The webhook controller verifies HMAC against the raw request body. Preserve
  raw-byte verification before JSON parsing.
- Current webhook deduplication searches lifecycle-event JSON under a settings
  row lock. It is an evidence-only foundation, not the production inbox.
- Current local binding resolution ultimately uses the global retail/wholesale
  `ShopifyStores` slots. Those slots must not onboard unrelated retail clients or
  silently provide subscription credentials.
- Historical subscription tables have tenant-only provider uniqueness,
  date-only billing fields, mutable JSON and no verified installation revision.
  They cannot independently prove provider ownership or exact billing timing.
- Customer contract lookup is currently active-only. Read visibility must
  eventually include the authenticated customer's paused/cancelled history;
  voting eligibility can remain a separate active-membership policy.

Relevant foundation paths are
`app/Services/Subscriptions/SubscriptionAccessService.php`,
`app/Services/Subscriptions/SubscriptionModuleService.php`,
`app/Http/Controllers/ShopifyWebhookController.php`,
`app/Services/Shopify/ShopifyStores.php` and
`database/migrations/2026_07_02_120000_create_subscription_module_tables.php`.

## Slice 1: verified installation and revisioned credentials

Introduce a subscription-specific installation resolver. Resolve tenant and
store ownership on the server, then use only a credential explicitly attached
to that installation. Never accept a tenant, domain, app or credential supplied
by a browser as authority. No global Modern Forestry, retail, wholesale, legacy
environment-token or marketing fallback is permitted.

Use a fixed, query-only identity document against the expected canonical host:

```graphql
query SubscriptionInstallationIdentity {
  shop {
    id
    myshopifyDomain
    ianaTimezone
  }
  currentAppInstallation {
    id
    app {
      id
      apiKey
      webhookApiVersion
    }
    accessScopes {
      handle
    }
  }
}
```

Compare returned Shop GID/domain, AppInstallation GID, App GID and `apiKey`
against the independently expected installation/public client. Validate granted
scopes from the response, not requested scopes in configuration. Persist the
verified result, API version, timestamp and credential revision. Shopify exposes
the authenticated installation and granted scopes, its installed App, the App's
public API identifier, and the shop's canonical domain/timezone.
[AppInstallation](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/AppInstallation),
[App](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/App),
[Shop](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/Shop)

Credential rotation appends a revision and atomically changes the active
revision pointer only after verification of the same identity. It must not
rewrite the original binding or move historical records. A different app, shop
or installation, including an unreviewed reinstall, blocks use until a deliberate
migration/reconnection decision is recorded. A settings edit is not such a
decision. Jobs record which revision actually made each request; no worker may
silently replace a pinned credential with an unrelated current connection.

Credential acquisition and refresh are explicit setup operations, separate from
offline readiness and read synchronization. Store token pairs atomically,
coordinate concurrent refreshes under an installation lock/lease, and account
for lost refresh responses without overwriting the durable pair. Follow returned
expiry values. A public-app path must support expiring offline tokens before
Shopify's January 1, 2027 requirement; online tokens and staff permissions remain
distinct. Client-credentials authentication is limited to stores in the same
Shopify organization as the app, not arbitrary clients' stores.
[Access tokens](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens),
[Client credentials](https://shopify.dev/docs/apps/build/authentication-authorization/client-credentials-grant),
[Refresh recovery update](https://shopify.dev/changelog/posts/more-resilient-refreshes-for-expiring-offline-access-tokens)

An app-secret revision is separate from an access-token revision. Retain an
explicitly bounded, approved previous-secret verification window during secret
rotation and record the revision that verified a webhook. Do not try arbitrary
tenant secrets. Replacing an app secret does not itself verify every installed
store's new access token; tokens remain tied to the secret that minted them.
[App credentials](https://shopify.dev/docs/apps/build/authentication-authorization/manage-credentials)

## Slice 2: additive schema and operation ledger

Start with five additive tables. This is a proposed minimal shape, not a released
DDL contract. Every service read/write must include installation and tenant
scope, including CLI and queue workers that have no authenticated web session.
Use composite ownership foreign keys where appropriate, or an explicit scoped
parent check in the same transaction; a tenant column beside an unrelated
foreign key is not sufficient isolation.

| Table | Minimum durable fields and constraints |
| --- | --- |
| `subscription_provider_installations` | Tenant/store foreign keys; provider; Shop/App/AppInstallation GIDs; public client ID; canonical domain; exact API version; verified scope snapshot/time; status; active credential revision; last completed sync and cursor/window metadata. Unique provider installation identity must prevent accidental cross-tenant rebinding. |
| `subscription_credential_revisions` | Installation and tenant IDs; monotonic revision unique within installation; encrypted token envelope; grant type; access/refresh expiry; scope/identity verification time; app-secret reference/revision; revoked/retired timestamps; replacement reference. Credentials hidden from serialization. |
| `subscription_operations` | Installation/tenant; unique installation-scoped request UUID; operation kind and source/actor; optional contract identity and expected revision; schema-versioned encrypted request/before/response evidence; request fingerprint; state; provider references; attempt count; lease and reconciliation/retry timestamps; sanitized failure code. |
| `subscription_webhook_inbox` | Installation/tenant; unique `(installation_id, webhook_id)`; topic; nonunique event correlation ID; API version; raw-body fingerprint and encrypted payload; verified secret revision; receipt/trigger timestamps; state, attempt count, lease and next reconciliation time. |
| `subscription_provider_resources` | Installation/tenant; allowlisted resource type and installation-scoped source identity unique; parent contract identity where required; schema-versioned encrypted snapshot; safe status/search fields; provider revision/updated time; observation time; completeness/freshness marker and sync-operation reference. |

Resource types should be explicitly enumerated: contract, contract line, masked
payment method, billing attempt, cycle and provider-confirmed order link. A cycle
uses a canonical contract-and-cycle identity rather than an invented Shopify
GID. Avoid a generic arbitrary-JSON API that bypasses typed validation. The
resource table permits trusted read projections without immediately rewriting
or reclassifying historical records. Do not copy unverified legacy rows into it
merely because their GID parses.

Use encrypted `TEXT`/`LONGTEXT` envelopes for sensitive provider snapshots,
addresses, notes, payment-action URLs and operation content. Keep only necessary
safe identifiers, statuses, timestamps and scope names in searchable columns.
Do not add raw card data, CVV, access/refresh tokens or client secrets to
operation/inbox content. Keep credentials solely in their protected envelope or
approved secret store. Use hidden attributes and explicit serializers; do not
return decrypted models directly. A digest is not anonymization: protect
fingerprints of low-entropy private content with a keyed digest where needed.

Store Shopify `revisionId` losslessly as an unsigned decimal string, with a
numeric `DECIMAL(20,0)` column or a validated decimal comparison routine. Never
cast it to a PHP signed integer or float, and never compare decimal revisions
lexically. Store exact UTC billing instants plus the verified IANA shop timezone;
the old `DATE` fields are insufficient. Preserve source decimal money and
currency rather than assuming every currency has two minor-unit digits.
Shopify's contract revision is `UnsignedInt64`, and its billing date is a
`DateTime`.
[Contract schema](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionContract)

The operation ledger must distinguish a saved local request, provider read in
progress, completed observation, blocked/rejected result and unknown outcome.
An HTTP 200 or accepted request is not a confirmed business transition. Repeating
the same request UUID/content returns the original operation; reusing it with
different content returns a conflict. Any later mutation phase needs its own
reviewed state machine, provider idempotency and canonical reconciliation. The
existence of this ledger must not unlock those mutations.

Queue jobs contain scalar operation/inbox IDs only. Re-resolve scope and gates
at execution, claim work atomically with a lease, persist result and cursor with
the projection, then complete the operation. An after-commit dispatch failure
must leave recoverable work for a bounded scanner. Never perform network calls
while holding a long database transaction. Recheck ownership/revision at the
short transaction that accepts a provider result.

## Slice 3: query-only adapter and owned-resource reads

Add a dedicated subscription transport without changing the global Shopify API
version or repurposing the generic client for subscription execution. Pin the
endpoint to `/admin/api/2026-10/graphql.json`; verify the actual response version
and reject mismatch. Use fixed query documents/operation names and validated
variables, not caller-supplied GraphQL text. HTTP POST is normal for GraphQL
queries, so enforce the GraphQL operation boundary. Shopify may fall forward
when a requested version becomes unsupported.
[Versioning](https://shopify.dev/docs/api/usage/versioning)

Bound requests by timeout, response size, pages, rows and total run time. Handle
HTTP errors, GraphQL errors/partial data, throttling and missing required fields
explicitly. Retry safe reads with bounded backoff and cost/rate-limit awareness.
Do not include raw provider errors, query variables, headers or response bodies
in operational logs. An authentication/scope error blocks the affected
installation; it must not trigger another app's token or an automatic reinstall.

Enumerate `subscriptionContracts` with cursor pagination across relevant states,
then confirm every contract's `app.id` matches the verified bound App GID before
any trusted projection. Validate requested/returned GID and customer/parent
relationships. The query supports `updated_at` filtering; match sort and filter
and persist the completed window. A syntactically valid local GID is not
ownership evidence.
[Contract query](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/subscriptionContracts)

The first trusted projection should cover:

- Contract status, revision, billing/delivery policies, currency and exact next
  billing instant, keeping app ownership visible in the evidence.
- Fully paginated lines, quantities, source price/currency, discounts and
  relevant product/selling-plan identities. Source/provider facts must remain
  distinguishable from Everbranch's local commitment/reward rules.
- Only the masked payment instrument needed for an owned contract, with
  explicit revoked, unavailable/redacted and unknown states. Validate the
  returned customer's identity before associating a payment method.
- A bounded billing-cycle window with cycle index, expected attempt instant,
  start/end, edited/skipped status and source contract. Do not generate a
  calendar by adding a month to a local date.
- Billing attempts and their typed states, plus provider-confirmed order links.
  Do not guess an order link from email, amount or a nearby timestamp.

Customer payment methods require customer and payment-method read access and
may be revoked; request masked details only. A null field is not proof that a
customer has no saved method. Scope/protected-data failures are readiness gaps,
not a reason to erase previous evidence.
[Payment methods](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/CustomerPaymentMethod)

The cycle API exposes expected billing instants, cycle boundaries, edited/skipped
state and source contract. Its list accepts a contract ID and explicit date or
index range, enabling a bounded review window.
[Billing cycles](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionBillingCycle),
[Cycle query](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/subscriptionBillingCycles)

**2026-10 attempt parsing:** use `state.__typename` and typed fragments for
`SubscriptionBillingAttemptPendingState`, `...ActionRequiredState`,
`...FailedState` and `...SuccessState`. The older `ready`, `errorCode`,
`errorMessage`, `nextActionUrl` and direct `order` fields are deprecated. An
unrecognized union member is unknown/unsupported, never success. A successful
attempt's typed `order` may be null if the order was deleted; do not turn that
into payment failure or create a substitute order.
[Billing attempt](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionBillingAttempt),
[Attempt states](https://shopify.dev/docs/api/admin-graphql/2026-10/unions/SubscriptionBillingAttemptState),
[Success state](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SubscriptionBillingAttemptSuccessState)

Fetch all required nested pages before declaring a snapshot complete. Recheck
the contract revision around a multi-page read; if it changes, retry or mark the
snapshot incomplete rather than combining revisions. Attempts and other
independently changing resources also need their own observation/completeness
markers. A contract revision alone does not freeze every nested history.

Advance a watermark only after the entire bounded window commits. Use overlap
on incremental `updated_at` reads and periodic full reconciliation. Absence
from a partial/failed run must not delete or cancel an existing contract. Keep
the last complete snapshot visible with its stale reason/time; never present a
failed sync as fresh. Shopify does not guarantee webhook ordering or delivery
and recommends reconciliation jobs.
[Webhook behavior](https://shopify.dev/docs/apps/build/webhooks)

## Slice 4: indexed webhook intake and recovery

Use an installation-specific endpoint or another reviewed routing mechanism that
unambiguously selects the original installation/app before processing. Match the
canonical shop header to that binding; verify raw-byte HMAC using only its
approved app-secret revisions. The request's tenant/shop/topic headers are not
independent ownership proof. Select allowed topics from the registered endpoint
and validate header/topic consistency, API version, delivery ID and body bounds.

In one short transaction, insert the encrypted inbox row under its unique
delivery key before acknowledging. Same delivery ID with a different body hash
is a conflict to quarantine, not an overwrite. Return success for an already
persisted matching delivery. Storage failure must not acknowledge successful
durable intake. Preserve the encrypted raw body for verification/audit and store
only allowlisted nonsecret headers. Dispatch scalar IDs after commit, with a
bounded scanner repairing missed dispatches and expired worker leases.

Use `X-Shopify-Webhook-Id` for delivery uniqueness and `X-Shopify-Event-Id` as
nonunique correlation. Separate subscriptions can receive different delivery IDs
for the same event. Shopify requires raw-body HMAC verification and prompt
acknowledgment; provider reads belong in workers, not the request handler.
[Delivery verification](https://shopify.dev/docs/apps/build/webhooks/verify-deliveries)

Retain contract create/update, attempt success/failure/**challenged**, payment
method create/update/revoke, app uninstall and scope-change evidence where
available/authorized. Record actual payload API version. Authenticated
unsupported-version or malformed-business-data events should be quarantined,
not interpreted as 2026-10. Registration inspection is read-only; creating or
changing webhook subscriptions requires a separately authorized setup operation.
[Webhook topics](https://shopify.dev/docs/api/admin-graphql/2026-10/enums/WebhookSubscriptionTopic)

Treat events as reconciliation triggers. Canonically read the owned resource
before accepting a projection; older revisions cannot overwrite newer ones.
Event timestamps help ordering but do not replace revision/ownership checks.
Do not infer a confirmed charge, updated address or cancellation solely from an
untrusted/malformed webhook payload.

Entitlement and provider-read pauses must block projection/provider access.
Define durable intake separately from processing: previously authorized,
authenticated events retained during a pause need a quarantined reason and an
explicit reviewed reconciliation policy. Do not silently drop them with a 2xx
response or automatically execute historical local requests after reactivation.
Unknown/unbound installations remain rejected. App uninstall/scope loss disables
the relevant capability; it does not erase historical evidence or send emails.

## Slice 5: truthful readiness, CLI and read surfaces

Suggested interfaces:

| Interface | Behavior |
| --- | --- |
| `subscriptions:readiness --tenant=... --installation=... --json` | Local database/configuration checks only; no HTTP, token exchange, provider writes, dispatch or credential mutation. |
| `subscriptions:provider-probe ...` | Explicit query-only identity/scope verification for the selected binding; may record a local verification operation/result; cannot rebind or silently refresh credentials. |
| `subscriptions:sync-owned-contracts ... --limit=... --max-pages=...` | Explicit bounded provider reads and local trusted projections; resumable cursor/window; accurate partial/failure result. |
| `subscriptions:reconcile-inbox ... --limit=...` | Bounded authenticated inbox reconciliation and lease/dispatch recovery under current read gates. |

Commands require explicit tenant/installation scope and an audited operator or
approved service identity; do not add a default `all` merchant mode. Console
output contains safe counts, status codes, age and identifiers only. Private
diagnostic reports, if needed, require explicit output selection, restrictive
permissions and no credentials/customer values.

Readiness should separately report entitlement, installation identity status,
credential revision/expiry, required scopes, protected-data availability,
webhook version/registration evidence, scanner/queue health, last complete sync,
stale/blocked counts and owned-contract count with observation time. Separate
`provider_reads_ready` from contract-servicing/billing readiness. Zero owned
contracts can be a successful read result while servicing remains unavailable.

Admin list/detail/history must visibly distinguish verified provider snapshots
from local requests, demo data and migration evidence. Expose last complete
observation and stale reasons. Tenant/customer ownership checks apply to every
detail/history route; support paused/cancelled visibility without making a
resume operation executable. Current Everbranch membership/login remains
required until an audited Shopify staff-sub-to-active-tenant-member mapping or
reviewed online identity flow exists. A signed launch/session alone is not
Everbranch management authority.

Keep `SubscriptionAccessService::assertCanExecute()` hard-closed. No Phase 2
command, webhook worker, readiness check, app-billing fulfillment or customer UI
may enable the billing scheduler, commit a calculation, create a billing
attempt, send a payment-update email, publish a selling plan or activate a
cutover. Canonical add-on fulfillment remains separate from Shopify customer
subscription billing.

## Meaningful release tests

Use synthetic 2026-10 GraphQL/webhook fixtures and HTTP fakes with stray requests
forbidden. Do not commit real catalog/customer/contract data or use production
credentials in tests.

1. **Binding and credentials:** wrong tenant/store/shop/app/installation/public
   client; same domain with a different app token; global slot/env changes cannot
   change the bound credential; duplicate cross-tenant binding; same-installation
   rotation; concurrent refresh/rotation; expired/revoked revisions; scope loss;
   changed installation requires review; no secret/token serialization or logs.
2. **Read-only boundary:** readiness sends no HTTP or jobs; adapter accepts only
   fixed queries; mutation/extra-operation injection rejected; exact version
   mismatch; 401/403, HTTP failure, GraphQL errors/partial data, invalid JSON,
   oversized responses, throttling and bounded retry exhaustion remain truthful.
3. **Ownership and completeness:** current-app zero contracts/groups is distinct
   from failed/partial reads; all-app six-group/different-app evidence never
   becomes owned; foreign-app/local/imported GIDs rejected; multiple pages at
   every nested connection; missing/looping cursor; mid-run crash; unchanged
   replay; revision changes between pages; concurrent sync/webhook convergence;
   absent-from-partial-run never cancels/deletes; watermark only after completion.
4. **Source semantics:** revision above `PHP_INT_MAX`; exact datetime/UTC and DST
   boundaries; non-two-decimal currency; discounts/quantity; nullable/redacted
   customer/payment method; revoked instrument; edited/skipped cycles; every
   typed attempt state plus unknown member; success with deleted/null order;
   provider-confirmed order/customer/contract relationships; paused/cancelled
   customer history stays tenant/customer scoped.
5. **Inbox:** actual raw-body HMAC; body change with copied signature; wrong
   app/shop/endpoint; approved old-secret window then expiry; concurrent duplicate
   delivery produces one row; conflicting payload under same delivery ID
   quarantines; same event ID with distinct deliveries is retained; old revision
   after new; malformed/unsupported payload version; challenged attempt; install
   revocation/scope loss; database failure before ACK; replay after lost response.
6. **Durability and pauses:** lost after-commit dispatch; crashed worker and lease
   expiry; duplicate jobs; bounded scanner rotates through work fairly; paused
   entitlement/read gates send no provider queries; quarantine/reconciliation on
   reactivation; historical intents never auto-execute; no provider mutation,
   email, charge, native Website/legacy order or billing-scheduler side effect.
7. **Privacy and UI:** encrypted database snapshots/requests/raw inbox; no PII,
   action URL or credential in ordinary metadata, logs, UI errors, console or
   queued job serialization; unauthorized detail/history inaccessible; stale and
   unknown states do not display confirmed success.

Each new multi-step migration needs real MySQL 8.4 recovery evidence, registered
in `tests/Integration/migration-recovery-manifest.php` and discovered by the
existing workflow/deployment file filters. Use short explicit FK/index names
and keep composite keys within InnoDB's byte limit. Released migrations remain
immutable.

Recovery tests must create durable partial state, not merely rerun a completed
migration: retain an early table; retain a **bare table with all columns but no
indexes/foreign keys and a sentinel row**; retain some constraints with later
ones missing; simulate a completed data step before the remaining DDL. Run the
migration twice, assert every intended unique/index/FK, sentinel preservation,
deduplication and correct clean-schema convergence. Guard creation and repair
each missing named constraint after creation. Test conflicting duplicate/orphan
state fails safely without deleting evidence. Run the migration linter and
generate/review the combined data-free baseline only through disposable CI.

## Blockers and later phases

Phase 2 needs a reviewed tenant-owned retail-client installation/authentication
path and synthetic fixtures for actual owned contracts. Given the supplied zero
owned-contract result, a controlled development-store owned-contract fixture
will be needed before claiming provider integration coverage. Creating one is
a separate authorized provider-write exercise, not part of this design/read-only
review. Validate app distribution, purchase-option/protected-data access and
staff permissions for the intended retail rollout.

Recharge/other-app migration needs an independently verified source identity,
payment-gateway/vault feasibility, explicit customer/contract mappings, merchant
acceptance, rollback/reconciliation and prevention of concurrent billing. Do not
label the six different-app groups with a provider name absent evidence.
Shopify's gateway-specific migration guide warns about double billing. API-
created contracts may omit a payment method, but cannot charge until a valid
method is attached; migration of data is not proof of billing readiness.
[Migration requirements](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/migrate-to-subscriptions-api/migrate-customer-information),
[Payment-method requirement change](https://shopify.dev/changelog/posts/removal-of-payment-method-requirement-on-api-created-subscription-contracts)

Phase 3 separately reviews one confirmed servicing operation at a time, with
fresh owned reads, exact revision, explicit preview/confirmation, policy snapshot
and reconciliation after ambiguous responses. Review 2026-10 calculation,
inspection and commit semantics; do not implement a generic executor from the
old intent strings or copy earlier draft API mutation names. Billing attempts,
retry/dunning, customer action links and scheduler activation remain a later
financial phase. A changed future contract must not rewrite an existing Shopify
order, fulfillment or shipping label.
[Subscription contract lifecycle](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts)

Do not uninstall or replace a subscription app as an ordinary connection repair:
Shopify documents cancellation of its nonterminated contracts 48 hours after
uninstall. Any ownership migration or app replacement therefore needs its own
reviewed runbook and evidence. The design deliberately does not assume a generic
ownership-transfer endpoint exists.

## Acceptance boundary

Phase 2 is complete when verified installations can perform bounded, query-only
owned-resource reconciliation; encrypted inbox/operation/snapshot records survive
retries and crashes; paused/unverified/cross-tenant states fail closed; and staff
can distinguish an empty current-app result, stale provider evidence and local
requests. It must remain possible to deploy this capability dark, with every
provider mutation, customer send, renewal and migration cutover still disabled.
