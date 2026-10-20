# Everbranch final-release quality reassessment

**97.0/100 provisional**, compared with the immutable **75.3** original and accepted **87.3** post-release audit. Raw weighted points: 97.0; improvement from the previous raw score: 9.75 points.

Production release **`0169e0e85cb08b83dc50e072bde9672db4e3d94e`**, Forge **80677314**. Its tree `155f20cb69f71b7af67aa53302a117c1cc0a3a96` exactly equals checked PR head `19abee58d6d4e5c275f15adc2f54b24b12fb6e69` and the reviewed MySQL recovery rehearsal. This audit is a separate report-only branch; its eventual report commit is not an application release. Readiness and runtime evidence were captured on 2026-10-20; timing observations pin `/ready` before and after the measured interval.

The original rubric v1 bytes, all **27 criterion IDs/names/weights/acceptance checks**, and **all 83 prior flow IDs** are retained. SHA-256: `f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048`. Both earlier reports are preserved byte-for-byte in [prior-audit](prior-audit/report.md), including its complete original audit archive. Historical scores were not recalculated or rewritten.

**97 points of criterion weight are assessed; 3 points remain unknown and receive no credit.** Known points are not normalized to 100. The conditional interval is 97–100 if actual future I2 evidence earns credit without changing other ratings; it is not a prediction. The score remains provisional. A 100 subtopic means its defined bounded checks are satisfied, not that every route/provider/customer/device or universal product quality has been certified. In particular, X3 credit is for repeatable measurement and honest limitations, **not a speed score of 100**.

## Current customer experience

The live public offer is **Everbranch Simple: $499 one-time setup + $299/month per business**. **“Additional text, email, and AI usage fees may apply.”** Customers choose included core branches. **Teams, Trajectory and Markets & Makers** remain separately priced advanced software, with no invented premium prices or usage allowances. Existing agreements/subscriptions retain their accepted terms.

The Branches settings structure is **Your plan / Your branches / Add software / Setup**, with short guides and progressive details. Assigned advanced software now appears with actual rollout/setup/access states; unassigned products stay in Add software. Unknown is distinct from unowned. Optional Teams components remain independent. Open links use the existing tenant/product/financial policies. Workspace available never promises payment, merchant or delivery readiness.

F06 documentation drift and F07 premium ownership/readiness are resolved within the reviewed source and exact-tree synthetic/browser scope. F09 now satisfies the original **disposable** backup/customer-export criterion. This audit did not log in as a real production customer or perform production record/provider mutations.

## Category scores

Full-category denominators are retained; unknown weight is uncredited. Category 100 applies only to the stated rubric. The accessibility/responsiveness/performance category includes measurement completeness, not a universal speed or physical-device result.

| Category | Weight | Original | Previous | Current | Unknown weight |
|---|---:|---:|---:|---:|---:|
| Customer workflow completeness and usefulness | 25 | 94.0 | 100.0 | 100.0 | 0 |
| Reliability and data correctness | 15 | 63.3 | 73.3 | 100.0 | 0 |
| Usability, onboarding, and navigation | 15 | 70.0 | 93.3 | 100.0 | 0 |
| Security, permissions, and tenant isolation | 15 | 100.0 | 100.0 | 100.0 | 0 |
| Clarity of purpose, audience, and customer value | 10 | 65.0 | 92.5 | 100.0 | 0 |
| Shared platform architecture and maintainability | 10 | 57.5 | 75.0 | 100.0 | 0 |
| Integrations and operational readiness | 5 | 40.0 | 40.0 | 40.0 | 3 |
| Accessibility, responsiveness, and performance | 5 | 50.0 | 70.0 | 100.0 | 0 |

## All 27 subtopics

Unchanged 0–4 ratings are displayed as rating×25. Unknown retains a null rating. Reasons, exact acceptance text and weighted comparisons are in [scorecard.json](scorecard.json) and [criterion-comparison.csv](criterion-comparison.csv).

| ID | Subtopic | Weight | Previous /100 | Current /100 | Current evidence |
|---|---|---:|---:|---:|---|
| W1 | Account request to first workspace | 6 | 100 | 100 | Q01, Q02, Q03 |
| W2 | Field job lifecycle | 7 | 100 | 100 | Q01, Q02 |
| W3 | Retail operations | 6 | 100 | 100 | Q01, Q02 |
| W4 | Shared customer, team and class work | 6 | 100 | 100 | Q01, Q02 |
| R1 | Persistence and validation | 4 | 100 | 100 | Q01, Q02 |
| R2 | Retry and idempotency | 4 | 100 | 100 | Q01, Q02 |
| R3 | Failures and health visibility | 3 | 100 | 100 | Q01, Q02 |
| R4 | Recovery and customer export | 4 | Unknown | 100 | Q01, Q02, Q06 |
| U1 | First value and approval guidance | 5 | 100 | 100 | Q01, Q02, Q03 |
| U2 | Navigation and module discovery | 4 | 75 | 100 | Q01, Q02, Q04 |
| U3 | Form feedback and recovery | 3 | 100 | 100 | Q01, Q02 |
| U4 | Daily home and search | 3 | 100 | 100 | Q01, Q02 |
| S1 | Tenant and object boundaries | 6 | 100 | 100 | Q01, Q02 |
| S2 | Role and financial boundaries | 4 | 100 | 100 | Q01, Q02 |
| S3 | Authentication and session controls | 3 | 100 | 100 | Q01, Q02 |
| S4 | Sensitive data and integration gates | 2 | 100 | 100 | Q01, Q02 |
| C1 | Purpose, audience and brand | 3 | 100 | 100 | Q01, Q02, Q03 |
| C2 | Prices and package promises | 4 | 100 | 100 | Q01, Q02, Q03 |
| C3 | Availability and next steps | 3 | 75 | 100 | Q01, Q02, Q04 |
| A1 | Canonical catalog and shared services | 4 | 75 | 100 | Q01, Q02, Q04 |
| A2 | Ownership and domain separation | 3 | 100 | 100 | Q01, Q02 |
| A3 | Documentation and release discipline | 3 | 50 | 100 | Q01, Q02, Q05 |
| I1 | Integration contract behavior | 2 | 100 | 100 | Q01, Q02 |
| I2 | Live sandbox and recovery operations | 3 | Unknown | Unknown | Q01, Q02, Q06, Q07 |
| X1 | Keyboard, labels and contrast | 2 | 100 | 100 | Q01, Q02, Q03 |
| X2 | Responsive public surfaces | 1.5 | 100 | 100 | Q01, Q02, Q03 |
| X3 | Representative performance | 1.5 | Unknown | 100 | Q01, Q02, Q08 |

The changed ratings are R4, U2, C3, A1, A3 and X3. All other scoped ratings are retained and refreshed by final same-tree CI where applicable. I2 remains unknown. No criterion was added, removed or strengthened to demand physical devices or production-provider backups where the original acceptance did not require them. Those remain separate operational concerns.

## Evidence and practical limits

- **Q01 — final checked CI:** [CI 39404022555](https://github.com/johncollinsgit/TimberLine/actions/runs/39404022555) passed **4,120 PHP tests / 29,511 assertions**, six intentional fixture-export skips, **22 settings browser** and **34 public browser** cases. **55 actual MySQL migration-recovery cases / 352 assertions** passed with zero recovery skips, plus schema baseline, prior-release upgrade and concurrency checks. [Code quality 39404022536](https://github.com/johncollinsgit/TimberLine/actions/runs/39404022536) passed. Final CI supersedes the candidate’s two environment-dependent local failures, whose inspected fake-provider reruns also passed. This audit did not repeat broad tests.

- **Q02 — release:** [PR 440](https://github.com/johncollinsgit/TimberLine/pull/440) merged into the exact audited release; [protected deployment 39405227615](https://github.com/johncollinsgit/TimberLine/actions/runs/39405227615) succeeded. Final Test And Build Gate and Migration Safety Gate were **skipped through verified identical-PR-tree reuse**, not rerun. This audit independently fetched the setup-plan output confirming `verified_pr_tree`; actual MySQL/CI results above are separate.

- **Q03/Q07 — live public/runtime:** recorded `/ready` reports the exact release and `/up` returns 200. All 22 public GET/HEAD-only Chromium/WebKit smoke cases pass. That wrapper uses the initial hostname-only guard and may block WebKit blob images; its contrast/layout/labels/offer/keyboard assertions are filtered functional evidence, not complete-image visual acceptance. Corrected V2 measurements and the separately inspected WebKit desktop/mobile promo screenshots provide the additional visible-image baseline. All eight effective Marketplace availability/collection flags remain false and its host is unset. Teams flags remain unchanged; provider-write controls are separate and retained rollback metadata includes 00acdc1b. This is public/runtime evidence, not production login or actual merchant/delivery acceptance.

- **Q04/Q05 — settings/docs:** source review, preserved authority-file hashes,27 premium feature contracts and 22 browser cases cover owned/gated/setup/ready/restricted/unknown state, optional components, policy links, owner/member/financial boundaries, tab controls, guides and no summary writes. README/agent/snapshot lead to the maintained map. Its old checkpoint is explicitly dated and later `/ready`/protected deploy/audit govern live state. Documentation did not require a recursive release solely to update its own SHA. Synthetic exported Blade/browser fixtures do not establish persistent production login.

- **Q06 — R4 recovery:** the coordinator executed actual MySQL 8.4.11 dump+DDL/gzip/guarded restore in a fresh private fictional instance. This audit independently read the runner/probe/guard/protocol, compared all 475 published table row identities/counts/hashes and all 10 manifest hashes, checked 13 command results, SQL relationships/totals, application graph/export result consistency and version identity. Two tenants’ customer/job/task/invoice/ledger relationships reconcile. Actual authorized export POST and streamed download bytes are checked; supported snapshot dry-run/apply recovers intentionally damaged customer data while foreign rows remain unchanged. Foreign-target, checksum tamper, owner-not-operator, unauthorized/foreign download and route-context mismatch reject. The non-test restore target rejects without changing its sentinel. Cleanup records the fictional databases/private server removed.

  **Recovery scope:** backup SHA-256 `a48f8c5fbe427343e9824f6d64bf5910bf60f9865c1f42fc92f48068e9d6aea7`. All records are fictional. The existing export is an 11-table landlord marketing/customer snapshot, with 3 tables populated in this scenario; it is not a universal job/ledger export/import. Linked jobs/ledger recovery is separately proved by the database restore. Raw SQL/export payloads are excluded from committed evidence; this reviewer validated public-safe scripts/results/hashes rather than rerunning private payloads. A successful disposable rehearsal does **not** establish production-provider backup retention, restore or disaster-recovery assurance.

## Repeatable browser timings and X3

Q08 retains the initial 48 samples plus a separately identified corrected 48-sample run. Four routes (`/platform/promo`, `/platform/plans`, `/platform/start`, `/explore/modules`) each have three sequential samples in Chromium desktop/emulated mobile and WebKit desktop/emulated mobile. Exact browser versions, viewport/UA/DPR, network/cache conditions, navigation/paint/LCP/resource metrics, blocked resources and errors are recorded in [timing evidence](evidence/timings/summary.json).

Measurements ran from 10:07:25–10:10:17 UTC for the initial diagnostics and 10:12:58–10:15:51 UTC for corrected V2, all pinned to 0169. Both runs use a Mac connection without bandwidth/CPU throttling, a new browser context per sample, disabled HTTP cache through request routing, a load event plus 3-second observation window, and unchanged OS/DNS/TLS/server/CDN cache state. Mobile is explicitly emulated, not a physical phone. No user p95, Lighthouse score or full production speed budget is inferred. LCP is the last observed candidate in the bounded window; unsupported fields remain null with reasons. Cross-origin timing/transfer values may be restricted and are not invented zero-byte guarantees.

The initial guard blocked48 external Cloudflare analytics scripts and 84 WebKit document-local blob images, producing132 preserved failed-resource entries. Those results are **image-filtered diagnostics**, not full-page WebKit speed evidence. The corrected protocol permits only GET local data images and same-origin document blob images, without outgoing network. It additionally records image completion/natural dimensions/viewport status. The initial run is retained unchanged; its failure was corrected in the harness, not hidden or attributed to the app.

All corrected 48 route samples load 200 with no page errors or HTTP error responses. The Cloudflare analytics script remains intentionally blocked once per sample, so its network/CPU cost is excluded. Corrected visible-image evidence and min/median/max timing calculations were independently checked. This supports the original X3 measurement-and-limitations criterion; it does not prove performance100, real-phone/assistive-technology acceptance, or disprove the historical local simulated-mobile Lighthouse performance around 59–61 and poor LCP. Those earlier throttled diagnostics remain historical; the fast unthrottled Mac results do not demonstrate that problem is cured. Current WebKit exposes LCP and first-contentful-paint in all samples; its absent first-paint entries remain null rather than invented zero.


Observed LCP in the corrected filtered lab profile, milliseconds (three samples per row; bounded observation, not user p95):

| Profile | Route | Minimum | Median | Maximum |
|---|---|---:|---:|---:|
| chromium-desktop | `/explore/modules` | 396 | 440 | 480 |
| chromium-desktop | `/platform/plans` | 396 | 416 | 440 |
| chromium-desktop | `/platform/promo` | 480 | 480 | 544 |
| chromium-desktop | `/platform/start` | 388 | 404 | 492 |
| chromium-emulated-mobile | `/explore/modules` | 384 | 392 | 400 |
| chromium-emulated-mobile | `/platform/plans` | 384 | 384 | 400 |
| chromium-emulated-mobile | `/platform/promo` | 372 | 392 | 400 |
| chromium-emulated-mobile | `/platform/start` | 352 | 392 | 444 |
| webkit-desktop | `/explore/modules` | 424 | 444 | 509 |
| webkit-desktop | `/platform/plans` | 416 | 431 | 493 |
| webkit-desktop | `/platform/promo` | 471 | 511 | 528 |
| webkit-desktop | `/platform/start` | 426 | 436 | 499 |
| webkit-emulated-mobile | `/explore/modules` | 405 | 406 | 461 |
| webkit-emulated-mobile | `/platform/plans` | 415 | 430 | 439 |
| webkit-emulated-mobile | `/platform/promo` | 457 | 466 | 525 |
| webkit-emulated-mobile | `/platform/start` | 436 | 450 | 456 |

## Flow inventory and remaining work

[flow-matrix.csv](flow-matrix.csv) retains all 83 prior IDs: 57 canonical modules and 26 journeys. Current status counts: 33 verified working, 33 partial, 7 implemented but unverified, 10 planned/out of current scope. Prior status/result and original baseline status are retained per row. J18 recovery and J26 ownership are verified within their stated synthetic acceptance; J22 remains partial because its broader physical-device journey has not been performed. A module row is an inventory group, not proof of every endpoint/customer lifecycle.

F01–F07 and F09 are resolved within their documented scope. F08 remains an assisted commercial activation limit, F10 genuine provider acceptance remains unknown, and F11 retains full-page/physical-device/realistic-network performance concerns. Deployed Marketplace code with gates off is not a launched merchant storefront.

| Priority | Next work | Effort | Acceptance |
|---|---|---|---|
| P1 before unattended paid launch | F08 exact-price assisted agreement/billing/activation handoff |0.5–1 day process;2–4 days separate sandbox integration | Accepted499/299/usage/premium terms; actual sandbox receipt and failure/retry/activation reconcile; preserve legacy agreements |
| P1 before affected provider rollout | F10 actual merchant/store/mailbox/queue acceptance |2–5 days plus external access/lead time | Genuine isolated provider outcomes, signed events, delivery/refund/retry and reconciliation; keep gates closed until verified |
| P1 before broad backup reliance | Production-provider backup assurance beyond completed R4 |1–2 days with approved copy/target | Retention/identity/objectives and isolated provider-copy recovery reconcile without production mutation |
| P2 before mobile/performance claims | F11 realistic full-page profiles and physical-device tasks |0.5–1.5 days baseline | Explicit budgets/conditions, complete assets, phone/tablet/assistive-technology evidence; retain all failures |

[fix-plan.md](fix-plan.md) provides complete acceptance criteria and boundaries. The [staff-only quality-agent proposal](quality-agent-proposal.md) is retained unchanged as a proposal; no in-app agent or scheduled automation was implemented.

## Integrity and reproduction

This branch changes only `docs/audits/20261020T-final-release/`; no app, gate, provider, customer, original source tip, previous audit or unrelated stash is changed. The original Carolina dirty tracked-diff SHA-256 remains `b3a08d4f7b42156b1ad3df2879f63104110c56673354b7879e82c8ed33dbc874`. The prior audit’s 43 files, original 75.3 archive and rubric remain byte-identical. Evidence source layers and limits are indexed in [evidence-index.json](evidence-index.json).

Run `python3 docs/audits/20261020T-final-release/validate.py` from this audit checkout to check arithmetic, 27 criteria/weights/acceptance texts, prior byte preservation, 83 flow IDs, source/deploy/CI identities, evidence pointers and artifact hashes. This validator checks report consistency/provenance; it is not an additional app/provider/device test. Source refs and the earlier audit commit are expected locally for preservation checks. The report commit is recorded separately in Git/handoff.
