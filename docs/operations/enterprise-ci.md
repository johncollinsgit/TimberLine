# Enterprise source verification

`.github/workflows/enterprise-runtime-ci.yml` preserves the isolated schema,
MySQL 8.4 recovery, runtime composition, Enterprise build, and existing private
Chromium/WebKit checks. It also runs these focused reviewer-operations gates:

| Gate | Entry point | Boundary exercised |
| --- | --- | --- |
| Transport and sample contracts | `node --test tests/e2e/enterprise-transport.test.mjs tests/e2e/enterprise-reviewer-sample.test.mjs tests/e2e/enterprise-workspace-tour.test.mjs` | Private identity/CSRF and replay contracts; isolated synthetic transport, roles and sample work. |
| Shared view rendering and public route boundary | `php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseReviewerRenderTest.php tests/Feature/EnterpriseReviewerPublicTest.php tests/Feature/LeesMcRaeCampusDemoTest.php --compact` | Actual shared Blade/workflow views render explicit sample presentation without account authority or operational SQL; public controller host, query, session and response boundaries remain covered. |
| Controlled trial pages | `php vendor/bin/pest -c phpunit.enterprise.xml tests/Feature/Enterprise/EnterpriseTrialPagesTest.php --compact` | Dedicated runtime, canonical origin, real browser CSRF enforcement, limited capability pages and expired/revoked access. Provider requests are blocked. |
| Reviewer tour | `node scripts/everbranch-team/enterprise-reviewer-tour-browser.mjs` | Tracked tour markup, accessible controls, responsive sample email and viewport behavior. |
| Full shared reviewer renderer | `node scripts/everbranch-team/enterprise-reviewer-browser.mjs` | Actual isolated HTTP kernel/controller/CSP plus the shared Enterprise and ReactFlow renderers; synthetic work, roles, resources, requests and meetings. |
| Native operations | `node scripts/everbranch-team/enterprise-browser-smoke.mjs` with `ENTERPRISE_TEST_OPERATIONS=1 ENTERPRISE_OPERATIONS_SCENARIO=all` | Persistent mapped workflow tasks, interruption/retry behavior, Roll Call meeting preview/confirmation/PDF, Resources conflicts and the signed-in tour opening current authorized records without replay writes. |
| Priority overview and guided exploration | `node scripts/everbranch-team/enterprise-priorities-browser.mjs` | Actual shared popup, icons, source/status labels, current priority, keyboard/focus/dismissal, persistent top controls and actual-screen spotlight. |
| Public procedural screens | `node scripts/everbranch-team/enterprise-public-procedures-browser.mjs` | Same report/certificate/Finance interfaces using only explicit fictional sample transport; no SQL, cookies, operational API or provider calls. |
| Persistent Finance packet | `enterprise-finance-browser.mjs` inside authenticated `all` or bounded `finance` scenario | Retained unsent form, exact interrupted save/reload/replay, single record, assigned review/reopen/history and inert hostile text. No payment is executed or inferred. |
| Existing-member read-only preview | `EnterpriseRolePreviewTest` in the independent runtime suite; `enterprise-role-preview-browser.mjs` in `all` or `roles` | Fresh owner/admin and selected existing-member intersection, real viewer roster, bounded/private/tenant denials, no identity/grant/content/provider writes, stale-response and role-loss clearing. |
| Map and navigation | `enterprise-shell-navigation.test.mjs`; `enterprise-navigation-browser.mjs` in `all` or `navigation` | Same authorized search/filter result for list and markers; actual Back/Forward workspace/view/selection and retained unsent note/reload. |
| Executive sample | `enterprise-executive.test.mjs`, sample contract tests and `enterprise-executive-browser.mjs` within priorities browser | Trusted default persona, configured simulated workspace stages, grounded drilldowns, separate uncommitted software terms, mutation rejection, saved branch and removal on role/scope changes. |
| Contrast | `enterprise-theme.test.mjs` | Numeric normal-text and focus contrast against actual light/dark/alpha backgrounds; trusted sample-only college palette, unchanged shared Everbranch identity. |

The signed-in tour helper `enterprise-private-tour-browser.mjs` runs inside the
native operations `all` scenario. It creates fictional projects and requests
through real HTTP, verifies triage/assignment/progress and workflow save/reload,
and checks retained drafts, current identity, authorized links and zero tour POSTs.

Both reviewer browser scripts and the native operations harness run sequentially
in Chromium and WebKit. Browser steps share the already installed Playwright
browsers. Each receives `EVERBRANCH_PLAYWRIGHT_MODULE=node_modules/playwright/index.mjs`,
`ENTERPRISE_BROWSER_ENGINE=chromium|webkit`, and a distinct `ENTERPRISE_EVIDENCE`
directory. HTTP harnesses also receive `ENTERPRISE_TEST_PHP=php`.

The shared reviewer fixture uses loopback port 8891 and rejects operational SQL.
The authenticated harness uses a disposable SQLite database and loopback ports
8878/8879; scenario helpers run sequentially against its synthetic fixture.
Keep these harnesses sequential within a job because their ports are fixed.
The original private browser pass remains a separate gate with new Operations
flags disabled, protecting the previous rollout behavior.

Screenshots, current sample PDFs and diagnostic output under
`test-results/enterprise` are retained by the existing always-run artifact upload
for seven days. CI path filters include Enterprise source/views/assets, location
data, public campus controller/routes, browser helpers and focused Node tests.

These are source acceptance gates. They do not establish physical iPad hardware
acceptance, real provider configuration or delivery, a production release, college
system connectivity, billing activation, or permission to merge or deploy.

The independent suite also contains EnterpriseTrialBudgetMySqlConcurrencyTest.
Four real disposable MySQL connections compete across SMS/email/AI/acceptance
for the final shared allowance, assert named-lock ownership and retain uncertain
reservations. SQLite explicitly skips this gate. Never point the fixture at a
customer database; its guard requires local everbranch_enterprise_ci.
