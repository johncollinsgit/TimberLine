# Shop Pay Wallet readiness

The owner requested Shop Pay for soccer and tenant storefronts. This is active
integration preparation, not an available buyer payment method. Do not treat a
Wallet session as a payment, order, tenant connection, payout or registration.

## Verified provider evidence, 2026-10-16

- The existing Evergrove Wallet store is `evergrove-11.myshopify.com`. Its Shop
  channel exposes its own Shop ID, Wallet client ID, and generated Admin and
  Storefront API tokens. The home page showed payments activated. Credentials
  were preserved in an owner-only, git-ignored local file, not production/source.
- Admin API identity verification returned HTTP 200 and matched the exact Shop
  ID and domain. The generated app is Shop Component. Its supplied scopes do not
  include general Shopify Payments account access; requesting that banking field
  is not a suitable connection prerequisite.
- A generic Storefront `shop { name }` query returned HTTP 403, Channel not found.
  The same credential successfully called the documented Wallet session-create
  operation: HTTP 200, no GraphQL/user errors, matching source identifier, and an
  HTTPS `shop.app` checkout URL for the expected Shop ID. A second check through
  the implemented preflight command also passed. No payment method, buyer data
  or submit mutation was sent. No real or test money was charged by these probes.
- This verifies the current Evergrove merchant's API access only. No Wren or
  other tenant merchant binding has been approved or installed. Never use the
  landlord's account for unrelated tenants' customer sales. Modern Forestry's
  existing Shopify store, app, tokens, orders and checkout remain excluded.

## Operator diagnostic

`payments:shop-pay-preflight --credentials=/private/path/wallet.json` checks the
merchant identity through Admin GraphQL. `--probe-session` additionally creates
a fictional $1 unpaid Wallet session without personal data. This command never
submits/captures payments, connects a tenant, changes credentials or enables any
checkout gate. Its output excludes bearer session tokens/URLs and API secrets.

The private JSON file requires `shop_domain`, `shop_id`, `admin_access_token`, and
`storefront_access_token`. Use `chmod 600`; symlinks and files readable by other
users are rejected. No global Shopify credential fallback exists. API version
2026-10 is pinned; requests use only the validated merchant myshopify.com host,
bounded timeouts and no redirects. Errors suppress provider request details.

## Work remaining before a buyer can use Shop Pay

1. Confirm the legal merchant and payout owner for each named tenant. Each
   merchant needs its own dedicated Wallet store and completed Shopify Payments
   setup. Confirm actual plan terms before signup; do not assume every merchant
   qualifies for the same offer. Save credentials only through a secure tenant
   settings form with server-side membership, unique shop binding, encrypted
   immutable revisions and audited replacement/revocation.
2. Add the exact SDK origins to that merchant's Shop channel allow list and
   configure its branding. Keep Shopify Payments test mode and SDK debug mode
   for payment testing. Do not attach Wren's origin to Evergrove's Wallet merely
   because the landlord owns the store.
3. Implement the official button/recognition components plus authenticated,
   server-priced session/submit endpoints for soccer and native carts. Freeze
   merchant, amount, currency, order/source and idempotency evidence. Recheck
   soccer offers/capacity or native inventory/shipping before submission. Resolve
   uncertain attempts before allowing another processor; browser success cannot
   mark a payment paid. Waitlisted children must never be charged.
4. Provide an approved server tax calculation/transaction/reversal path for
   native retail. Existing Stripe Checkout tax does not calculate an unrelated
   Wallet request. Missing tax decisions cannot become zero-tax assumptions.
5. Configure signed merchant-owned order/transaction/dispute webhooks and bounded
   provider reconciliation. Confirm capture mode, delayed/duplicate notifications,
   refund/void behavior, fulfillment tracking and original-account recovery.
   The generated Wallet Admin token alone does not provide a merchant app's
   webhook signing secret. Additional app installation/permissions need review.
6. Run real merchant test-mode acceptance, then enable named tenants through the
   protected GitHub/Forge process. Existing registration, commerce, tax, shipping
   and receipt gates remain authoritative. No ACH is added.

## Sources

- https://shopify.dev/docs/api/commerce-components/pay
- https://shopify.dev/docs/api/commerce-components/pay/shop-configuration
- https://shopify.dev/docs/api/commerce-components/pay/graphql-pre-payment
- https://shopify.dev/docs/api/commerce-components/pay/monitoring

The older uncommitted Website-only prototype remains in the primary developer
checkout. It has not been promoted or deployed. Its generic Storefront/banking
checks do not establish Wallet capability, and its customer checkout, tax and
reconciliation work remains unfinished. Preserve that checkout's unrelated edits
when integrating the remaining work in an isolated branch.
