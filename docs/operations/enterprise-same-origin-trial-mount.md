# Existing-origin Enterprise trial mount

This is a reviewed source plan, not evidence that routing, credentials, database,
storage or provider configuration has been changed. The existing Forge atomic
release site and `https://app.theeverbranch.com` origin can host both surfaces.
The code does not require another domain, a second Forge site or a DNS cutover.
Keep the restrictions in `forge-atomic-release-runbook.md`.

## Publish the isolated public demonstration

The public routes remain in the retail application:

- GET/HEAD `/platform/demos/lees-mcrae`
- GET/HEAD `/platform/demos/lees-mcrae/workflows`

The full campus restoration plan authorizes publishing this fictional experience
through the normal reviewed GitHub-to-Forge release. Complete the reviewed
dependency stack and exact-head checks, then use the existing atomic release
workflow. `npm run build` produces both retail assets and the separate
`apps/enterprise/public/build` bundle; the public workflow embeds that reviewed
bundle without exposing the Enterprise filesystem.

The precise availability action is to set **`ENTERPRISE_REVIEWER_DEMO_ENABLED=true`
in the existing retail Forge environment** and let the candidate release's
normal `php artisan config:cache` consume it. This is a public fictional-demo
availability flag. Its source default remains false. Do not enable Enterprise
tenant writes, the controlled trial, Gmail, SMS, email or AI provider switches
as a side effect. None is necessary to publish the tour or sample workflows.

Verify the protected deployment's exact `/ready` release SHA and both public
GET/HEAD routes, including no cookies, no operational/provider API requests and
query rejection. Keep the existing release owner's Forge login/retry ownership.
A green source job or acknowledged hook is not proof that production changed.

## Full private persistent workspace on the existing origin

The public tour and a trial-only prefix do **not** host the private campus
application. A working persistent demonstration needs the independent runtime
and database mounted as a complete private application, for example at
`https://app.theeverbranch.com/enterprise-campus`. This source supports that
prefix; no hosted database, environment, demo owner or tenant has been
provisioned or verified by these changes.

Use a separately reviewed exact `/enterprise-campus` and `/enterprise-campus/`
mapping within the existing site, leaving all other retail routing unchanged.
For dynamic requests the trusted server mapping must set:

- `SCRIPT_FILENAME` to the current release's fixed
  `apps/enterprise/public/index.php`;
- `SCRIPT_NAME` and `PHP_SELF` to `/enterprise-campus/index.php`;
- `REQUEST_URI` to the unchanged original `/enterprise-campus/...` URI,
  preserving its method, query and canonical HTTPS host.

Symfony then recognizes base URL `/enterprise-campus` while Laravel routes
remain `/login`, `/enterprise/data`, `/projects/workspace`, etc. Do not strip the
prefix into a root request or trust an end-user `X-Forwarded-Prefix` header.
Serve only reviewed built files beneath `/enterprise-campus/build/assets/`
from `apps/enterprise/public/build/assets/`; no fallback may expose `.env`,
storage, vendor, application source, arbitrary PHP, or another runtime's assets.
Verify the actual existing PHP handler/socket before applying any mapping.

The independent environment needs:

```dotenv
ENTERPRISE_APP_URL=https://app.theeverbranch.com
ENTERPRISE_BASE_PATH=/enterprise-campus
ENTERPRISE_SESSION_PATH=/enterprise-campus
ENTERPRISE_RUNTIME_ISOLATED=true
ENTERPRISE_TRIAL_MOUNT_ONLY=false
ENTERPRISE_TRIAL_ENABLED=false
ENTERPRISE_GMAIL_CONNECTION_ENABLED=false
ENTERPRISE_GMAIL_SEND_ENABLED=false
ENTERPRISE_SAMPLE_DATA=true
```

Keep `ENTERPRISE_APP_URL` as the origin; the trusted request base URL supplies
the prefix. `ENTERPRISE_BASE_PATH` is environment-only and defaults empty for
standalone compatibility. When set, the global guard requires an exact clean
server base URL and matching original request path, plus the distinct host-only,
Secure/HttpOnly session cookie scoped to the full prefix. Wrong mounts return
404 before sessions start; unsafe configuration returns 503. Prefix-only mode
suppresses the redundant `XSRF-TOKEN` response cookie, retaining real meta/form
CSRF validation without shadowing retail's cookie. Standalone behavior remains
unchanged. The trial-only and full-workspace modes cannot be combined.

The source supports independently gated Gmail and controlled-trial integrations
under this full prefix. `MountUrls` constructs integration URLs from the exact
canonical HTTPS origin plus the trusted `ENTERPRISE_BASE_PATH`, never from a
forwarded-prefix header or user input. The global mount guard also rejects a
different scheme, host or port before starting sessions. No provider credential
or flag is needed to demonstrate persistent campus work. Briefing,
Roll Call, native workflow operations and private authentication links use the
trusted request prefix. Login, the main shell and workflow workspace display a
persistent fictional-demonstration designation when the private sample flag is
true; that designation creates no user, membership, entitlement or write grant.

For own-user Gmail, the separately approved Google web client must register
exactly `https://app.theeverbranch.com/enterprise-campus/enterprise/gmail/callback`,
and `ENTERPRISE_GMAIL_REDIRECT_URI` must equal it. A root callback, different
prefix, alternate origin, query or fragment cannot substitute. Connect and
callback independently check the actual trusted request base URL; callbacks
return only to the same prefixed user's Ask context. Saved OAuth states,
connections and reviewed mailbox bindings include the mount fingerprint. A
mount/client change requires a fresh connection and invalidates old reviewed
draft authority; it never migrates a grant or reuses another user's mailbox.

The controlled trial uses `/enterprise-campus/enterprise/trial` under this
mode, including its boot API base, wordmark, opaque task links, update/upload
and authorized download routes. Its canonical-origin setting remains origin
only. Reviewer sessions and capabilities freeze the mount context, so moving
the mount invalidates old sessions/links. Opening a new explicitly approved
session does **not** reset the shared $5 lifetime ledger or release unknown
reservations. Trial email still uses its separately approved service sender;
reviewers do not connect Gmail. Prefix support creates no provider readiness.

Keep all integration flags false until their individual approvals and current
provider prerequisites are satisfied. These are source/fake-provider results,
not live OAuth, delivery, scanning, hosting or persistence acceptance. The
separate root trial-only mapping below remains supported with an empty
`ENTERPRISE_BASE_PATH`; it must not be combined with the full-workspace mode.

Actual hosting still needs approval and configuration for the isolated database
and credentials, a separate stable encryption key, durable private environment
and storage release bindings, and an explicitly approved synthetic demo owner,
tenant, active verified memberships and `team_work` entitlement. Existing
rollout allowlists and feature/write flags must target only that approved demo
tenant. Do not reuse retail accounts, credentials or a real college dataset.
Create no persistent owner/tenant/grants merely to make a release smoke pass.

After provisioning is separately authorized, verify real login, intake,
assignment, checklist/approval and fresh-request persistence across reload,
browser sessions and an atomic release; check tenant/role denial and unchanged
retail sessions. Until then report this as **source-ready prefix support**, not
an available persistent workspace. The focused
`EnterprisePrefixMountTest.php` uses actual PHP server variables, cookie-bearing
same-origin JSON requests and disposable native records. It checks prefixed
Gmail exchange/303/replay and denial boundaries, trial links/cross-device task
updates/quarantined uploads, real CSRF, idempotent confirmation, uncertain cost
holds and unchanged lifetime accounting across a mount change. Provider HTTP
is faked; it does not establish hosting, live acceptance or provision access.

## Optional controlled trial on the same origin

Route only the exact `/enterprise/trial` path and its `/enterprise/trial/`
descendants to the dedicated front controller:
`apps/enterprise/public/index.php`. Everything else continues through the
existing retail routing. Do not mount `/enterprise` broadly or expose another
directory tree. The current controller, HTML, APIs and wordmark all use the
existing trial prefix, so no additional public asset location is necessary.

An approved web-server mapping must:

- retain the full original `/enterprise/trial/...` request URI and method;
- invoke only the fixed Enterprise front-controller file in the candidate's
  current release, using the existing approved PHP handler;
- preserve the canonical HTTPS host/scheme and ordinary request/upload limits;
- avoid stripping the trial prefix into a Laravel base URL (the routes already
  include it), arbitrary PHP execution, or filesystem fallback/static serving;
- leave the existing site, document root, `/ready`, retail paths and atomic
  release activation unchanged.

This document does not apply that web-server/security configuration. Review the
actual existing server configuration and PHP socket before authorizing the
minimal prefix mapping. Do not paste an invented socket or broad rewrite.

The dedicated runtime requires its own persistent environment and storage,
separate database/credential configuration and application encryption key.
The existing Forge runbook shares retail `.env` and `storage` only; those are
insufficient to persist Enterprise state across retained releases. An approved
candidate preparation must bind separate persistent paths to
`apps/enterprise/.env` and `apps/enterprise/storage`, without copying the retail
environment or pointing Enterprise at the retail database/storage. Run only
the reviewed Enterprise schema installer against the approved isolated database.
Do not add its schema to the retail migration command.

For a prefix-only deployment, configure these **independent** environment values:

```dotenv
ENTERPRISE_APP_URL=https://app.theeverbranch.com
ENTERPRISE_RUNTIME_ISOLATED=true
ENTERPRISE_TRIAL_MOUNT_ONLY=true
ENTERPRISE_SESSION_PATH=/enterprise/trial
ENTERPRISE_TRIAL_CANONICAL_ORIGIN=https://app.theeverbranch.com
```

These values are prerequisites, not a complete live configuration or an action
performed by this source change. `ENTERPRISE_TRIAL_ENABLED` and channel flags
remain false until their separately required approvals/configuration are ready.
The existing marked synthetic storage anchor must contain only fictional work;
no real college organization, employee or membership is created by this plan.

`ENTERPRISE_TRIAL_MOUNT_ONLY` defaults false. When explicitly true, a global
middleware rejects all paths outside the exact trial prefix before web sessions
start, and refuses trial requests unless the cookie path is exactly
`/enterprise/trial`. The session remains `everbranch_enterprise_session`, Secure,
HttpOnly and host-only. Trial responses suppress the framework's redundant
`XSRF-TOKEN` cookie, preventing replacement of retail's same-origin cookie.
Inherited session-backed CSRF checks still require the explicit page meta/form
token; missing tokens return 419. Non-trial Enterprise requests retain their
previous XSRF-cookie behavior when prefix-only mode is disabled.

## Acceptance and remaining approvals

Before activating a mount, verify candidate path routing and independent
configuration, outside-prefix 404 responses, cookie scope/no retail-cookie
overwrite, and real missing/valid CSRF behavior. A separate runtime, database and
cookie name provide application isolation; a same-origin path is not a separate
browser origin. Do not treat path scoping as permission to weaken CSP or auth.

Reviewer invitations, exact receiving destinations, service sender/provider
credentials, current inclusive rates, verified prior related trial spend and
the single shared $5 lifetime ledger remain the requirements in
`enterprise-reviewer-trial.md`. Their configuration is not implied by demo
publication or a prefix mapping. Unconfigured scanning keeps uploads private
and quarantined. Provider acceptance remains distinct from confirmed delivery.

Root `/ready` establishes the retail release identity. Independently verify the
trial runtime/database and its guarded routes before describing the trial as
available. Rollback uses the retained atomic release and preserves the separate
trial database, encrypted records, budget ledger and private uploaded files.
