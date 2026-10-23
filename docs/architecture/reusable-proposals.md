# Reusable service proposals — source candidate, October 22, 2026

## Ownership and runtime boundary

One `App\Proposals` engine, separate tenant-owned clients, catalog, draft master
templates, drafts, publications, comments and requests. Every owner request
resolves the exact workspace slug to a native `Tenant`, checks an explicit local
ID/lane binding, and rechecks active verified owner/admin membership. Global admin
role, matching email/name, URL, HighLevel location and client access confer no
proposal-management authority. Child records also have compound tenant foreign
keys. No existing commercial catalog or accepted agreement is changed.

Inspected `Tenant`, `AuthenticatedTenantContextResolver`, `EnsureTenantAccess`,
the existing agreement management/access/acceptance services, agreement styles and
proposal, and Managed Website invoice/quote views. Repository `AGENTS.md`,
`SYSTEM_SNAPSHOT.md`, `README_FOR_AGENTS.md` and the advisory-only
`.claude/agents/project-strategist.md` were inspected; there was no tracked
`.agents/skills` or nested `AGENTS.md` in this checkout. The invoice references
support forest greens, warm neutrals and monetary hierarchy; this design is not
claimed to match an unseen reference.

Core business workspaces are `tenants`, not HighLevel locations or Teams areas.
The existing AI boundary in `config/ai_branch.php` identifies historical regular
Evergrove Software as tenant 10 / `evergrove-software`; this is source evidence,
not a fresh production identity check. Other operational notes use “space 3” in
provider contexts; those IDs must not be substituted for the tenant ID.

The campus owner's separate Enterprise candidate has an independent bootstrap,
database, identity/session/config namespace and tenant-owned department areas.
Read in its existing worktree: `apps/enterprise/bootstrap/app.php`,
`app/Enterprise/WorkspaceAccess.php` and
`docs/architecture/everbranch-enterprise-boundary-proposal.md`. A department is
not an owner business tenant. Tenant numbers do not establish identity across
the two databases.

`config/service_proposals.php` therefore ships disabled with an **empty binding
registry and recipient host list**. `regular` and `enterprise` are explicit
business lanes within the current runtime's local tenant registry. The tests use
two isolated fictional tenants to exercise both lanes. This candidate registers
routes in the core runtime only. Mounting the same route file in the independent
Enterprise runtime requires coordination with its owner and separate runtime
acceptance; no files in that owner's stack were copied or edited.

Real regular-owner identity must be verified read-only, and the new Evergrove
Enterprise owner tenant must be separately provisioned/bound after authorization.
That action is outside this task's permitted operations. Do not bind the
Lees-McRae client tenant, an existing similarly named HighLevel location or the
regular Evergrove workspace as a shortcut. No workspace was provisioned here.

## Source capabilities

- `/owner-workspaces/{workspace}/proposals`: list/create/edit, catalog, clients,
  draft master templates, previews, publication/revocation, agreement requests
  and shared named review comments. No shell/header modification.
- `/service-proposals/{token}`: standalone host-allowlisted password page,
  30-minute version-bound browser grant, expiring/revocable links, recipient or
  reviewer audience. Reviewer comments do not permit agreement requests.
- Clients select existing offered one-time/recurring services and configured
  12/24/36-month durations independently of monthly/yearly cadence. Alternative
  bundles cannot be combined. Prices are integer cents; unconfigured terms remain
  pending. No discount or full-term total is inferred.
- Published content/templates/client/prices are encrypted immutable snapshots.
  Publication operation UUIDs recover the exact encrypted token result on retry;
  altered retries stop. Draft/catalog edits use optimistic revisions. Revocation
  retains evidence. Immutability is enforced by the application model/service;
  direct database-administrator edits remain outside that boundary.
- Selection review precedes an idempotent non-binding agreement request. Its
  immutable selection/version hashes and exact services/prices/terms form the
  draft agreement basis. Signature, payment and subscription states explicitly
  remain `not_signed` / `not_started`. No Stripe or legacy agreement is created.
- `POST .../draft-intake` is a tested draft-only AI/voice adapter accepting only
  existing scoped catalog references and bounded text. It refuses supplied
  commercial fields or publication actions. The existing AI/voice menus/provider
  are not wired to it yet. No provider call or automatic publish/send occurs.
- Ross/Sarah/Paul are persisted review display names for the LMC draft. Shared
  comments include proposal version, page and self-selected name. They are not
  seats, user accounts, verified identities or signature evidence.
- One optional usage-estimator disclosure uses reviewer-entered low/high provider
  cost assumptions and department count. Results are bound to the exact version
  hash, apply zero markup and approve no fee, allowance or cap. It is planning
  arithmetic, not a provider quote or usage meter.

## Lees-McRae content boundary

Explicit Enterprise-lane owner action prepares a non-binding client-specific
draft: one workspace $3,000 setup + $1,000/month; three-workspace bundle $7,000
setup + $2,500/month **TOTAL when fully active**. No global prices are replaced.
Billing commencement follows the stored workspace-specific implementation,
client-dependency and operational-readiness provision when that provision is
present in the reviewed draft/version. Phased billing amounts, renewals,
discounts, usage amounts and allowances remain to be agreed. There is no
approved $150 fee/cap. Existing saved 12-month/no-pause terms must be read and
preserved; the current catalog/draft generator is not authority to overwrite
more recent agreed saved copy.

The Evergrove Difference explains hands-on onsite discovery, tool/process review,
co-design/scope, implementation/pilot, training/launch and reviewed improvement.
Its illustrated nested network shows proposed connections within and between
departments. Activate one department's accepted scoped workflows, training,
permissions/data checks and support handover before the next; the bundle reserves
three scopes rather than simultaneous activation. No native/offline/GPS promise,
installed app or actual activation is claimed. Campus app/header/tour remain with
the existing Enterprise owner.

Paper Roll Call tickets handed to technicians are the confirmed problem. The
proposed installable web-app outcome keeps optional printouts and protects
unfinished work. Admissions/marketing are excluded initially; expansion,
integrations, substantial migrations, system replacement and major new workflows
require discovery and separate written scope/timeline/price approval first.

Lees-McRae usage has no markup. Human support is Monday–Friday 9–5 Eastern, with
initial human response within one business day; resolution is not guaranteed in
that interval. Fixed routine travel is included; overnight travel needs an all-in
preapproved quote. Plan department-head setup then COO activation-plan approval;
staff access and billing require explicit acceptance. These are proposal content,
not live grants or activation code.

The implementation/billing-commencement clause is draft content, not a billing
executor. It covers a mutually agreed schedule and College participation, written
per-workspace operational-readiness notice, substantially completed deliverables,
institutional delay outside Evergrove's reasonable control, no outstanding material
Evergrove defect preventing intended use, and a 30-calendar-day period. Evergrove
delays do not accelerate billing; departments need not activate together. A future
charge needs workspace-specific notice/date/evidence, defect resolution, reviewed
amounts and human review. No Stripe, automatic charge, invoice or sending action
follows from preparing or displaying this clause.

The renderer displays this provision only from the stored payload fields. Older
published snapshots without those fields do not acquire new terms from current
source. A saved-draft correction must pin the exact tenant/draft/revision/hash,
preserve all other saved commercial fields, and never rebuild it through the
current generator. Publication and the version's access settings remain separate.

All draft legal language is explicitly **unreviewed / not attorney approved**.
Approved clause libraries, final agreement generation/signature execution,
provider sandbox integrations and real delivery remain later work. No legal
protection is guaranteed.

## Stored commercial copy and ordinary saves (reviewed source follow-on)

Additional financial, setup, support, usage and contract sections render only
from the exact stored `client_details.commercial_sections` snapshot. Plain-text
headings, paragraphs, bullets and accessible tables are escaped. Existing
publications without these fields retain their five original details and optional
implementation clause; source constants never add new terms to old versions.

A separately reviewed `commercial_snapshot` marker identifies the source document,
exact copy digest, native catalog origins and explicit draft-only term overrides.
The native catalog id/revision remains an origin, not a claim that its unchanged
payload contains the new draft terms. Catalog rows stay untouched. The marker's
protected digest covers client, complete items/prices, client details, unresolved
items, usage method, template, contractual scope notes, complete saved journey,
approach key and the provenance marker
itself (excluding its own digest), with recursively sorted object keys and stable
list order. It is integrity evidence, not a signature, grant or billing authority.

Under the existing revision lock, ordinary Save preserves that protected
commercial snapshot for the same client, service ID set, scope notes, usage method,
template and approach key. The exact stored journey is retained rather than
regenerated from current source. Later catalog prices or terms cannot silently replace it. Title,
introduction and reviewer names retain existing editing behavior. Scope,
client, services, usage method, template or approach-key changes require a separately reviewed
commercial revision; incomplete/drifted markers refuse Save and new publication.
Existing drafts without the new sections/marker retain the original behavior.
Never use the generator to reconcile an already saved target. Rollback to old Save
code requires holding edits and a separately reviewed draft recovery plan.

PDF v9 copy includes the staged setup and TOTAL monthly schedule, 12-month term,
60-day nonrenewal notice, 90-day reminder/next-term price notice, fixed base price
within the term, no routine pause, defined setup/support and unfinalized usage
choice without markup or automatic overages. Numbered PDF-page references become
actual online section titles only. None of this sends, signs, charges or enables a
provider. Future readiness-notice billing still requires per-workspace evidence
and human review; staged totals are not inferred per-workspace invoice allocations.
A data-only reconciliation needs its own exact target/catalog/version pins,
14-path diff, rollback/replay review and approval; this source supplies no runtime
write, publication, credential or CSP activation permission.

## Verification and release gates

Run local proposal tests with the repository PHP environment; optional
`PROPOSAL_CAPTURE_HTML=1` writes a fictional rendered preview to
`.tmp/proposal-preview.html`. `node scripts/proposals/preview-browser.mjs` captures
Chrome desktop/iPad-sized/phone-sized screenshots, checks overflow/story order,
and refuses external requests. It does not prove physical device acceptance.

The additive migration has a manifest entry, named repairable indexes/foreign
keys and a real disposable-MySQL recovery test wired into the existing CI job.
No local MySQL executable/service was available in this task. The registered
recovery test and prior-schema rehearsal passed in the existing MySQL CI job.
The baseline workflow now generates exact-head candidates for same-repository
migration PRs, using the existing guarded disposable MySQL environment; normal
committed-fingerprint verification remains mandatory. Generated SQL and its
fingerprint must be independently reviewed together before committing. Exact-head
CI outcomes belong in the PR evidence. Do not hand-edit schema artifacts or
claim SQLite establishes MySQL recovery.

Keep a draft PR and independent code/visual review. No merge/deploy or production
flag/configuration changes. Live workspace bindings, recipient hosts, legal
approval, signing and payment/provider evidence are separate gates.
