# Staging Commercial UAT Evidence Template

Use one copy of this template per tenant permutation run.

## Run Metadata
- Operator: `modernforestryteam@gmail.com`
- Date (YYYY-MM-DD): `2026-03-28`
- Environment: `https://app.forestrybackstage.com`
- Tenant slug: `n/a` (no tenant rows available in landlord commercial UI)
- Shopify store context: `n/a` (guarded sequence blocked before tenant selection)

## Blocked Run (Complete Only If Step 1 Could Not Be Executed)
- Blocked before guarded sequence started? (yes/no): `yes`
- Blocking reason category:
  - host/runtime reachability
  - landlord auth/operator access
  - Stripe credentials/config
  - mapping/lookup-key readiness
  - other
- Blocking reason detail:
  - Real landlord operator login succeeded and `/landlord/commercial` loaded, but the page rendered no tenant rows.
  - `Tenant::count()` runtime probe returned `0`, so there is no tenant target for guarded customer/prep/live actions.
- Blocked-at step: `Step 1 pre-action tenant selection`
- Ticket/owner for unblock: `Ops/landlord-commercial runtime owner`
- Target retry date: `after at least one staging tenant exists`
- Evidence for blocker (headers/screenshots/log refs):
  - `docs/operations/evidence/2026-03-28/guarded-stripe-run-2026-03-28T23-01-20.111Z/01-login-page.png`
  - `docs/operations/evidence/2026-03-28/guarded-stripe-run-2026-03-28T23-01-20.111Z/02-landlord-commercial-overview.png`
  - `docs/operations/evidence/2026-03-28/guarded-stripe-run-2026-03-28T23-01-20.111Z/run-summary.json`
  - Runtime DB check output: `tenant_count=0`

## Assignment Inputs Applied
- Assigned plan key: `n/a` (no tenant row)
- Assigned template key: `n/a` (no tenant row)
- Display labels JSON: `n/a`
- Module overrides changed: `none`
- Add-on states changed: `none`

## Screenshot Checklist
- [x] `landlord-commercial-overview.png` (`02-landlord-commercial-overview.png`)
- [ ] `landlord-tenant-row-state.png` (blocked: no tenant row exists)
- [ ] `shopify-start-context.png`
- [ ] `shopify-plans-context.png`
- [ ] `shopify-integrations-context.png`
- [ ] `shopify-integrations-drawer.png`
- [ ] `landlord-guarded-customer-sync-state.png` (blocked before step 1)
- [ ] `landlord-guarded-subscription-prep-state.png` (blocked before step 2)
- [ ] `landlord-guarded-live-subscription-state.png` (blocked before step 3)

## Guarded Stripe Sequence Detail

| Step | Prerequisites Checked | Before State | Action Result | After State | Stripe-Side Evidence | Status (PASS/FAIL) | Notes |
|---|---|---|---|---|---|---|---|
| 1. Customer sync | landlord login/session ok; route reachable; guarded flags enabled; Stripe auth + lookup keys verified | no tenant row available | not executed | unchanged | n/a | FAIL | blocked because tenant target does not exist in landlord UI/runtime |
| 2. Subscription prep | step 1 prerequisite not met | n/a | not executed | unchanged | n/a (metadata step) | FAIL | blocked by missing tenant target |
| 3. Live subscription create/sync | steps 1-2 prerequisites not met | n/a | not executed | unchanged | n/a | FAIL | blocked by missing tenant target |

## Pass/Fail Matrix Log

| Matrix Row | Status (PASS/FAIL) | Evidence File(s) | Notes |
|---|---|---|---|
| Landlord commercial host lock and access | PASS | `01-login-page.png`, `02-landlord-commercial-overview.png` | landlord host/login reachable and operator session loads page |
| Plan assignment propagation | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Template assignment propagation | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Template fallback (`Template · none`) | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Label override behavior (`tenant override`) | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Label fallback (no override) | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Label fallback (malformed override) | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Module override propagation | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Add-on visibility propagation | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Included usage display | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Integrations context propagation | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Integrations read-only guard | FAIL | `02-landlord-commercial-overview.png` | blocked: no tenant row exists |
| Billing lifecycle guard (disabled) | PASS | `02-landlord-commercial-overview.png` | disabled wording/guardrails still present |
| Guarded Stripe customer sync action | FAIL | `run-summary.json` | blocked before action; no tenant target |
| Guarded Stripe subscription prep action | FAIL | `run-summary.json` | blocked before action; no tenant target |
| Guarded live subscription create/sync action | FAIL | `run-summary.json` | blocked before action; no tenant target |

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
