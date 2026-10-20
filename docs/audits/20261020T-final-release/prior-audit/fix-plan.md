# Prioritized remaining work

These are proposed bounded follow-ups. This audit changes no app behavior, provider gate, customer record or production configuration. Effort is one engineer, excluding external account approvals.

## N01 — Confirm and rehearse assisted Simple commercial handoff

Priority 1; effort: **0.5–1 day for assisted process; 2–4 days for separately scoped sandbox billing acceptance, access dependent**.

Use separate scoped approval/sandbox work; no live collection in this audit.

Acceptance:

- Setup49900 and recurring29900 USD are exact for the new offer; usage terms and any premium/custom services are explicitly accepted.
- Provider-confirmed successful and failed/retried/abandoned states do not grant early or duplicate access.
- Existing subscriptions/agreements do not silently convert; no legacy price fallback.
- Actual sandbox receipt, payment and activation reconcile; staff can explain pending next step.

## N02 — Close recovery and genuine-provider acceptance prerequisites before broad rollout

Priority 2; effort: **Recovery 1–2 days; provider 2–5 days plus external lead time**.

Disposable/restricted environments only; keep launch gates off until independently reviewed.

Acceptance:

- Disposable restore completes and representative customer/job/ledger relationships reconcile.
- Export is tenant-scoped, complete for the chosen scenario and can be independently read.
- Evidence contains version/backup identity and reconciliation results, with no private backup contents or credentials committed.
- Actual sandbox checkout/refund/invite delivery and representative queue/retry outcomes are independently observed.
- Merchant/tenant/store/account and callback signatures reconcile to the original operation.
- Domain/TLS, policies, merchant readiness, usage/rates and relevant device wallet acceptance have explicit evidence before enabling Marketplace.

## N03 — Show canonical premium ownership/readiness in existing settings

Priority 3; effort: **1–2 engineer-days**.

Do not broaden public/internal visibility or infer activation from a marketing key.

Acceptance:

- Owned premium fixture shows identity and accurate gated/setup/ready state.
- Non-owner and foreign-tenant data stays hidden; financial terms retain existing policy.
- Internal/default-off product never becomes a public choice or automatic activation.
- Core tabs/search/guides and no-mutation preview behavior retain browser coverage.

## N04 — Reconcile current product/setup/release entry points and label historical sections

Priority 4; effort: **2–4 engineer-hours**.

Documentation cleanup only, with source-of-truth links; not a legal or legacy-agreement rewrite.

Acceptance:

- One dated current entry point names the actual live release, Simple offer, request/review/setup flow and genuine gates.
- Retired Launch Partner/current-candidate references are clearly historical or superseded.
- Runbook states exact released vs pending items and links reproducible evidence; do not rewrite historical evidence.

## N05 — Measure production/device performance, then prioritize actual bottlenecks

Priority 5; effort: **0.5–1.5 engineer-days for baseline; optimization effort follows measured bottleneck**.

GET-only baseline and approved synthetic accounts; never infer production percentiles from localhost.

Acceptance:

- Versioned repeatable representative route timing and actual network/cache/device conditions are recorded.
- Primary interaction and accessibility tasks work on representative physical devices.
- Metrics distinguish local, emulated and production observations; accessibility 100 is never substituted for performance.

## N06 — Optional staff-only quality-report agent

Priority 6; effort: **3–5 engineer-days for read-only report prototype after runner/permissions design**.

Proposal only; see quality-agent-proposal.md. Not required for current capability completeness.

Acceptance:

- On-demand landlord-only report uses immutable rubric and versioned evidence.
- Missing evidence remains null; deterministic score arithmetic is independently validated.
- No autonomous billing/provider/customer/flag/deploy actions; optional AI narrative cannot mark its own checks passed.

Known scored gaps account for **4.25 points** (U2/C3/A1/A3). Closing them would raise the verified minimum to 91.5 before any unknowns receive credit. R4/I2/X3 hold another 8.5 unverified points; their future ratings must follow actual acceptance, not a target score.
