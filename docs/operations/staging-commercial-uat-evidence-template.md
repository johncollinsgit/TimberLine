# Staging Commercial UAT Evidence Template

Use one copy of this template per tenant permutation run.

## Run Metadata
- Operator:
- Date (YYYY-MM-DD):
- Environment:
- Tenant slug:
- Shopify store context:

## Blocked Run (Complete Only If Step 1 Could Not Be Executed)
- Blocked before guarded sequence started? (yes/no):
- Blocking reason category:
  - host/runtime reachability
  - landlord auth/operator access
  - Stripe credentials/config
  - mapping/lookup-key readiness
  - other
- Blocking reason detail:
- Blocked-at step:
- Ticket/owner for unblock:
- Target retry date:
- Evidence for blocker (headers/screenshots/log refs):

## Assignment Inputs Applied
- Assigned plan key:
- Assigned template key:
- Display labels JSON:
- Module overrides changed:
- Add-on states changed:

## Screenshot Checklist
- [ ] `landlord-commercial-overview.png`
- [ ] `landlord-tenant-row-state.png`
- [ ] `shopify-start-context.png`
- [ ] `shopify-plans-context.png`
- [ ] `shopify-integrations-context.png`
- [ ] `shopify-integrations-drawer.png`
- [ ] `landlord-guarded-customer-sync-state.png`
- [ ] `landlord-guarded-subscription-prep-state.png`
- [ ] `landlord-guarded-live-subscription-state.png`

## Guarded Stripe Sequence Detail

| Step | Prerequisites Checked | Before State | Action Result | After State | Stripe-Side Evidence | Status (PASS/FAIL) | Notes |
|---|---|---|---|---|---|---|---|
| 1. Customer sync |  |  |  |  |  |  |  |
| 2. Subscription prep |  |  |  |  | n/a (metadata step) |  |  |
| 3. Live subscription create/sync |  |  |  |  |  |  |  |

## Pass/Fail Matrix Log

| Matrix Row | Status (PASS/FAIL) | Evidence File(s) | Notes |
|---|---|---|---|
| Landlord commercial host lock and access |  |  |  |
| Plan assignment propagation |  |  |  |
| Template assignment propagation |  |  |  |
| Template fallback (`Template · none`) |  |  |  |
| Label override behavior (`tenant override`) |  |  |  |
| Label fallback (no override) |  |  |  |
| Label fallback (malformed override) |  |  |  |
| Module override propagation |  |  |  |
| Add-on visibility propagation |  |  |  |
| Included usage display |  |  |  |
| Integrations context propagation |  |  |  |
| Integrations read-only guard |  |  |  |
| Billing lifecycle guard (disabled) |  |  |  |
| Guarded Stripe customer sync action |  |  |  |
| Guarded Stripe subscription prep action |  |  |  |
| Guarded live subscription create/sync action |  |  |  |

## Blocking Failure Attachments

If any row above is `FAIL` and blocking, include:
1. Landlord assignment screenshot before/after save.
2. Failing surface screenshot (`/shopify/app/start`, `/shopify/app/plans`, or `/shopify/app/integrations`).
3. Exact expected vs actual mismatch sentence.
4. URL and timestamp for each screenshot.

## Deferred (Non-blocking) Notes

List cosmetic/non-correctness items only.

| Item | Evidence File | Why Non-blocking |
|---|---|---|
|  |  |  |
