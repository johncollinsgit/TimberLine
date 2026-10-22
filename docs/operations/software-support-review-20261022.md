# Software support review — October 22, 2026

This is a disabled native-tenant foundation and test-only campus transport, with
fictional fixtures only. Actual campus adapters/bindings, production scanning and
live providers remain absent. Nothing was deployed, provisioned, granted or sent.
Current exact-head CI status is recorded on PR473; earlier observations below
retain their original source-head boundaries.

## Follow-up milestone

- **54 local tests passed, 1,086 assertions:** signed campus/owner packets,
  independent stores, interrupted/repeated mutations, current mapping/membership
  revocation, response substitution and target changes, hard production denial,
  quarantine/scanner failure states, approved human-response expectations and
  existing mobile/search/operator support regressions. PHP Pint passed 25 files.
- Nine synthetic WebKit views were regenerated after the support-hours change;
  no horizontal overflow, missing form labels or JavaScript errors.
- Disposable MySQL run **39834093409**, PR head **06a2cb9**, executed the original
  support/recovery suite: **11 passed, 109 assertions**. The generated data-free
  schema passed fresh-tree and blank-database boot checks. The job then correctly
  failed because its new fingerprint was not yet committed.
- Baseline artifact **12449727718** was downloaded and verified against ZIP SHA256
  `d09940fa7c87a9ce4164eccc5a727f93b6bcb5afc499d4f8691d2418ab9f4e72`.
  Its manifest names source head `06a2cb9dbbdc42cb5f795d46508ddcda58fbe50a`
  and tested checkout `f7a0f3056c8f321d2c2d43d9dfc78133315ec23d`.
  SQL review found the new support table/indexes/migration entry plus equivalent
  explicit utf8mb4 charset output on the existing POS tables. No business data
  was included. The generated SQL and fingerprint are committed together, with
  fingerprint `b85b688d7e6d355fb2800877f881775cfb579f93d4d6c4e1384740cc77baeb9c`.
- Independent follow-up review found invalid holiday dates were format-checked
  only. Strict date round-trip validation and a regression now keep calculated
  targets null for malformed dates. No actual holiday list or live SLA clock
  was configured.

Narrow integration interface, exact file ownership and live approval bundle:
[campus transport handoff](../architecture/software-support-campus-transport.md).
Production transport is not established by the fixture tests.

## Initial milestone verification (68ad2df)

- **46 tests passed, 1,031 assertions:** the new support suite plus existing
  `EverbranchMobilePlatformTest`, `LandlordSearchControllerTest` and
  `OperatorAlertServiceTest`. The first broader attempt found a missing local
  Vite build manifest in the isolated checkout; copying the existing local
  compiled build into this checkout let the unchanged login regression run.
- **Migration safety lint passed:** one additive migration, explicit short
  indexes, guarded table/index DDL and registered retained-row recovery test.
- **PHP Pint passed** for the affected PHP files. `git diff --check` passed.
- The disposable MySQL recovery test is **not executed** on MySQL here. It
  skipped under SQLite; no local MySQL/Docker service was available. MySQL
  upgrade/recovery and data-free baseline gates remain mandatory before release.
- Nine WebKit renders at desktop **1280×960**, iPad **820×1180**, phone
  **390×844**. No horizontal overflow, unlabeled visible form fields or page
  JavaScript errors. Keyboard focus reached form controls with visible rings.
  Rendering used captured server HTML and blocked every network request. This
  establishes responsive rendering, not actual iPad hardware/live acceptance.

## Independent review

An independent code review found malformed attachment-array values could reach a
file-specific callback after validation failed. Added `bail` and a regression
test; the reviewer reproduced validation errors without an exception afterward.
The reviewer also identified the separate Enterprise-runtime boundary and
relative notification link; the runbook now makes integration explicitly pending
and notification templates use configured canonical `app.url`, not request Host.
No additional blocking defect was found within the disabled native foundation.

Independent visual review found readable desktop/phone/iPad layouts and clear
focus indicators. Its refinements were applied: filter search has its own row
so option labels fit, departments use display names, and a top submission link
reaches the form below longer mobile queues. Final screenshot files were
regenerated after these changes.

## Screenshot evidence

- [Client submission, desktop](../../output/support-review/client-desktop.png)
- [Client submission, phone](../../output/support-review/client-phone.png)
- [Owner queue, desktop](../../output/support-review/owner-desktop.png)
- [Conversation, iPad](../../output/support-review/thread-ipad.png)
- [All measurements](../../output/support-review/browser-results.json)

Regenerate from the repository root after installing its existing PHP/Node
dependencies and WebKit browser: create `.tmp/views`, set a local writable
`VIEW_COMPILED_PATH`, set `SUPPORT_CAPTURE_HTML=1`, run the support feature test,
then run `node scripts/ci/review-software-support.cjs`. The harness reads only
`.tmp/support-{client,owner,thread}.html`, blocks network and writes synthetic
screenshots. Do not capture real private ticket conversations into source.

Review package and proposed notification text/configuration/test action:
[software-service-support.md](software-service-support.md).
