# Tenant payment connection setup

## Guided setup screen (2026-10-19)

The Payments page shows account creation, submitted business details and Stripe
approval as separate steps, using saved provider evidence. Cards and payouts have
separate statuses and a last-checked time. The secure handoff can be resumed;
identity and bank details remain in Stripe. A bank account awaiting approval does
not prevent starting business setup, but unresolved provider requirements and
the existing checkout gates still prevent collection.

Wren Settings links to this shared page when its exact rollout is enabled, so
owners have one payment setup path. The legacy soccer handoff remains available
when shared setup is disabled. No account-create, launch, fee or retry rules change.

This is connection setup only. Follow the phased delivery plan in
`docs/architecture/stripe-paypal-payments-plan.md`. Never advertise PayPal
checkout or recurring billing as live on the strength of a connection alone.

## Configuration

Default-off `TENANT_PAYMENT_SETUP_ENABLED` plus exact
`TENANT_PAYMENT_SETUP_TENANTS` slug allowlist controls the new page and navigation.
The server additionally requires an active verified owner/admin membership and
canonical access to Soccer, Managed Website or Everbranch Billing. Platform
operator status alone grants no tenant payment access.

Stripe uses existing platform credentials and tenant-payment gates. Set the
verified `STRIPE_ACCOUNT_ID` as well as the key; reservations are scoped to
platform identity and test/live mode, so rotating a key cannot create another
account reservation. Optional
`TENANT_STRIPE_EMBEDDED_ENABLED` enables the official Connect.js component when
`STRIPE_KEY` is present. Only account onboarding is enabled in the AccountSession;
the client secret is returned to its authorized owner with no-store caching.
The hosted fallback remains available. Sensitive identity/bank fields stay with
Stripe. Do not disable Stripe authentication to make setup appear shorter.

PayPal requires `TENANT_PAYPAL_ENABLED`, `PAYPAL_ENVIRONMENT=sandbox|live`,
`PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_PARTNER_MERCHANT_ID` and
`PAYPAL_BN_CODE`. Live additionally requires `PAYPAL_PARTNER_APPROVED=true`.
Store credentials in the server secret environment, never tickets, source,
screenshots or chat. Sandbox/live have separate connection fingerprints.

Only the explicit consent POST creates a referral. A 30-minute session-bound
return token is scoped to tenant and actor. PayPal return merchant IDs and success
flags are ignored. Status comes from the partner's merchant-integrations API
using the server-generated tracking ID, matching partner client ID, permission
scopes, receivable state and confirmed email. Merchant/tracking IDs are encrypted
inside `IntegrationConnection.external_account_secret`. No provider identity or
bank evidence is retained in plaintext metadata. The page's account verification
badge expires after 15 minutes; reconnect/check is an explicit owner action.
No polling or automatic external calls occur when viewing the page.

Disconnect is local; tell the owner they can also revoke Everbranch permissions
in PayPal. A reconnection gets a fresh tracking ID. In-flight status reads cannot
override a disconnect or a replacement setup. Revocation webhook handling and
action-time provider checks are mandatory before checkout is implemented.

## Stripe retry recovery

Wren and the shared page use the same Stripe service. It commits an encrypted,
immutable account-create payload and UUID key before contacting Stripe. A changed
email never changes a pending retry payload. Requests older than 23 hours stop
for operator reconciliation before Stripe can expire its idempotency record.
Never delete the reservation or issue a new key to recover an ambiguous result.
Locate the original account using Stripe evidence/tenant metadata and reconcile
the local record first. A completed local account is reused.

Explicit `signed up for Connect` and `complete your platform profile` provider
errors are shown as platform setup problems rather than bad emails. No raw
provider response is displayed. The latter may require the platform owner to
complete identity verification directly in Stripe.

### Reviewed Wren recovery, 2026-10-19

The owner completed live platform identity verification and explicitly approved
the Connect agreement. The saved setup now matches destination payments,
Stripe-hosted onboarding and Express dashboard access. Stripe disclosed platform
refund/chargeback and negative-balance liability and Radar Standard pricing of
$0 per screened transaction and $1 per connected account.

The original request returned a cached HTTP 400 platform-profile rejection
(`Idempotent-Replayed: true`). An authenticated complete scan proved zero live
connected accounts, with the configured platform identity independently verified.
The operator marked that definitively failed reservation failed, preserved its
encrypted key/payload and creation time, and committed a separate immutable
recovery reservation linked to it before contacting Stripe. That recovery created
one Wren account and linked the local record, with append-only audit evidence.
This is not a retry pattern for an ambiguous result: any provider account,
timeout, unverified identity or incomplete scan stops creation for reconciliation.
The existing service and its 23-hour guard remain unchanged.

The account is onboarding only. Business verification, bank payouts, signed
callbacks and the controlled payment/refund proof remain collection gates.

## Release and proof

### Observed provider setup, 2026-10-16

- The owner completed a separate Evergrove PayPal Business account. Its UI showed
  the Evergrove business name, a linked bank account, and the online-checkout
  activation confirmation. This is merchant-account setup, not platform approval.
- Created PayPal's sandbox Platform Partner App with a separate automatically
  generated fictional business account and sandbox BN code. Credentials are in
  the git-ignored local environment, with owner-only permissions; none were
  installed on production or added to source/chat. Keep production secrets in
  the server secret environment when live approval is complete.
- Genuine sandbox OAuth returned HTTP 200. The implemented referral service then
  returned HTTP 201 from `POST /v2/customer/partner-referrals` for a fictional
  tenant in a disposable local SQLite testing database. The returned signup link
  passed the sandbox PayPal host allowlist. This is link generation evidence;
  completed seller onboarding, revocation, capture/refund, webhooks and recurring
  billing are still unverified/unimplemented as described in the plan.
- Submitted the PayPal platform application on 2026-10-16 after the owner
  explicitly approved submission and contact consent. The form identified
  Evergrove Software LLC, theeverbranch.com, United States, company type Platform,
  tenant-owned seller payments, and Stripe as the existing card processor. The
  owner estimated combined tenant-customer PayPal volume at USD 10,000/year; the
  form offered the range Less than $10 Million. PayPal displayed: "Thank you"
  and "We are reviewing your request. A PayPal representative will contact you
  as soon as possible." Review remains pending; this is not partner approval.

No migrations. Use normal GitHub test/build and Forge readiness release gates.
Leave both rollout flags off until the pilot owner is named and provider setup
is complete. Test genuine sandbox verification, embedded authentication, browser
return and retry before rollout. No live charge, email or SMS is part of this
release. Existing native commerce, subscription and Wren launch blockers remain.

Rollback: disable `TENANT_PAYMENT_SETUP_ENABLED` and `TENANT_PAYPAL_ENABLED`;
retain all account/attempt evidence. The existing Wren Stripe hosted route uses
the corrected retry service independently of the new page flag.

## Production pilot release, 2026-10-16

- PR #410 passed quality, full CI and MySQL migration recovery, then deployed
  through protected run `38904619886`. Public `/ready` confirmed exact release
  `cb133ecfea1ef8afc6b54152a58f357518500df7`.
- Enabled only `TENANT_PAYMENT_SETUP_ENABLED=true` and
  `TENANT_PAYMENT_SETUP_TENANTS=wren-family-soccer`, with an operator audit and
  owner-only environment backup. Embedded onboarding and PayPal stay off. No
  customer-payment, registration, subscription, SMS or other tenant gate changed.
- Selected Platform in Stripe's sandbox and used Stripe's built-in fictional
  account/payment demo. The sandbox payment succeeded for its default $100.
  Its sample $3 platform fee is demo data only; Everbranch's actual platform fee
  remains zero. This is provider-demo evidence, not the soccer checkout or signed
  webhook acceptance test. The fictional account still has verification tasks.
- The live Wren onboarding request was rejected before an account was created:
  Stripe requires the platform profile. The signed-in live dashboard specifically
  requests John Collins's identity document. The owner must complete this directly
  in Stripe. The existing immutable request reservation remains in place; retry
  it after verification, without replacing the key or creating a different account.
- A Wren-owned connected account, verified callbacks and the controlled
  payment/refund smoke test remain necessary before collection. Registration stays
  behind its existing readiness checks.

## Shop Pay diagnostic preparation

See `docs/operations/shop-pay-wallet-readiness.md`. The operator command verifies
explicit merchant-owned credentials and can create an unpaid fictional session.
It does not connect a tenant, submit a payment or expose a buyer checkout option.
