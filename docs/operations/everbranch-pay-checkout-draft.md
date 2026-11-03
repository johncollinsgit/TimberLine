# Everbranch Pay checkout work in progress

This checkout work is not approved for live use. The checkout-rule and receipt-email feature flags default to off. Publishing a register's reviewed rule version does not enable the checkout feature flag.

The in-person rule editor supports percentage and fixed discounts, item or sale scope, ordered stacking, and product add-on prices. Amounts, rule versions, eligible products, discounts and tax are resolved on the server. Rule edits invalidate prior register reviews. Online checkout does not consume these rules yet. Modern Forestry's proposed show rules are a separate, locally reviewed configuration; they have not been published.

Cashiers can explicitly select a customer and view linked purchase history and rewards. Saving an explicitly entered receipt email can queue an email only when receipt email is enabled. Email status and retries do not change the payment result. No card-derived identity is used to link customers.

Separate bundle configurations retain distinct basket-line identities, while reservations aggregate their shared component stock. Editing an unstarted quote requires a confirmed discard before a fresh quote; started or uncertain payments cannot be replaced. A quote reopened after an app restart currently displays its frozen lines but cannot be rebuilt into an editable basket.

## Release blockers

- Configure and review each tenant's rules and verify taxes, refunds and receipt totals with the actual merchant and provider. Complete physical-device checkout and protected release checks before enabling either feature flag.
- Amount-only partial refunds cannot identify returned merchandise or its tax. Reward adjustments go to review until an authorized item-allocation workflow exists. Full refunds can reverse the purchase earning; spent-credit shortfalls remain in review.
- In-person Candle Cash reservation is an unwired foundation with no production authorization binding, payment finalization, release or refund lifecycle. Customer-present verification and a shared-ledger lifecycle must be completed and tested, including concurrent online use, before enabling it. Modern Forestry's approved in-person cap is $5 per sale.
- Birthday evidence is read-only and cannot redeem. An existing Shopify-issued birthday code requires cross-channel coordination to prevent duplicate use; no POS entitlement or redemption lifecycle is enabled.
- The customer app's QR credential and scanner, online rule adapter, scent-level purchase preferences, and self-service reward and checkout policy workflows remain incomplete. Do not infer customer identity from card details.
- Refresh and review the MySQL schema baseline through the repository's documented GitHub Actions workflow after both new migrations are finalized. Run migration recovery and the complete protected checks before deployment.
