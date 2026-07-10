# Modern Forestry Candle Club Readiness

Date: 2026-07-09

## Scope

This pass reviewed the Candle Club chain across Shopify, Everbranch/Backstage, and the Modern Forestry iOS app. The goal was to verify whether the current system is ready to replace Recharge for customer subscription management and recurring order creation.

## Current Status

Not ready to replace Recharge yet.

The customer-facing and admin-facing surfaces exist, and Candle Club voting is real inside Everbranch. The Shopify subscription lifecycle is not live yet: scent swaps, pauses, cancellations, address updates, and payment-card updates are recorded as lifecycle intents or preview events, not executed against Shopify subscription contracts.

## What Works Today

- Active Candle Club eligibility is based on a mirrored active Shopify subscription contract in `subscription_contracts` with `is_candle_club = true`.
- Authenticated iOS voting records one vote per active Shopify subscription contract.
- Public storefront/share voting supports one-time-code verification by email or phone and records votes against the active contract mirror.
- The iOS account screen exposes Candle Club menus for voting history, previous scents, scent swap, pause, address update, payment card update, and cancellation.
- John preview accounts can exercise all iOS Candle Club menus without mutating a live Shopify contract.
- Backstage can show mirrored subscription metrics, active Candle Club members, monthly scents, migration batches, and failed billing attempts.
- Recharge migration dry-runs validate imported rows locally and cutover approval requires explicit confirmation that Recharge billing is paused.
- Shopify subscription, billing-attempt, and customer-payment-method webhook routes exist and record received webhook events.

## What Is Intent-Only

- iOS scent swap records `subscription_lifecycle_events.status = intent_recorded`.
- iOS pause records `intent_recorded`.
- iOS cancel records `intent_recorded`.
- iOS shipping-address update records `intent_recorded`.
- iOS payment-card update records the intent to call `customerPaymentMethodSendUpdateEmail`; it does not call Shopify yet.
- Backstage admin subscription actions record intents when called through the API, but the page does not yet provide full mutation-ready forms for swap/address/cancel inputs.
- Webhook receivers store Shopify events but do not yet upsert local subscription contract/payment/billing state from the payloads.

## Shopify Requirements Confirmed

- Shopify creates the initial subscription contract when a customer purchases a product with a selling plan through checkout.
- Storefront Cart API subscription checkout requires `CartLineInput.sellingPlanId` on the subscription line.
- Subsequent recurring orders are created by the subscription app via Shopify subscription billing attempts.
- A successful billing attempt creates an order; failures must be handled through recovery/dunning.
- Payment method updates should use Shopify's secure payment update email flow.

Primary Shopify references:
- https://shopify.dev/docs/apps/build/purchase-options/subscriptions/selling-plans
- https://shopify.dev/docs/apps/build/purchase-options/subscriptions/model-subscriptions-solution
- https://shopify.dev/docs/api/storefront/latest/input-objects/CartLineInput
- https://shopify.dev/docs/api/admin-graphql/latest/mutations/subscriptionBillingAttemptCreate
- https://shopify.dev/docs/api/admin-graphql/latest/mutations/customerPaymentMethodSendUpdateEmail

## Key Gaps

1. In-app checkout does not pass `sellingPlanId`.
   - The mobile checkout service builds cart lines with merchandise, quantity, and attributes only.
   - The app does link to the Shopify product URL with `?selling_plan=11300438275`, so web product checkout may create the initial contract, but in-app bag checkout is not subscription-aware yet.

2. No recurring-order runner is implemented.
   - There is a `subscription_billing_attempts` table and webhook routes, but no scheduler/service was found that calls `subscriptionBillingAttemptCreate` or bulk billing-cycle charge mutations.
   - `approveCutover()` deliberately leaves `billing_scheduler_enabled = false`.

3. Customer management actions do not mutate Shopify yet.
   - The code stores the intended Shopify mutation name in event metadata, but does not call `subscriptionContractUpdate`, `subscriptionDraftLineUpdate`, `subscriptionDraftCommit`, or `customerPaymentMethodSendUpdateEmail`.

4. Shopify webhook handling is not a full mirror sync.
   - Current handlers record received events as lifecycle events.
   - They do not yet upsert `subscription_contracts`, `subscription_contract_lines`, `subscription_payment_methods`, or `subscription_billing_attempts`.

5. Backstage is an operations shell, not a Recharge-parity management app.
   - It displays data and supports backend intent recording.
   - It still needs mutation-ready admin forms, action confirmations, audit status, and Shopify result/error handling.

## Replacement Plan

P0: Make initial purchase create a real Shopify contract.
- Add selling-plan support to the mobile product payload, bag model, and checkout service.
- Pass `sellingPlanId` in Storefront `CartLineInput` for Candle Club lines.
- Verify a test purchase creates a Shopify subscription contract and the contract webhook arrives.

P0: Build the Shopify mirror.
- Implement contract create/update webhook upserts.
- Implement customer payment method webhook upserts.
- Implement billing attempt success/failure webhook upserts and order linkage.

P0: Implement safe customer mutations.
- Payment card: call `customerPaymentMethodSendUpdateEmail`.
- Pause/cancel/address: create subscription draft, update the draft, and commit.
- Swap scent: create subscription draft, update the subscription line variant/selling plan, and commit.
- Store before/after payloads and Shopify user errors on the lifecycle event.

P0: Implement recurring order creation.
- Add a guarded scheduler for due contracts.
- Use idempotency keys per tenant, contract, and billing date.
- Call `subscriptionBillingAttemptCreate` only after a dry-run/sandbox confirmation path exists.
- Do not enable `billing_scheduler_enabled` until Recharge is paused and a live test contract has passed.

P1: Make Backstage Recharge-parity.
- Add real forms for pause duration, swap target, shipping address, payment email, and cancellation reason.
- Show each action as pending, succeeded, failed, or manual review.
- Add filters for due soon, failed payments, paused, cancelled, no payment method, and missing contract mirror.

P1: Harden iOS UX.
- Replace "request recorded" copy with action-specific confirmation states.
- Show whether an action is pending Shopify processing or completed.
- Hide preview-only fallbacks outside explicit preview/test accounts.

## Safe Test Boundary

Do not trigger a live subscription purchase, billing attempt, or recurring order without explicit action-time confirmation. Those actions can charge a customer or create an order in Shopify.
