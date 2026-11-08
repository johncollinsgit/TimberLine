# Teams event details and first-time setup

Status: November 8, 2026 source candidate. Protected release and live browser
acceptance are pending. Scanner installation and acceptance are underway; the
local client-contract tests are not proof of a working production scanner.

## Event workspace

The main event dialog uses the shared Teams shell and operations APIs. Its wider
card layout presents dates, venue, assigned team members, permitted current and
previous-year sales, hotel and coordinator information. Maps, phone and email
links are constructed from allowed values. They do not fetch arbitrary URLs or
send messages. Technical IDs, hashes and source versions remain in backend
receipts and integration tools rather than the tenant's event-detail content.

`EventEssentials` projects this information after current tenant, work-area and
source admission. It reuses `OperationsWorkspace`, `EventSchedule`,
`EventIntelligence` and `EventCommerceReport`. Previous-year sales require a
reviewed series/occurrence mapping; current sales require the reviewed Pay event
link. Workbook and Pay totals are separate bases and are never added together.
Financial permissions remain owner/admin permissions. Missing mappings or source
coverage produce unknown/review states, not invented zero sales or name matches.

Unique explicit note candidates can appear as provisional event facts without
silently approving them. Current audited approvals remain distinct. Conflicting
candidates and stale approvals require review. Hotel and coordinator labels reuse
the existing note parser and approval receipt flow; original notes are retained.

The shared routes are `GET /team/events/{id}/essentials` and
`POST /team/events/{id}/travel`, with corresponding authorized mobile APIs.
Responses are private and must not be cached.

## Travel estimates and saved event inputs

There is no enabled directions/traffic provider in this implementation. A user
opens the Maps directions link and reviews the driving minutes. The owner/admin
travel form saves a home-base address and timezone, separate outbound/return
minutes and an audited event revision. It never estimates road speed from straight
line distance or treats mileage reimbursement rates as journey duration.

Home base resolves from the work-area override, then explicitly configured
`TenantAccessProfile.metadata.teams.home_base`. A departure recommendation uses
the first recorded setup session with an opening hour, or the first selling
session when there is no such setup time. Return uses the final recorded selling
close, saved teardown minutes and the reviewed return drive. Unknown final days,
missing hours and unconfirmed dates cannot establish a return time. Date-only
source evidence does not claim that hours have been provider-verified.

New setup defaults are 60 minutes of estimated teardown and an explicit zero
arrival buffer. Companies can edit these defaults. A new, explicitly reviewed
travel command snapshots both defaults on that event. Existing estimates read
their own saved inputs; changing company defaults never recalculates them. Legacy
travel snapshots without these fields retain 60/0. Frozen budgets and payroll are
unaffected. A later route review creates a new event revision using then-current
reviewed inputs.

Travel arithmetic uses real elapsed minutes through UTC and renders in the event
and home-base timezones. Nonexistent or ambiguous local hours remain unknown.
Origin, destination and source-version changes retire a reviewed route.
`OperationsAccess.admittedAsanaVersion` captures the version from the existing
fresh exact-source admission; it does not cache an authorization decision or skip
a provider check. Live source changes before reconciliation retire displayed
ETAs and reject a new route review with 409. The application does not approve or
replace source notes to make an estimate available.

## First-time company setup

`TeamsSetup` is a minimal owner/admin workflow over existing tenant metadata,
`TeamArea` settings, Studio definitions and operation receipts. Native companies
can create events without an Asana account. Connections are optional; selecting
Asana only exposes the next connection step. Existing Modern Forestry source
areas retain Asana authority. Setup never enables cutover or historical migration.

The UI steps collect home base/timezone, a new or existing work area, selected
current team members, optional event defaults and an optional cost-policy draft.
Member selection supplies future planning defaults. It does not invite people,
grant work-area access, change Asana assignments or send email.

- `GET /team/setup` returns status, current defaults and permitted choices.
- `POST /team/setup/draft` accepts an exact command UUID, expected setup version,
  step and partial values. It preserves other steps and encrypts the draft in
  `TenantAccessProfile.metadata.teams.setup`. Incomplete essential text can be
  saved; finishing validates it. Exact current retries replay; older versions
  conflict instead of overwriting a newer owner edit.
- `POST /team/setup/finish` validates the saved draft and current verified member
  choices. A selected existing area includes its expected version. The command
  locks tenant/profile/area state, merges only operational Teams keys into freshly
  loaded metadata and records a durable `teams.setup` receipt. Exact interrupted
  retries return the original area and policy rather than making another copy.

Existing companies can complete setup without blocking their current work.
Company and work-area defaults remain editable. Billing, entitlements, unrelated
metadata, connected provider configuration and existing event/budget snapshots
are preserved.

Optional rates create an editable, unpublished Studio `cost_policy`; missing
rates remain missing. The Modern Forestry criteria template is available only
when configured for that tenant and retains its owner-review requirements. Other
companies can start with a blank policy and choose their own currency/date/rates.
Setup does not publish a policy, calculate payroll or move money. Current
`rate_minor` fields store integer minor units; the wizard rejects unsupported
fractional-cent mileage rates rather than silently rounding them.

## Updates, files and recovery

The sticky update composer accepts text, file selection, pasted screenshots and
dropped photos/files. Selection detection does not prove that bytes were stored
or scanned. Existing chunk identities, quotas, SHA checks, MIME checks and scanner
admission remain authoritative. Supported files are JPEG, PNG, PDF and UTF-8 text;
inline previews are restricted to verified JPEG/PNG bytes.

Event files reuse `TeamFileService` with an explicit `target_type=event`, event
UUID and a non-task marker. A graph task with the same UUID cannot access them.
Task uploads retain their original target and behavior. The additive
`2026_11_08_190000_add_team_file_event_targets.php` migration preserves legacy task
rows and supports durable partial-DDL retry. Preview/download routes re-admit the
current event/source, require a ready scanned file and verify stored integrity.
Quarantined or unavailable scans never become downloadable or postable files.
See [Teams scanner setup](teams-clamav-scanner.md) and
[scanner/environment gates](everbranch-team-scanner-and-environment-gates.md).

Native updates use the existing encrypted operation receipt store without
creating a duplicate graph task. Successfully posted native receipts have a
stable posting-time cursor, bound to the current tenant, actor and event. Native
finalization serializes with event/area authority changes and withdrawal.

Source updates use the existing per-user Asana command service and supported
comment scopes. The exact child command, body and file associations are frozen.
An unknown or executing post is never automatically sent again. A parent receipt
projects its actual child state if the child claim/result committed first.
Current edited/deleted Asana comments take precedence over the original immutable
receipt; old text/files are not resurrected in the visible feed.

Explicit unsent review is allowed only when the parent/child admission proves the
source post was never claimed. The exact child identity is retired with a durable
tombstone under the shared source lock and database row checks. Claimed or
uncertain children remain source-review cases, never a false 'not sent' result.
Parent/child claim guards also hold when cache locks are process-local. Safe
review retains the draft and files for an intentional new update; original audit
receipts remain intact.

## Acceptance evidence and release

Local actual MySQL 8.4.11 acceptance on a separately created `codex_teams_` database
passed **225 tests / 1,835 assertions**. The guarded Studio script passed four
creation/budget races; setup passed three finish/draft races. The update script
passed three real two-process cases with independent array-cache locks and a
strict local fake provider: review before preparation produced zero POSTs, while
review/duplicate retry after a claim produced exactly one POST. These are local
concurrency proofs, not real Asana-post or production scanner evidence.

Persistent report:
`outputs/teams-event-detail-mysql-validation-20261108.json` in the active task's
artifact workspace. The disposable database was removed after evidence capture;
no production database or provider data was used.

Repeat the guarded MySQL suite with `phpunit.team-studio-mysql.xml`, then:

```sh
php scripts/everbranch-team/studio-mysql-concurrency.php
php scripts/everbranch-team/setup-mysql-concurrency.php
php scripts/everbranch-team/event-updates-mysql-concurrency.php
```

These scripts require an already migrated, explicitly disposable local
`codex_teams_` MySQL database and `TEAM_WORK_DISPOSABLE_MYSQL=1`; they never create
or reset a database themselves. Normal CI also runs the authenticated Teams
browser checks and 11 Python scanner-client/operator-script tests plus Bash
syntax validation. Those scanner tests mock the operational protocol; only real
production acceptance can establish ClamAV readiness.

Before claiming this candidate live, complete protected GitHub/Forge checks,
exact-release `/ready`, production scanner acceptance and actual tenant browser
verification. Preserve Asana authority, unknown outcomes, drafts and file evidence
when any part of that acceptance fails.
