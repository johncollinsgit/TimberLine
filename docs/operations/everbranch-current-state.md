# Everbranch product, setup and release map

This is the maintained entry point for current product language and operating
boundaries. Dated implementation notes elsewhere retain their original scope.
They do not establish current runtime or external-provider acceptance.

## Dated independently verified checkpoint

Last observed **2026-10-20 08:58 UTC (04:58 EDT)**:

- Production `/ready`: `status=ok`, release
  `00acdc1b05b04e81356c53601f6a6f653dd6f843`; `/up` returned 200. Forge 80669972.
- [Protected deployment 39396530459](https://github.com/johncollinsgit/TimberLine/actions/runs/39396530459)
  activated that release. Its tree `dfb891e758e0d4ce6824a2920be2d32171a72dbc`
  equals checked head `5d590371b2145b07acd642f8bca61de4b3f928f4`.
- [CI 39394548668](https://github.com/johncollinsgit/TimberLine/actions/runs/39394548668)
  passed 4,093 PHP / 29,247 assertions, 8 settings and 34 public browser cases, 55 actual
  MySQL migration-recovery cases / 352 assertions with zero recovery skips, baseline,
  prior-upgrade and concurrency checks. Three normal-suite skips were deliberate
  fixture exports. The final deploy reused this identical checked tree, so its
  Test/Build and Migration jobs were skipped rather than rerun.
- Live public GET/HEAD-only Chromium/WebKit smoke passed 22 cases. This was public
  functional/contrast/keyboard/layout evidence, not production login, provider,
  payment, physical-device or universal performance acceptance.
- All eight effective Marketplace availability/collection flags were off and host
  unset. Teams rollout was unchanged; its external-write gates remained separate.

This checkpoint is **a dated observation, not a permanently current SHA claim**.
For later live state, use [production readiness](https://app.theeverbranch.com/ready),
the matching protected Deploy Production run/checked tree and the latest immutable
same-rubric audit. Source code, a merge, or this document alone is not live evidence.
New audit reports can record a later release without a recursive docs-only deploy.
For private performance evidence, collect local JSON or separate LHCI collection/
assertions without its public-upload target; see the offer runbook.

## Offer and product families

New public **Everbranch Simple** offers are **$499 one-time setup + $299/month per
business**. **Additional text, email, and AI usage fees may apply.** Customers
choose included core branches. **Teams, Trajectory and Markets & Makers** are
separate premium software; unapproved premium prices/rates/allowances are not
invented. Custom development is scoped separately.

`config/module_catalog.php` owns `offers.standard`, explicit core keys and premium
marketing-to-module mappings. Public choices intersect core inclusion with safe
public visibility. `product_surfaces.public_plans` is prospective acquisition copy;
it does not replace existing tenant catalog terms, agreements or subscriptions.
The old public Launch Partner acquisition offer is superseded. Client-specific
recorded Launch Partner/legacy agreements keep their accepted terms.

The current public brand is Everbranch, with Evergrove Software parent/product-family
presentation. Historical entity/ownership wording is retained as historical context,
not a new legal verification or authority to change legal/payment entities.
Modern Forestry is a protected client/alpha workspace, not the product definition.

## Customer request to useful workspace

1. The visitor chooses an industry and core branches on demo/production intake.
   Electrician selection is independent of the optional tutorial rollout.
2. Submitting records pending interest and an inactive identity, not payment or
   active workspace access. Staff reviews business needs, exact agreement/usage
   terms and any separately priced software.
3. Existing approval/password-setup flows provide access instructions. Synthetic
   invite tests do not establish real inbox delivery. A verified user follows
   workspace/first-login guidance toward a useful first action.
4. In the existing Branches settings screen:

   | Tab | Purpose |
   |---|---|
   | Your plan | Current package, core-branch readiness and authorized agreement details |
   | Your branches | Assigned branches and advanced software, with actual access/setup state |
   | Add software | Unassigned choices and separately reviewed premium software |
   | Setup | Unfinished setup, requests and assigned software needing attention |

5. Short guides/native details explain one next step at a time. Back, Cancel and
   Escape preserve context; viewing a summary or guide does not purchase, grant,
   connect, send or change provider/rollout configuration.

`standard` remains prospective commercial metadata without an automatic legacy
checkout/fulfillment mapping. Staff must finalize the accepted terms and actual
billing/activation handoff before collection. Missing configuration/unknown plans
fail closed instead of substituting an older tier. See [the offer contract](standard-public-offer.md).

## Assignment, workspace access and readiness

Source capabilities after the checkpoint include the premium assignment/readiness
summary described in [its candidate evidence](../audits/20261020T-premium-readiness-candidate/report.md).
Verify a later protected deployment before attributing source changes to live use.
This statement describes their relationship to the dated checkpoint, not a claim
that a candidate must remain pending forever.

- Recorded assignment comes from the canonical resolver after active-date and
  explicit disabled/override decisions. Informational `has_recorded_access` never
  authorizes an action; effective `enabled`/`canAccess` and the actual product
  policy still control access.
- Assigned products are separate from public/purchasable catalog visibility.
  Internal software stays internal. Teams components remain independent; a
  configured optional component cannot imply an assigned/configured primary.
- Waiting for rollout, Setup needed, Access managed by owner, Workspace available
  and Unable to confirm are distinct. Unknown reads cannot become false ownership.
  Open links require the configured actual workspace and current tenant/user
  authorization. No source/billing/price/credential/financial-space values enter
  ordinary member summaries.
- Workspace access never confirms payment setup or external connections. Product
  payment, merchant, delivery, bank and provider-write gates remain independently
  reviewed; no grant or marketing choice bypasses them.
- `markets_makers` is a marketing product identity; canonical `markets_and_makers`
  is the internal module. Neither is included in Simple core selections. Deployed
  Marketplace code is not a launched storefront or authorization to collect money.
- Existing owner/member/financial and tenant/store boundaries are authoritative.
  Non-financial members must not receive financial fields or unauthorized links.
  Genuine household FinanceAccess permission is distinct from business-financial
  roles; keep the existing policy rather than inventing a parallel one.

## Release, acceptance and audit rules

Use the serialized protected PR/CI/MySQL/Forge path and exact `/ready` verification.
Keep Modern Forestry, Teams and existing customer/provider lanes unchanged outside
an explicitly scoped change. Preserve retained rollback releases and customer data;
do not run down migrations or rewrite live files to reverse a release. See
[the release record](coordinated-release-2026-10-20.md).

The original 75.3 baseline and the accepted
[87.3 post-release audit](https://github.com/johncollinsgit/TimberLine/blob/fb2a5550e177e18aebbe755440ad55ec09625b34/docs/audits/20261020T-post-release/report.md)
are immutable. Rubric v1 SHA-256:
`f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048`.
Use all 27 original criteria/weights/checks and half-up arithmetic, without
rescaling assessed weight. A new audit uses a new directory/branch and pins its
actual audited release separately from the report commit.

The 87.3 report left R4/I2/X3 unknown. New genuine disposable recovery/export or
controlled performance measurements must be evaluated on their own evidence in a
new audit; neither old score is rewritten. Synthetic provider mocks, a gzip check,
migration restart tests, lab/browser emulation and physical-device/user acceptance
are different evidence layers. Never infer real collection, delivery or provider
readiness from deployment alone. The optional staff quality agent remains a
proposal; it is not implemented or authorized to change the app autonomously.
