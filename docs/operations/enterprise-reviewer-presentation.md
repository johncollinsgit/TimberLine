# Shared Enterprise reviewer presentation

## Signed-in demonstration tour

The private workspace may display the same six-scene tour only when
`enterprise.runtime === true`, `enterprise.tour_enabled`, and
`enterprise.sample_data` are enabled by trusted server configuration. The tour
flag defaults off. Its label is **Server-backed demonstration workspace**.
This wrapper never installs the public sample adapter, sample perspectives,
sample reset, sample email CTA or trial navigation. Its records remain in the
separate Enterprise database and existing server authorization governs actions.

`workspace-tour.js` consumes the shell's existing `enterprise:render` and
`enterprise:tour-context` snapshots. It makes no requests or writes. Current
record selection is validated against the loaded permission-filtered arrays;
the selected authorized project opens the existing full workflow route. Missing
records, feature restrictions, access reset and unavailable workspace context
disable links explicitly. Destination routes recheck access. Same-screen
navigation uses the existing selection and composer-draft lifecycle.

The original opening and active-playback timing remain shared. Private scene
cards expose the current identity, workspace and authorized sources, with links
to Requests/Projects, Buildings/Calendar, People/Assets and Roll Call when
available. Procedure support for owners, next actions, status, checkpoints,
location and closure evidence is distinguished from future discovery. Current
Roll Call/PDF is a dated snapshot; quarterly history/templates and automation,
a dedicated insurance-expiry registry/alerts and an accounts-payable/finance
ledger are not represented as completed features. The tour does not attribute
brainstormed solutions to a customer or run them automatically.

Private-wrapper checks: `node --test tests/e2e/enterprise-workspace-tour.test.mjs`
and `EnterpriseReviewerRenderTest.php`. Actual authenticated persistence and
touch-viewport acceptance use the separate native HTTP Operations browser
fixture, rather than the public sample browser transport.

Source candidate, October 22, 2026. The reviewer controller supplies a trusted
`runtime: sample` presentation to the **existing Enterprise shell and workflow
workspace**. It does not authenticate a real institution or choose a tenant.
The real HTTP transport remains available only for the private runtime. Missing
or incomplete sample adapters fail closed; there is no HTTP fallback.

The sample adapter lives in `resources/js/enterprise/reviewer/sample-adapter.js`.
Its canonical-shaped requests, tasks, linked handoffs, checklists, resources,
meeting notes and workflow runs are fictional browser records. Namespaced
sessionStorage belongs to that tab; reset requires an explicit action. Records,
notes, retained operations and drafts are not a server backup or cross-device
trial record. Perspective filtering demonstrates the intended experience; all
public sample content is inspectable and it is **not server authorization**.

The source-backed 68-location catalog is injected by the controller. No
coordinates, campus-area ownership or inventory is invented. The PA system,
event, employee and contractor entries are explicitly fictional examples.
Sample reservations show conflicts without automatically rescheduling work.
Task completion is separate from contractor quality, with 3.9 awaiting manager
verification and 4.0 only after explicit approval. The sample workflow maps
assignment and approval to the same browser task/checklist; real execution uses
the independent permission-controlled server engine.

Navigation adapters keep public context in URL fragments, never tenant query
parameters or contact details. They map only trusted public presentation routes;
neither URL nor storage can change the runtime mode. Workflow canvas, forms,
activation review and run-history presentation are the existing React Flow UI.
There is no public operational API, mailbox grant, provider dispatch or paid AI
call. File selection rehearses bounded chunks and quarantine using sample
metadata; it does not prove server storage, scanning or download acceptance.
Canonical task evidence reuses the same task for assigned-request and task
discussions. Caption edits are uploader-only, versioned sample metadata with
exact retries; another sample perspective cannot edit them. Notes and captions
render as literal text, and captioning never changes file bytes or quarantine.

## Tour and email preview

`reviewer-tour.blade.php` and `reviewer/tour.js` provide six connected scenes,
30 seconds each, excluding pauses. Play/pause, previous/next, scene selection,
skip and restart work with keyboard and touch. Hidden tabs pause playback.
Reduced-motion preference starts paused and removes animated transitions.
Scene changes alter presentation only. Replaying does not mutate sample work,
make a provider request, send a message or reserve spending.

The nested native dialog shows a responsive Lees-McRae email explicitly labeled
**Email preview · not sent**. Its official wordmark is embedded from the locally
retained public original, with source and checksum recorded beside the asset.
No font, image, tracker or analytics request is made. Escape/close returns focus
to the preview trigger; closing the tour returns focus to its launcher. The CTA
opens the browser's fictional sample request. It is not a bearer trial link.
The separate controlled trial's verified service sender, approved recipient,
exact preview and shared $5 budget are independent of this presentation.

### Approved Try-it-out destination

Public tour navigation is independently default-disabled. In the **retail
presentation environment**, an authorized operator must approve the exact
destination and configure both `ENTERPRISE_REVIEWER_TRIAL_DESTINATION` (empty
by default) and `ENTERPRISE_REVIEWER_TRIAL_DESTINATION_APPROVED=true` (false by
default). This work does not set either value. For the proposed full mount the
reviewable destination is exactly
`https://app.theeverbranch.com/enterprise-campus/enterprise/trial`; the separately
supported root trial-only destination is
`https://app.theeverbranch.com/enterprise/trial`.

The public renderer supplies `routes.trial` only when explicit boolean approval
and a canonical HTTPS same-origin URL are both present. The exact port must
match, and the path must be `/enterprise/trial` or a clean explicitly configured
prefix followed by `/enterprise/trial`. Credentials, query/fragment, encoded
characters, double slashes, traversal and alternate origins are refused; a
request query cannot override configuration. Missing/invalid approval keeps
the tour's connection-pending state. The existing sample adapter retains its
same-origin navigation boundary. Link rendering performs no operational SQL,
session, identity, trial-store or provider resolution, and public CSP stays
unchanged.

Approving this destination publishes only a navigation entry. It does not
authorize hosting changes, an invitation/recipient, credentials, Gmail consent,
provider activation, paid acceptance or sending. The controlled runtime must
independently enforce its own approval, CSRF, synthetic storage and lifetime
budget gates after navigation. Never place a reviewer code, address or token
in this public destination.

Sample PDF links create fresh single-page Letter snapshots from current browser
priorities and meeting notes. They label sample scope, generation time and
overflow counts, and link to the complete sample view. They are not continuously
updated documents. The text PDF is untagged; equivalent HTML remains available.
Requester perspective cannot export internal operations or meeting content.

## Verification

```sh
node --test tests/e2e/enterprise-transport.test.mjs tests/e2e/enterprise-reviewer-sample.test.mjs
php vendor/bin/pest tests/Feature/Enterprise/EnterpriseReviewerRenderTest.php --compact
EVERBRANCH_PLAYWRIGHT_MODULE=node_modules/playwright/index.mjs ENTERPRISE_BROWSER_ENGINE=chromium node scripts/everbranch-team/enterprise-reviewer-tour-browser.mjs
ENTERPRISE_TEST_PHP=php EVERBRANCH_PLAYWRIGHT_MODULE=node_modules/playwright/index.mjs ENTERPRISE_BROWSER_ENGINE=chromium node scripts/everbranch-team/enterprise-reviewer-browser.mjs
```

Repeat browser scripts with `ENTERPRISE_BROWSER_ENGINE=webkit`. The standalone
tour check renders the tracked tour markup and JS. The shared-renderer check
serves actual HTTP kernel/controller/CSP responses from the **isolated Enterprise
test bootstrap** on loopback port 8891, composing only a no-op Livewire asset flush
because the retail provider is deliberately absent. No retail `.env`, production
data or operational SQL is used. Production retail route middleware is verified
separately by the public controller feature tests.

The shared browser harness exercises same-task checklist and workflow approval,
student intake and explicit public updates, escaped notes and quarantined upload
metadata, all 68 catalog entries, asset custody, overlapping reservations, and
meeting save/preview/confirmation/PDF. Its CSP assertions run after all functional
and viewport checks and before separate screenshot capture. Playwright's WebKit
screenshotter injects a nonce-less `body {}` style to synchronize animations;
that test-runner operation raises CSP events. The application policy is kept
strict, and those screenshots are evidence capture rather than an application
CSP acceptance check. The workflow document's earlier evidence capture precedes
navigation back to the separately checked main document.

Desktop, phone and tablet viewport/touch simulation does not establish physical
iPad Photos picker, software-keyboard, camera or multi-touch acceptance. Neither
fake provider tests nor sample UI tests establish live SMS/email delivery, real
AI acceptance, production deployment or college-system connectivity. Review and
the protected GitHub-to-Forge release process remain required before publishing.

Tour publication has no real email/SMS/AI delivery dependency. Its email is an
in-app preview only. A reviewed source release may publish that isolated tour
while the separate controlled trial stays disconnected and disabled; normal
review and protected Forge gates still apply under the owner's adopted plan.
