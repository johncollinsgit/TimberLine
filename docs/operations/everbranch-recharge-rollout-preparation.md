# Recharge customer and settings rollout preparation

## Authorized scope

On October 16, the owner confirmed Recharge is Modern Forestry's current
subscription billing provider, then requested settings and customers be prepared
one-to-one for a future Everbranch rollout **without turning it on**. This is a
separate source preparation lane. Shopify contract ownership is not Recharge
ownership, and the earlier Shopify-only evidence remains unchanged.

This release contains no provider mutation, billing retry, payment-update email,
customer notification, scheduler, vault adoption or cutover implementation.
Imported candidates are encrypted shadow records, not operational subscription,
CRM, Website or Shopify customers. No email/name matching merges identities.
Existing Recharge billing and all native commerce gates retain their state.

## Source access and identity

The native workspace is `/subscriptions/recharge`, available from Subscriptions
to active entitled tenant owners/admins/managers. Every route resolves the tenant
through `tenant.access`; global platform roles and Shopify launch tokens do not
replace active tenant membership. Existing Modern Forestry comped access and the
Subscriptions purchase-disabled policy remain intact.

Private setup accepts an expected Recharge store ID, exact platform identifier
and private store API credential. It saves a tenant-owned pending encrypted
IntegrationConnection draft. Draft account IDs are request UUIDs, avoiding the
generic tenant/provider/account uniqueness collision during credential rotation.
Credentials never appear in flash input, views, URLs, command arguments, read
evidence, customer snapshots or ordinary metadata. Saving a draft is local only.
Do not paste credentials in chat.

Verification reads only fixed HTTPS endpoints at `api.rechargeapps.com`, with
request version `2021-11`. `/store` must match the independently expected
`id`, `external_platform` and `identifier`; the current schema does not promise
`my_shopify_domain`. Never infer identifier from a tenant name or Shopify URL.
`/token_information` supplies actual granted scopes. Source rows are explicitly
selected by tenant/provider/ID; there is no latest/global credential fallback.
Initial expected-ID errors do not create a permanent unverified binding.

Read access requires both default-closed settings:

```text
SUBSCRIPTIONS_RECHARGE_READS_ENABLED=false
SUBSCRIPTIONS_RECHARGE_READ_TENANT_IDS=
```

These flags govern source GETs only. They cannot turn on billing, messaging,
servicing, public sales or migration. Production source access requires a
merchant-owned credential and reviewed expected store identity. Read-only
production inventory found no Recharge IntegrationConnection for Modern
Forestry and no credential in its legacy Recharge settings. That inspection
performed no authenticated provider call and printed no secret values.

## Durable source preparation

Four additive tables retain verified immutable store bindings, append-only
encrypted credential revisions, durable read operations and generation-scoped
encrypted source resources. Composite keys reject another tenant/connection's
credential or operation. Rotation retains original credentials/evidence; an old
or superseded read cannot change a newer accepted identity or generation.

The initial capture covers store facts, customers, addresses, subscriptions
and masked payment-method relationships. It follows bounded cursor pages across
all documented subscription statuses. Complete consecutive collection passes
must agree before a generation becomes accepted. Missing pagination, denied
scopes, changing source, duplicate identities or unresolved customer/address/
payment relationships are failures, never a successful empty inventory.
After the repeated collection passes agree, a resumable local reconciliation
checks at most 100 rows per request without provider HTTP. Promotion waits for
every relationship batch and the final total check. An unfinished capture
retains the previous accepted generation and totals.

Portal hashes, processor customer/payment tokens, arbitrary analytics and
unrecognized private fields are excluded before any resource persistence.
Masked card fields retain the documented nested `payment_details` structure.
Source resource IDs, exact decimal strings, bare dates, explicit offsets, nulls
and missing fields remain distinguishable. API subscription `updated_at` alone
is not a reliable revision for related charge-date changes; complete projected
source comparisons are used. No local revision is presented as provider CAS.

Opening, searching and filtering the workspace makes no provider call. Source
capture is an explicit POST or narrow CLI read. `status=completed` on one read
operation means the batch completed; only `sync_complete=true` establishes the
accepted full capture. Unknown totals remain unknown until then.

## Operator reads

The CLI requires an explicit active entitled manager, tenant and selected source
or connection. It has no token argument, default tenant or read-gate override.
Use a new request UUID for every subsequent capture slice; retain the original
UUID when replaying an uncertain operation.

```sh
php artisan subscriptions:recharge-shadow readiness --tenant=ID --actor=ID --connection=ID
php artisan subscriptions:recharge-shadow verify --tenant=ID --actor=ID --source=INTEGRATION_ID --store-id=RECHARGE_STORE_ID --identifier=REVIEWED_IDENTIFIER --platform=shopify --request=UUID
php artisan subscriptions:recharge-shadow capture --tenant=ID --actor=ID --connection=ID --request=NEW_UUID --page-size=50
php artisan subscriptions:recharge-shadow refresh-customer --tenant=ID --actor=ID --connection=ID --customer=RECHARGE_CUSTOMER_ID --request=UUID
```

`readiness` makes no provider request. `capture` runs one bounded slice, including
the later local reconciliation phase. An individual customer refresh is an
observation, never a substitute for the complete accepted generation. Use
`--restart` only to abandon an incomplete staged capture and begin a new one;
the accepted capture remains available.

## Candidate comparison

The explicit local preparation action processes at most 100 accepted resource
rows per request, under the original connection/generation. Repeating it does
not duplicate customers or edit the source. Each encrypted candidate records
the source identity/digest, exact retained fields, source links, reviewer and
issues. Only the last accepted generation is available for comparison; staged
and observation-only records cannot masquerade as the complete customer list.

The UI distinguishes **source fields preserved**, **needs review** and **not
prepared**. The detailed comparison is private to the same authorized tenant
manager. Exact source values are a data-preservation check, not proof that a
future Everbranch runtime behaves identically. `one_to_one_verified`,
`live_ready`, billing, servicing, messages and cutover remain false.

## Required evidence before one-to-one rollout readiness

The following remain explicit source coverage/acceptance requirements:

- Store currency/timezone, every source customer and subscription, all statuses,
  addresses and payment references must reconcile by provider/store/resource ID.
- Plans, discounts, queued charges, prepaid deliveries, one-time items, bundles,
  credits and relevant metafields need captured and mapped evidence or a reviewed
  proof that they are absent. The initial customer capture does not import these
  families and does not claim they are empty.
- Notification templates/events, retry and dunning rules, portal permissions,
  pause/cancellation rules, workflows/retention, shipping/tax and gateway settings
  need a source export or manual merchant evidence and matching target drafts.
  A complete public settings-export endpoint is not documented. Missing fields
  are unverified, never replaced with house defaults or approval checkboxes.
- Processed-charge lists are limited to 90 days; delivery schedules project at
  most 365 days. Full historical/future parity requires additional evidence.
  Absent list rows do not prove deletion/cancellation or complete tombstone
  coverage; webhook deletion evidence has not been reconciled.
- Payment references remain provider-bound. A masked card, local token or copied
  customer does not transfer vault authority or authorize charges.
- Customer-facing behavior, operational settings implementation, provider test
  acceptance, migration ownership and explicit activation/rollback review are
  separate work. No old local migration request becomes executable here.

Recharge documents active/cancelled/expired subscription states. A customer
portal `subscription/paused` webhook does not establish a public API pause
endpoint. Never emulate pause by cancelling. Even `send_email=false` on a future
cancel operation would not prove third-party SMS/Klaviyo automations are silent.
No live cancel/reactivate test is permitted by this preparation workflow.

## Release and recovery

Use the protected test/build and MySQL migration gate, with a data-free baseline
generated only by the disposable schema workflow. Released migrations stay
immutable. The new migration is restart-safe and forward-only; code rollback
retains encrypted source credentials, mappings and accepted evidence.
Pause source reads with their dedicated flags; preserve accepted snapshots and
operator evidence. No queue scanner, automatic API request or customer message
is started by installing or opening this workspace.

Tests use fictional records and faked HTTP, including tenant/role/paywall checks,
secret stripping before staging, malformed provider fields, exact money/dates,
cursor/revision races, accepted-generation isolation, lost leases, credential
rotation and duplicate request replay. Real MySQL tests cover partial durable
DDL, conflicting constraints, composite ownership keys, concurrent UUID/lease
claims and accepted/staged generation preservation. Desktop/mobile layout and
source/candidate comparisons require synthetic browser verification as well.

## Primary provider references

- [Recharge 2021-11 API reference](https://developer.getrecharge.com/2021-11)
- [API versioning](https://docs.getrecharge.com/docs/api-versions)
- [Subscription semantics and limitations](https://docs.getrecharge.com/docs/subscriptions)
- [Merchant API credential access](https://support.getrecharge.com/hc/en-us/articles/360008829993-Recharge-API)
- [Payment-update flow and vault ownership](https://support.getrecharge.com/hc/en-us/articles/40830660708759-How-to-add-Shopify-s-payment-update-flow-to-your-custom-Customer-Portal)
