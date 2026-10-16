# Tenant payment connection setup

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

An explicit `signed up for Connect` provider error is shown as a platform
activation problem rather than a bad email. No raw provider response is displayed.

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
- The owner estimated combined PayPal tenant-customer volume at USD 10,000/year
  for the platform application. Submission/contact consent is a separate approval;
  no platform application has been submitted by this change.

No migrations. Use normal GitHub test/build and Forge readiness release gates.
Leave both rollout flags off until the pilot owner is named and provider setup
is complete. Test genuine sandbox verification, embedded authentication, browser
return and retry before rollout. No live charge, email or SMS is part of this
release. Existing native commerce, subscription and Wren launch blockers remain.

Rollback: disable `TENANT_PAYMENT_SETUP_ENABLED` and `TENANT_PAYPAL_ENABLED`;
retain all account/attempt evidence. The existing Wren Stripe hosted route uses
the corrected retry service independently of the new page flag.
