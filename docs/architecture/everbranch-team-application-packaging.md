# Teams application packaging and entry contract

User clarification: “This should be a link in everbranch to Modern Forestry Teams it should live as an application by itself as well similar to how trajectory works”. This document supplements the existing 643-clause supplied mobile matrix; it does not replace, remove or relabel its requirements. Scope is the current local candidate, default disabled pending release.

## Existing pattern and chosen integration

| Inspected source | Evidence and decision |
| --- | --- |
| `routes/trajectory.php`, `resources/views/trajectory/welcome.blade.php` | Trajectory has a public welcome/marketing page. A new public page, domain or acquisition/billing flow is not required for this request and is not added. |
| `resources/views/trajectory/index.blade.php`, `TrajectoryController::index` | Authenticated Trajectory is a complete HTML application with its own sidebar, current spaces and Back to Everbranch link. Use this independent web-shell pattern. |
| `UnifiedAppNavigationService` | Everbranch already links to Trajectory's own application. Team previously linked to its embedded `/team` view. Keep the existing `team-work` navigation key/order but point eligible viewers to `/teams`. |
| `TeamWorkAccess`, `TeamWorkController`, shared repository/policies | Reuse current active/verified membership, exact rollout, canonical entitlement, tenant/object gates, authentication and task/project records. Do not copy Trajectory's finance-space authorization into Team. |
| `PostLoginRedirectResolver` and Fortify responses | Existing login accepts safe same-host intended links with membership-safe tenant queries, then target routes reauthorize objects. Reuse it unchanged; no standalone credentials or second account store. |

Chosen route: **`/teams?tenant=<workspace-slug>`**, on the existing application host. It returns a complete authenticated Teams HTML shell with its own Home, My Tasks, All work, Inbox, Create, account and return navigation. `/team?tenant=<slug>` remains the embedded Everbranch view and compatibility entry. There is no new service/domain, public marketing route, installed PWA, service worker, desktop binary or native app-store package.

## Required behavior and acceptance

1. Everbranch shows an application link only for current eligible Team access, including a recognized active role. Its label is the existing workspace brand plus “Teams”; Modern Forestry's existing brand yields **Modern Forestry Teams**, while other companies get their own label. `TeamAppPresentation` reads the existing brand or canonical default without provisioning a brand/workspace or hardcoding a tenant ID/name grant.
2. Standalone and embedded entry call one controller authorization/render method and include one `team.workspace` Blade partial and one `team-work.js`. Existing `/team/*` APIs, UUIDs, memberships, source/history, receipts and file/Field policies remain shared. No separate record or import copy is created.
3. Direct task, project and comment links authenticate and reauthorize before content. Supported forms are `/teams?tenant=<slug>&task=<uuid>`, optional `&comment=<uuid>`, or `&project=<uuid>`. Ambiguous task/project targets are refused just as in the embedded view. API/native canonical URLs stay compatible; the standalone client maps authorized HTML links to its own surface.
4. The Everbranch sidebar uses a full document navigation for this independent app entry. The contextual Open Teams / Back to Everbranch links preserve the current permitted task/comment/project. Clean switching does not sign out. Unsent task/comment/create/field/recurrence input, status/follow-up drafts, files and pending writes keep their existing applicable guards; standalone section actions use those guards.
5. Workspace switching uses the existing eligible own-workspace directory. It reloads the chosen standalone surface, updates company branding and active-workspace indication and replaces permitted data. Explicit tenant-scoped bookmarks are recommended; a bare `/teams` uses the existing current/session/default tenant resolver and fails closed if that context is ineligible. It is not an all-company discovery or new membership bootstrap interface.
6. Layout is responsive with app sections available on a narrow viewport and readable task details. Read-only/guest controls stay disabled and inaccessible objects never become readable merely because an app link is available. Private cache/no-store, stale viewer/workspace headers and current object authorization remain in force.

Acceptance evidence: `tests/Feature/Team/TeamStandaloneAppTest.php` and `scripts/everbranch-team/standalone-teams-acceptance.mjs`; detailed actual results and limits in `../operations/everbranch-team-standalone-application-verification.md`. The existing comprehensive matrix retains mobile/native navigation as partial or pending; an independent responsive web shell is not native parity.

## Remaining setup decisions and closed gates

- For an approved environment, confirm the actual Modern Forestry tenant/slug and existing company brand, then review the existing Team entitlement plus exact global/tenant rollout settings. This local task neither activates nor edits a real tenant. Existing role/object grants remain authoritative; a menu label or URL never grants access.
- Parent owns the staging/production target, current integration, protected build/Linux/private-file checks, real MySQL baseline/recovery/concurrency, scanner/storage readiness and explicit release approval. No production route or link is claimed installed.
- A separate domain, public site, installable PWA, native packaging/store signing, push and encrypted offline/restart recovery are future explicit setup/product decisions. The supported approach here needs no new paid service, DNS, OAuth client, credentials or billing change. Future mobile work belongs in the existing Everbranch app stack rather than a duplicate task app/store.
- Real Asana migration stays closed. Existing recorded partial source capture/ZIP restore must be reviewed through authorized archive access; obtain only identified gaps/deltas. Durable source-history import, identity/privacy/attachment mappings, reconciliation and cutover/rollback remain required. No real source read/import, membership/invitation, source rewrite or migration is performed by opening this app.
