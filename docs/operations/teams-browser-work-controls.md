# Browser Teams work controls

The `/teams` browser workspace keeps layout choices together, places labeled
search/project/source fields on aligned rows, and separates saved views from the
primary work filters. All controls in this toolbar use at least 44px touch targets.
On phones, the header has one title and Create action. Workspace switching,
Templates, New project, saved views and connection controls live in a keyboard-accessible
disclosure; search and the current source remain visible. Work areas have their
own compact disclosure inside the original Operations scope. Existing control IDs,
commands, tenant boundaries and source permissions are retained.

The task Calendar shows task due dates in UTC. It is separate from the Events
calendar, whose recorded setup/selling sessions and source date anchors have
different meanings. An Event calendar shortcut is available only through a
currently authorized Events area. Opening it neither connects an account nor
verifies unrecorded dates. Source-only due markers are neutral/dashed and explicitly
labeled as unconfirmed task dates, rather than being painted as selling sessions.

Selected months and one-to-three-day event views issue fresh tenant/area-scoped
window queries. The local encrypted-record scan is bounded to 200 rows and 8 seconds
per request, with a scope-bound continuation and 30 authorized records per page.
Explicit Load more retains each fetched page in full and stops appending once
at least 90 records are loaded (at most 119 with 30-record pages). Continue batch
moves to the next bounded batch without dropping the last page's excess rows.
Reviewed sessions are checked independently of legacy range
bounds, while source due anchors remain separate. Unknown and incomplete source
coverage stays partial; no view claims to contain all source history.

Load more rechecks at most eight current-user selected Asana projects, with an
8-second elapsed budget checked between requests. An in-flight request retains
the existing 25-second GET timeout or 20-second credential-refresh timeout; the
elapsed budget does not cancel an active request. A failure or project cap clears
retained pages. Individual task access is checked when its row loads or opens,
and on refresh; retained pages do not promise continuous per-task verification.

Validation uses real Fortify login and Laravel HTTP with a newly migrated,
guarded private SQLite fixture. It includes Chromium 320/390/430/820/1280/1440 and
WebKit 320/390/1440, control sizes, field alignment, responsive overflow, search,
filters, calendar switching, saved-view dialogs and separation from area controls.
External browser traffic is blocked. Fixtures contain only fictional data.

```sh
npx vite build
TEAM_TOOLBAR_EVIDENCE=/absolute/private/output node tests/e2e/team-browser-toolbar.browser.mjs
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DATABASE_URL= \
  php -d memory_limit=768M vendor/bin/pest --configuration=phpunit.team.xml \
  tests/Feature/Team/TeamAsanaTest.php --compact
```

The scoped CSS is imported by the existing `resources/js/team/app.js` Vite entry.
Production's normal frontend build generates the manifest and hashed assets;
`public/build` remains ignored and is not a source commit. No mobile package,
schema, permission, provider, financial or activation change is required by the
toolbar styling itself. Calendar retrieval behavior is separately covered by its
server/window and authenticated browser tests.

The event calendar uses compact rounded day cells and event cards while keeping
44px interactive targets. A legacy range with no dated sessions is represented by
one dashed, explicitly unconfirmed marker at the recorded range start. An Asana
due date remains a separately labelled task anchor when it differs from that
start; identical anchors are deduplicated. Neither marker paints intervening dates
or confirms a selling, setup, travel or teardown day. Source coverage and
continuations remain explicit.
