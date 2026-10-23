# Everbranch Enterprise: proposed application boundary

Status: owner-approved application boundary, implemented as a source candidate,
2026-10-21. Working name only; naming and
trademark review are deferred. No infrastructure, access, billing or deployment
activation is enacted by this document. The owner approved a distinct application
and database while retaining shared source components.

## Recommendation

Build a distinct Enterprise application in the TimberLine repository, with its
own deployment, database, private storage, cache/session namespaces, queues,
configuration, release approval and rollback. Reuse proven tenant, membership,
Teams work and audit components. Keep Enterprise UI and domain code in explicit
namespaces/directories. A separate repository is not necessary to achieve an
independent application or data boundary.

Independent deployment is practical, but the current Laravel application is a
monolith, not an already isolated Enterprise package. First establish an
application composition boundary and test its route/provider/command exposure.
Do not point a second deployment at the existing production database or copy
production tenants, users, tokens, files or provider connections into it.

Source work proceeds locally behind disabled rollout gates. Provisioning and production migration remain separate
reviewed work. A tenant allowlist inside the current retail deployment alone
would not deliver the proposed operational isolation.

## Evidence from main 4753ba5

* `bootstrap/app.php` loads the shared `routes/web.php`, `routes/api.php` and
  `routes/console.php`. `routes/web.php` combines commerce, landlord, tenant,
  integrations and Teams routes. An alternate app name or URL would not remove
  those surfaces.
* `app/Services/Team/TeamWorkAccess.php` requires global rollout, a tenant
  allowlist, canonical `team_work` module access and active, verified tenant
  membership. Reuse this authority; a department is not another tenant.
* `TeamWorkRepository.php` persists project/task state, serializes mutations per
  tenant, rechecks membership, and records versions, events and replay receipts.
  `WorkGraph.php` already handles assignments, completion and object ACLs.
  Assignment refuses an assignee without access; it does not grant membership.
* `TeamArea`, `team_area_members` and
  `app/Services/Team/Operations/OperationsAccess.php` supply inner workspace
  structure and viewer/editor/coordinator roles. Existing Operations profiles
  cover events and wholesale, not enterprise intake lifecycle semantics.
* `WorkGraph.php` currently allows all nonguest tenant members into a project
  with `privacy=workspace`; tasks can be read through any accessible project.
  A department restriction only on new Enterprise endpoints would leave native
  Teams routes as alternate access paths.
* `TeamFileService.php`, `TeamProjectOverview.php`, native inbox and mobile
  adapters depend on shared graph authorization. Preserve those checks and
  extend the common visibility rule for department-scoped work.
* `config/module_catalog.php` is the canonical access/commercial catalog.
  `config/filesystems.php` currently places private Teams files under the app's
  storage directory. `.github/workflows/tests.yml` and the existing production
  release pipeline target the shared product; Enterprise needs a separate
  release destination and approval before independent production operation.

## Repository and runtime shape

Start with one repository and explicitly composed shared modules. Proposed
Enterprise domain paths are `app/Enterprise`, `routes/enterprise.php`,
`resources/views/enterprise` and `tests/Feature/Enterprise`. Keep tenant identity,
membership and existing Teams contracts canonical rather than duplicating them.
Extract versioned Composer packages only when a concrete dependency boundary
and automated contract tests make that extraction useful.

The Enterprise runtime must register only approved auth, tenant, Teams and
Enterprise surfaces. Retail, Shopify, POS, public commerce, provider callbacks,
billing and unrelated scheduled commands must not be exposed by default. Test
route registration, workers and scheduler composition, not merely navigation.
Separate release artifacts/approvals can initially use the same repository SHA;
independent cadence should use compatible shared contracts and targeted CI.

Use an independent database and app encryption key, private file root or bucket,
session cookie/domain, cache prefixes, queue workers and scheduler. Do not share
the retail `.env`, service accounts or transport credentials. No live mail/SMS,
payment or provider activation is part of this slice. Database ownership and
backup/restore checks are deployment prerequisites, not compliance claims.

A separate repository would allow separate repository permissions, ownership
and divergent releases, but would duplicate fixes and create package/version
coordination overhead immediately. Reconsider it if team ownership or customer
distribution requires that boundary. It is not a substitute for separate
runtime resources and does not itself guarantee isolation.

## Identity and workspace hierarchy

An Enterprise tenant represents an organization/account boundary; departments
are tenant-owned inner workspaces, using the existing area model where suitable.
Every request, department, project, task, file, audit entry and assignment must
resolve against the current tenant on the server. Cross-department collaboration
is explicit shared visibility within that tenant, never cross-tenant access.

Reuse active verified tenant membership and supported roles. Department roles
narrow existing authority. Organization owners/admins can administer structure;
creating a department must not attach users or grant real membership. Persist
only explicit authorized department grants through a separately reviewed flow.
Assignments require preexisting active membership and object access. Unknown
roles, inactive users and removed grants fail closed.

Start with the existing Laravel/Fortify authentication in the independent
Enterprise user database. Matching email addresses across deployments do not
establish identity or access. If unified login is needed later, use a reviewed
OIDC/SAML identity boundary with explicit issuer/subject-to-local-membership
mapping. Do not share session cookies, database user IDs or app keys as SSO.
No identity-provider connection or credential work is authorized here.

## First vertical slice

1. Add disabled global availability, empty tenant allowlist and mutation freeze
   gates, alongside canonical module entitlement checks. Do not alter existing
   grants, accepted pricing, billing or retail rollout.
2. Add organization/department workspaces with explicit shared versus
   department-only visibility. Fence native graph reads, writes, multi-homing,
   search/counts, overview/history, files, inbox, mobile and linking paths.
   Retained scoped records remain protected even when rollout is switched off.
3. Persist request submission, triage, assignment into an existing authorized
   project/task, progress and completion. Use version checks, idempotent
   receipts, transactional linkage and immutable audit events. Reuse native task
   lifecycle instead of creating a parallel task system. Do not infer real
   departments, buildings, users, projects or integration records from fixtures.
4. Adapt the existing Teams/campus presentation for those persisted workflows.
   Preserve the Everbranch sidebar and premium Teams shell, request/project
   List/Calendar/Map views, map-to-detail selection, Hide Map/View Map and
   role-specific detail. Retain contractor navigation with an honest unavailable
   state until its domain is implemented. Map positions require authorized saved
   location data; no fictional campus coordinates or stock incident evidence.
5. Verify tenant/role negatives, revoked membership and replay access,
   cross-department multi-homing, stale/concurrent triage, no implicit grants,
   disabled rollout and native Teams regressions. New migrations must satisfy
   migration lint, durable MySQL interruption recovery and schema baseline gates.
   Open a draft PR only after focused and relevant regression checks pass.

## Phases after the first slice

* Resource/building reservations and maintenance conflicts, with transactional
  overlap detection and explicit local dates/timezones.
* Staff capacity and time tracking; contractor assignments, authorized documents
  and costs; budgets with role-specific financial visibility.
* Versioned workflow builder with reviewable transitions and independently gated
  notification dispatch, suppression and delivery evidence.
* Workspace entitlements and usage accounting, with uncommitted pricing planning
  separate from billing activation.

Enterprise is an operations product, not a compliance certification or a
replacement for SIS/ERP. Live college systems, procurement/security requirements
and customer acceptance need actual source evidence and explicit authorization.

## Visual reference and current verification limits

The reference exists in this worktree at
`resources/demos/lees-mcrae-campus.html` (2,214,480 bytes), with its provenance,
fictional data contract and interaction scope in
`docs/operations/lees-mcrae-campus-demo.md`. The original v3 source commit is
`a0e3119ca86a267182e0be5d46cac98a3fd8861f`; hosted adaptation is merged in
`4753ba59214def09d6f709630d926db57084a716`. Existing demo assets remain unchanged.

Source and actual rendered visuals were inspected before Enterprise UI work.
The in-app browser was unavailable; the repository's development rendering
harness used a fresh headless browser process with all external requests blocked.
It did not share user or release tabs. The original demo assets remain unchanged.
Enterprise retains the reference stylesheet's visual tokens and shell classes;
no fictional college records or browser authorization were imported.

The first slice now has an independent entry point, configuration and safe
minimal schema installer, plus persisted workspace/request/task workflows and
negative authorization tests. See the implementation and release limits in
`docs/operations/everbranch-enterprise.md`. Database activation, real access,
credentials, providers and deployment remain outside this source change.
