# Everbranch post-release quality audit

**87.3/100 provisional**, up from the immutable original **75.3/100** (+12.0 points). Audited 2026-10-20 05:08 EDT.

Production release: `00acdc1b05b04e81356c53601f6a6f653dd6f843`. Forge release 80669972. Checked integration head `5d590371b2145b07acd642f8bca61de4b3f928f4` and production merge share tree `dfb891e758e0d4ce6824a2920be2d32171a72dbc`. The audit report is on a separate audit-only branch; its eventual report commit is not the production release.

The exact original rubric v1 bytes are retained as [rubric-v1.json](rubric-v1.json), SHA-256 `f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048`. All 27 names, weights and acceptance checks are unchanged. The original 75.3 baseline has not been rewritten; no 72.3 baseline was found.

**91.5% of rubric weight is assessed; 8.5 points remain unverified and receive no credit.** This is evidence coverage by criterion weight, not code/route coverage. Raw weighted points are 87.25; one-decimal ROUND_HALF_UP gives87.3. Known points are not rescaled to 100. If unknowns later earned full credit with known ratings unchanged, the interval would be 87.3–95.8; that is a conditional range, not a prediction.

## What changed and what is usable

The public offer consistently says **$499 one-time setup + $299/month per business**, with **“Additional text, email, and AI usage fees may apply.”** Customers choose included core branches; Teams, Trajectory and Markets & Makers are separate premium software, with no invented premium prices. Public internal/retired choices are rejected. Existing tenant agreements and grants retain their terms.

The authenticated Branches screen now has **Your plan / Your branches / Add software / Setup** and short three-step guides, Back/Cancel/Escape, keyboard focus cycling/return, isolated pending requests and member/financial boundaries. Messaging uses its own configured product rather than accidentally quoting the SMS bundle. Electrician intake works independently of tutorial rollout. Failed integration-health reads now show Unknown instead of a fabricated zero. These fixes exist in the exact live tree.

**Remaining ownership gap:** an authorized synthetic tenant with a canonical Marketplace entitlement resolves access=true, but the internal module is absent from Your branches and the premium payload is key/name only. The generic discussion card does not tell an existing customer what is owned or gated. This is a verified presentation gap, not a reason to expose internal products publicly or enable provider gates.

**Launch limits:** Simple is prospective commercial metadata with staff-finalized agreement/usage/billing setup; this audit did not establish an automatic provider-confirmed $499/$299 collection/activation flow. Marketplace code is deployed, while all eight effective availability/collection flags are off and its host is unset. Gated code is not a launched merchant storefront.

## Category scores

Scores are earned weighted points divided by the full category weight. Unknown weight is uncredited, rather than counted as failure or normalized away. A 100 below applies only to the defined bounded rubric checks.

| Category | Weight | Original | After | Unverified weight |
|---|---:|---:|---:|---:|
| Customer workflow completeness and usefulness | 25 | 94.0 | 100.0 | 0 |
| Reliability and data correctness | 15 | 63.3 | 73.3 | 4 |
| Usability, onboarding, and navigation | 15 | 70.0 | 93.3 | 0 |
| Security, permissions, and tenant isolation | 15 | 100.0 | 100.0 | 0 |
| Clarity of purpose, audience, and customer value | 10 | 65.0 | 92.5 | 0 |
| Shared platform architecture and maintainability | 10 | 57.5 | 75.0 | 0 |
| Integrations and operational readiness | 5 | 40.0 | 40.0 | 3 |
| Accessibility, responsiveness, and performance | 5 | 50.0 | 70.0 | 1.5 |

## All 27 subtopic scores

Ratings use the unchanged 0–4 scale; displayed subtopic score is rating×25. “Unknown” retains a null rating. Full acceptance text, reasons, before/after points and evidence layers are in [criterion-comparison.csv](criterion-comparison.csv) and [scorecard.json](scorecard.json).

| ID | Subtopic | Weight | Before /100 | After /100 | Evidence |
|---|---|---:|---:|---:|---|
| W1 | Account request to first workspace | 6 | 75 | 100 | P01, P03, P04, P10, E09 |
| W2 | Field job lifecycle | 7 | 100 | 100 | E04, E09, P01, P08 |
| W3 | Retail operations | 6 | 100 | 100 | E04, E05, P01, P08 |
| W4 | Shared customer, team and class work | 6 | 100 | 100 | E04, E05, P01, P08 |
| R1 | Persistence and validation | 4 | 100 | 100 | E04, E09, P01, P08 |
| R2 | Retry and idempotency | 4 | 100 | 100 | E04, E05, P01, P08 |
| R3 | Failures and health visibility | 3 | 50 | 100 | P01, P02, P04, P05 |
| R4 | Recovery and customer export | 4 | Unknown | Unknown | P06, P08 |
| U1 | First value and approval guidance | 5 | 50 | 100 | P01, P03, P04, P08, P10, E09 |
| U2 | Navigation and module discovery | 4 | 50 | 75 | P01, P03, P07, P10 |
| U3 | Form feedback and recovery | 3 | 100 | 100 | E04, E05, E07, E09, P01, P08 |
| U4 | Daily home and search | 3 | 100 | 100 | E04, E09, P01, P08 |
| S1 | Tenant and object boundaries | 6 | 100 | 100 | E04, E14, P01, P08 |
| S2 | Role and financial boundaries | 4 | 100 | 100 | E04, E14, P01, P08 |
| S3 | Authentication and session controls | 3 | 100 | 100 | E04, E09, E14, P01, P08 |
| S4 | Sensitive data and integration gates | 2 | 100 | 100 | E04, E14, P01, P08 |
| C1 | Purpose, audience and brand | 3 | 100 | 100 | E02, E07, E11, P01, P08 |
| C2 | Prices and package promises | 4 | 50 | 100 | P01, P03, P04, P08, P10 |
| C3 | Availability and next steps | 3 | 50 | 75 | P01, P06, P07, P08 |
| A1 | Canonical catalog and shared services | 4 | 50 | 75 | P01, P07, P08, P10 |
| A2 | Ownership and domain separation | 3 | 75 | 100 | P01, P06, P08 |
| A3 | Documentation and release discipline | 3 | 50 | 50 | P01, P02, P06, P08 |
| I1 | Integration contract behavior | 2 | 100 | 100 | E04, E05, P01, P08 |
| I2 | Live sandbox and recovery operations | 3 | Unknown | Unknown | P06, P08 |
| X1 | Keyboard, labels and contrast | 2 | 50 | 100 | P01, P03, P10 |
| X2 | Responsive public surfaces | 1.5 | 100 | 100 | P01, P03, P10 |
| X3 | Representative performance | 1.5 | Unknown | Unknown | P06, P08, P09 |

W1 and U1 now satisfy their defined synthetic request/approval/setup/first-task checks after the electrician and public-guidance repairs. R3 now satisfies failure-distinguishability contracts. U2/C3/A1 retain deductions for premium ownership/readiness; A3 retains documentation-drift deductions. Existing scoped contract ratings remain bounded: no universal security, production login, per-route or real-provider claim follows from a 100 subtopic.

## Evidence and limits

- **P01, current checked tree:** independently read GitHub metadata/logs for [CI 39394548668](https://github.com/johncollinsgit/TimberLine/actions/runs/39394548668). 4,093 PHP pass / 29,247 assertions; 3 deliberate fixture exports skip in the normal suite. Separate settings export 5 cases / 39 assertions and 8 browser cases pass; public functional browser suite 34 cases passes. No broad application suite was rerun during this audit.
- **P02, deployment:** [production run 39396530459](https://github.com/johncollinsgit/TimberLine/actions/runs/39396530459) succeeded on 00acdc1b. Its Test/Build and Migration Safety jobs were **skipped by the verified identical-PR-tree reuse plan**, not rerun. The prior same-tree CI and earlier Marketplace schema deployment ran actual recovery. Exact tree equivalence and reuse-plan output are included.
- **P03, live public:** coordinator 22/22 Chromium/WebKit GET/HEAD-only cases pass at the final release, with 390/820/1440 public layouts, contrast/hover/focus, labels, reduced motion, keyboard/dialog and electrician selection. Imported scripts explicitly abort non-GET/HEAD requests; no production access request was submitted.
- **P04, fresh independent observation:** this audit fetched /ready, /up and canonical public start/plans/explorer/parent pages. All return 200; /ready reports the exact audited SHA. Public offer/usage/industry markers are recorded without cookies or credentials.
- **P05/P10, authenticated UI and failure behavior:** actual Blade exports plus isolated synthetic PHP/browser fixtures validate settings and health/role boundaries. They do **not** prove persistent production login, real customer setup, actual mailbox delivery or native device behavior.
- **P06, schema/runtime:** 55 actual MySQL recovery cases / 352 assertions pass with zero recovery skips; baseline, prior-release upgrade and concurrency checks pass. The 475-table data-free schema fingerprint is 684096c11eadf0470864600b629c2470ccd0c700b7c530ca85c43b81c94ba1b6. Runtime flags and private backup metadata come from the coordinator’s read-only evidence. A gzip-integrity check and migration restart rehearsal are **not** a backup-data restore/customer export drill. Private backup contents/path were not copied into this report.
- **P07, new concern only:** a fresh network-denied SQLite :memory: ownership probe confirms F07 with zero HTTP. No app code, customer records or rollout gates were changed.
- **P08/E01–E15:** current source/docs reviewed; original historical manual/browser evidence is archived byte-preserved and labeled historical. Passing final CI refreshes contract evidence, not old hands-on session/device claims.
- **P09, performance:** previous local Lighthouse accessibility 100 in 12 runs is historical diagnostic evidence only. Simulated mobile performance around 59–61 and poor LCP remain concerns; no new controlled production timing or physical-device acceptance was collected. **X3 remains unknown.**

## Flow review and remaining findings

[flow-matrix.csv](flow-matrix.csv) preserves all 79 original flow IDs/observations, adds the 3 new canonical Teams/Marketplace module rows and an explicit premium-ownership journey: **83 groups** (57 modules + 26 journeys). Status counts are 31 verified-working, 1 verified-broken presentation journey, 32 partial, 9 implemented-but-unverified and 10 planned/out-of-scope. “Verified working” applies to the exact scoped test/observation layer, not an entire external customer lifecycle. The 57-module inventory is breadth, not 57 completed manual walkthroughs.

Original F01–F05 are resolved within stated evidence scope. F06 is partially resolved: public guidance improved, but current entry-point docs still reference retired/pending release states. Remaining actionable work is in [findings.json](findings.json) and [fix-plan.md](fix-plan.md).

| Priority | Remaining issue | Effort | Completion evidence |
|---|---|---|---|
| P1 before unattended paid launch | F08 exact Simple agreement/usage/billing activation handoff | 0.5–1 day process; 2–4 days sandbox acceptance | Exact accepted terms, actual sandbox receipt/payment and one authorized fulfillment; no legacy fallback |
| P1 before broad reliance/affected rollout | F09 restore/export and F10 genuine provider acceptance | 1–2 days recovery; 2–5 days provider plus external lead time | Disposable data restore/export reconcile; actual merchant/store/mailbox/queue/refund evidence |
| P2 | F07 owned premium/readiness in settings | 1–2 days | Authorized canonical owned/gated/setup/ready state, no public exposure or activation |
| P2 | F06 current product/setup/release documentation | 2–4 hours | One exact-release current entry point; historical states explicitly superseded |
| P2 | F11 production/device timing and accessibility acceptance | 0.5–1.5 days baseline | Versioned representative production conditions and actual device tasks |

Known deductions total 4.25 points. Closing those defined gaps could bring assessed credit to 91.5; the remaining 8.5 points can only follow genuinely new acceptance, with whatever rating that evidence warrants. No future 100 is promised. The optional [staff-only quality-agent proposal](quality-agent-proposal.md) remains a proposal; no in-app agent or automation was built.

## Audit integrity

Only this audit directory is changed on `codex/everbranch-post-release-audit-20261020`. Original source tips and Carolina dirty-work fingerprint remain unchanged; unrelated stashes are untouched. Audited production release, checked tree and later audit-artifact commit are separate identities. Artifact hashes and an executable validator accompany the report. Run `python3 docs/audits/20261020T-post-release/validate.py` from this audit checkout; the workspace-preservation checks expect the preserved local source refs. The report commit is provided separately in the handoff. This audit performed no merge, deployment, flag change, real payment/provider action, message or customer-record mutation.
