# Persistent Enterprise workspace: proposed action scope

Status: source preparation only, October 22, 2026. The owner has requested the
real workspace and reusable client provisioning. These proposed destinations
make the remaining credential/security actions reviewable; none is provisioned.

## Existing infrastructure and proposed destinations

| Item | Proposed value / authority | Verification boundary |
| --- | --- | --- |
| Existing Forge server | 129.212.138.111 | Repository production runbook; no new server requested. |
| Existing Forge site record | backstage.theforestrystudio.com | Existing production site serving app.theeverbranch.com. Numeric server/site IDs are configured repository variables, but unavailable through current permitted metadata tools; an authenticated Forge owner must confirm them before changes. |
| Application URL | https://app.theeverbranch.com/enterprise-campus | Independent Enterprise front controller under the existing origin; local actual-server base-URL and native HTTP tests pass. Not hosted yet. |
| Client database | everbranch_enterprise_demo | New isolated schema, no retail connection or copied production rows. Name proposed, not created. |
| Database user | everbranch_ent_demo | New credential restricted to that schema, with no global privileges or grant authority. Reviewed installer requires schema privileges; normal runtime should use only approved application privileges. No password created or copied. |
| Persistent environment | /home/forge/backstage.theforestrystudio.com/shared/enterprise-demo/.env | Proposed path to verify against the actual Forge release layout. Select this parent directory with the trusted ENTERPRISE_RUNTIME_ROOT mapping; the dedicated runtime reads its own .env. Never copy retail .env. |
| Encryption key | ENTERPRISE_APP_KEY in that independent environment | New distinct key, created and installed only after action-time approval. No key value in source, logs, PR, or shared messaging. |
| Private files and runtime state | /home/forge/backstage.theforestrystudio.com/shared/enterprise-demo/storage | Use as the selected runtime storage across releases, alongside its own bootstrap/cache. Private upload roots remain app/private and app/team-private; no public storage symlink. |
| Release code and assets | Existing GitHub-to-Forge atomic release | Fixed apps/enterprise/public/index.php; allow only reviewed Enterprise build assets. Preserve retail document root, routes, /ready, release macros and protected hook. |

The proposed trusted runtime root is /home/forge/backstage.theforestrystudio.com/shared/enterprise-demo, including .env, storage and bootstrap/cache. The new reviewed runtime-root seam replaces release-local writable state for this client. The web-server mapping selects that fixed root; headers, URLs and manifests cannot choose it.

No additional billable infrastructure is proposed. Capacity and existing backup
storage entitlement have not been independently verified; a paid storage/server
change would require its own approval. Do not promise dedicated hardware or
operational backup/restore from application/database separation alone. A shared Forge PHP/service account is also not operating-system isolation between clients: it may be able to read neighboring runtime files. Stronger process/file access separation requires a separately approved per-client service identity/pool or equivalent boundary, not a claim made by application path guards.

## Routing and configuration action

Review the actual existing web-server mapping and approved PHP handler first.
Add only a narrow /enterprise-campus mapping with trusted SCRIPT_NAME and
PHP_SELF=/enterprise-campus/index.php, fixed SCRIPT_FILENAME pointing at the
candidate Enterprise front controller, and the original REQUEST_URI preserved.
Serve only the reviewed Enterprise built assets through a narrow static mapping.
No arbitrary PHP or directory fallback, DNS change, new site or retail rewrite.
The exact handler/socket cannot be invented from source.

The trusted mapping must also set ENTERPRISE_RUNTIME_ROOT to the reviewed persistent root before bootstrap. The independent runtime requires ENTERPRISE_BASE_PATH=/enterprise-campus,
ENTERPRISE_SESSION_PATH=/enterprise-campus, ENTERPRISE_APP_URL=https://app.theeverbranch.com (origin only), and
ENTERPRISE_RUNTIME_ISOLATED=true. The optional prefix guard checks actual server
base URL, raw URI and host-only Secure/HttpOnly cookie scope before web sessions.
Trial and Gmail activation fail closed in full-prefix mode until their paths
are separately adapted. Other provider/channel switches remain false.

## Proposed fictional workspace and access

Use a clearly marked fictional demonstration tenant, proposed slug
lees-mcrae-demonstration, and an Operations department configured through reusable
provisioning. Branding, source-backed location catalog, workflow defaults and
role settings are configuration rather than a special authorization branch.
Operational requests, people, schedules and activity remain fictional. The proposed reusable manifest is resources/data/enterprise/provisioning/lees-mcrae-demo.json: fixed proposed client/silo/storage UUIDs, one tenant, shared source directory, Operations department and 68 approved map references. The source reference preserves map numbers, functions and aliases; department mapping controls software visibility rather than asserting facility ownership. Review its exact-hash dry-run before an authorized apply.

The only proposed initial login is the owner's john@evergrovesoftware.com,
with tenant-owner authority limited to this isolated fictional runtime. This is
a new independent account, not a copied retail password, MFA secret, OAuth grant
or session. Establish verified identity and an owner-set credential through an
approved secure process; do not mark an arbitrary address verified or bypass an
existing MFA requirement. Explicitly record the tenant membership, Operations
coordinator role and existing Teams entitlement needed for this demonstration.
No platform-wide landlord grant is implied. No Andrew, employee, student or
contractor invitation, account or access is authorized by this document.
Additional fictional reviewer identities need an approved name, role and expiry.

The owner authorized building/provisioning the workspace within available
approved infrastructure. Code, templates, preview/idempotency tests and the
already authorized public preview release can proceed. Creating database
credentials/key, installing persistent configuration, changing routing and
establishing persistent login/access remain the concrete per-action gates.
No actual provisioning has executed while those gates are pending.

## Acceptance and recovery boundary

After approved provisioning, verify real login, create/edit/assign/workflow,
reload persistence, tenant/department denial, private upload authorization,
correct prefixed links, cookie isolation and release survival using fictional
records. Source/CI success and public preview availability do not prove a hosted
workspace exists. Coordinate release with the existing Forge retry owner.

Per-client backups, restore monitoring and searchable deleted-item recovery are
PLAN FIRST under the latest owner direction. No new recovery tooling, retention
policy, backup credentials, paid storage or production restore is activated here.
The review must distinguish landlord disaster recovery from tenant-admin item
recovery and validate an isolated restore before claiming backed-up/restorable.
