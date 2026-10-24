# Enterprise source verification

## Isolated parallel verification (performance candidate)

The workflow keeps the required check named `enterprise-runtime`. It is an
always-run aggregate: the dependency/contracts/build producer, native PHP/MySQL
job and **every** browser matrix entry must succeed. Failure, cancellation or a
skipped prerequisite fails the aggregate. No branch-protection change or deploy
gate bypass is part of this change; the protected GitHub-to-Forge procedure and
the independent primary CI/migration gates remain authoritative.

The producer installs locked dependencies once, runs all existing Node contracts
and compiles Enterprise. Its run-scoped archive contains only `vendor/`,
`node_modules/` and `apps/enterprise/public/build/`. Restore verifies exact checked
out commit, run ID, lockfile hashes, OS/architecture, PHP minor, Node major and
archive checksum; it rejects an existing input root, unsafe archive paths and
escaping/traversed links. Environment/auth files are excluded. Database files,
storage, uploads, sessions, keys, caches and prior browser evidence are never
shared. This is disposable **CI input reuse**, not a deployable production
artifact or permission to copy CI files into Forge.

After preparation, the native job retains the original MySQL 8.4 service,
loopback-only disposable database guard and complete PHP commands, including all
original five real MySQL recovery/concurrency/provisioning classes, plus the
publication-bound shared-workspace concurrency gate. In parallel, thirteen
browser entries use independent GitHub-hosted runners/filesystems:

| Entries | Existing checks retained |
| --- | --- |
| Operations work × Chromium/WebKit | Mapped workflow, Roll Call, work views, resources, task evidence, in original order. |
| Operations tour × Chromium/WebKit | Full signed-in tour. |
| Operations workspace × Chromium/WebKit | Procedures, Finance, navigation, role preview and voice shell, in original order. Procedures must precede roles to supply populated shared-work evidence. |
| Native × Chromium/WebKit | Original default-off persistent UI, jobs wizard, generated voice with provider-only fake. |
| Proposal × Chromium/WebKit | Persisted proposal review, activity, Financial planning; mounted shared-workspace core/directory and separately fake-provider shared-AI checks, sequentially. |
| Reviewer × Chromium/WebKit | Tour, shared renderer, priorities, public procedures. |
| Components (one entry) | Assistant, conversation, Blaine sample and voice-job scripts, each retaining its own internal two-engine loop. |

Operations groups select helper calls only: `ENTERPRISE_OPERATIONS_SCENARIO=all`
and the complete feature profile, fresh migration/fixture/login, 800ms dynamic
request queue, child-context pacing, zero-error/external-request assertions and
cleanup remain intact. `ENTERPRISE_OPERATIONS_GROUP=work|tour|workspace` is valid
only with enabled `all`; unknown/misapplied groups fail before fixture creation.
No-group `all` remains available for a continuous accumulated-state manual run.
The matrix preserves each explicit helper case/configuration, with independent
fixtures between groups; it does not claim identical accumulated state across
all eleven helpers. `jobs` and `voice-generation` remain separate required gates.

Never parallelize fixed-port harnesses in one checkout: independent SQLite names
or ports alone do not isolate file caches, sessions, quarantine storage or login
rate limits. Each matrix entry runs its applicable harnesses sequentially.
`fail-fast: false` collects every result; concurrency is bounded to twelve matrix
entries. Original per-step time bounds remain, and both browser engines install
using the existing verified official Ubuntu mirror procedure.

For a failed check, inspect that exact job and retry failed jobs on the **same
frozen head** after understanding the failure. Successful preparation's output
names its immutable input artifact, including its original attempt; a failed-job
rerun can reuse it while retained (seven days). Never use another run/commit's
bundle. If inputs have expired, rerun preparation and all dependents. Do not
cancel a live production deployment to benchmark. A new source change requires
a new frozen head and the normal complete check set.

Record actual Actions run/head/merge SHA, attempt, job queued/start/end and step
start/end timestamps. Compare preparation, artifact transfer/restore, browser
installation, every engine/group, native MySQL and aggregate elapsed time against
the baseline; include runner queue time and increased parallel runner-minutes.
The prior measured Enterprise runs took 46m09s and 46m39s; one Operations step
took 28m17s, while browser installation took 67s. These are baseline evidence,
not a promise for this candidate. Only a completed normal candidate CI run can
establish a new critical path. A prepared browser image is deferred until those
measurements justify its maintenance; Forge's existing atomic build/rollback is
unchanged. Rollback is reverting this workflow/helper change through review and
normal checks, not modifying a live release or required-check settings.

The primary PHP 8.4 and isolated Enterprise workflows verify the known Ubuntu
`mirror+file:/etc/apt/apt-mirrors.txt` indirection before PHP setup and remove only
its exact Azure entry. Official HTTPS archive/security entries, source fields,
keyring references and unrelated repositories remain unchanged. Unsupported
formats fail before modification. This changes the observed slow download path;
it does not cache OS packages or guarantee speed. Preserve every test, browser
engine, timeout and loopback-only MySQL safeguard, and verify the actual source
and result on a fresh runner before declaring the correction accepted.

`.github/workflows/enterprise-runtime-ci.yml` preserves the isolated schema,
MySQL 8.4 recovery, runtime composition, Enterprise build, and existing private
Chromium/WebKit checks. It also runs these focused reviewer-operations gates:

The fictional reviewer sample currently exposes only Coming soon cards for
Chats, Assistants and Voice. Its browser assertions require inactive chat,
microphone and generated-draft controls while preserving manual workspace work.
Native signed-in voice/record-check fixtures keep their separate positive gates.

Departing native pages invalidate their existing workspace and project-catalog
read generations at `beforeunload`, before a replacement document commits. This
stops obsolete batches from starting more reads during provisional navigation.
Cancelling navigation preserves data, pending writes and exact retry receipts;
a deliberate fresh load still works. Keep strict browser-error assertions and
the existing request pacing. Do not suppress access-control errors to pass CI.

The dedicated PHPUnit process uses the same `1G` test memory ceiling as the
primary `phpunit.xml`. This accommodates the complete combined suite, including
local PDF rendering; it does not change a production PHP limit. Fresh isolated
12-page owner/recipient PDF checks also pass at 128M. Do not replace the full
suite with those focused checks or bypass its MySQL gates.

| Gate | Entry point | Boundary exercised |
| --- | --- | --- |
| Transport and sample contracts | `node --test tests/e2e/enterprise-transport.test.mjs tests/e2e/enterprise-reviewer-sample.test.mjs tests/e2e/enterprise-workspace-tour.test.mjs` | Private identity/CSRF and replay contracts; isolated synthetic transport, roles and sample work. |
| Voice controls and authorized job lookup | `npm run test:ai-voice` and `php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseVoiceJobsTest.php --compact --display-warnings` | Fake speech lifecycle, bounded sessions, cancellation, ambiguity and current tenant/department/task authorization; no provider dispatch. |
| Voice browser component | `VOICE_WEBKIT=1 node tests/browser/enterprise-voice-jobs.smoke.mjs` | Chromium/WebKit component controls and returned detail/location projection with fake speech and synthetic replies; no acoustic or live model claim. |
| Shared view rendering and public route boundary | `php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseReviewerRenderTest.php tests/Feature/EnterpriseReviewerPublicTest.php tests/Feature/LeesMcRaeCampusDemoTest.php --compact` | Actual shared Blade/workflow views render explicit sample presentation without account authority or operational SQL; public controller host, query, session and response boundaries remain covered. |
| Controlled trial pages | `php vendor/bin/pest -c phpunit.enterprise.xml tests/Feature/Enterprise/EnterpriseTrialPagesTest.php --compact` | Dedicated runtime, canonical origin, real browser CSRF enforcement, limited capability pages and expired/revoked access. Provider requests are blocked. |
| Reviewer tour | `node scripts/everbranch-team/enterprise-reviewer-tour-browser.mjs` | Tracked tour markup, accessible controls, responsive sample email and viewport behavior. |
| Full shared reviewer renderer | `node scripts/everbranch-team/enterprise-reviewer-browser.mjs` | Actual isolated HTTP kernel/controller/CSP plus the shared Enterprise and ReactFlow renderers; synthetic work, roles, resources, requests and meetings. |
| Native operations | `node scripts/everbranch-team/enterprise-browser-smoke.mjs` with `ENTERPRISE_TEST_OPERATIONS=1 ENTERPRISE_OPERATIONS_SCENARIO=all` | Persistent mapped workflow tasks, interruption/retry behavior, Roll Call meeting preview/confirmation/PDF, Resources conflicts and the signed-in tour opening current authorized records without replay writes. |
| Native job editing | `node scripts/everbranch-team/enterprise-browser-smoke.mjs` with `ENTERPRISE_TEST_OPERATIONS=1 ENTERPRISE_OPERATIONS_SCENARIO=jobs` | Real authorized job creation, exact uncertain retry, task editing/removal, queue assignment and reload in a disposable runtime. Runs separately to preserve existing request limits. |
| Blaine browser demonstration | `node scripts/everbranch-team/enterprise-job-sample-browser.mjs` | Additive fictional jobs/people/contractors, New ticket, queue assignment, Roll Call, map/months, Settings and shared detail controls; operational SQL is forbidden. |
| Priority overview and guided exploration | `node scripts/everbranch-team/enterprise-priorities-browser.mjs` | Actual shared popup, icons, source/status labels, current priority, keyboard/focus/dismissal, persistent top controls and actual-screen spotlight. |
| Public procedural screens | `node scripts/everbranch-team/enterprise-public-procedures-browser.mjs` | Same report/certificate/Finance interfaces using only explicit fictional sample transport; no SQL, cookies, operational API or provider calls. |
| Persistent Finance packet | `enterprise-finance-browser.mjs` inside authenticated `all` or bounded `finance` scenario | Retained unsent form, exact interrupted save/reload/replay, single record, assigned review/reopen/history and inert hostile text. No payment is executed or inferred. |
| Existing-member read-only preview | `EnterpriseRolePreviewTest` in the independent runtime suite; `enterprise-role-preview-browser.mjs` in `all` or `roles` | Fresh owner/admin and selected existing-member intersection, real viewer roster, bounded/private/tenant denials, no identity/grant/content/provider writes, stale-response and role-loss clearing. |
| Map and navigation | `enterprise-shell-navigation.test.mjs`; `enterprise-navigation-browser.mjs` in `all` or `navigation` | Same authorized search/filter result for list and markers; actual Back/Forward workspace/view/selection and retained unsent note/reload. |
| Executive sample | `enterprise-executive.test.mjs`, sample contract tests and `enterprise-executive-browser.mjs` within priorities browser | Trusted default persona, configured simulated workspace stages, grounded drilldowns, separate uncommitted software terms, mutation rejection, saved branch and removal on role/scope changes. |
| Contrast | `enterprise-theme.test.mjs` | Numeric normal-text and focus contrast against actual light/dark/alpha backgrounds; trusted sample-only college palette, unchanged shared Everbranch identity. |
| Voice runtime | `EnterpriseVoiceRuntimeTest` in the independent suite; `EnterpriseVoiceJobsTest` in the separate Pest gate | Real mounted CSRF/method/throttle and current private-task/tenant/role denials; inert record text, no provider/business writes. |
| Usage overview | `EnterpriseUsageOverviewTest`; `tests/js/enterprise-usage.test.mjs` | Existing encrypted usage ledger through fake Responses, current financial-role/department filtering and aggregate-only projection; estimate/uncertainty/empty-ledger honesty. |
| Integrated voice and usage shell | `enterprise-voice-shell-browser.mjs` within shared renderer and private `all` / bounded `voice` scenario | Real HTTP records, typed and fake-speech lookup, exact source map navigation, context/late-response/error clearing, preview focus, unavailable-assets/no-model fallback and desktop/phone/iPad viewports. No physical acoustic or provider acceptance. |
| Generated private conversation | `enterprise-voice-shell-scope.test.mjs`, `EnterpriseVoiceAssistantTest`, and authenticated `voice-generation` browser scenario | Actual shell, CSRF, permission rechecks, encrypted session history and existing reservation ledger; only the provider transport and speech devices are fake. Sequential typed/spoken history, exact manual recipient previews, cancellation pinned to old scope, voice usage privacy and phone/tablet layouts. No send or paid provider acceptance. |

The signed-in tour helper `enterprise-private-tour-browser.mjs` runs inside the
native operations `all` scenario. It creates fictional projects and requests
through real HTTP, verifies triage/assignment/progress and workflow save/reload,
and checks retained drafts, current identity, authorized links and zero tour POSTs.
It also creates a long fictional workspace list and verifies an actual sidebar
click after returning from the full-screen builder. The desktop sticky rail
scrolls independently; tablet/phone retain document-flow navigation. The original combined two-engine step had a 45-minute ceiling and measured
partial runs exceeded 23 minutes. Each new Operations entry retains that step
ceiling while preserving real server throttles. The candidate must be measured
before claiming a shorter critical path.

Within each matrix entry, applicable browser scripts run sequentially. The two
engines have separate entries except for the four internally dual-engine scripts.
Each receives `EVERBRANCH_PLAYWRIGHT_MODULE=node_modules/playwright/index.mjs`,
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
