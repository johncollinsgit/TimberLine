# Enterprise workflow workspace and campus briefing

Source candidate, October 22, 2026. Stacked after draft PR459 at
`b0a78bed68930b7accc526dcb44bb819e6e4816e`; PR458 remains its foundation.
No merge, rollout, deployment or provider connection is implied.

## Evidence and source organization

The existing Trajectory is a dedicated financial operating workspace with its own
sidebar, main context and navigable sections (`resources/views/trajectory/index.blade.php`
and `resources/js/trajectory`). Its financial records and provider permissions
are unsuitable for campus operations. Its full-workspace navigation pattern is
the interaction reference. The existing Workflow Studio already has React 18,
typed definitions and an automation compiler; its commerce/provider actions and
retail entitlements are not imported into Enterprise.

Enterprise keeps the campus Teams colors, typography and project launch point.
Projects opens `/projects/workspace` as a separate routable document, with Canvas,
Screens & forms, Runs & history, Settings and Back to Projects. It uses the
MIT-licensed [React Flow](https://reactflow.dev/learn) library, pinned to 12.12.0,
with an accessible list and explicit connection controls for touch/keyboard use.
The graph package is built locally into Enterprise's own public directory; no CDN,
external font, analytics or graph-hosting service is called.

`npm run build:enterprise` type-checks the source and builds through
`vite.enterprise.config.js` into `apps/enterprise/public/build`. The retail build
and application entry remain separate. Both apps share source and dependencies,
not their public artifact, database, credentials, sessions, caches or release.

## Friday vertical slice

1. Choose an authorized project and open its full workflow workspace. Create a
   graph or explicitly load the illustrative maintenance template. No template
   creates real college records or access grants.
2. Edit form screens, condition branches, existing-member assignment and approval.
   Definitions use a closed, bounded language: no executable expressions,
   arbitrary bindings, external action tools, HTML or generated permissions.
3. Save a versioned draft. Preview is read-only. Explicit confirmation activates
   the reviewed digest. Draft editing preserves the active definition separately.
4. Start a run, collect validated fields, evaluate a condition, obtain the named
   current coordinator's approval, complete assigned work and review its history.
   Runs use an immutable activated snapshot. Assignment is human work within the
   persisted run; native task creation and external notification are later scope.
5. Return to the project or Home. Home builds a bounded, current, authorized
   briefing with source links and freshness/coverage. Explicit Ask refresh may
   prioritize validated record references through the owned provider transport.
   Download regenerates a one-page PDF snapshot from current server facts.

Unsent graph and run-field drafts use per-tab, tenant/user/department/project/run
namespaces and are restored only after current server authorization. Interrupted
save/start/advance operations retain their exact UUID and input. The server's
tenant lock, encrypted operation receipts and revisions establish the result;
the browser cannot grant access or establish execution. Normal sign-out clears
the user's local draft namespaces. Browser/session storage loss is not a server
backup; saved drafts and runs remain in the isolated database.

## Integration acceptance and tradeoffs

See [integration acceptance](../operations/enterprise-integrations.md) for the
real Responses and own-user SMTP adapters, empty mailbox schema, approved cost
reservation and ClamAV prerequisite. Source interfaces are implemented; real
provider calls/delivery/scans require separate approved configuration and evidence.
No credentials are copied, no provider account or mailbox is provisioned, and no
send or paid request is performed by installing this code.

The briefing's generated model output can select current references, not invent
campus facts. Server-computed reasons and links explain those choices. The PDF
is a fresh record summary, not a continuously updating document or campus-wide
feed. It explicitly states sample designation and bounded coverage. Unicode
record text is supported in HTML; PDF titles use Latin transliteration and the
PDF is not tagged. Equivalent accessible HTML remains available.

Source rollout flags default off. Illustrative fixture designation is trusted
operator configuration, never a client flag or guessed college identity. The
Friday acceptance target is one coherent internal workflow, not a general-purpose
SIS/ERP, compliance claim or autonomous external automation platform.

Next phases extend native task effects, resource/time reservations, restricted
contractor documents, capacity/budgets and gated notification actions after their
own authority/idempotency checks. Gmail or Microsoft 365 consent is a separate
per-user provider choice; neither is silently substituted for the native mailbox.

## Verification

Focused suites cover graph/field validation, tenant/department/private-record
isolation, revoked actors/approvers, source changes during AI processing,
immutable run snapshots, encrypted receipts, uncertainty and provider cost caps.
The dedicated runtime exercises mailbox DDL repair in disposable MySQL CI.
Chromium/WebKit HTTPS browser checks cover the complete flow plus lost responses,
retained drafts, one-page download, hostile text, and desktop/phone/tablet
portrait/landscape layouts. Viewport/engine simulation does not establish actual
iPad Photos, picker, software-keyboard or multi-touch hardware acceptance.
