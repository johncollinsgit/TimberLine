# Isolated Enterprise client configuration

This source installs reusable structural configuration in an independently
prepared client runtime and database. It does not create infrastructure,
credentials, users, memberships, entitlements, operational workflow runs,
provider connections or billing. It does not create a backup or establish
restore readiness. Recovery planning remains pending review.

## Runtime boundary

Set `ENTERPRISE_RUNTIME_ROOT` in the trusted process/server environment before
PHP enters `apps/enterprise/bootstrap/app.php`. It cannot be selected through a
request header, query, cookie or an untrusted manifest. The selected directory
must already exist outside the release source tree and contain independent
`storage` and `bootstrap/cache` directories. These directories must resolve
inside that root rather than aliasing another client's directories.

The runtime reads its own `.env`, writes its own config/route/provider caches,
encrypted sessions, logs and private files, and reads its own maintenance file.
Source configuration, the reviewed migration tree, public assets and bootstrap
code remain in the release. Laravel `config:cache` can therefore bootstrap the
same code again while retaining the selected client's private cache location.
Generated cache filenames also carry a tag derived from the canonical code
release root, for example `bootstrap/cache/<release-tag>-config.php`. Retained
and candidate releases therefore use different config, route, service, package
and event cache files within the same client's private cache directory. No new
directory or secret value is needed for the tag. Reusing another release's
cached absolute view/source paths is not supported.

After runtime setup and release preparation are explicitly approved, run that
client's dedicated `config:cache` from the new candidate code root with its
trusted `ENTERPRISE_RUNTIME_ROOT` selected, before atomic activation. Verify the
candidate can bootstrap its own tagged cache. Do not overwrite or broadly clear
the currently active release's cache files. Retained-release cache cleanup must
follow the approved retention process; this source applies no Forge change or
cache deletion. A same-directory code replacement is outside the atomic-release
contract and cannot acquire a new identity merely by changing an environment
variable.
Do not share application keys, database credentials, writable runtime roots or
private disks between clients. Distinct database credentials with access only
to the approved client schema remain an operator provisioning prerequisite.
This application guard cannot establish server-level credential permissions.

When no runtime root or client identity is selected, the existing standalone
Enterprise runtime retains its previous paths. Selecting a client identity
without its trusted runtime root fails closed. The independent runtime must
still satisfy its existing isolation, database, canonical-host, prefix, cookie,
CSRF and rollout checks. A private client mount does not enable the public tour.

Required client configuration, without any secret values:

```dotenv
ENTERPRISE_CLIENT_ID=<immutable UUID>
ENTERPRISE_SILO_ID=<immutable UUID for this deployment silo>
ENTERPRISE_CLIENT_ENVIRONMENT=demo
ENTERPRISE_CLIENT_DATABASE=<exact independently approved database name>
ENTERPRISE_CLIENT_STORAGE_ID=<immutable private storage UUID>
```

`demo`, `production`, `staging` and `testing` are supported environment
designations. A demo must also retain the existing explicit demonstration
label configuration; it must not be presented as a connected college system.

## Data-only manifest and review

The JSON manifest is bounded to 256 KiB and rejects unknown fields. It carries
the same immutable client/silo/environment/storage IDs, one tenant's stable
business UUID/slug/name, escaped branding text and a six-digit accent color,
up to 40 departments with stable UUIDs and explicit `shared` or
`department_only` visibility, and up to 200 explicitly approved location seeds.
Each location has a stable UUID/key, department key, name, optional HTTPS source
reference and source label. Source references are metadata; nothing is fetched.
Seeds are unverified facilities with no coordinates, surveyed hierarchy or
ownership claim. Never insert fictional employee or college data implicitly.

An optional `source_entry` may name only an existing reviewed local reference,
currently `lees-mcrae-official-map-v1:1` through `:68`. The configured name must
match exactly; duplicate references and caller-supplied source metadata are
rejected. Validation freezes the approved map number, functions, aliases,
related/historic source facts and source URL/page into the encrypted installed
manifest and seeded record. The client key remains separate from an official
map number. No source URL is fetched, and department visibility does not claim
physical ownership or responsible department. The proposed
`resources/data/enterprise/provisioning/lees-mcrae-demo.json` is configuration
only; reading or validating it creates no tenant or access.

Role defaults contain only manager/employee/viewer display labels, view and
density preferences. They do not change existing role or membership semantics.
Workflow defaults reference the closed `maintenance_v1` template with a label
and key. They create no actual workflows, runs, task mappings or assignments.
Branding/defaults are available through `ClientSilo::configuration()` for the
existing UI; their installation is not a permission or feature activation.

Use only the dedicated entry point:

```text
php apps/enterprise/artisan enterprise:client plan --manifest=<local JSON>
php apps/enterprise/artisan enterprise:client apply --manifest=<same JSON> --plan-hash=<reviewed hash>
php apps/enterprise/artisan enterprise:client status
```

Install only the reviewed independent Enterprise schema after database setup is
authorized. `plan` requires that schema and performs no writes. Initial claims
require exactly its migration/table inventory and no existing business records;
the tool never adopts a retail database or an existing tenant by slug.
An unclaimed runtime must also have empty storage, including private files,
sessions, cache and logs. Both private disk configurations and their existing
canonical path components must stay inside that client's storage, without
symlink aliases. Removing runtime/client environment selectors cannot make an
already-marked client database behave as standalone.

Review the change counts and manifest before `apply`. The plan hash binds the
canonical manifest, effective database/runtime/storage/key fingerprint and
provisioning/schema source digest. The same reviewed hash is required at apply;
another target, changed source/input or foreign existing data is rejected.
No password or raw key is printed in the plan or receipt.

Apply serializes first claims using a database-wide MySQL named lock, writes
an exclusive durable prepared filesystem marker, then atomically creates the
tenant, departments, seed records and encrypted singleton receipt in a database
transaction. It creates no identities, memberships, area grants, module profile,
entitlement, projects, tasks or provider activity. The receipt preserves the
business UUID and exact configuration rather than changing the shared tenant
model. Initial provisioning is not a general configuration editor.

Exact replay returns the original receipt and does not overwrite later domain
edits. An interruption before database commit retains the prepared marker and
can resume only the same plan. An incomplete/corrupt marker or a changed target
requires operator review; the command never deletes state to repair it.
HTTP requests fail before sessions when the database and filesystem markers do
not agree with the configured client/key/storage, or when an additional tenant
is detected. Existing tenant and department ACLs still apply after that check.
Client catalog projections require current authenticated department visibility;
they never fall back to another client's or the public campus catalog.

Destructive migration/reset/seed and key-generation commands are unavailable
inside a selected client runtime. Code rollback retains the silo receipt.

## Activation and evidence

Stage three remains separate: an approved local owner identity, its existing
active verified membership, canonical `team_work` entitlement, tenant allowlists
and scoped feature/write activation are required for employee workflows. Login
does not create any of them. No account/grant or live provider configuration is
created merely to make an acceptance check pass.

The proposed initial existing-origin deployment remains server
`129.212.138.111`, Forge site `backstage.theforestrystudio.com`, public host
`app.theeverbranch.com`, private prefix `/enterprise-campus`, database
`everbranch_enterprise_demo`, principal `everbranch_ent_demo` and separate
`shared/enterprise-demo/{.env,storage}` state. These are proposed uncreated
targets, not proof of authorization or hosting. Review minimum database grants,
stable independent key, runtime/cache release bindings, prefix/build mapping and
the exact approved demo owner/tenant before performing those operator actions.
Follow `enterprise-same-origin-trial-mount.md`; source now supports prefixed
Gmail callbacks and controlled-trial links, but both remain default-disabled
pending their separate provider/configuration/consent and acceptance approvals.
For this proposed mount, register only the exact approved callback
`https://app.theeverbranch.com/enterprise-campus/enterprise/gmail/callback`.
Provisioning never creates those credentials, grants or approvals. No second
site, new domain or DNS cutover is required.

`EnterpriseClientProvisioningTest` boots the actual runtime against disposable
SQLite and includes a second non-campus client's catalog, role/default storage,
permission denial, exact replay and interrupted initialization. Its independent
processes prove config-cache/private-file separation for two temporary roots.
`EnterpriseClientProvisioningMySqlTest` runs only against explicitly disposable
local `everbranch_enterprise_ci`, before retained MySQL fixtures. It checks three
independent first claims waiting behind a real named lock and durable receipt
survival while missing indexes are repaired. It cleans only its own records.
The recovery scenario is registered in the shared recovery manifest and checked
by `apps/enterprise/scripts/lint-schema.php`.

Local SQLite success is not MySQL concurrency evidence. A hosted persistent
workspace and independent backup/restore readiness require their own verified
acceptance evidence. Status intentionally reports `pending_plan_review` for
backup readiness; no recovery command or searchable deleted-item recovery is
implemented by this provisioning slice.
