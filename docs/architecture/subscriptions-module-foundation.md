# Subscriptions Module Foundation

## Scope

The `subscriptions` module is a Shopify-first recurring subscription workspace for Evergrove tenants. It is cataloged as a paid add-on, entitled first for Modern Forestry, and exposed in the embedded Shopify app at `/shopify/app/subscriptions`.

Shopify remains the source of truth for checkout, selling plans, subscription contracts, customer payment methods, billing attempts, and resulting orders. Evergrove stores an operational mirror for admin workflows, migration audit trails, Candle Club rules, and verified poll voting.

## Surfaces

- Embedded admin: `/shopify/app/subscriptions`
- Embedded admin API: `/shopify/app/api/subscriptions/*`
- Storefront app proxy: `/shopify/marketing/v1/candle-club/*`
- Public Facebook/share voting: `/candle-club/vote/{poll}/{token}`
- iPhone account payload: `/api/mobile/v1/modern-forestry/account` and `/api/mobile/v1/modern-forestry/account/candle-club`

## Data Rules

- Active Candle Club eligibility is based on an active Shopify subscription contract mirror with `is_candle_club = true`.
- Votes are unique by tenant, poll, and Shopify subscription contract GID.
- Recharge migration runs are dry-run first and cutover approval requires explicit Recharge billing pause confirmation.
- Admin actions are recorded as lifecycle intents until live Shopify mutations are enabled after cutover.
- Customer-facing Candle Club management actions are also intent-only until Shopify draft/commit, payment update email, and billing-attempt services are implemented.
- Current readiness and replacement-plan details are tracked in `docs/operations/modern-forestry-candle-club-readiness.md`.

## Secrets

Recharge credentials must stay in environment variables such as `RECHARGE_API_TOKEN`. Do not commit Recharge or Shopify API tokens.
