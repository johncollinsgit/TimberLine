# Recharge replacement: implementation and acceptance plan

Owner direction confirmed October 30, 2026: Everbranch Subscriptions replaces
Recharge's recurring billing and member management. Preserve existing Braintree
and Shopify-vaulted methods wherever their continuing authorization and supported
access can be verified. Stripe setup is an explicit fallback, not a universal
migration requirement. The first rollout is the two already prepared members;
all other members retain Recharge billing until separately reviewed.

## Original-app schema and native update construction (October 31)

At 06:02 UTC, a read-only query using the original retail Shopify credential
returned pinned `2026-10`, the correct store, own-contract write/historical-order/
saved-method read scopes and the documented create/update calculation and commit
types. All 15 existing native contract/billing documents and six new update
documents validate against that actual schema. Actual generated create/update
variables also pass its input coercion. This resolves the original-app syntax/
type evidence gap; it does not execute or accept any provider mutation.

`tests/Fixtures/Subscriptions/shopify-original-2026-10.graphql` is a roughly
29 KiB projection of the captured type/field/input closure, with provenance and
checksums. It contains no credentials, account IDs or customer data. The pinned
`graphql` development dependency powers a new CI step:

```
node scripts/ci/validate-subscription-shopify-api.mjs
node scripts/ci/validate-subscription-shopify-api.mjs --schema-json /private/original-app-schema.json
```

This validator reads protocol strings directly from the PHP classes without
bootstrapping the app or making a provider request. A negative schema check
rejects a removed update operation. The separate bundled toolkit still reports
missing calculation types, and connector validation still skips; neither is a
pass. Runtime capability/identity checks and actual isolated-store mutation,
merchant, saved-method/source and customer/backend acceptance remain mandatory.

A further read-only original-app method check at approximately 06:28 UTC found
one visible Jennifer Shop Pay agreement: unrevoked, `inactive:false` and not
expired. The method connection was complete. Rynda's complete Shopify method
connection was empty, consistent with keeping her Braintree route separate.
Only method fingerprints and status booleans are retained in the check output.
Visibility alone is not exact Recharge source-method association, continuing
authority, merchant acceptance or an authorized billing switch.

`SubscriptionShopifyUpdateProtocol` now constructs and validates a native update
intent using the actual original owned-contract snapshot and original-app
catalog. It has no HTTP, commit, local-term save or billing side effect. It:

- Captures the exact contract/customer/app/method, version, note and schedule,
  original member/source identity, credential context and a unique amendment
  marker under a keyed intent. The trusted caller supplies `MERCHANT` or
  `CUSTOMER`; this must not come from an unverified form actor.
- Omits the full line list for address-only edits and omits delivery replacement
  for scent-only edits. Other omitted contract fields remain unchanged. See
  [Shopify update calculation semantics](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractUpdateCalculate).
- When scents change, retains every real provider line ID and restates the
  existing quantity, member price override, app-managed base-price policy,
  custom attributes, no-discount state and selling-plan metadata. Unique exact
  source-line binding is required; ambiguous duplicates need review. IDs are
  never fabricated from Recharge/source IDs. See
  [Shopify's line replacement requirements](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts/migrate-to-subscription-calculation-api).
- Rechecks active shippable 16oz catalog identity and uses its returned label;
  validates pending/success/warnings, exact projected subtotal/shipping/discounts/
  tax/total and the original provider line-ID set. Projected tax is estimated.
- Validates the applied original-contract result against the exact keyed quote,
  note, saved method, line bindings and original schedule. A changed date/status
  fails for reconciliation; this helper never restores a prior schedule.

The protocol class is a **pure builder**. The calculation journal described
below now durably retains intent before calculate and polls only the original ID.
The owner commit/history/recovery path below now retains exact approval and one
committed send claim before HTTP. Native fresh tax preflight is now connected as
described below; authenticated native customer confirmation remains required. Existing original-term
billing retains its separate gates and already-started attempts remain reconcilable.
No DDL, contract update, charge, source stop, activation or deployment occurred.

## Customer future delivery and scent confirmation (October 31; source candidate)

The existing authenticated app management link now opens a branded private member
page with Braintree delivery editing, behind the independent default-off
`SUBSCRIPTIONS_REPLACEMENT_CUSTOMER_DELIVERY_ENABLED` gate. Existing exact tenant,
member, servicing, amendment/apply and entitlement gates still apply. Only an
individually activated, method-verified original Shopify customer can use it.
Other members and native Shopify agreements do not gain this action from the gate.

Merchant shipping coverage is explicit and separate from customer confirmation.
`subscriptions:delivery-policy` previews offline and requires a private regular
0600 JSON file. The preview includes the actual fixed shipping fee, current
amount, currency, agreement/member, policy and keyed review. An explicit reviewed
hash/revision/UUID records one encrypted signed `delivery_policy_reviewed` action
and a current policy pointer. Billing terms, revision, original authorization,
saved method, source subscription and existing orders/cycles are unchanged.
Identical retries retain the original action; a newer policy cannot be rolled
back by replay. The recorded reviewer must be an active verified owner/admin.

Fictional input shape; supply the merchant's actually reviewed shipping coverage
and offered scent list for the exact original store and membership:

```json
{
  "enabled": true,
  "provinces": ["SC", "NC"],
  "allow_scent_swaps": true,
  "scents": [{"variant_id": "888", "label": "Forest Rain / 16oz"}],
  "reference": "merchant-policy:REVIEW_ID",
  "fixed_shipping_reviewed": true,
  "continuing_authorization_reviewed": true
}
```

```
subscriptions:delivery-policy --tenant=SLUG --actor=ID --agreement=ID --file=/private/delivery-policy.json
subscriptions:delivery-policy --tenant=SLUG --actor=ID --agreement=ID --file=/private/delivery-policy.json --reviewed-hash=REVIEW_HASH --revision=REV --request-id=UUID --record
```

The policy offers complete US addresses only in the reviewed states and optional
curated membership scents. A fresh original-store calculation still verifies
each selected variant's active shippable 16oz status, exact member pricing and
quantities, fixed shipping and tax. A policy label alone is not availability.
Set `enabled:false` through a new reviewed policy to withdraw new customer edits.
Withdrawing editing does not undo a previously accepted customer delivery or
cancel its separately authorized subscription billing.

The customer flow posts to `/subscriptions/member/{token}/delivery/review`, then
`/delivery/confirm`. Both retain CSRF, request throttles and the configured app
host. The original verified Shopify identity and current tenant/profile/store/
member select the agreement; form IDs, matching email or demo identity cannot.
Profile views remain offline. There is no card entry, new payment setup, provider
vault mutation, charge, order or source/default-address edit in this flow.

The review shows the actual returned scent label, address, subtotal, shipping,
estimated tax, future total and effective date. Paused members are told that
billing stays paused. A required checkbox confirms these future delivery terms.
The ten-minute server review is encrypted in cache and HMAC-bound to the exact
customer/profile, UUID, revision, input, quoted terms/total and merchant policy.
Confirmation recalculates the original quote; changed tax, products, policy,
credential, owner, schedule or terms requires review again before saving.

One `terms_amended` action records `actor_type:shopify_customer`, the original
customer GID and customer confirmation separately from the merchant reviewer of
continuing authority. Shared member/agreement locks and current profile/store,
owner/pivot, policy/action and cycle reads precede acceptance. The existing signed
terms history and collector verify the retained customer/policy evidence without
replacing the original mandate. Unstarted future terms change once; old paid
orders, attempted cycles and the billing anchor stay intact. Exact accepted
replays return the old action even after cache expiry, later pause/cancel or an
editing freeze, without restoring an older state or making provider requests.

Source tests cover the private HTTP flow, identity/host/expiry/confirmation,
scope and tax drift, encrypted-cache/evidence tampering, paused old-date changes,
merchant review CLI, and one same-method payment/Shopify receipt after customer
confirmation. Seven real MySQL worker races cover identical/competing requests,
policy withdrawal, pause, owner revocation, profile removal and a billing claim
after an older snapshot. Four actual rendered desktop/phone views pass browser
checks for overflow, labels, required confirmation and absence of payment entry.
No DDL, native distribution, source stop, real customer delivery change or payment
occurred. Native Shopify contract amendments and actual hosted/provider/two-member
acceptance remain outstanding; the customer feature is not live.

## Original-store historical access and fresh deployment check (October 31)

The Braintree original-payment read now requests the current original app's
scopes alongside the original paid Shopify order. Every method verification,
merchant acceptance, activation and fresh collection requires `read_all_orders`
plus `read_orders` or `write_orders`. A recent accessible source order does not
prove that continuing verification can read it after the default 60-day window.
Missing or revoked access rejects the proof before activation/new collection;
the customer is not charged and no replacement cycle or Shopify order is created.
The existing native Shopify acceptance already checks historical access.
See [Shopify's order-history requirements](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/Order).

Read-only checks at approximately 05:25 UTC on October 31 found:

- The current production application has exactly two prepared members, both
  Recharge-owned, targeting November 2 at $25 and $30. Pilot enrollments and
  cycles remain zero. Replacement agreement/cycle tables are absent; the new
  replacement config fields are unavailable in this undeployed source candidate.
- No Modern Forestry Braintree API connection was configured. Browser vault
  inspection and historical mapping do not connect the original merchant API.
- The original retail credential read `modernforestry.myshopify.com` through
  pinned `2026-10`, with `read_all_orders`, order/customer/product access,
  `write_draft_orders`, own-subscription-contract and saved-method read scopes.
  This proves the observed scopes, not capability/commit or reusable-method
  acceptance, current vault access or existing-contract control.
- Connected Shopify reads confirmed Rynda's #33195 has the exact expected
  Recharge subscription and payment-reference attribute keys. Jennifer's #33186
  has Recharge subscription linkage and a successful Shopify Payments sale,
  without the Braintree payment-reference attribute. Her path remains separate.
  These are historical paid/fulfilled October orders, not proof of current
  source-stop coverage or the upcoming November charge state.
- `/ready` returned `status: ok` and `release: unknown`; that response does not
  establish a deployed Git commit. The resolved Forge release directory was
  `81725293`. No deployment, provider setup, source cancellation, activation,
  customer notice, charge, order edit or credential change was performed.

The saved check contains no credentials, raw provider receipts or payment-method
tokens. The two testers still need actual original-account/API and continuing
authority acceptance. Everyone else stays with Recharge until individually
accepted. Customer address/scent confirmation, native Shopify amendments and
customer/backend parity remain outstanding; this health check is not cutover.

## Accepted Braintree future terms (October 31; source candidate)

`subscriptions:amend` now separately accepts an owner-reviewed delivery/scents
quote for an individually activated Braintree member. It changes only current
future terms/hash and the agreement revision. Original authorization, method
binding, source/member evidence, billing anchor/date, activation, status, existing
cycles and paid orders remain unchanged. No payment, source cancellation, order,
customer/default address, message or provider-vault mutation is submitted by
acceptance. The only provider operation is a fresh calculation of the exact quote.

The private regular 0600 JSON file wraps the same `change` object used by the
quote command plus a separate approval:

```json
{
  "change": {"shipping_address": {"firstName": "Fictional", "lastName": "Member", "address1": "456 Example Road", "city": "Greenville", "provinceCode": "SC", "countryCode": "US", "zip": "29601"}, "fixed_shipping_reviewed": true, "policy_reference": "source-shipping:789"},
  "approval": {"reference": "customer-request:REVIEW_ID", "quoted_total_reviewed": true, "continuing_authorization_reviewed": true}
}
```

The verified owner/admin must review the customer's actual instruction, exact
new total and whether continuing authority covers those future terms. A saved
method or quote alone does not prove consent to a changed charge. This is an
operator workflow; the separate Braintree customer flow is described above.
Native Shopify contract updates remain outstanding. No universal
no-reauthorization claim follows.

```
subscriptions:amend --tenant=SLUG --actor=ID --agreement=ID --file=/private/amendment.json
subscriptions:amend --tenant=SLUG --actor=ID --agreement=ID --file=/private/amendment.json --reviewed-hash=QUOTE_HASH --revision=REV --request-id=UUID --apply
```

Preview is offline. Apply requires the independent default-off
`SUBSCRIPTIONS_REPLACEMENT_AMENDMENT_APPLY_ENABLED`, existing amendment/servicing
and exact tenant/member gates, unchanged quote/context, current revision and an
unstarted future billing instant. Unknown/processing/challenged/reviewed payments,
refunds/disputes, prepared cycles and unconfirmed paid-order handoffs block it.
An indefinitely paused member can accept future delivery terms after an old
billing date; the action records that they take effect after resumption. The
amendment never resumes billing or catches up missed cycles.
Member/agreement locks, current owner/pivot reads and current cycle/action reads
serialize acceptance with other requests, pauses, owner revocation and collection
claims. Identical retries return the original action without provider requests,
including after a later pause; changed content/approval or another action UUID
cannot replace it.

One encrypted append-only `terms_amended` action retains exact before/after terms,
quote/context, explicit reviews, actor, revision and keyed authorization. The
current-terms guard verifies the complete ordered chain back to the immutable
original. Missing/corrupt/intermediate evidence or rolling the current row back to
an older signed snapshot blocks new collection. Original customer, source line
IDs, unit prices, quantities, fixed shipping, currency, schedule, anchor and
Candle Cash exclusion cannot be replaced by a delivery amendment.

Each subsequent amended Braintree collection recalculates current tax before a
cycle or sale is created. Drift requires a new review; it never silently reprices
the charge. Accepted billing verification follows the independent collection
gate, so a servicing/amendment freeze stops new changes without undoing accepted
terms. The immutable cycle carries the accepted tax breakdown into one paid
Shopify order; old receipts and fulfillment details are retained. Numeric zero
tax rates are normalized before encrypted JSON/HMAC persistence.

Source tests cover original-method collection, exact new delivery/tax handoff,
later-address preservation of paid orders, replay/tamper protection and five real
MySQL races using older snapshots. No schema or production change was made.

## Future delivery quote foundation (October 31; source candidate)

The preview-first `subscriptions:amendment-quote` owner command now validates one
existing agreement's proposed address or scent changes. An explicit `--fetch`
calls the original store's pinned 2026-10 `draftOrderCalculate`; Shopify documents
that this calculates totals [without creating a draft order](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/draftOrderCalculate).
No agreement/action/cycle, order, customer/default address, saved method, source
subscription or payment is written. It is an operator quote foundation; applying
reviewed terms and the separate Braintree customer confirmation are described
above. Native Shopify amendments remain outstanding.

The private regular 0600 JSON input may contain a complete US shipping address,
exact source-line scent swaps, and an explicit owner review of the source's fixed
shipping policy for that proposed destination:

```json
{
  "shipping_address": {
    "firstName": "Fictional", "lastName": "Member",
    "address1": "456 Example Road", "city": "Greenville",
    "provinceCode": "SC", "countryCode": "US", "zip": "29601"
  },
  "swaps": [{"subscription_id": "ORIGINAL_NUMERIC_ID", "variant_id": "SELECTED_NUMERIC_ID"}],
  "fixed_shipping_reviewed": true,
  "policy_reference": "original-shipping:SOURCE_SUBSCRIPTION_ID"
}
```

Provide an address, a swap, or both. The source shipping amount and original unit
prices/quantities remain fixed; this does not infer that retail shipping rates
apply to the club. The owner must verify the actual contractual shipping policy
covers that destination. New scent identity must be an active, shippable 16oz
variant in the exact store and requires reviewed Candle Club membership. Catalog
prices cannot replace membership pricing: the documented
[variant price override](https://shopify.dev/docs/api/admin-graphql/2026-10/input-objects/DraftOrderLineItemInput#fields-priceOverride)
is explicit, and returned override/unit/line/subtotal values must agree.

```
subscriptions:amendment-quote --tenant=SLUG --actor=ID --agreement=ID --file=/private/delivery.json
subscriptions:amendment-quote --tenant=SLUG --actor=ID --agreement=ID --file=/private/delivery.json --fetch
```

Without `--fetch` the command is offline. Fetch additionally requires the
independent default-off `SUBSCRIPTIONS_REPLACEMENT_AMENDMENTS_ENABLED`, existing
servicing and exact tenant/member gates, verified owner/admin and the original
store credential. The original app must have `write_draft_orders` plus effective
customer/product/inventory reads. Actual original-account permissions still need
verification. Sandbox quotes require an isolated development store. Shopify-vaulted
contract amendment calculation/commit is still separate outstanding work.

The returned quote binds the original/current terms hashes, agreement/member,
revision, exact input, fixed shipping policy reference, store/app identity and
credential fingerprint. It checks both shop/presentment money, all tax lines and
their sum, currency, customer, quantities, no bundles/discounts/deposits, and no
alerts/warnings. Tax remains a projected figure. Changed permissions, credentials,
schedule, terms or rollout stop the next request; original authorization and the
agreement are rechecked before/after each request. A quote hash cannot authorize
collection or bypass the unchanged-terms guard. The existing paid/cycle history
is untouched. Both new documents validate against the bundled pinned schema;
connector validation explicitly skips and is not a pass. Local HTTP fixtures do
not establish actual provider/tax/shipping acceptance.

## Candle Club membership after source stop (source candidate)

An activated replacement membership now retains the app's current poll, exact
voting history, previously chosen scents, monthly scent cards and private scent
notes even when the imported source contract is cancelled. It uses the existing
authenticated customer session; this does not request new payment authorization
or create a new customer account. Reads do not call a provider or enrich stock
photos. Voting/comments send no message and issue no reward or payment.

Club membership is separate from having a saved payment method. When preparing a
Candle Club agreement, the owner-reviewed private input must include:

```json
{
  "membership_program": "candle_club",
  "membership_reference": "original-club:SOURCE_SUBSCRIPTION_ID",
  "source_membership_reviewed": true
}
```

These fields enter the original reviewed hash and encrypted authorization
snapshot. Generic subscriptions grant no club benefits, and client-supplied
program/profile/tenant values cannot grant access. Do not edit an existing
authorization to add these fields. Existing agreements without original club
review use the separate membership supplement below. Verify the two testers'
original membership evidence as part of preparation/cutover acceptance.

New ballots use `subscription_member_votes`, keyed to the original member and
agreement rather than a fabricated Shopify contract. The unique member/poll
constraint, current member/agreement/poll locking reads and shared legacy vote
mutex prevent duplicate or post-pause acceptance. Poll timing and option ownership
are rechecked. Shared poll totals include the neutral ballots. Existing ballots
remain unchanged and count once: their original Recharge subscription and full
Shopify customer IDs link the history. Matching email/profile is insufficient;
unmapped prior votes block another ballot for review. Converted source mirrors
cannot provide an alternative voting/comment authority, even if stale as active.

Scent notes continue to use the existing private/pending feedback store. The new
record retains its neutral agreement/member context. An unchanged request already
accepted before cutover returns the original comment without replacing its old
contract link. Paused/cancelled memberships and servicing freezes block new votes
and notes; historical reading and safe billing stops remain available.

The additive migration is registered for real MySQL partial-DDL recovery and
retains accepted ballots on code rollback. Composite agreement/member tenant
constraints, incompatible-index refusal, regenerated dump/fingerprint and
prior-base upgrade/dump-boot checks accompany it. Real workers test competing
ballots, a pause and poll closure committed after the initial repeatable-read
snapshot, concurrent unchanged scent-note retries, and an original legacy vote
request whose snapshot predates cutover. Each rejected request leaves no ballot;
the retried note returns one original ID. Legacy mutation locks follow the same
member/agreement-before-poll order. A reproduced poll-closure race was corrected
by validating the current locking row rather than re-reading an older snapshot.

Real MySQL also exposed JSON object-key reordering in the existing store binding.
Its comparisons now use canonical typed content for unchanged installation
validation/replay; identity types and values must still match exactly. Replaying
setup adds no lifecycle event and does not contact a provider.
Broader commitment/gift/member/operator overview parity and customer acceptance
remain outstanding. Existing public OTP delivery is still not configured in
production; this source enables no email/SMS sends.

### Existing-agreement membership review (October 31; source candidate)

`subscriptions:membership` previews an owner/admin review for one exact allowed
agreement. Its private 0600 JSON input contains:

```json
{
  "membership_program": "candle_club",
  "membership_reference": "original-club:SOURCE_SUBSCRIPTION_ID",
  "source_membership_reviewed": true,
  "customer_gid": "gid://shopify/Customer/ORIGINAL_NUMERIC_ID",
  "source_subscription_ids": ["ORIGINAL_NUMERIC_SUBSCRIPTION_ID"]
}
```

Every original source subscription and the full original customer ID must match.
The verified owner/admin reviews source membership; no customer authorization form
is sent. Preview performs no write or provider request. Apply requires that exact
review hash, revision and request UUID:

```
subscriptions:membership --tenant=SLUG --actor=ID --agreement=ID --file=/private/membership.json
subscriptions:membership --tenant=SLUG --actor=ID --agreement=ID --file=/private/membership.json --reviewed-hash=REVIEW_HASH --revision=REV --request-id=UUID --apply
```

Apply appends one encrypted `membership_review` action with a keyed review hash,
the exact original agreement/customer/source context and reviewer. It leaves the
complete billing agreement, original authorization, current terms/hash, payment
binding, billing revision/anchor/status and provider gates untouched. It does not
activate the member, cancel Recharge, collect, send or issue anything. Benefits
still require the existing authenticated activated Everbranch membership and
entitlement. An unchanged UUID retry returns the accepted review after later
schedule changes; another review cannot replace it. Missing/corrupt original
authorization cannot be reconstructed by this workflow. Shared member/agreement
locks and current action reads retain one review under concurrent old snapshots.
Real MySQL tests cover both shared and competing request UUIDs. No schema change
or production membership supplement was performed.

## Native Shopify billing (source candidate; production remains disabled)

The original-app transport now supports separately gated activation, one durable
billing attempt and reconciliation of the Shopify-created order. It preserves
the original saved method, contract, credential, customer and billing anchor.
There is no external order-create handoff or second collection for a native order.

```
subscriptions:shopify-billing summary --tenant=SLUG --actor=ID --agreement=ID
subscriptions:shopify-billing accept --tenant=SLUG --actor=ID --agreement=ID --terms-hash=HASH --apply
subscriptions:shopify-billing activate --tenant=SLUG --actor=ID --agreement=ID --terms-hash=HASH --revision=REV --apply
subscriptions:shopify-billing collect --tenant=SLUG --actor=ID --agreement=ID --apply
subscriptions:shopify-billing reconcile --tenant=SLUG --actor=ID --agreement=ID --cycle=ID --apply
subscriptions:shopify-billing sync --tenant=SLUG --actor=ID --agreement=ID --apply
```

All actions without `--apply` are offline. Acceptance checks current pinned
billing capabilities, own-contract write permission and `read_all_orders` for
continued historical receipt verification. Shopify otherwise limits ordinary
order access to recent history; see [order access](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/Order).
Acceptance records no charge and does not enable collection. The existing setup,
activation, collection, handoff and exact tenant/member gates still apply.
`SUBSCRIPTIONS_REPLACEMENT_SHOPIFY_COLLECTION_ENABLED` defaults off; production
also requires independent `SUBSCRIPTIONS_REPLACEMENT_SHOPIFY_LIVE_ACCEPTANCE_VERIFIED`.

Activation requires the reviewed paused adoption and fresh original Recharge
shutdown/charge coverage. The original activation intent is retained before the
provider patch. A lost acknowledgement recovers the same contract; replay never
undoes a later member pause, skip or cancellation. Local billing ownership changes
only after the owned contract is active on its original first billing instant.

Collection requires the reviewed current day/hour, unchanged terms, exact current
saved-method proof and fresh source stop. The provider cycle must be unedited,
unskipped, unbilled and without prior attempts, on the exact reviewed instant.
Shared member/agreement/cycle locks durably claim one dispatch and source proof
before HTTP. The original UUID, contract and payload stay fixed. Inventory policy
is respected and payment policy is `FAIL_UNLESS_VALID_PAYMENT_METHOD`; unpaid-order
creation is never selected. There is no overdue catch-up or automatic new attempt
after uncertainty.

The current API's pending, authentication-required, failed and successful states
remain distinct. Reconciliation uses the original attempt ID or complete bounded
original-contract pages with an exact idempotency key. Empty, partial or multiple
matches never permit another dispatch. A successful state requires the exact
owned paid order, products, quantities, prices, shipping, tax, currency, address,
environment and matching actual payment transactions before receipt confirmation.
Projected tax remains estimated; actual merchant tax/shipping acceptance is
required before enabling production. Future authentication handling and dunning
remain separate outstanding work; a challenged attempt never becomes paid from a
redirect or a new payment request.

Only a fresh verified receipt of the original immutable native cycle is excluded
from the post-payment Shopify collection check. An arbitrary own-order string
cannot hide a Shopify payment. Other Shopify/Recharge collections remain review
blockers. Original order IDs and attempts survive refunds/disputes; these states
block later collection and are excluded from confirmed collection totals. Sandbox receipts
never appear as production collections. Production native order/payment and later
fulfillment project into the existing read-only reporting lane without counting
the receipt a second time.

The bounded `subscriptions:replacement-process --reconcile --collect` worker now
routes by the retained processor and syncs accepted Shopify schedule changes.
Unchanged confirmed schedules make no extra provider calls. Local safe stops
still prevent future collection while a remote acknowledgement is unconfirmed.
Schedule patches never submit a charge, and cannot race past a newer local review.

Seven pinned billing operations validate against the bundled 2026-10 schema.
That does not resolve the separate calculation/adoption schema gap below or prove
real account permissions, processor behavior, source cancellation effects or
customer-visible parity. Full rollout remains blocked on hosted/provider
acceptance for the two original testers. No production command was executed.

## Original authorization evidence (source candidate)

Preparation now freezes `original_authorization` inside encrypted agreement
evidence: the original reviewed terms, existing reviewed hash and authorization
reference/reviewers. The model protects this snapshot and review identity against
replacement, removal or later reconstruction. The original keyed hash is checked
against those terms, the immutable saved-method binding and original review.

Historical Braintree sale/order checks and Shopify source order/subscription/
address checks use the retained original terms. Their proof distinguishes the
original terms hash from the current agreement hash. A future delivery or scent
change must not make a historical payment appear to have used that new address,
price or product. Current customer/store/currency/schedule identity also cannot be
silently replaced by editing JSON.

Reviewed Braintree owner amendments now carry their own separate signed history.
Unreviewed changed terms cannot collect or adopt a Shopify contract, even if an
old reviewed hash is left in place. Customer confirmation now retains its own
merchant-policy and customer evidence; native Shopify contract amendments remain
outstanding. Original attempts still reconcile using their
frozen cycle terms and original account. Missing/corrupt snapshots fail closed;
preparation replay never fills them from an existing agreement's current terms.
Do not backfill original authority from mutable data as an upgrade shortcut.
This source adds no schema migration, consent request or live billing change.

## Modern Forestry rewards (owner confirmed October 30)

Members may redeem Candle Cash on other purchases: up to **$5 per in-person
purchase** and **$10 online**. Recurring Candle Club charges are excluded.

The owner also requested the **$10 annual birthday reward**. The native rewards
overview and Modern Forestry Coming soon page include it as the existing Birthday
Club coupon benefit, separate from the Candle Cash wallet. The native overview
shows it as available only when that tenant's annual $10 coupon program is enabled;
otherwise it is labelled Coming soon. Rendering rewards never issues a birthday
gift, credits a balance or sends a message. Existing Birthday Club annual coverage,
identity, eligibility and expiration remain authoritative; no second bonus or
retroactive gift is created by this change.

The existing fixed-value reward model issues new $5 Square codes and $10 online
codes. This applies only to the persisted `modern-forestry` tenant through
`marketing.candle_cash.tenant_channel_rules`; other merchants retain their rules.
In-person issuance reuses an open code rather than debiting again. Each new code
retains its issued amount/channel; cancellation of an unused new in-person code
restores that debit exactly once. Previous issued codes remain unchanged and must
be resolved before issuing another in-person code for that customer.

Square remains the established staff workflow: issue the in-person code from the
customer profile, apply $5 at the register, then record the Square order ID when
marking it redeemed. This build does not automatically apply a Square discount.
Manual reconciliation and ingested use share tenant/order locks and permit one
reward per purchase. They reject online codes on Square orders. In-person codes
cannot create Shopify discounts or become the native bag's active online code.
The native rewards screen and history show both limits and the issued value.

Modern Forestry Shopify discounts explicitly set `appliesOnSubscription=false`
and `appliesOnOneTimePurchase=true`. Reusing an existing discount can correct those
eligibility flags without changing its amount, code or dates. This is exercised
only with fictional local HTTP fixtures; deploy/hosted acceptance remains required.
Everbranch recurring agreement terms retain `candle_cash_allowed=false`.

## Shopify saved-method mapping (source candidate)

`subscriptions:shopify summary --tenant=SLUG --actor=ID --agreement=ID` is offline.
`verify` additionally requires `--source-order=ORIGINAL_NUMERIC_ORDER_ID` and defaults
to preview; `--apply` saves encrypted evidence only. It never creates or adopts a
contract, stops Recharge or submits a billing attempt.

The original pinned subscription installation and credential, shop/app identity,
scopes, environment, customer, unrevoked/unexpired card or active Shop Pay agreement
must agree. The paid original Shopify order must match the reviewed source
subscription IDs, products, amount, tax, shipping and address. Current Recharge
store/customer/subscription/address/method reads must identify the **same full
Shopify payment-method GID**, not a matching email, card suffix or default method.
Unavailable/opaque source mappings, changed dates or terms, refunds, rotated
credentials, expired methods and missing scopes fail closed. Raw source vault
tokens are used ephemerally and are not copied into mirrors or reports.

This step proves current method access only. `reauthorization_required` remains
unknown until supported contract adoption and actual merchant acceptance succeed.
Finite source charge limits or a conflicting fixed monthly anchor require separate
review; they cannot silently become an indefinite contract on another billing day.
No real merchant mapping or no-reauthorization conclusion is established by the
fictional tests. Separate native billing source is described above and remains
disabled pending actual merchant/API acceptance.

## Paused Shopify contract adoption (source candidate; adoption command does not bill)

**Original-provider dispatch durability:** new original adoption calculate/commit
and native/Braintree collection/sale entry points reject enclosing transactions
before provider work. Direct transport calls have the same guard. A savepoint is
not a durable send claim: run these operations from a top-level command/worker.
Already-started original adoption/payment recovery remains available without
another send. Inside a caller transaction, Braintree can confirm its original
payment while deferring a new external Shopify order receipt to a top-level pass.
The replacement receipt bridge also rejects direct new sends inside a transaction;
an already-started receipt can still be recovered by its original marker.

Eight existing provider/customer/amendment workflow suites moved to
`tests/Integration/SubscriptionRuntime`, where they run on guarded disposable
local test-named MySQL with no outer test transaction. Provider requests remain
fictional. Independent connections observe original adoption/payment/receipt
markers before the corresponding provider calls; outer-transaction rejections,
lost-response recovery and one later receipt are exercised. CI includes the
directory. Cleanup tracks only tenant/user IDs created in each test. Stored
Braintree merchant acceptance compares canonical object values/types because
MySQL's JSON storage reorders keys; a different original account/credential still
fails. This adds no migration or live merchant acceptance evidence.

The original-app transport has a fixed calculation/commit protocol following
Shopify's documented `SubscriptionContractCalculation` API. It retains the exact
customer and saved method, monthly anchor, quantity, variant, fixed unit pricing,
address, shipping and currency. Projected subtotal, delivery, tax and total must
match the reviewed source terms, with no warnings or discounts. **Projected tax
is an estimate**, not proof of a future collected amount; actual billing receipt
verification and merchant acceptance remain required before rollout.

Commands require the exact tenant, verified owner, agreement and controlled
member. `plan` is offline. Without `--apply`, the calculation/adoption/recovery
commands also return the offline plan and cannot contact the provider.

```
subscriptions:shopify plan --tenant=SLUG --actor=ID --agreement=ID
subscriptions:shopify calculate --tenant=SLUG --actor=ID --agreement=ID --terms-hash=HASH --revision=REV --apply
subscriptions:shopify refresh --tenant=SLUG --actor=ID --agreement=ID --apply
subscriptions:shopify adopt --tenant=SLUG --actor=ID --agreement=ID --review-hash=QUOTE_HASH --revision=REV --apply
subscriptions:shopify recover --tenant=SLUG --actor=ID --agreement=ID --apply
```

`SUBSCRIPTIONS_REPLACEMENT_SHOPIFY_ADOPTION_ENABLED` defaults off. Production also
requires independent `SUBSCRIPTIONS_REPLACEMENT_SHOPIFY_LIVE_ACCEPTANCE_VERIFIED`;
enabling neither follows from source tests. Each write requires current
`write_own_subscription_contracts`, the original installed-app identity and its
unchanged credential revision. Runtime introspection on the **pinned 2026-10
endpoint** confirms the calculation/commit/read fields and every supplied input
field before a dispatch is claimed. Missing capabilities fail without mutation.

The agreement freezes the original calculation input/UUID/context and original
source credential context. Create and commit record write-once dispatch markers
before HTTP. An uncertain calculation without a returned ID requires provider
review and is never resent. A known ID is polled until a reviewable result exists.
The first `calculation_id` stays immutable even when a concrete Pending ID resolves
to a concrete Success or Failure ID. A successful result must prove the original
adoption marker, saved method, terms and projected totals before adding immutable
`terminal_calculation_id`. Commit uses that actual successful ID; the quote, exact
review hash and immutable commit target must agree. A later different success ID
cannot redirect the reviewed adoption. Invalid terms/warnings retain the original
poll handle without granting a terminal commit target. A failed result likewise
cannot authorize a commit.

An unsent prepared commit rereads the exact successful ID it reviewed. Older
generic-ID prepared rows may gain terminal evidence only from a fresh verified
response matching their immutable original commit ID and review hash. Already
dispatched commits go straight to original-contract recovery, never a new commit.
No storage migration is required. The guarded MySQL race exercises pending to
concrete success followed by two workers competing for the same one commit.

Before the one commit, fresh original-method verification, unchanged quote review
and complete original Recharge stop/charge coverage are required. The commit proof
and original source revision are rechecked immediately before dispatch.

A lost commit response recovers only a complete original-customer contract list
with one matching app/agreement/adoption UUID. Missing, multiple or partial
results never trigger another commit. Once its ID is known, that exact contract
is paused before considering adoption ready, and its original first billing
instant is set and read back. Lost pause/date acknowledgements recover through
fresh owned reads. Changed terms or an elapsed original billing date remain
paused for new review. No local activation, member billing-owner change, order
creation or billing attempt is part of this command.

**API verification limit:** the bundled offline 2026-10 validator still rejects
these documented types and the connector validation endpoint skips. The later
original-app pinned capture described above exposes the actual operations and
validates all native documents and sample input variables. These are separate
results: actual schema syntax/types pass; provider mutation/merchant acceptance
is unproven. Runtime original-account capability probes and isolated-store
calculate/commit/recovery acceptance remain mandatory before production adoption.
The fictional tests and MySQL races prove local authority/recovery logic, not a
completed real provider cutover.

References: [Shopify calculation migration requirements](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts/migrate-to-subscription-calculation-api),
[contract calculation](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractCreateCalculate),
[commit calculation](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractCalculationCommit),
[Recharge source subscription terms](https://developer.getrecharge.com/2021-11).

## Existing implementation examined

| Existing component | Retain and extend | Current limit |
| --- | --- | --- |
| `SubscriptionAccessService` | Entitlement, active membership, exact persisted store/app binding | Generic provider execution deliberately closed |
| Shopify installation/credential/operation/resource/inbox services | Revisioned tenant-owned access, bounded owned-contract reads, encrypted evidence and raw HMAC verification | Read operations do not authorize contract adoption or mutations |
| Recharge connection/transport/readiness/source guard | Original store/customer/subscription mapping and fresh queued/collected-charge checks | No automatic source cancellation; incomplete reads block cutover |
| `SubscriptionPilotExecutionService` and cycle models | Reviewed exact economics, original anchor, durable request, no double collection, lost-response recovery, pause/cancel pattern | Stripe-specific authorization, fields and verification |
| `SubscriptionPilotShopifyBridge` | Paid-order product/customer mapping, inventory policy, original-result recovery | Separate original Stripe/Braintree verification; Shopify billing attempts create their own orders |
| `TenantBraintreeConnectionService` | Encrypted tenant connection and exact account/customer/method reads | Connection/verification only, no collection permission |
| `SubscriptionModuleService` and native Candle Club UI | Existing identities, votes, scent catalog, private ratings/comments, validated action payloads | Most management actions are review requests; pilot membership is separate |

Historical intents, imported mirrors, manual payment labels and matching emails
must never become executable payment instructions. Preserve the existing Stripe
pilot's independent behavior and all paid orders/fulfillments.

## Build order

1. **Shared billing ledger and controlled enrollment.** Separate the billing
   owner (Recharge/Everbranch) from the processor (Braintree/Shopify/Stripe).
   Reuse prepared member identities. Retain encrypted reviewed terms, exact
   source and reusable-method mappings, versioned amendments, unique cycles and
   immutable provider requests. Preparation cannot stop Recharge or collect.
2. **Braintree execution.** Keep the original merchant/account and vaulted method.
   Verify original-source payment mapping and continuing recurring authority.
   Submit one recurring sale per durable cycle; recover uncertainty by original
   transaction/order identity, never blind-retry a sale. Track processing,
   declines, settlement, refund and dispute state independently of Shopify.
3. **Shopify-vaulted execution.** Verify the installed subscription app, granted
   scopes, customer payment-method identity and supported contract migration.
   Own-contract API access does not grant ownership of Recharge contracts.
   Use Shopify billing attempts for this rail; its resulting order is not
   recreated through the external-payment bridge. An API key alone is not proof.
4. **Effective customer controls.** Connect the existing authenticated web/app
   surfaces to the new agreements. Implement pause/resume, one-cycle skip,
   cancellation, address and eligible scent changes with idempotent action IDs,
   cutoff/processing checks and future-term revisions. Preserve existing cycle
   requests, orders and labels. Reuse voting and private scent feedback.
5. **Operations and recovery.** Provider-specific dunning and customer action
   flows, original-payment refunds, signed events plus current reads, paid-order
   and fulfillment reconciliation, durable alerts and explicit operator recovery.
   Uncertain results block new payment attempts. No marketing is sent by setup.
6. **Dark release, then two-member acceptance.** Run migration recovery,
   concurrency, tenant isolation, provider sandbox and web/app checks. Verify
   each selected source is stopped and no queued/collected duplicate exists
   before individual activation. Confirm one payment, one paid Shopify order,
   expected inventory/shipping/notifications and working member controls.
   Expand only after a reviewed cycle; retain all other Recharge subscriptions.

## Gates and proof

Setup, mutation, collection, Shopify handoff and actual merchant acceptance are
separate default-off controls with exact tenant/member rollout. Connecting a
processor, an owner checkbox, a completed test or a redirect cannot enable live
billing. Supported existing authorization is retained only after exact mapping
and continuing authority are verified; unsupported/expired/revoked methods use
a separate customer authorization flow.

Rollback stops new attempts while retaining every agreement, original request,
payment, refund and order mapping. Never automatically re-enable Recharge for
an unknown or already collected cycle. Reconciliation precedes any resumption.

Preserve Shopify order/fulfillment behavior and verify downstream automations.
Provider/app/source labels can differ; an invisible backend cutover is not yet
proven. The two testers are acceptance cases, not hard-coded customer rules.

## Implementation checkpoints

- [x] Inventory existing services, schema, customer surfaces and tests.
- [x] Record replacement scope and retain existing-method reuse as the priority.
- [x] Encrypted provider-neutral ledger, controlled preparation and original-anchor operator schedule controls.
- [x] Recorded gateway badges in Everbranch customer purchase history, with bounded history enrichment.
- [x] Source implementation for exact Braintree method/source mapping and gated individual activation.
- [x] Source implementation for Braintree execution, original-transaction recovery and owned Shopify order/fulfillment reporting.
- [x] Authenticated customer pause/resume/skip/cancel and private member schedule page, with paired native entry point.
- [x] Immutable original reviewed authorization snapshot, separated historical proof and current collection/adoption guards.
- [x] Source implementation for authenticated member polls/scents/history and private feedback after source cancellation, with neutral ballots and exact legacy vote continuity.
- [x] Source implementation for reviewed Braintree owner amendments, signed future-term history and exact amended-cycle receipt/tax handling.
- [x] Source implementation for authenticated Braintree customer address/scent review and confirmation, separate merchant coverage and truthful customer action evidence.
- [ ] Native Shopify contract amendments and actual hosted customer-delivery acceptance.
- [ ] Actual Braintree merchant sandbox/live acceptance and original source-stop verification.
- [x] Source implementation for Shopify-vaulted paused adoption, activation, billing attempts and native receipt reconciliation.
- [ ] Actual Shopify API/merchant acceptance, customer authentication recovery and source cutover acceptance.
- [ ] Customer membership/control parity, dunning, refunds and reconciliation.
- [ ] Protected release and actual two-member acceptance.
- [ ] Reviewed wider migration and eventual Recharge account retirement.

## First source checkpoint

The three new tables are `subscription_billing_agreements`,
`subscription_billing_cycles` and `subscription_billing_actions`. Composite tenant
ownership, named recoverable constraints, immutable original requests and
encrypted/hidden terms, method bindings and audit payloads preserve the existing
Stripe tables without reinterpreting their fields. No released migration changed.
MySQL 8.4 partial-DDL recovery and a data-free schema baseline are included.

`SUBSCRIPTIONS_REPLACEMENT_SETUP_ENABLED`,
`SUBSCRIPTIONS_REPLACEMENT_SERVICING_ENABLED`, exact
`SUBSCRIPTIONS_REPLACEMENT_TENANT_IDS` and exact
`SUBSCRIPTIONS_REPLACEMENT_MEMBER_IDS` all default off/empty. These authorize
local preparation/servicing; separate execution controls are listed below.
An active verified workspace owner/admin and the existing Subscriptions
entitlement are required. There are no seeded tester names or email bypasses.

`subscriptions:replacement summary --tenant=WORKSPACE --actor=ID` is offline.
`prepare --member=ID --file=/absolute/private.json` previews reviewed terms and
returns a digest. The file must be a regular nonsymlink 0600 JSON file, at most
64 KiB. `--apply --reviewed-hash=DIGEST` saves only a prepared agreement. Method
tokens, original authorization references and addresses remain encrypted and are
not printed. Source amount/products/shipping/tax, future date and the original
tenant-owned processor identity must match. Review checkboxes do not establish
provider method verification or continuing collection authority. Mixed/prepaid
schedules remain unsupported by this first slice.

`pause`, `resume`, `skip` and `cancel` require `--agreement=ID --revision=N` and
`--request-id=UUID`; they preview by default and persist only with `--apply`.
They reject a prepared/Recharge-owned agreement. Individual Braintree activation
is separate from these schedule controls and repeats current provider/source checks.
Unchanged IDs replay the original result; stale/conflicting reviews fail.
Skips and resumes preserve the original month-end anchor and prior skips.
Unknown/prepared/in-flight cycles block schedule edits. Cancellation stops future
billing while retaining an original uncertain payment for reconciliation; it
does not void/refund that payment. Existing Stripe creation/collection refuses
an already activated replacement agreement, even if an old ownership flag is stale.

Customer address/scent/payment changes are not wired to these agreements yet.
Customer schedule access now uses the private account path described below. Neither a model status nor an operator schedule action
is proof that the full replacement is ready.

## Braintree execution source checkpoint

`SubscriptionBraintreeMethodService` links an original paid Shopify order's exact
Recharge subscription/customer/payment references, products, amount, shipping,
tax and address to an original settled Braintree sale and its current vaulted
token. It uses the original tenant-owned connection and official SDK, not an
email match. Active unexpired cards and nonrevoked PayPal billing agreements are
supported; shared-vault grants and active native Braintree subscriptions need
separate review. Verification performs no sale, hold, vault change, new consent
or source cancellation. Continuing authorization must already be reviewed in the
prepared agreement; technical mapping does not replace actual merchant acceptance.

Separate controls all default off:

- `SUBSCRIPTIONS_REPLACEMENT_ACTIVATION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_COLLECTION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_HANDOFF_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_COLLECTION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_LIVE_ACCEPTANCE_VERIFIED`

These also require setup, exact tenant/member allowlists, the active verified
owner/admin and the original persisted account/credential revision. Production
additionally requires an audited per-connection mapping/polling acceptance that
matches the current credential fingerprint. Setting an environment flag alone
does not satisfy that merchant proof. Sandbox attempts require an isolated
Shopify development store and test source orders; they cannot decrement stock
in a production Shopify store.

`subscriptions:braintree summary --tenant=WORKSPACE --actor=ID --agreement=ID`
is offline. `verify --source-order=SHOPIFY_ORDER_ID` previews current owned
Braintree/Shopify reads; only `--apply` persists masked proof and audit evidence.
`accept --reviewed-hash=HASH --apply` pins the freshly verified merchant mapping
and transaction-polling acceptance without collecting or activating.
`activate --source-connection=RECHARGE_CONNECTION_ID --reviewed-hash=HASH
--revision=N --apply` repeats method/current-product/Shopify permission checks
and fresh exact-source stopped/queued/collected checks. Only then does it switch
that member's billing owner to Everbranch. It does not cancel any Recharge
subscription, change other members or send a payment.

`collect --apply` is limited to the original current billing day at/after the
reviewed hour. It repeats exact source-stop/charge coverage immediately before
reserving the immutable cycle. One database-serialized dispatch marker is
durable before one recurring SDK sale, using the original merchant account,
saved token and stable `ebsub-UUID` order ID. No automatic sale retry is used.
Lost, timed-out, zero-result or mismatched responses remain unresolved; subsequent
collection calls reconcile that original request and cannot send another sale.
Even a definite decline retains its original attempt. A future reviewed dunning
attempt ledger is still required for safe retries.

`reconcile --cycle=ID --apply` searches the original order ID, reads the original
transaction and a bounded complete customer/account billing window. Multiple
matches or additional transactions persist a duplicate-review block. Settlement,
refund and dispute reads update the original cycle. A restarted source blocks
the first fulfillment until fresh proof recovers; a genuinely observed source
collection remains a duplicate alert even after source billing is stopped.
Refund/dispute/uncertainty blocks another cycle and never reenables Recharge.
The original monthly anchor advances once after verified settlement and source
coverage. Source reads currently require a recent seven-day cycle for first
handoff; older unresolved collection needs operator review. Eventual Recharge
retirement needs a separately verified source-retirement policy; this pilot does
not silently remove source coverage checks.

The Braintree path reuses the existing paid external Shopify order bridge with
its own receipt/reference/account attributes and an exact original-payment read.
Only settled, nonrefunded, undisputed transactions with fresh source coverage
permit a first handoff. The durable original request/tag recovers a lost order
response without another create. Original products, prices, tax, shipping,
customer, payment receipt, test mode and amount must match. Inventory obeys
Shopify policy; receipt and fulfillment emails remain disabled by this request.
Order/fulfillment projection requires the persisted original cycle/order link,
not just an order note. The external receipt never becomes another payment.
The pilot dashboard includes observed production Braintree collections, excludes
sandbox/refunded/disputed money, and retains duplicate-review alerts. These
operational observations are not accounting revenue.

`subscriptions:replacement-process` is offline by default. Its `--collect
--reconcile --limit=20` scheduler runs every five minutes with overlap protection,
exact allowlists and the independent gates. It polls unresolved/recent cycles
and older retained receipts daily within the reviewed window. The worker never
resubmits an original sale or changes provider context. Braintree's transaction
settlement webhooks cover ACH/SEPA rather than general card/PayPal sales, so this
path uses current SDK reads instead of depending on an unavailable settlement
webhook. Signed dispute/revocation events remain a later operations requirement.

No provider credentials, real sale, source stop, customer activation, hosted
fulfillment acceptance or production deployment has been performed by this
checkpoint. Customer future-term changes and membership parity, Shopify-vaulted
contract adoption, safe dunning, refund execution and two-member acceptance remain
outstanding.

## Customer schedule and private account access source

The existing verified Shopify customer session selects the original persisted
retail store, customer and activated Everbranch agreement. Email, merged CRM
profile permissions, supplied member IDs and demo identity cannot select it.
A prepared/Recharge member retains its existing path. Signed-in access can issue
a random private URL lasting 15 minutes; only its SHA-256 key and original
identity grant are retained in the protected cache, without the account bearer
token. Every page/post rechecks original customer/store/agreement and expiry.
Private pages use the configured application host, CSRF, no-store/no-referrer,
no-index and denied framing. Existing account login is reused; the page has no
new payment-authorization step and never requests payment details.

`SubscriptionBillingAgreementService` now shares the same member-then-agreement
lock order across owner and customer schedule changes. Customer requests require
an explicit current revision and stable UUID; repeated matching requests return
the original action, while stale/conflicting requests fail. Actor identity is the
original Shopify customer, not an impersonated workspace owner. Schedule changes
retain all immutable cycle requests, payments and placed orders. The cutoff
prevents skipping a billing day after its reviewed hour. Uncertain payment
attempts block skip/resume; pause/cancel stop future billing while retaining the
original attempt for reconciliation. Existing customers retain safe stop access
when rollout is frozen or the merchant loses the add-on. Resume/skip still require
the current entitlement and exact servicing rollout.

A pause can be indefinite or a whole number of monthly billing cycles. Finite
pauses preserve the original month-end anchor and already skipped cycles. Only
its original customer pause audit/revision/date permits a scheduled resume;
changing a model date cannot manufacture that permission. The Braintree worker
resumes only on the accepted billing date, with collection/source/payment checks
still required. Overdue dates never become catch-up charges.

The paired native source shows Manage subscription only when the server supplies
an owned Everbranch subscription. It obtains a fresh private link from the fixed
authenticated account endpoint, accepts only HTTPS private subscription paths at
the original app host, uses the existing in-app browser and refreshes account
state when that page closes. Private URL tokens are redacted from browser debug
logs. Other members retain the existing account menus. No version bump, store
submission, provider activation or customer distribution is performed.

The later sections above provide current owner-reviewed Braintree amendments and
membership/vote/comment continuity after cancellation. Customer address/scents
confirmation, native Shopify contract amendments, payment updates, operations
and real hosted/customer acceptance remain required; this schedule checkpoint
alone is not the full customer replacement.

## Native Shopify update calculation journal (source candidate; disabled)

`SubscriptionShopifyUpdateService` connects the validated native update protocol
to encrypted signed append-only `SubscriptionBillingAction` stages. The internal
owner methods calculate, reconcile or explicitly close an uncommitted review.
Owner commit/CLI/history and worker recovery are described next; native customer
confirmation remains outstanding; the amended-tax collection lane is described below.
`SUBSCRIPTIONS_REPLACEMENT_SHOPIFY_AMENDMENTS_ENABLED=false` is independent of
the general amendment and existing exact-member servicing gates. Enabling it
alone cannot change a contract or charge a customer.

Preparation retains normalized merchant-reviewed shipping/scents, the exact
original contract/version/line IDs, provider context, revision/hash and UUID.
Current member/agreement/owner/pivot and original installation/credential/store
checks precede the send. The signed send action commits first. A second locking
transaction holds the servicing authority through bounded fixed-operation HTTP;
an outer caller transaction is rejected before any provider request so that the
send marker cannot remain uncommitted. An independent MySQL observer verifies
durability in the test fixture. The journal, not mutable agreement metadata,
prevents duplicate dispatch after a process failure or concurrent request.

If the initial response is lost without a calculation ID, retain
`calculation_unknown`; never invent an ID or submit another calculation for the
same review. A known ID is retained in its own committed action before warnings,
user errors, totals or current revision are evaluated. Reconciliation only polls
that original ID. Provider failure is terminal for that review. A pending result
completed after a local pause/cancel returns `review_stale`; an already retained
quote can be replayed after a freeze without changing status, revision or dates.
Pre-commit closure adds a signed aborted action and permits a separately reviewed
new request; it does not cancel a contract or erase the original send evidence.
A finite pause may legitimately leave an older valid paused provider billing date;
calculation preserves it and the newer local pause date.

The calculation catalog now requests the complete original transport identity.
All 21 documents are still checked against the original-app pinned 2026-10 schema
capture and its CI projection. MySQL tests cover separate-connection send-marker
visibility, no-resend recovery, immutable evidence tampering, stale review/current
owner revocation, credential revocation beyond an older consistent snapshot,
terminal failure, close/replay and two independent workers.

The owner commit/history/recovery and fresh native tax extensions follow.
Authenticated native customer confirmation remains required before customer edits launch.
Original source-method association and real merchant/provider/two-tester hosted
acceptance remain unproven. No live contract, source, order or charge changed.

## Owner native update commit and recovery (source candidate; disabled)

`subscriptions:shopify-amend --tenant=WORKSPACE --actor=ID --agreement=ID` is an
offline preview of the exact owned member. No file, provider request or gate
change occurs. Every stateful action below additionally requires `--apply`.

1. `--action=calculate --file=/PRIVATE/change.json --request-id=UUID --revision=N`
   accepts only a top-level `change` object with the reviewed address/scents,
   fixed-shipping review and policy reference. The file must be a regular 0600
   JSON file of at most 64 KiB. Retain the returned quote hash, exact total,
   original UUID and reviewed revision. Pending calculations poll only their
   original ID; unknown initial outcomes are never regenerated.
2. `--action=commit --file=/PRIVATE/approval.json --request-id=UUID --revision=N
   --reviewed-hash=HASH` accepts only an `approval` object containing `reference`,
   `quoted_total_reviewed` and `continuing_authorization_reviewed`. Both reviews
   must actually be completed by the active verified owner/admin before setting
   them true. No software/CLI action fabricates those reviews. The quote expires
   after ten minutes. The service rechecks the original contract/version and
   calculation, original saved method and complete fresh Recharge stop coverage.
   Original method/source scope and native/general amendment/apply gates remain
   required. This commits contract terms and never charges or changes source billing.
3. `--action=reconcile --request-id=UUID` recovers a dispatched commit only from
   the original owned contract, unique amendment attribute, exact line IDs and
   reviewed price/policy/method/note state. It never sends another commit. An
   uncertain result remains blocked for review and the CLI exits nonzero. A
   definite provider rejection or a proven failure before HTTP can be closed;
   an ambiguous dispatched operation cannot be erased or closed as unsuccessful.
4. `--action=abort --request-id=UUID` explicitly closes a pre-commit review or a
   definitively rejected/unsent commit. It preserves signed original evidence.
   A separately reviewed new request uses a fresh UUID. Do not clear journal rows.

Shopify's documented calculation IDs include concrete state types such as
`SubscriptionContractCalculationSuccess`. Keep full returned IDs as opaque
references. A fixed original-ID poll can return a terminal concrete ID; the
original poll handle stays immutable, and a success still must prove the unique
request marker plus exact original intent before it can become a quote/commit.
No ID is fabricated from a customer, source subscription or line ID. See
[Shopify's calculation query](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/subscriptionContractCalculation)
and [commit mutation](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractCalculationCommit).

Commit approval/send/result actions are encrypted, append-only and signed. The
send claim commits before bounded HTTP; a second transaction holds current
member/agreement/owner/pivot/original credential authority through the send.
Outer transactions are rejected before dispatch. A missing parent approval cannot
hide a retained send claim and reopen collection. No retry of an uncertain
commit is allowed. `subscriptions:replacement-process --reconcile` can apply an
already approved result before schedule synchronization; it does not approve or
send another contract update. Already-attempted payments retain their own recovery.

Native signed future-term history retains original authorization, the exact
quote/approval, send authority, confirmed contract and any later signed pause/
cancel chain. Application increments the actual current revision and preserves
current status, next date and resume date. Historical checks require no live
provider access and remain valid after later freezes or credential withdrawal.
Current contract attributes come only from this history. Native projected tax
is aggregate estimated evidence; no itemized lines or new tax rate is fabricated.
Unresolved approval/commit blocks resume/skip/new collection while owned-contract
pause/cancellation remain possible. Finite customer pauses survive contiguous
validated terms-only amendments for both Braintree and native agreements; a
missing revision, new schedule or changed accepted resume date requires review.

Fresh native amended-tax collection preflight is connected below. Native
authenticated customer confirmation remains incomplete. Positive provider
synchronization retains its original owned-contract and authority checks.
The owner CLI is not evidence of production acceptance. Actual saved-method/source
association, merchant/API, customer view, Shopify fulfillment/backend and exact
two-tester acceptance remain required. No deployment, live contract/source/order/
charge, customer notification or rollout was performed.

## Fresh native amended-tax collection (source candidate; gated)

`SubscriptionShopifyTaxService` performs an original-app update calculation only
for accepted native amended terms that are due today on an active individually
activated Everbranch member. Its input retains all unchanged live fields and adds
a unique `everbranch_collection_review` attribute only to the calculation. It
never commits that calculation. The separate amendment CLI cannot commit its
request UUID. Consequently this check does not change a customer's saved address,
the live contract, old order, source subscription or payment method. See
[Shopify's calculation semantics](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractUpdateCalculate).

Encrypted signed request/send/received/proof/expired actions retain every
generation. The send claim commits before bounded original-app HTTP; a separate
MySQL observer verifies visibility. Initial provider handles remain immutable.
A correlated terminal success must match the exact accepted subtotal, shipping,
aggregate tax, total and currency, with no discounts/warnings, and the original
method, line IDs/quantities/prices/policies, selling-plan fields and note. Native
tax lines/rates are not fabricated. User errors, stale context, changed terms or
changed tax require review before any billing cycle or payment is created.

Freshness is at most three minutes from the recorded calculation send and proof
observation. A fresh read of an old completed calculation cannot extend that
window. The service performs up to two short bounded pending-result reads. The
minute `subscriptions:replacement-process --tax-preflight` mode also prepares or
polls due allowed active native members; it never charges when used alone. The
existing `--collect --reconcile` job keeps its five-minute cadence. Both use the
existing exact-member collection/handoff/merchant gates, which remain default-off.
Expired or unconfirmed tax-only calculations age into an auditable new unique
generation; the original send/handle is never overwritten or replayed. No
calculation from this lane can update the live contract. Slow, failed or ambiguous
checks leave collection blocked rather than manufacture a paid result.

The collector checks proof freshness/context/current revision before preparing a
cycle, then re-reads the original full contract version and checks again before
claiming/sending payment. The actual proof is frozen as
`reconciliation.dispatch_native_tax_preflight`; it cannot be erased or replaced.
A prepared unsent cycle can refresh only its mutable proof pointer while its
frozen amount, terms hash/revision, method and request remain identical. A cycle
that belongs to an older authority requires review. An already-started attempt
reconciles through its original ID without another tax calculation or payment,
even after its proof expires or editing is withdrawn. New amended dispatch refuses
an enclosing transaction before provider work. Existing source-stop/duplicate
coverage is still refreshed after the tax check and immediately gates payment.

Real MySQL cases exercise independent claim visibility, one calculation/payment
across competing workers, delayed minute/five-minute schedules, same-handle
pending recovery, expired/unknown generation renewal, changed tax/version/owner,
warnings/errors/revocation, immutable dispatch proof and frozen-cycle refresh.
This is local source evidence, not actual merchant/provider/customer/Shopify
acceptance. Native customer confirmation, original adoption ID/outer-transaction
audits and the real two-tester rollout remain required. No DDL, deployment, live
provider contract/order/charge/source change or customer notification occurred.

## Customer purchase history gateway icons

The existing Everbranch customer profile Orders table now has a Payment gateway
column. Bundled Braintree, Stripe, PayPal, Shopify and Square icons include text
labels; Shop Pay/other methods use a neutral icon with the reported name. The
separate Square payment records also show their known source. No remote icon
service is contacted. Source and license accompany the bundled SVGs.

Normal Shopify ingestion retains minimal gateway names and source time in
`Order.attribution_meta.payment_gateways`, not raw receipts, tokens or card data.
Successful SALE/CAPTURE gateways take precedence when supplied; otherwise names
are explicitly reported gateway evidence, never confirmation of payment success.
Older/partial payloads do not erase a newer known observation. Existing unknown
history stays Not recorded until refreshed.

`customers:payment-gateways --tenant=WORKSPACE --actor=ID --profile=ID --limit=10`
previews fixed query-only reads for at most 20 of that profile's linked Shopify
orders. It requires active verified customer management access, an exact
tenant-owned persisted store credential and both local order/customer links.
Shop/app/customer identity and credentials are rechecked after I/O. Reads must
return API 2026-10; failure saves nothing. `--apply` writes only gateway metadata
in one transaction without order model events, loyalty, messages, payment or
Shopify changes. It does not run on profile load. Older-order visibility depends
on the installed app's Shopify permissions. Its GraphQL document was validated
against the 2026-10 schema.

References: [Braintree recurring sales](https://developer.paypal.com/braintree/docs/reference/request/transaction/sale/php),
[Braintree transaction states](https://developer.paypal.com/braintree/docs/reference/response/transaction/php),
[Braintree settlement webhook coverage](https://developer.paypal.com/braintree/docs/reference/general/webhooks/transaction/php),
[Shopify subscription contracts](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts),
[Shopify payment-method migration](https://help.shopify.com/en/manual/products/purchase-options/subscriptions/migrating-payment-methods).
