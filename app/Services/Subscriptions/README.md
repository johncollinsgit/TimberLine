# Subscriptions and the shared customer

Every current and future Subscriptions surface resolves the same tenant-owned
canonical customer as Customers, Pay, retail and Teams. This included customer
capability applies to every retail business. Reuse the shared customer/commerce
read contracts, explicit source associations, Candle Cash/rewards ledger and
birthday profile/annual reward cycle. Opening or refreshing a subscription cannot
create another customer master, balance or birthday award. See the
[universal customer standard](../../../docs/architecture/unified-customer-commerce.md).

Modern Forestry currently bills subscriptions through Recharge. Completed
Recharge subscription orders already arrive in Shopify and enter customer
purchase history through the Shopify adapter. Keep the original Shopify order
reference and count each purchase once; a Recharge charge or a second app's
representation must not become another sale.

Everbranch Subscriptions also needs the original Recharge customer and
subscription records: provider/store/customer/subscription IDs, recorded status,
products/variants and quantities, cadence, original membership start/cancellation
dates, next-charge dates, currency, discounts, shipping/tax terms and queued or
prepaid obligations. Preserve provider-bound billing/payment references and the
legacy versus Shopify billing distinction. Order history alone does not prove
active membership, the next renewal, vault ownership or a new membership join.

Associate these records with the canonical customer only through proven original
provider references and reviewed source mappings. Every read is scoped to the
business and current actor. Expose captured coverage, unresolved identities and
refresh timestamps; an uncaptured subscription source is unknown.

The current Recharge preparation lane supports explicit bounded, audited source
capture. It has no automatic background capture or billing cutover. Ongoing pulls
must retain provider/store identity checks, durable checkpoints, current access,
replay safety and visible freshness. Follow
[Recharge preparation](../../../docs/operations/everbranch-recharge-rollout-preparation.md)
before extending it. Source installation and customer-profile linkage do not
activate collection, migrate a payment vault or change existing Recharge billing.
