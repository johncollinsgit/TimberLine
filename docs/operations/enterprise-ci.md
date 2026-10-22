# Enterprise source verification

`.github/workflows/enterprise-runtime-ci.yml` preserves the isolated schema,
MySQL 8.4 recovery, runtime composition, Enterprise build, and existing private
Chromium/WebKit checks. It also runs these focused reviewer-operations gates:

| Gate | Entry point | Boundary exercised |
| --- | --- | --- |
| Transport and sample contracts | `node --test tests/e2e/enterprise-transport.test.mjs tests/e2e/enterprise-reviewer-sample.test.mjs` | Private identity/CSRF and replay contracts; isolated synthetic transport, roles and sample work. |
| Shared view rendering and public route boundary | `php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseReviewerRenderTest.php tests/Feature/EnterpriseReviewerPublicTest.php tests/Feature/LeesMcRaeCampusDemoTest.php --compact` | Actual shared Blade/workflow views render explicit sample presentation without account authority or operational SQL; public controller host, query, session and response boundaries remain covered. |
| Controlled trial pages | `php vendor/bin/pest -c phpunit.enterprise.xml tests/Feature/Enterprise/EnterpriseTrialPagesTest.php --compact` | Dedicated runtime, canonical origin, real browser CSRF enforcement, limited capability pages and expired/revoked access. Provider requests are blocked. |
| Reviewer tour | `node scripts/everbranch-team/enterprise-reviewer-tour-browser.mjs` | Tracked tour markup, accessible controls, responsive sample email and viewport behavior. |
| Full shared reviewer renderer | `node scripts/everbranch-team/enterprise-reviewer-browser.mjs` | Actual isolated HTTP kernel/controller/CSP plus the shared Enterprise and ReactFlow renderers; synthetic work, roles, resources, requests and meetings. |
| Native operations | `node scripts/everbranch-team/enterprise-browser-smoke.mjs` with `ENTERPRISE_TEST_OPERATIONS=1 ENTERPRISE_OPERATIONS_SCENARIO=all` | Persistent mapped workflow tasks, interruption/retry behavior, Roll Call meeting preview/confirmation/PDF and Resources asset/reservation conflicts. |

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
