# Staging Operator UAT Runbook: Landlord Commercial Hardening (2026-03-27)

Status: Active for staging-UAT only.
Scope: Validate commercialization assignment behavior without enabling checkout or live connector writes.

## Guardrails (Do Not Violate During UAT)
- Preserve Modern Forestry alpha behavior.
- Keep Shopify as the flagship track.
- Do not activate checkout/subscription lifecycle actions.
- Keep `/shopify/app/integrations` read-only/placeholder.
- Do not create or test any parallel identity/profile/entitlement systems.
- Limit live Stripe mutation coverage to the single guarded landlord subscription create/sync action only.

## Prerequisites
- Staging host routing resolves correctly:
  - Landlord host: `app.forestrybackstage.com` (or staging equivalent)
  - Tenant host: `<slug>.forestrybackstage.com`
- Operator account has landlord access (`admin` role or configured landlord operator allowlist).
- At least one staging tenant exists and is mapped to a Shopify embedded store context.
- Latest migrations are applied, including commercial foundation tables:
  - `landlord_catalog_entries`
  - `tenant_commercial_overrides`
  - `tenant_usage_counters`

## Execution Preflight Gate (Must Pass Before Step 1)
- Validate host/runtime reachability:
  - `GET /login` on landlord host should load (`200` expected).
  - `GET /landlord` and `GET /landlord/commercial` on landlord host must not be `404`.
    - unauthenticated responses may be `302` (redirect to login) or auth-denied status once authenticated (`401`/`403`) based on session.
  - if landlord routes return `404`, treat as blocking host-lock/runtime mismatch and stop the run.
- Validate operator/runtime prerequisites:
  - landlord operator account/session is available for the run.
  - `COMMERCIAL_STRIPE_CUSTOMER_SYNC_ENABLED=true`.
  - `COMMERCIAL_STRIPE_SUBSCRIPTION_PREP_ENABLED=true`.
  - `COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED=true` when validating step 3.
  - `STRIPE_SECRET` present and valid (`sk_` prefix).
  - `STRIPE_API_BASE` is HTTPS for remote endpoints (HTTP allowed only for `localhost`/`127.0.0.1`/`::1`).
- Validate mapping prerequisites:
  - assigned tenant plan/add-on mappings resolve to configured recurring lookup keys.
  - tenant row in `/landlord/commercial` shows clear readiness and non-empty prerequisites before executing each guarded step.
- If any preflight check fails:
  - do not execute guarded actions.
  - record the run as blocked in `docs/operations/staging-commercial-uat-evidence-template.md` (blocked run section).
  - mark this pass as `Blocked` (not `PASS`).

## Route Order for UAT
1. Open `GET /landlord/commercial` on the landlord host.
2. Assign tenant plan (Starter/Growth/Pro) in the tenant row.
3. Assign tenant template (Candle/Law/Landscaping/Apparel/Generic).
4. Apply tenant display-label override JSON (optional).
5. Toggle module override and add-on state for one or two modules.
6. Verify usage and included-limit display in the tenant row.
7. Open tenant embedded surfaces and validate propagation:
  - `GET /shopify/app/start`
  - `GET /shopify/app/plans`
  - `GET /shopify/app/integrations`
8. Run guarded billing actions from landlord tenant row in strict order:
  - Stripe customer sync
  - Stripe subscription prep
  - Stripe live subscription create/sync

## UAT Procedure
### Plan assignment
- Set plan to `Starter`, then `Growth`, then `Pro`.
- Expected:
  - landlord save succeeds with status message
  - tenant plan row reflects the assigned key
  - `/shopify/app/plans` updates current plan label and module mix

### Template assignment
- Assign each template key once (`candle`, `law`, `landscaping`, `apparel`, `generic`) on a staging tenant.
- Expected:
  - landlord save succeeds with status message
  - template key appears in landlord tenant row
  - commercialization pages show template context pills

### Display-label overrides
- Set `display_labels_json` (example: `{"rewards":"Forest Credits"}`).
- Expected:
  - landlord save succeeds
  - module label reflects override on `/shopify/app/start` and `/shopify/app/plans`
  - label source shows `tenant override`
- Fallback check:
  - clear template + display-label override
  - pages should show `Template · none` and `Labels · entitlements default`

### Module visibility/effective experience
- Set a module override to `disabled` for a module included by current plan (for example `rewards`).
- Expected:
  - landlord module row shows updated effective state badge
  - `/shopify/app/start` moves module into locked/upgrade context
  - `/shopify/app/plans` reflects locked state for that module

### Integrations read-only behavior
- Validate `/shopify/app/integrations` after assignment changes.
- Expected:
  - plan/template/label-source context updates
  - card states update from entitlement context
  - setup drawer opens and shows guidance/status registry
  - no live connector writes are triggered from this page

### Guarded live subscription action
- Trigger the landlord-only Stripe live subscription create/sync action only after customer sync + subscription prep succeed.
- Expected:
  - action is blocked with explicit reasons until prerequisites pass
  - action creates or syncs `stripe.subscription_reference` when prerequisites pass
  - action status/mode/message is visible in landlord tenant row metadata
  - checkout and broad update/cancel lifecycle controls remain disabled

## Guarded Stripe 3-Step Validation (Required)

Use this exact sequence for staging evidence capture.

### Step 1: Stripe customer sync
- Prerequisites:
  - landlord host + operator access confirmed
  - guarded customer action enabled in config
  - `services.stripe.secret` is valid and `services.stripe.api_base` is HTTPS for remote endpoints (HTTP allowed only for local loopback: `localhost`/`127.0.0.1`/`::1`)
- Before action verify:
  - customer reference is empty or existing expected reference
  - status line shows previous customer-sync state clearly
- Expected after action:
  - `billing_mapping.stripe.customer_reference` present
  - metadata status for `billing_guarded_actions.stripe_customer_sync` updates with status/mode/message/time
- Stripe-side evidence:
  - Stripe customer ID exists and matches landlord row reference
- Pass/fail:
  - `PASS` when state is deterministic and reference is correct
  - `PASS` for intentional prerequisite-failure tests only if action is safely blocked with clear reasons
  - `FAIL (Blocking)` for silent failure, ambiguous state, or unexpected mutation

### Step 2: Stripe subscription prep sync
- Prerequisites:
  - Step 1 succeeded (or existing valid customer reference is present)
  - plan/add-on mapping lookup keys exist for assigned tenant context
- Before action verify:
  - plan/add-on assignment and current customer reference
- Expected after action:
  - `billing_mapping.stripe.subscription_prep_candidate` refreshed
  - `billing_mapping.stripe.subscription_prep_hash` present
  - metadata status for `billing_guarded_actions.stripe_subscription_prep` updates with status/mode/message/time
- Stripe-side evidence:
  - none required (metadata-only step), but capture expected lookup keys from candidate payload
- Pass/fail:
  - `PASS` when candidate + hash are deterministic and status is clear
  - `PASS` for intentional prerequisite-failure tests only if blocked reasons are explicit
  - `FAIL (Blocking)` when prep state is stale/ambiguous or hash/candidate is missing after success

### Step 3: Stripe live subscription create/sync
- Prerequisites:
  - Step 1 succeeded
  - Step 2 succeeded
  - prep hash is current for the active plan/add-on mapping
- Before action verify:
  - live action readiness indicates prerequisites satisfied
  - current subscription reference state is clear (`none` or existing)
- Expected after action:
  - `billing_mapping.stripe.subscription_reference` created/synced
  - metadata status for `billing_guarded_actions.stripe_live_subscription_sync` updates with status/mode/message/time
  - idempotent rerun behavior is deterministic (`create` then `sync`)
- Stripe-side evidence:
  - Stripe subscription ID/status matches landlord row
- Pass/fail:
  - `PASS` when create/sync is deterministic and no unrelated lifecycle actions occur
  - `PASS` for intentional blocked tests only if reasons are explicit and no mutation occurs
  - `FAIL (Blocking)` for unexpected mutation, unclear state, or incorrect reference/status

## Expected Results by Surface
### `/shopify/app/start`
- Shows assigned plan and operating mode.
- Shows template/label-source context.
- Checklist and recommended actions reflect effective module state.
- Label overrides appear where module labels are rendered.

### `/shopify/app/plans`
- Shows assigned plan and template/label-source context.
- Included modules/locked modules reflect current entitlement + overrides.
- Add-on cards remain informational and state-aware.

### `/shopify/app/integrations`
- Shows assigned plan and template/label-source context.
- State remains placeholder/read-only with status registry metadata.
- Fallback guidance remains available.

## UAT Pass/Fail Matrix (Staging Merge Gate)

Use this matrix as the merge-hardening source of truth. A row is `PASS` only when all expected results are observed.

| Area | Scenario | Expected Result | Failure Class |
|---|---|---|---|
| Landlord commercial | Open `/landlord/commercial` on landlord host | Page loads; no tenant-host access to this write surface | Blocking |
| Plan assignment | Assign `starter`, `growth`, `pro` to same tenant | Save succeeds; assignment persists; `/shopify/app/start` + `/shopify/app/plans` update plan context | Blocking |
| Template assignment | Assign `candle`, `law`, `landscaping`, `apparel`, `generic` | Save succeeds; template context updates on `/shopify/app/start`, `/shopify/app/plans`, `/shopify/app/integrations` | Blocking |
| Template fallback | Clear template assignment | Surfaces show `Template · none`; no stale template labels remain | Blocking |
| Label override | Set valid override JSON (for example `{\"rewards\":\"Forest Credits\"}`) | Surfaces show `Labels · tenant override`; overridden label appears in Start/Plans copy | Blocking |
| Label fallback (no override) | Remove `display_labels_json` | Surfaces resolve to `template default` when template has labels, otherwise `entitlements default` | Blocking |
| Label fallback (malformed override) | Set malformed/ignored override shape (non-object keys/empty labels) | No misleading `tenant override` state; deterministic fallback to template default or entitlements default | Blocking |
| Module override propagation | Disable an included module (for example `rewards`) | Landlord row shows locked effective state; `/shopify/app/start` and `/shopify/app/plans` move module into locked/upgrade context | Blocking |
| Add-on visibility | Toggle add-on state (for example `sms`) | Landlord state persists; Plans add-on card shows enabled/available state correctly | Blocking |
| Included usage display | View tenant usage/included values in landlord row | Contacts/email/SMS usage + included limits render without empty/ambiguous state | Acceptable follow-up if cosmetic only |
| Integrations context propagation | Change plan/template/labels, then open `/shopify/app/integrations` | Context pills and card states update to assigned tenant context | Blocking |
| Integrations read-only guard | Attempt to use integrations page actions | Setup drawer/details only; no live connector sync/OAuth/job/webhook/API write action | Blocking |
| Billing lifecycle guard | Inspect landlord commercial + config behavior | Checkout/subscription mutation remains disabled; only guarded landlord Stripe actions are visible (customer sync + subscription-prep metadata sync + live subscription create/sync) | Blocking |
| Guarded Stripe customer sync action | Run step 1 action | Customer reference state and metadata update deterministically, or safe explicit block reasons are shown | Blocking |
| Guarded Stripe subscription prep action | Run step 2 action | Candidate/hash and metadata update deterministically, or safe explicit block reasons are shown | Blocking |
| Guarded Stripe live subscription action | Run step 3 action | Single guarded create/sync action succeeds or blocks with explicit reasons; subscription reference persists; no broad update/cancel automation appears | Blocking |

### Blocking vs Deferred Interpretation
- Block merge immediately for any row marked `Blocking`.
- Deferred follow-up is acceptable only for cosmetic/wording polish where state correctness and guardrails remain intact.
- If uncertain, classify as `Blocking` until correctness is confirmed.

## Staging Evidence Capture Protocol

Use this protocol for real staging runs so results are merge-usable and comparable across operators.

### Tenant permutations to run first
Run in this order before any additional exploratory combinations:
1. `Starter + Candle + no display override`
2. `Growth + Law + valid display override` (example: `{"rewards":"Forest Credits"}`)
3. `Pro + Generic + module disabled override` (for example `rewards` disabled)
4. `Growth + no template + no display override`
5. `Growth + Law + malformed display override shape` (for deterministic fallback verification)

### Screenshots to capture per permutation
Capture each screenshot with browser URL and timestamp visible.
1. `/landlord/commercial`
2. Tenant row showing assigned plan/template/label source and usage summary
3. `/shopify/app/start` summary pills + checklist region
4. `/shopify/app/plans` current profile pills + included/locked module regions
5. `/shopify/app/integrations` summary pills + one open setup drawer showing placeholder/read-only state text
6. Landlord tenant row after guarded live subscription create/sync showing status/message/reference

### Values/states to verify on each page
- `/landlord/commercial`:
  - assigned plan, effective plan, template, label source, usage values, billing-readiness messaging, and guarded Stripe action states
- `/shopify/app/start`:
  - plan/template/label-source pills, effective label copy, locked module behavior
- `/shopify/app/plans`:
  - plan/template/label-source pills, included vs locked modules, add-on visibility
- `/shopify/app/integrations`:
  - plan/template/label-source pills, card state counts, drawer status-registry text, explicit read-only behavior

### Pass/fail recording workflow
1. Open `docs/operations/staging-commercial-uat-evidence-template.md`.
2. Create one run record per tenant permutation.
3. Mark each matrix row `PASS` or `FAIL` with notes.
4. Link each matrix row to the exact screenshot filename(s).
5. Include operator name, run date, tenant slug, and applied assignment values.
6. If preflight blocks the run before step 1, complete the blocked-run section and do not mark guarded-step rows as `PASS`.
7. Save the blocked run summary as a dated record in `docs/operations/` (example: `staging-commercial-uat-blocked-run-YYYY-MM-DD.md`).

### Blocking failure evidence requirements
For every blocking failure, attach all of:
1. Landlord assignment screenshot (before/after save)
2. Failing tenant surface screenshot (start/plans/integrations)
3. Exact tenant slug + plan + template + override JSON used
4. Route URL and timestamp
5. One-sentence expected vs actual mismatch

### Deferred/noise control guidance
- Log deferred findings only when they are clearly cosmetic/non-correctness issues.
- For deferred items, include one screenshot and one sentence; avoid long narratives.
- Do not classify placeholder/read-only behavior as failure if it matches documented intent.

## Pre-Billing Handoff Requirements

This runbook validates commercialization behavior before any billing-focused pass.

Before handing off to billing readiness work:
1. Complete this runbook matrix with evidence attachments.
2. Complete `docs/operations/staging-commercial-uat-evidence-template.md` for each tested permutation.
3. Confirm `docs/operations/pre-billing-readiness-gate.md` requirements are satisfied.
4. Complete `docs/operations/billing-activation-checklist.md` (readiness only; no lifecycle actions).
5. Keep checkout/subscription lifecycle actions disabled until all blocking rows are resolved.

## Intentionally Placeholder / Read-Only
- `/shopify/app/integrations` connector actions and status are informational.
- Billing mapping fields are readiness metadata only.
- No checkout/subscription tenant UX is available.
- Guarded landlord Stripe actions are allowed as explicit operator actions:
  - customer-reference sync
  - subscription-prep metadata sync
  - live subscription reference create/sync

## Intentionally Deferred
- Broad multi-tenant refactor across non-commercial legacy domains.
- Shopify App Store packaging.
- Speculative AI automation work.
- Full legacy Candle Cash string replacement outside commercialization UAT surfaces.
  - Deferred examples include deep legacy rewards/admin copy paths outside:
    - `/shopify/app/start`
    - `/shopify/app/plans`
    - `/shopify/app/integrations`
    - `/landlord/commercial`

## Findings Triage
### Block merge
- Landlord host-lock bypass (commercial page writable on tenant/unknown hosts).
- Plan/template assignments do not propagate to commercialization pages.
- Label override or fallback behavior is inconsistent/non-deterministic.
- Integrations page triggers unintended live connector writes.
- Any path enables checkout/subscription mutation.

### Acceptable follow-up (non-blocking)
- Cosmetic spacing/text polish issues.
- Additional legacy terminology cleanup outside this pass scope.
- New convenience UX ideas that do not affect correctness.
