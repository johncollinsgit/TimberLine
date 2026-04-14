# Staging Commercial UAT Evidence Template

Use one copy of this template per tenant permutation run.

## Run Metadata
- Operator: `modernforestryteam@gmail.com`
- Date (YYYY-MM-DD): `2026-03-29`
- Environment (canonical): `https://app.grovebud.com`
- Legacy compatibility environment (transition-only): `https://app.forestrybackstage.com`
- Tenant slug: `modern-forestry`
- Tenant name: `Modern Forestry`
- Evidence artifact directory:
  - `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/`
- Run summary JSON:
  - `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/run-summary.json`

## Blocked Run (Complete Only If Step 1 Could Not Be Executed)
- Blocked before guarded sequence started? (yes/no): `no`

## Assignment Inputs Applied
- Assigned plan key: `starter` (existing row state)
- Assigned template key: `none` (existing row state)
- Display labels source: `entitlements default` (existing row state)
- Module overrides changed in this run: `none`
- Add-on states changed in this run: `none`

## Screenshot Checklist
- [x] `01-login-page.png`
- [x] `02-landlord-commercial-overview.png`
- [x] `03-landlord-tenant-row-state-before-guarded-actions.png`
- [x] `04-landlord-guarded-customer-sync-state.png`
- [x] `05-landlord-guarded-subscription-prep-state.png`
- [x] `06-landlord-guarded-live-subscription-state.png`
- [x] `07-landlord-commercial-final-state.png`
- [ ] `shopify-start-context.png` (out of scope for this guarded-only slice)
- [ ] `shopify-plans-context.png` (out of scope for this guarded-only slice)
- [ ] `shopify-integrations-context.png` (out of scope for this guarded-only slice)
- [ ] `shopify-integrations-drawer.png` (out of scope for this guarded-only slice)

## Guarded Stripe Sequence Detail

| Step | Timestamp (UTC) | Prerequisites Checked | Before State | Action Result | After State | Stripe/Billing Mapping Evidence | Status (PASS/FAIL) | Notes |
|---|---|---|---|---|---|---|---|---|
| 1. Customer sync | `2026-03-29T16:23:11Z` | landlord route reachable; tenant row exists; customer button enabled; Stripe auth + lookup keys + guarded flags validated | `stripe.customer_reference` already present (`cus_UEpZQoP8cJadrs`) and customer-sync action available | UI flash: `Stripe customer sync succeeded: cus_UEpZQoP8cJadrs` | tenant row shows `last status: succeeded`, `mode: update`, synced timestamp | `billing_mapping.stripe.customer_reference=cus_UEpZQoP8cJadrs`; metadata `billing_guarded_actions.stripe_customer_sync.status=succeeded` | PASS | Step 2 remained enabled after step 1 as expected |
| 2. Subscription prep sync | `2026-03-29T16:23:12Z` | step 1 state satisfied; prep button enabled; lookup-key mapping available | prep candidate/hash already present from prior prep state | UI flash: `Stripe subscription prep synced: eaaddd980cf88b07e7f52f3ce7db5856a7394ff9eb08c602ee87afeb4b6ad563` | tenant row shows `succeeded`, `mode: noop`, same candidate hash | `billing_mapping.stripe.subscription_prep_hash=eaaddd980cf88b07e7f52f3ce7db5856a7394ff9eb08c602ee87afeb4b6ad563`; candidate payload present | PASS | Step 3 became enabled after step 2 as expected |
| 3. Live subscription create/sync | `2026-03-29T16:23:14Z` | step 1 + step 2 satisfied; live button enabled | `stripe.subscription_reference` empty (`none`) | action executed, result failed | tenant row shows `last status: failed`, `mode: create`, subscription reference remains `none` | metadata `billing_guarded_actions.stripe_live_subscription_sync.message=Missing email. In order to create invoices that are sent to the customer, the customer must have a valid email.`; `stripe.subscription_reference` remains null | FAIL | Blocking failure for full guarded PASS evidence |

## Per-Step Outcome Summary
- Step 1 (`customer sync`): `PASS`
- Step 2 (`subscription-prep sync`): `PASS`
- Step 3 (`live subscription create/sync`): `FAIL`
- Overall guarded 3-step run: `FAIL` (partial pass only)

## Pass/Fail Matrix Log (This Slice)

| Matrix Row | Status | Evidence File(s) | Notes |
|---|---|---|---|
| Landlord commercial host lock and access | PASS | `01-login-page.png`, `02-landlord-commercial-overview.png` | route reachable with real landlord operator session |
| Billing lifecycle guard (disabled) | PASS | `03-landlord-tenant-row-state-before-guarded-actions.png`, `07-landlord-commercial-final-state.png` | checkout/self-serve/lifecycle expansion still disabled |
| Guarded Stripe customer sync action | PASS | `04-landlord-guarded-customer-sync-state.png`, `run-summary.json` | deterministic update mode; customer reference preserved |
| Guarded Stripe subscription prep action | PASS | `05-landlord-guarded-subscription-prep-state.png`, `run-summary.json` | deterministic noop sync; candidate hash remains valid |
| Guarded live subscription create/sync action | FAIL (Blocking) | `06-landlord-guarded-live-subscription-state.png`, `run-summary.json` | failed create due missing Stripe customer email for invoice flow |

## Blocking Failure Attachments
1. `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/06-landlord-guarded-live-subscription-state.png`
2. `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/run-summary.json`
3. Expected vs actual:
   - Expected: guarded live subscription create/sync succeeds and sets `billing_mapping.stripe.subscription_reference`.
   - Actual: live create failed; `subscription_reference` stayed empty because Stripe returned `Missing email...` for invoice creation.

## Deferred (Non-blocking) Notes

| Item | Evidence File | Why Non-blocking |
|---|---|---|
| Full permutations and embedded surface propagation matrix not rerun in this slice | `run-summary.json` | This approved slice executed only the guarded 3-step landlord Stripe evidence flow |
