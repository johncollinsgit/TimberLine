# Software support review — October 22, 2026

This is a disabled native-tenant foundation, with fictional fixtures only.
Campus Enterprise identity/transport integration, production upload scanning,
MySQL acceptance, private workspace bindings and live provider integration remain
open. Nothing was deployed, provisioned, granted or sent.

## Verification

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
