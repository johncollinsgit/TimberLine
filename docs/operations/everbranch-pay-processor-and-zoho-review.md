# Everbranch Pay: processor costs and Zoho comparison

Research date: November 1, 2026. Prices and capabilities are US-specific and may change. This is an operational comparison, not a contract quote. Verify each merchant's actual contract and eligibility before making a decision.

## Payment costs

Modern Forestry's reviewed Square account evidence from October 31, 2026 records 2.6% plus $0.15 for tap, dip or swipe, with an additional 1.5% for international cards. Square is the existing connected in-person processor. Confirm the current account pricing in the merchant's [Square processing-fees dashboard](https://app.squareup.com/dashboard/account/pricing/processing-fees) before relying on it; the account's actual schedule takes precedence over public pricing.

| Payment | Card-present rate | $20 example | $50 example | Important limits |
| --- | --- | ---: | ---: | --- |
| Modern Forestry Square | 2.6% + $0.15, as reviewed | $0.67 | $1.45 | Existing account and Square SDK; international and other categories can differ. |
| Shopify Payments Basic | 2.6% + $0.10 published | $0.62 | $1.40 | Requires the merchant's Shopify plan and supported Shopify POS/payment flow; other plans have different rates. |
| Stripe Terminal Tap to Pay | 2.7% + $0.05 domestic card, plus $0.10 per Tap to Pay authorization | $0.69 | $1.50 | The extra authorization fee can apply even when authorization is unsuccessful; international and optional encryption fees differ. |
| PayPal Braintree | In-person quote required | Not comparable | Not comparable | Published standard card rate is 2.89% + $0.29, but the documented in-person integration uses Verifone and does not establish a comparable iPhone Tap to Pay rate. |

Examples assume one successful domestic card-present authorization, no refunds, and ordinary cent rounding. They exclude monthly subscription, device, software and one-time integration costs; retain an existing Shopify subscription as a sunk cost only if it would be kept regardless. Shop Pay is an accelerated checkout method within Shopify Payments, not a separately quoted card-present processor in this comparison. A published Shopify POS rate does not establish that Shopify Payments can be embedded in Everbranch's current native Square SDK checkout.

Sources: [Square fees](https://squareup.com/us/en/payments/our-fees), [Shopify POS pricing](https://www.shopify.com/pos/pricing), [Stripe Terminal pricing](https://stripe.com/pricing), [Braintree pricing](https://www.paypal.com/us/enterprise/paypal-braintree-fees), [Braintree in-person architecture](https://developer.paypal.com/braintree/in-person).

## Zoho capabilities and Everbranch evidence

This is a feature comparison against source evidence, not a claim that all source features are live or enabled for Modern Forestry. Zoho's [Commerce features](https://www.zoho.com/commerce/features/) and [plan comparison](https://www.zoho.com/commerce/plan-comparison/) list capabilities whose availability depends on plan.

| Capability | Zoho evidence | Everbranch evidence and open question |
| --- | --- | --- |
| Customer accounts, history and loyalty | Customer portal, order history and loyalty points | Existing customer commerce snapshots and Candle Cash services; the POS cross-channel customer and redemption work remains gated and needs physical acceptance. |
| Products, bundles and inventory | Catalog, kits, multiple inventory locations and stock reports | Finished-goods stock ledger and bundle mappings exist; reviewed opening balances and Shopify publication are not yet activated for the pilot. |
| Discounts and price lists | Coupons, automatic discounts and customer/item price lists | Tenant POS discount and add-on rules are in development; online rule parity and general price lists need an explicit capability audit. |
| Returns and financial reports | Returns, refunds and order/payment reports | Square refund observation and customer purchase history exist; amount-only partial-refund reward allocation requires review. |
| Abandoned carts and recommendations | Cart activity, recovery and product recommendations | Verify enabled lifecycle/marketing adapters and data coverage per tenant; source presence alone is not proof of a comparable live feature. |
| Lot/serial traceability and purchasing | Higher plans include serial/batch tracking and purchasing | Materials and production batch models exist, but end-to-end finished-goods lot/serial traceability and supplier purchasing parity are unverified. |
| Workflow automation and roles | Workflow automation, user roles and reporting | Everbranch has tenant roles and marketing workflows; verify exact admin coverage, channel support and rollout before claiming parity. |

Prioritize a tenant-facing gap audit and demos around fulfillment/returns, stock counts and reconciliation, customer history, rule configuration, and payment recovery. Validate per tenant and channel instead of treating a repository symbol as a live capability.
