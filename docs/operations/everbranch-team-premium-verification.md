# Premium Teams local verification and rollout

Date: 2026-10-19. Local G: candidate only. No production deployment or real Asana import.

## Enablement

Keep `TEAM_WORK_PREMIUM_ENABLED=false` and `TEAM_WORK_ASANA_ENABLED=false` by default.
The existing `TEAM_WORK_ENABLED`, tenant allowlist, entitlement and membership gates
still apply. Run the additive connection migration through the established migration
safety workflow before enabling either new feature. Build the Vite entry normally.
Turn on premium independently; turn on Asana only after registering the OAuth app and
setting the dedicated client ID/secret. Every user must connect their own account and
choose projects. The Codex Asana plugin is a research tool, not a runtime credential
bridge into Laravel. Its availability does not establish an application connection.

Rollback disables the two new flags and returns to the retained public-script UI.
It preserves native work, personal preferences and encrypted connections. Do not
roll back the database by deleting retained user records.

## Evidence

- `php -d memory_limit=1G vendor/bin/pest --configuration=phpunit.team.xml --compact`:
  162 tests / 1,779 assertions pass, including 13 new premium/provider tests.
- Provider cases include separate users, encryption/no-secret responses, selected
  project authorization, unselected/private task refusal, unrelated membership
  filtering, attachment access, scoped discovery, cursor binding, selection identity
  rotation, token refresh, reconnect persistence, throttling, disconnect isolation,
  tenant/session/header fences and one-time same-session OAuth.
- Native favorites/views tests check per-user isolation and inaccessible project
  refusal. Both standalone and embedded HTML retain one set of shared controls;
  independent premium rollout and the legacy fallback are covered.
- `npm run build` passes the existing TypeScript check, 11 Trajectory tests and Vite
  production compilation. `npm run test:team-work-local` passes 60 Node tests and
  47 shared-graph checks. Migration safety lint passes all five changed migrations
  against the current candidate base plus the working tree.
- In-app browser review used the real local Laravel app and a newly created,
  marked fictional SQLite database. Checked project navigation, list/board/calendar,
  detail tabs, retained unsent text across navigation attempts, canonical save,
  inline assignment, selected task completion, saved-view restoration, timeline,
  workload/dashboard and phone presentation. Source fixture review checked selected
  Asana navigation, read-only fields/subtasks/comments and absence of edit controls.
  Desktop, 390px phone and tablet checks are captured in the task outputs/evidence.

The default PHP 128MB limit exhausted memory while loading the full suite. The
successful run used 1GB without weakening any assertions. MySQL is not available on
this host. The added durable-partial-DDL recovery scenario is registered in the
existing guarded MySQL recovery suite; MySQL recovery/schema-baseline and Linux CI
are still rollout requirements, not locally verified results.

## Reproducible fictional preview

`prepare-premium-fixture.php` inherits refusal of existing databases and only creates
a new checkout-bound `team-fixture-<uuid>.sqlite`. It adds clearly fictional design
content. `premium-fixture-router.php` is an optional CLI loopback acceptance router
outside `public/`; it requires a marked disposable checkout database and blocks
stray provider requests. It is not registered by the application and must never be
used as a production entry point. A seeded source connection in that fixture contains
only fictional tokens. It does not prove live OAuth acceptance.

## Release limits

### Hands-on UI audit follow-up (2026-10-19 America/New_York)

The local fictional preview was exercised through the in-app browser as owner and
viewer, in both Teams surfaces, at 1440x1000, 1024x768 and 390x844. No production or
real Asana records were changed. The audit fixed these defects and retested them:

- Creating a project from Home retained the personal mode/date filters; new projects
  now enter a clean project context. Sidebar project navigation also resets filters.
- Reopened editors retained an obsolete `Saving…` notice; opening clears that notice.
- Mobile navigation allowed focus behind the panel and its closed offscreen controls
  remained reachable; it now uses inert background/closed navigation, keyboard
  containment, Escape/focus restoration and a dismissible backdrop.
- Restricted viewers saw assigned tasks labeled `Unassigned` when directory names
  were unavailable; they now see `Assigned member` without exposing directory data.
- The embedded host header covered the drawer close button. The drawer and resize
  separator now follow the measured host topbar inset; tablet and phone close tested.
- Active narrowing filters now have a visible count and reset action. Removing a
  saved view is disabled until an actual saved view is selected.

Manual workflows included required/invalid-date validation, section/number-field
creation with zero retention, assigned dated tasks, multiline edits, inherited
subtasks, draft close/resume and navigation guards, comment create/edit, activity,
quarantined attachment upload, dependency search/add, board section moves via select,
calendar month/unscheduled work, timeline/workload/dashboard, saved views, reviewed
project template copies, project-status drafts/posting, reviewed follow-ups, daily
recurrence completion/next date, completed/reopen, bulk completion, priority/source
filters, search, archive/reopen, Ctrl+K, drawer keyboard resize and task deep links
between surfaces. Source fixture checks included picker search, selection removal and
restoration, read-only detail/subtask/custom field/conversation and phone navigation.
Viewer creation/edit/follow-up restrictions and cross-tenant/user source isolation
were observed. Inbox was checked in its empty state; notification delivery was not
generated. Archive picker controls were clicked, but the source fixture does not
contain a distinct archived catalog.

Regression run: 162 Laravel tests / 1779 assertions, 60 Node tests, 47 graph checks,
and production build (including TypeScript and 11 Trajectory tests) passed. MySQL and
Linux checks, real OAuth, live throttling/revocation, interrupted-save browser fault
injection, real scanner release, large-project UX and assisted screen-reader/physical
device testing remain distinct acceptance work. Pointer drag/drop was not exercised;
the authorized keyboard section-move path was. Ownership/security changes and
permanent deletion were not performed. This is local audit evidence, not a claim
that all 643 clauses or every error path has been human tested.

The Laravel runtime needs dedicated OAuth configuration and a real user connection
before live source reads can be accepted end to end. Source search/planning/boards
describe loaded pages; a complete production indexing or analytics system is not
claimed. Screen-reader semantics and keyboard paths were inspected; a full assisted
technology/device audit, native-device tests and offline recovery remain open.
Inherited scanner, MySQL/Linux, feature entitlement and protected CI/Forge gates stay
in force. The later planning/collaboration/operations backlog remains uncompleted.
