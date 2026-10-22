# Everbranch Enterprise first slice

Working name; source candidate only. The owner approved a separately built
Enterprise application and database using proven Everbranch components. Naming
review, pricing, billing and production launch are not part of this change.

## Application and data boundary

The entry points are `apps/enterprise/public/index.php` and
`apps/enterprise/artisan`, with their own bootstrap, config, environment,
storage, compiled views, sessions, cache and database directory. The public
document root for a future reviewed deployment must be `apps/enterprise/public`,
not the retail root. No host or deployment has been provisioned.

The Enterprise application explicitly registers its own provider and route file.
Composer package discovery, retail providers/routes, provider callbacks,
registration, billing and the shared scheduler/command discovery are excluded.
Mail uses only the array transport; queue dispatch uses the null driver. There
is no notification send/activation path. Existing retail bootstrap, workflows,
customers and provider connections are unchanged.

`apps/enterprise/.env.example` has no credentials. All runtime identity and DB
configuration uses `ENTERPRISE_*`, with no retail fallback. HTTP and migration
commands refuse an unconfigured runtime. Production requires an independent
MySQL connection, app key, explicit isolation acknowledgement and matching HTTPS
application host; sessions are encrypted, secure and host-only. These guards
cannot establish that an operator selected a genuinely separate database:
review actual deployment, storage, database ownership, backups and permissions
before configuration. Do not point Enterprise at retail production resources.

## Schema installation

The default Enterprise migration path includes three own guarded migrations.
Identity and canonical access-record DDL deliberately omit legacy membership
and profile backfills. New users and membership records default inactive;
schema creation grants nobody access. The work installer calls only six
checksum-pinned schema-only existing migrations listed in
`apps/enterprise/database/shared-schema-v1.php`. It never globs or runs the
retail migration tree or preloads its schema dump. No root migration changed.

Each creation/index/constraint is retry guarded. Rollback retains identity,
grants and work evidence; rolling back application code is not authority to
delete those records. Future upgrades need new migrations/manifests rather
than editing released sources. The separate schema lint and disposable MySQL
durable-partial-state test are mandatory release checks. Local SQLite results
do not replace MySQL recovery, backup/restore or deployment acceptance.

## Persisted workflow and roles

Tenant remains the organization/account boundary. Existing `tenant_user` roles
and active verified membership remain authoritative. `TeamArea` profiles
`enterprise_shared` and `enterprise_department` represent inner workspaces.
Shared means visible to existing nonguest organization members; department-only
means explicit existing department membership, plus organization owner/admin
authority. Unknown roles fail closed. Department viewers cannot submit or mutate
work; ordinary organization members can submit to shared intake. Department
editors can submit and progress their assigned work; coordinators triage and
assign. Owners/admins create workspaces and coordinate within their tenant.

Creating a workspace creates no memberships. Assignment requires an existing
active, verified, writable workspace member with project/task access and never
grants access. This slice has no real-account/grant provisioning endpoint.
Fixture memberships are synthetic tests only.

Requests use the existing encrypted `team_operation_records` storage with a
reserved `enterprise_request` kind. Operations' provider/event writer does not
accept that kind. Submission → triage → existing scoped project → new native
task → progress/completion persists transactionally under the existing tenant
lock. Existing task assignment/completion, graph ACLs, optimistic versions,
replay receipts and immutable history are reused. Command receipts and current
action permission are checked again on retry. Stale versions refuse changes.
Request audits store only request-owned content and stable IDs, excluding
historical projections of potentially revoked graph references.

Scope is immutable in project/task payloads and enforced inside the shared
graph, including native reads, search, overview, files, inbox and mobile. Task
scope survives removing its last project. Cross-scope multi-homing is refused;
scoped template copying and recurrence are unavailable in this slice. Turning
Enterprise off never makes retained scoped work visible through ordinary Teams.
Ordinary unscoped Teams behavior retains its existing semantics.

The dedicated route surface exposes selected native read/file endpoints only;
mutation comes through the bounded Enterprise workflow. No imports, connection,
project-member grant, billing or provider mutation routes are composed.

## UI and reference

The actual campus v3 HTML and rendered screenshot were inspected. The Enterprise
shell retains the dark green Everbranch sidebar, cream surface, serif headings
and stylesheet tokens. Request/project List/Calendar/Map navigation and map
selection open permission-controlled details. Hide Map/View Map remain explicit.
Contractor navigation exposes the separately gated planning groundwork below.
No college name, real department, incident, personnel or campus dataset is seeded.

Map markers use authorized saved latitude/longitude on a coordinate grid. There
is no imported college basemap, GPS, live provider, building inventory or survey
claim. The API returns at most 100 visible requests while scanning at most 1000;
permission filtering precedes visible counts and more-results indicators. The UI
labels bounds and incomplete scans. Large workspaces need cursor pagination in
a subsequent increment. Calendar dates remain literal date-only deadlines.

Login reuses Laravel session authentication with no registration or recovery
delivery. Unverified, inactive and MFA-enrolled accounts fail closed. An MFA
challenge flow/enterprise SSO and controlled account provisioning are required
before onboarding customers who need them; matching retail email/IDs do not grant
Enterprise identity or access. No shared sessions or copied users are SSO.

## Acceptance and checks

The first slice requires separate-runtime boot and install, persistent lifecycle,
explicit visibility, stale/replay behavior, tenant/role negatives, no implicit
grants, no external sends, and actual UI persistence/layout checks. Commands:

```sh
php apps/enterprise/scripts/lint-schema.php
php vendor/bin/phpunit -c phpunit.enterprise.xml
php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseWorkflowTest.php tests/Feature/Team --compact
php scripts/everbranch-team/work-graph.test.php
php scripts/ci/lint-migrations.php --base=4753ba59214def09d6f709630d926db57084a716 --working-tree
```

The optional browser harness needs installed PHP, Playwright and Chromium paths
through `ENTERPRISE_TEST_PHP`, `EVERBRANCH_PLAYWRIGHT_MODULE` and optional
`ENTERPRISE_CHROME`. Run `node scripts/everbranch-team/enterprise-browser-smoke.mjs`.
It creates a named disposable testing SQLite fixture, uses a separate headless
process, blocks external requests, and deletes its database afterward. Synthetic
fixture credentials and a fixed test-only key never configure an actual account.
Screenshots/output are local evidence, not customer or deployment records.
The harness uses loopback HTTPS with a one-use self-signed certificate; its
temporary key/certificate files are deleted before browser requests. No system
trust setting changes. This tests the unchanged secure session-cookie contract
in both Chromium and WebKit. OpenSSL is required for that disposable fixture.

The new `.github/workflows/enterprise-runtime-ci.yml` runs the separate runtime,
schema lint and opt-in MySQL 8.4 recovery suite. No deployment job is added.
Required CI results, independent production configuration, MFA/account setup
requirements, backup/restore and release review remain gates to production.

## Next phases

### October 22 collaboration and Ask source increment

See `docs/architecture/enterprise-next-slice.md` for the bounded milestone and
following phases. All four new flags default off: `ENTERPRISE_COLLABORATION_ENABLED`,
`ENTERPRISE_PLANNING_ENABLED`, `ENTERPRISE_ASK_ENABLED` and
`ENTERPRISE_EMAIL_DRAFTS_ENABLED`. Existing rollout, tenant allowlist and write
freeze still apply. No provider, scanner, mailbox, real account or grant is
configured by source changes.

Project detail is larger and includes bottom notes/file composition. The first
explicit project discussion mutation creates one native backing task. Its
immutable `enterprise_discussion_project` marker keeps it out of operational
search/feeds and project progress. It retains the project's native ACL and
department scope. Reads never create a thread. Notes have stable actor/request
receipts; files use the existing private resumable/checksummed upload service.
Supported types are JPEG, PNG, PDF and UTF-8 text up to 10 MB. Upload retries
recheck access and uploader identity. Downloads are attachments with no-store,
nosniff and sandbox headers, never public URLs. The separate scanner defaults
off; unscanned bytes remain quarantined. Future confidential contractor contracts
must not be attached to a broadly visible project discussion.

Per-tab draft storage is keyed to tenant, user, area and selected target and is
cleared on normal sign-out. Text and stable pending-note/upload keys survive
reasonable navigation/reload; selected File objects must be reselected after
interruption. Current authorization is checked before drafts redisplay. Desktop,
phone and iPad portrait/landscape browser viewports are checked; physical iPad
keyboard/camera/Photos/file-picker acceptance is not established by simulation.

Planning records reuse encrypted operation storage, tenant serialization and
immutable revisions. Contractor register entries create no user/portal access.
Date blocks are inclusive date-only plans per contractor/department, with overlap
refusal and explicit cancellation. They are not room reservations, hourly staff
capacity or promised completion dates. Costs are separate organization-owner/admin
records with integer minor units, explicit currency and estimate/recorded-cost
basis. No purchasing, payment, ERP or accounting reconciliation is implied.
Read projections filter permissions before result limits and use constant bounded
coverage disclosure rather than hidden-record threshold signals.

Ask Everbranch is chat-based and user/role aware. Each question rebuilds current
authorized selected-department evidence and returns server-owned citations/links,
record versions, timestamp and coverage limits. No source HTML, generated URL,
tool invocation or historical chat becomes authority. The default is explicitly
record-backed facts; general model interpretation is not connected. The owned
AI Branch's unchanged Responses transport is extracted into `ResponsesTransport`;
the Enterprise selection adapter is exercised with fake HTTP and rejects
non-testing execution. It has no production binding, credential fallback, billing
or usage approval shortcut. A real model connection requires its own reviewed
metering/budget and data-processing acceptance. Notes, file contents and mailbox
messages are not retrieved in this increment.

Email uses an unconnected own-mailbox gateway contract, not the owner's sender.
There is no mailbox/grant/OAuth setup endpoint. With a fake authorized adapter,
drafts persist exact sender/recipient/subject/body, source/binding digest and a
15-minute approval. Confirmation rechecks current tenant/user/department, sender,
source versions and exact digest under the tenant lock. Dispatch claims commit
before I/O; ambiguous results retain a single uncertain attempt without automatic
retry. Provider acceptance is not confirmed delivery. The runtime's default
gateway lists no mailboxes and cannot dispatch. Real connection, consent/policy,
delivery and reconciliation acceptance remain later gates.

Additional focused checks:

```sh
php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise/EnterpriseNextSliceTest.php --compact
php vendor/bin/pest -c phpunit.team.xml tests/Feature/Enterprise tests/Feature/Team tests/Feature/AiBranch --compact
```

Reservations and maintenance conflicts; staff capacity/time; contractor
assignments/documents/costs; budgets; versioned workflows and gated notifications;
workspace entitlements and usage accounting. Pricing is uncommitted planning.
Enterprise makes no compliance certification claim and does not replace SIS/ERP.
