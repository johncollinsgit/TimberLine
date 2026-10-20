# Release 1 — public quality and Everbranch Simple

Candidate score: **81.8/100 provisional**, up from **75.3/100** using unchanged rubric v1. Evidence coverage is **91.5% of rubric weight**, not route/test/code coverage. This report records a candidate; deployment and live checks are recorded separately.

**Public accessibility is 100/100 in the defined checks, with Lighthouse accessibility 100 in all 12 runs.** Responsive checks are 100/100. Repeated local performance measurements are now available, but X3 retains the original unverified treatment until production/device performance is established. Actual simulated-mobile performance is only 59–61/100 and is not described as 100 or production-fast.

| Area | Before | Candidate |
|---|---:|---:|
| Customer workflow completeness and usefulness | 94.0 | 94.0 |
| Reliability and data correctness | 63.3 | 63.3 |
| Usability, onboarding, and navigation | 70.0 | 76.7 |
| Security, permissions, and tenant isolation | 100.0 | 100.0 |
| Clarity of purpose, audience, and customer value | 65.0 | 92.5 |
| Shared platform architecture and maintainability | 57.5 | 75.0 |
| Integrations and operational readiness | 40.0 | 40.0 |
| Accessibility, responsiveness, and performance | 50.0 | 70.0 |

The 8.5 points still unverified are recovery/export (4), actual provider/operational acceptance (3), and representative production/device performance (1.5). Production/device performance remains a separately explicit unknown; satisfying the local measurement criterion does not verify it. Unchanged subtopics carry forward the original bounded synthetic evidence. No universal penetration, device or customer lifecycle audit is implied.

## What changed

- Restored readable header links by scoping the colliding Workflow Studio selectors. Corrected demo-pill, workflow-step, module-description and illustrated-workspace contrast, including hover/focus states. Added clear public keyboard focus.
- Deferred below-the-fold industry images and lowered priority of secondary hero scenes. Desktop home LCP is 2.46–2.60 seconds locally; simulated mobile is still 14.4–15.1 seconds. The local server sends uncompressed assets and third parties are blocked.
- Published one prospective Simple offer from `module_catalog.offers.standard`: **$499 one-time setup + $299/month**. **Additional text, email, and AI usage fees may apply.**
- Included explicit safe core branches in the simple offer; presented Teams, Trajectory and Markets & Makers as separate premium software with separate pricing, without invented prices. Removed the old paid-add-on grid and retired introductory tiers from new-customer discovery.
- A chosen branch carries from Explorer into signup and is stored as a setup preference. Hidden/premium/roadmap branch selections, old introductory tiers and paid-add-on submissions are rejected. Requests do not create access or charge money.
- Improved shared support-page typography and white bordered surfaces, preserved page shapes, and added public browser checks to PR and deploy build gates.

## Rechecked subtopics

| Criterion | Before | Candidate | Evidence |
|---|---:|---:|---|
| U2 — Navigation and module discovery | 50 | 75 | Public header and branch links are usable, choices carry into signup and internal/retired selections are rejected. The signed-in plan/branches/setup hierarchy still needs the requested progressive tab structure. |
| C2 — Prices and package promises | 50 | 100 | One canonical $499 setup / $299 monthly Simple offer, conditional text/email/AI usage disclosure, included core branch list and separate unpriced premium products now agree across public landing, Plans, signup, Explorer and parent pricing. No invented seat/capacity or premium-rate promises. |
| C3 — Availability and next steps | 50 | 75 | Only explicit safe included branches are displayed and accepted as selections; premium/internal/roadmap choices and retired offers fail closed. Fine-grained setup/readiness guidance in the signed-in branch experience remains a follow-up. |
| A1 — Canonical catalog and shared services | 50 | 75 | Public offer and included branch choices reuse the canonical catalog and services. The legacy signed-in first-matching add-on display can still select a bundle price for Messaging and is a scoped follow-up. |
| A2 — Ownership and domain separation | 75 | 100 | Existing backend tenant/provider ownership contracts continue to pass; the demonstrated cross-surface navigation collision is now scoped to its owning Workflow Studio. No provider or entitlement mutations were introduced. |
| X1 — Keyboard, labels and contrast | 50 | 100 | Header collision, demo pill, workflow controls, module descriptions and illustrated-workspace text now meet measured contrast checks. Keyboard, focus, form labels, reduced motion and 12 Lighthouse accessibility scores of 100 are verified locally. |
| X2 — Responsive public surfaces | 100 | 100 | Seven core public routes plus parent pricing are rechecked in Chromium/WebKit at 390, 820 and 1440px; no horizontal overflow or lost primary action. Visual baselines and public screenshots reviewed. |
| X3 — Representative performance | Unverified | Unverified | Repeated local measurements exist, but production/device performance is not established. Simulated mobile scores 59–61. |

All 27 subtopic scores and unchanged acceptance checks are in [scorecard.json](scorecard.json); the frozen rubric is [rubric-v1.json](rubric-v1.json). No score credit is assigned for the unverified performance criterion. Local evidence is retained to guide the next improvement without implying production speed was fixed.

## Local performance evidence

Each row summarizes two independent Lighthouse runs. Chrome used desktop and default simulated-mobile settings, a disposable loopback Laravel/SQLite server, a clean browser, blocked outbound providers/fonts and no public upload.

| Route | Profile | Performance | Accessibility | LCP seconds | CLS |
|---|---|---:|---:|---:|---:|
| /platform/promo | desktop | 82–84 | 100 | 2.46–2.60 | 0 |
| /platform/plans | desktop | 93–93 | 100 | 1.24–1.28 | 0 |
| /explore/modules | desktop | 93–93 | 100 | 1.28–1.28 | 0 |
| /platform/promo | mobile | 59–59 | 100 | 14.40–15.08 | 0 |
| /platform/plans | mobile | 61–61 | 100 | 6.75–6.75 | 0 |
| /explore/modules | mobile | 60–61 | 100 | 6.75–6.90 | 0 |

Mobile speed remains an actionable follow-up. These results do not establish production compression/cache behavior, mobile hardware performance, field Core Web Vitals or a production percentile. Local best-practices score is 96 and SEO 100 in these runs. Full public-only LHR JSON is preserved compressed under [evidence/lighthouse](evidence/lighthouse).

## Verification and limits

- Q01: [34 Chromium/WebKit tests](evidence/browser-results.json), including keyboard/dialog Escape and focus return, form labels, seven routes at three widths, parent pricing, offer visibility, branch selection and visual baselines. Real iPhone hardware and assistive-technology acceptance remain unverified.
- Q02: [PHP summary](evidence/php-summary.json): 4,008 passed in the network-denied run, one intentional fixture-export skip, two DNS-dependent failures. Both passed separately with existing HTTP/mail fakes and normal DNS. Thus 4,010 distinct cases have passing evidence; this is not described as one completely green network-denied run. Local PHP is 8.5.2; protected CI uses 8.4.
- Q03: [Lighthouse measurements](evidence/lighthouse-summary.json), two runs per route/profile, with all accessibility scores 100. Focused PHP public/billing/navigation checks passed 102 cases; build and dirty-file Pint passed.
- Q04: [legacy catalog preservation](evidence/catalog-preservation.json) and [original worktree preservation](evidence/workspace-preservation.json). Existing customer plans, entitlements and subscriptions were not repriced or activated. Formatting in the shared commercial service follows the existing repository Pint requirement; substantive changes there are public payload selection and recorded offer-interest handling.

The new standard offer has no automated checkout/fulfillment mapping. It cannot silently fall back to an old tier price, even when legacy hosted checkout is enabled. A staff-reviewed agreement, usage terms and setup/billing readiness are required before activation. No live payment or message was sent in these checks.

An existing open pricing PR #420 contains separate prepaid usage and billing work. This release does not merge it or invent its future activation policy; it must be reconciled with the owner’s newer Simple/core-branches/premium-products decision before any later billing activation.

## Next approved work, one area at a time

1. Organize the existing signed-in Branches screen into Your plan / Your branches / Add software / Setup tabs, reusing its existing three-step guide and canonical tenant/role/audited actions. Keep current entitlements distinguishable from available and pending choices.
2. Correct the legacy signed-in Messaging/bundle display mismatch using exact canonical add-on ownership.
3. Repair electrician public intake independently of the tutorial gate, then operational issue-read failures that currently look like zero issues.
4. Improve mobile delivery performance from measurements; establish production and device baselines before claiming production speed.

Backup/restore and real provider acceptance remain necessary for a broad readiness score near 100. The staff-only quality-agent proposal remains in the original audit; no autonomous production mutation agent was installed.

## Release state

Prepared locally on `codex/everbranch-public-quality`. The owner explicitly authorized push and deployment. Only a successful protected test/build, migration gate, Forge activation of the intended SHA and anonymous public rechecks will establish that it is live. See the later release record.
