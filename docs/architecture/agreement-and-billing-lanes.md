# Everbranch Agreement and Billing Lanes

Date: 2026-07-16

## Decision

Everbranch pricing is agreement-specific and may be assembled à la carte. An agreement stores the exact immutable scope, price cards, authorized line items, billing lane, electronic acceptance, termination/export terms, and later provider receipt evidence. It is not a universal plan definition and does not itself create a charge.

Front Yard Foods is an implementation client, not the basis for an App Store pricing architecture. Its accepted direct-client lane is `stripe_direct`; Relay is only the Stripe payout bank. Shopify App Store billing remains separate and disabled. The active work remains the proposal, implementation phases, Shopify/Square/inventory/classes/bookings scope, separated costs, acceptance, payment, and handoff.

## Billing lanes

| Customer | Provider lane | Checkout/invoice owner | Tax source of truth | Everbranch responsibility |
| --- | --- | --- | --- | --- |
| Shopify App Store merchant | `shopify_app_pricing` / `shopify` | Shopify | Shopify merchant invoice | Verify Partner API subscription state, mirror receipts/tax, gate access |
| Direct or non-Shopify customer | `stripe_direct` / `stripe` | Hosted gateway on `theeverbranch.com` | Stripe Tax/invoice when configured | Use hosted Checkout/portal, verify webhook state, mirror receipts/tax, gate access |
| Manual implementation work | separately approved one-time service line | Approved provider/manual accounting process | Issuing provider/accounting record | Record the agreed line and external receipt reference; do not silently convert it to recurring access |

A subscription has exactly one active provider lane. A Shopify-distributed merchant cannot choose Stripe to bypass Shopify app billing. A direct customer who later installs the Shopify-distributed app must be deliberately migrated without overlap or double billing.

## À-la-carte pricing

`subscription_authorizations.authorized_line_items` preserves the negotiated recurring, one-time, promotional-cycle, and future-price components. `tenant_billing_orders` snapshots only the accepted collectible lines and attaches Stripe identifiers after the customer begins payment. Shopify and third-party lines remain informational and can never be submitted to Stripe.

## State transition

1. Operator prepares a tenant-scoped draft and immutable version.
2. Operator sends a rotating, password-protected Evergrove proposal link.
3. Authorized signer accepts every required confirmation and types the matching legal name.
4. Everbranch atomically stores the exact content hash, acceptance evidence, permanent HTML snapshot, event, audit record, and `authorized_pending_provider` subscription authorization.
5. If separately approved later, a provider workflow creates/selects the approved price and obtains customer approval.
6. Everbranch verifies active subscription state from the provider and records provider-confirmed receipt/tax values.
7. Entitlement fulfillment is audited and replay-safe. Only then can the activation guard allow paid access.

Acceptance, provider approval, payment, and entitlement fulfillment are separate states. Failure in one cannot imply success in another.

## Front Yard Foods termination

Front Yard Foods retains Shopify, Square, its domain, branding, content, photographs, products, and client-owned data. On the effective termination date, tenant-specific Everbranch access, integrations, synchronization, APIs, workflows, reminders, and modules stop. The shared Everbranch App Store application remains listed. The operational export request/completion and 30-day window are tracked without hard-deleting agreement, billing, audit, security, or legal evidence.
