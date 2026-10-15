# Everbranch subscriptions and gift cards

## Scope and commercial decision

Subscriptions and Gift Cards are separate add-ons. Modern Forestry retains its
existing explicit comped subscription entitlement (production source
`modern_forestry_alpha_default`, observed on October 15);
there is no runtime tenant-ID, tenant-name or customer-email bypass. Other
workspaces require canonical enabled paid or deliberately granted access through
the existing module entitlement system. Revoking access blocks every subscription
entry point, including customer/mobile, public polls, webhook ingestion and CLI
apply operations. Existing records are retained.

The catalog opts subscriptions into confirmed commercial access: active explicit
paid, comped, included, contract or trial grants, or an active add-on produced by
the existing trusted `stripe_fulfillment` path. Self-activation's
`pending_billing`, a manual add-on flag or a module override cannot bypass this
check; ordinary entitlement/add-on validity dates and explicit revocation still
apply. No other module changes its commercial policy.

Subscriptions has approved local catalog metadata of USD 99/month, purchase key
`addon.subscriptions`, product lookup `addon_subscriptions`, recurring lookup
`addon_subscriptions_monthly`. This source change creates no Stripe product,
price, charge, subscription or Shopify billing agreement. Before selling through
the Shopify App Store, validate the distribution and required Shopify billing
path; do not automatically use the platform's Stripe checkout for Shopify app
fees. Follow [Shopify app billing](https://shopify.dev/docs/apps/launch/billing).

The canonical add-on has `purchase_enabled=false` until actual servicing and the
distribution/billing route pass review. Catalogs omit purchase metadata and show
a request CTA; direct activation, single-add-on checkout, combined-plan checkout
and mobile handoff cannot bypass the gate, even if global Stripe billing is on
or a client posts `addon.subscriptions` with an enabled flag. Approved pricing
and existing comped/paid servicing access remain intact. Other add-ons retain
their existing purchase behavior by default.

Gift Cards will use its own canonical module/add-on and explicit Modern Forestry
comped grant when implemented. USD 29/month was recommended but has not been
approved, so no gift-card price, entitlement, provider operation or billing flow
is configured in this phase. Neither add-on implicitly grants the other.

## Verified current setup, 2026-10-15

The release owner performed read-only canonical GraphQL checks against the
existing Modern Forestry retail installation. Its domain was
`modernforestry.myshopify.com`; the installed app was Modern Forestry Backstage,
`gid://shopify/App/324577984513`. The token had read/write **own** subscription
contract scopes, `read_customer_payment_methods` and `write_customers`.
`write_customer_payment_methods`, all-contract access and gift-card scopes were
absent. A 2026-10 `subscriptionContracts(first: 1)` query returned zero nodes and
no next page: this app currently owns **zero** Shopify subscription contracts.
The one local subscription row is not evidence of a provider-owned contract.
An explicit all-app selling-plan query returned six groups, all with app ID
`294517`; the default current-app query returned zero. A separate canonical
`app(id: "gid://shopify/App/294517")` query identified it as **Recharge
Subscriptions**. Its `installation` field returned null; that field alone does
not establish whether historical billing remains active, whether migration has
occurred, or who controls a payment vault. The current Backstage installation is
`gid://shopify/AppInstallation/669328507139`, and the shop is
`gid://shopify/Shop/20812479`, timezone `America/New_York`.

These facts do not establish that Backstage can operate contracts created by
Recharge or another app. Validate a reviewed ownership/payment-vault migration
before any cutover. Keep existing
provider billing, Shopify checkout, sign-in, customer records and orders intact.
The checks contained no customer names, addresses, card data or provider writes.

## Phase 1 — access, ownership foundation and truthful behavior

Deployed through PR #401 at `f8bf74f3` (protected run `38818732814`, Forge
`80346741`); provider execution and purchasing remain hard disabled:

- `SubscriptionAccessService` applies the canonical entitlement to all service
  entry points. Managers must be active members with owner/admin/manager roles.
  A global platform role or a Shopify signed launch/token alone grants no
  management authority. Embedded mutations require a verified Shopify session
  for the store and a separate authenticated Everbranch manager membership.
  Missing identity/membership shows setup copy without customer data. A later
  audited Shopify staff-sub/member mapping may remove the separate sign-in.
- The first authorized local setup mutation freezes the persisted Shopify store
  row ID, tenant, store key, canonical domain, public app client ID and isolated
  Admin API contract version **2026-10** in server-owned module metadata.
  Settings and cutover review cannot overwrite this binding. Changed tenant,
  store or app identity fails closed. This is routing evidence, not proof that an
  imported contract or payment method belongs to this app. Canonical installation
  app GID and per-contract ownership require Phase 2 verified reads.
- Strict action allowlists reject arbitrary nested metadata, card fields,
  incomplete addresses and invalid dates. New intent and migration-draft payloads
  are encrypted in versioned JSON envelopes. Existing historical evidence is not
  rewritten. Address requests describe future contract deliveries; no existing
  order, shipment, label or native Website record is edited.
- Actions return `intent_recorded`: saved for review, never executed. No email is
  queued and no payment is retried. Empty migration drafts need review; a manual
  stop-billing checkbox requests cutover review and cannot approve migration or
  enable a scheduler. Existing mutation-looking strings are not an executor.
- Webhooks still require the existing HMAC/shop verification. Evidence ingestion
  additionally requires the enabled add-on, matching original store/app and a
  bounded provider webhook ID/body. Encrypted `received_unprocessed` events are
  deduplicated under the tenant settings lock by shop/app/webhook identity.
  No projection, customer communication or financial work follows ingestion.
  IDs remain on their event rows; none are evicted from metadata. This existing
  JSON lookup is suitable only for the dark evidence lane: an indexed, unique
  durable inbox is mandatory before financial workers or production-scale sync.
- Verified App Review demo sessions render fictional payloads and never seed
  contracts/polls, cast live votes, create reviews or append processing events.
  Historical demo contracts remain retained and are excluded from operations.
  Poll/vote totals and history require a same-tenant non-demo contract with a
  matching provider GID. Known demo-only polls are suppressed by their explicit
  metadata or exact historical seeder description. Unknown or mixed historical
  options remain retained; no row is reclassified from a guess.
  Customer email alone no longer unlocks a preview. Demo access still needs the
  tenant's explicit entitlement.
- Public voting-code delivery is unavailable in production until a verified
  delivery adapter exists. Local/test codes explicitly say that no email/SMS was
  sent. The CLI's Shopify product-sync option fails closed; local recipe apply
  additionally requires the canonical entitlement. The global legacy Shopify
  API version is unchanged.

Validation: subscription access/role/tenant/store tests; encrypted-intent and
strict payload cases; disabled execution; webhook replay/store/HMAC rejection;
public/mobile paywall; demo non-persistence; existing subscription/mobile
regression. No real card, renewal, notification, gift card or provider mutation
is part of verification.

## Phase 2 — trustworthy provider reads and operations ledger

The first bounded implementation is staged with five additive evidence tables,
explicit query-only CLI setup/probe/resumable sync, a saved-history workspace and
a dedicated raw-HMAC evidence inbox. See
`everbranch-subscriptions-provider-reads.md` for exact setup, retries, freshness,
size limits and default-disabled gates. Production rollout, scheduled inbox
reconciliation, customer-facing history and secure token acquisition/refresh are
still acceptance requirements; no financial worker or provider mutation exists.

Build a subscription-only adapter pinned to 2026-10; do not repurpose global
retail/wholesale slots to onboard unrelated retailers. Add tenant-owned immutable
installation/app GID/public-client/credential-revision identity and validated
scopes from `currentAppInstallation`. Same-shop credential rotation must retain
the original installation; app changes need deliberate migration review.

Add an additive encrypted operation ledger and indexed unique webhook inbox,
tenant/store/app-scoped provider identities, revision/staleness markers and
reconciliation cursors. Recover durable partial migrations in real MySQL and
refresh the approved schema through disposable CI. Preserve all old evidence.
Project contracts, lines, masked payment methods, attempts, cycle timezone and
provider-confirmed order links from owned provider reads. Pending, unknown,
failed, action-required and confirmed states remain distinct. Do not infer
success from HTTP 200, a local row or an accepted command. Ignore older webhook
revisions; replay and retries must converge. Never store raw card data.

Expose a usable admin list/search/filter/detail/history, setup prerequisites and
freshness. Show paused/cancelled history and a permitted resume path; active-only
customer lookup is the current foundation limitation. Add an audited Shopify
staff-sub to active tenant-member mapping or reviewed online identity resolution
before claiming seamless staff access. Any necessary app-store billing adapter
must fulfil/revoke the same canonical add-on entitlement idempotently.

Before enabling public voting-code delivery, add verified channel delivery,
bounded failed attempts and resend limits, atomic token consumption, generic
recipient responses and duplicate-safe vote insertion. No "sent" status may be
derived from token creation alone.

## Phase 3 — confirmed contract servicing

Implement one reviewed operation at a time: pause/resume/cancel, future shipping
address, product/quantity swap, skip/reschedule/billing date, and Shopify secure
payment-update email. Use fresh owned contract/payment-method reads, explicit
preview/confirmation, policy snapshots, contract revision checks, durable request
IDs, per-contract locks and immutable before/after evidence. Validate product,
payment method and address against the bound store. Use 2026-10 calculation and
commit semantics where required, rather than copying old draft mutation names.
Distinguish editing a contract from one billing cycle or an already created order.
Shopify user permissions remain an independent provider constraint.

Tests must cover repeated submissions, stale revisions, wrong shop/app/customer,
provider user errors, timeout-after-acceptance, cancellation/resume eligibility,
address changes after order creation, and intent versus confirmed UI/history.

## Phase 4 — billing retry, cycles and customer recovery

Implement targeted `subscriptionBillingAttemptCreate` with a durable once-only
idempotency key for a selected contract/cycle. Store provider attempt identity
before treating it as retryable; reconcile an unknown result instead of issuing
another charge. Use current async attempt states, SCA/customer action links and
bounded merchant-reviewed retry/dunning rules. Check cycle/risk/stock constraints,
shop timezone, prepaid semantics and amount/currency. A successful renewal order
stays in the Shopify lane and proceeds through existing Shopify fulfillment;
never copy it into native Website orders. Pause the executor independently while
retaining read/recovery evidence. Test all partial/provider outcomes with fakes
and controlled sandbox acceptance before any real card rerun.

## Phase 5 — merchant acceptance and reviewed migration

First prove a disposable owned contract end to end: enrollment, renewal, failed
payment, secure update, retry/SCA, address change, pause/resume/cancel, webhook
replay and fulfillment. Then separately reconcile Recharge/other-provider
ownership, vaulted payment migration eligibility, imported mappings, timezone,
policy snapshots and stop-old-billing proof. A dry run cannot transfer ownership
or payment-vault authority. Require a reviewed activation/rollback checklist and
explicit real-transaction authorization. No duplicate billing or provider
uninstall is permitted as a migration shortcut.

## Gift Cards phase 1 — separate entitlement and verified reads

After subscription operations, add separate `gift_cards` catalog/add-on gating,
an explicit Modern Forestry included grant and approved pricing. Validate app
type/store capabilities and scopes with Shopify; the current token has none of
the gift-card scopes. Read store-owned cards, masked last-four codes, balances,
customer/recipient links and transaction history through an isolated adapter.
Never infer that every Shopify app can access every gift-card feature.

## Gift Cards phase 2 — audited lifecycle and merchant acceptance

Add distinct paid gift-product fulfillment versus unfunded administrative issue,
customer/recipient notification, approved credit/debit, deactivation and
reconciliation. Keep full issue codes encrypted with tightly scoped reveal;
never log or place them in analytics, URLs or broad history. Verify ambiguous
provider results before retrying issuance or sending notifications. Deactivation
cannot be reversed. Test balances, currency, permissions, duplicates, partial
failure, tenant isolation and merchant acceptance before activation.

Shopify gift cards can fund eligible **initial** subscription checkout payments,
not recurring rebills. Existing Shopify redemption stays at Shopify checkout.
Native Website gift-card redemption would need a separately reviewed tenant-owned
ledger/reservation/payment design; it is not an implicit shared balance bridge.

## Provider references

- [Shopify purchase options and scopes](https://shopify.dev/docs/apps/build/purchase-options)
- [Owned subscription contracts](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts)
- [Current contract update workflow](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts/update-a-subscription-contract)
- [2026-10 pause contract](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionContractPause)
- [2026-10 billing attempt and idempotency](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/subscriptionBillingAttemptCreate)
- [Billing cycles and prepaid limitations](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/billing-cycles)
- [Secure payment method update email](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/customerPaymentMethodSendUpdateEmail)
- [Selling plan app identity](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/SellingPlanGroup)
- [Gift-card creation/scopes](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/giftCardCreate)
- [Gift-card deactivation](https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/giftCardDeactivate)
- [Gift-card checkout and subscription restrictions](https://help.shopify.com/en/manual/products/gift-card-products/redeem-gift-card)

## Release and rollback

This phase has no migration, provider executor or external billing setup. Run
focused tests, the existing mobile/Shopify/commercial regression, PHP formatting,
asset build and the protected exact-head CI gate before deployment. Verify the
setup/readiness copy, access denial and demo behavior in a private local fixture.
Do not query or mutate live customers to smoke-test these changes. Rollback uses
the normal retained Forge release; preserve binding and event evidence. Never
activate charging from a settings checkbox, environment toggle, billing webhook,
catalog price or successful deployment alone.
