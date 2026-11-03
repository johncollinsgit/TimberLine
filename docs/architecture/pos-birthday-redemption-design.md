# POS birthday redemption: shared annual coverage

## Status

Design only. The current POS candidate and lifecycle policies are default-off,
have no production authority binding or route, do not issue an entitlement, and
always report `redeemable: false`. No synthetic birthday issuance should be
created. Existing Shopify birthday codes and uncertain online issuance remain
ineligible for POS redemption until a coordinated cross-channel lifecycle exists.

## Existing behavior and why a separate hold is insufficient

`BirthdayClubService::withCycleLock` locks the canonical customer and a
`birthday_cycle_coverage` row, and considers existing `BirthdayRewardIssuance`
records, coverage references, and historical issuance markers. That row has a
unique tenant/customer/cycle key. The club-enabled path of
`BirthdayRewardEngineService::issueAnnualReward` uses this lock; its legacy path
when the club is disabled does not. A separate POS reservation row alone would
not stop online issuance. A fabricated issuance is also unsafe: existing
storefront status selection treats non-Candle-Cash issued/claimed rewards as
online rewards, and the issuance path may dispatch an email for the record.
Writing `legacy_covered`, `cancelled`, or `redeemed` as a sentinel would falsify
history and mishandle declined or interrupted payments.

## Proposed shared guard

1. Define one channel-aware annual-cycle guard used by every **new** online or
   POS issuer, including the legacy club-disabled path. Resolve tenant and
   explicitly verified canonical customer, then lock in a documented order:
   canonical customer, annual coverage row, entitlement, and original POS sale.
   Lock ordering must be reconciled with existing sale and wallet locks to avoid
   deadlocks. Revalidate the identity graph, published tenant policy and the
   selected cycle after obtaining the locks. If aliases change, historical
   evidence conflicts, or ownership is ambiguous, fail closed to review; do
   not join customers by name, email, phone or card.
2. Use an additive migration with recovery checks to give coverage a typed
   reference to a separately persisted POS entitlement or reservation. Preserve
   the unique `(tenant, canonical customer, cycle year)` constraint and all
   historical online references. Store no synthetic Shopify code or invented
   issuance. Maintain auditable state and immutable policy/version, amount,
   timezone, window, customer, sale and non-secret authorization reference.
   Existing annual coverage, online issuance of any status, historical markers,
   uncertain provider state or conflicting import evidence block new POS
   issuance. Historical imports must remain possible as evidence, and conflicts
   discovered after a POS hold must go to review rather than be overwritten.
3. A POS hold blocks a competing new online issuance while it is active. The
   hold is consumed once after fresh, verified Square payment evidence for the
   original sale and correct merchant binding. Release only after verified
   unstarted discard or authoritative terminal unpaid evidence. Timeouts,
   dismissal, process death, unknown callbacks, and expiration alone retain the
   hold. A subsequent refund enters review until the tenant has approved an
   explicit benefit-reinstatement policy. Preserve idempotency and audit across
   retries and reordered provider events. If online and POS coverage conflict,
   do not perform another charge or manufacture another reward.
4. Existing issued or uncertain Shopify codes are **excluded** from the POS-only
   release. Supporting a single code in both channels would additionally need
   an explicit Shopify disable/verify and reconciliation protocol; a local lock
   cannot stop a customer using an active external code. That is separate work.

## Authorization and pricing

An assigned register operator must select an Everbranch customer and the customer
must authorize this specific redemption before the discount is included in the
quote. Ordinary checkout can still associate customers after payment. A future
authority implementation must verify an unexpired, one-use customer-present
proof bound to tenant, profile, sale and nonce, with no network calls under a DB
lock. Store only a non-secret reference or hash, never the bearer QR/code. A
phone lookup or cardholder name is not authorization.

Use the owner's published tenant policy for amount, eligibility, timezone,
window and leap-day handling. Freeze those terms and the verified customer on the
original sale. Cap the benefit at the eligible post-discount amount, calculate
tax after the discount, and block collection if the policy, annual guard or
authorization is invalidated before payment. The server must compute all amounts;
the POS client supplies no trusted price or eligibility. Duplicate taps and a
replacement quote must not reserve the same entitlement twice.

## Consumers to update and audit

- `BirthdayClubService` and `BirthdayRewardEngineService`: centralize the guard
  for club and non-club issuance paths; preserve legacy annual and identity
  rules. Review status selection and `claimIssuedReward` for the new channel.
- `BirthdayRewardActivationService` and
  `BirthdayRewardRedemptionReconciliationService`: keep Shopify code activation
  and online redemption semantics intact; never activate a POS-only entitlement
  or infer provider redemption from an amount-only POS payment.
- `BirthdayCsvImportService`, `MarketingBackfillBirthdayCoupons`,
  `MarketingIssueBirthdayRewards`, and `ReconcileBirthdayClub`: audit every
  direct write or backfill against the shared guard. Preserve historical imports
  and surface conflicting previously unknown online evidence for review.
- `BirthdayPagesController`, customer/storefront status and claim surfaces,
  `BirthdayEmailDispatchService`, `BirthdayEmailFollowupService`, birthday
  reporting and dashboard/weekly reporting: distinguish channel and state;
  never email or display a POS hold as an online code. Preserve existing links,
  customer-visible history and reporting of genuine Shopify rewards.
- POS quote, collection, discard, recovery, outbox, Square refund observation,
  register UI and customer proof/QR flows: integrate the durable entitlement
  only after the shared guard and provider evidence contract exist. Reward
  accounting, inventory and the original payment remain independent; a failed
  reward side effect must not block an already-confirmed provider refund.
- Tenant policy administration, rollout gates, schema baseline and migration
  recovery checks: include explicit reviewed publication, backwards-compatible
  rollout, retention of evidence on rollback, and a default-off global gate.

## Required verification before activation

- Unit tests for window boundaries in the tenant timezone, year-crossing windows,
  leap-day policy, invalid/changed terms, zero caps, item/sale discounts and
  tax on the discounted amount. The current preview and transition tests are not
  evidence of a working entitlement.
- Isolated MySQL concurrency tests for online issuance versus POS reservation,
  two registers for one customer/cycle, identity merges, tenant isolation,
  disabled-club legacy issuance, backfill/import during a hold, retries and
  deadlock recovery. Exactly one authority may win; uncertain results hold.
- Payment tests for declined/cancelled/unknown/interrupted callbacks, duplicate
  and reordered provider observations, process restarts, quote discard,
  successful single consumption, full and partial refunds, already-spent
  rewards and operator review. No financial side effect may be inferred from
  client callbacks alone.
- Existing Shopify birthday issuance, claim, activation, email, catch-up,
  historical identity and redemption reconciliation regressions, plus explicit
  proof that existing active or uncertain codes are blocked from POS use.
- An end-to-end customer authorization and physical-register rehearsal in a
  disposable or approved test environment, followed by a separately reviewed
  tenant rollout. Do not claim POS birthday redemption until all gates pass.
