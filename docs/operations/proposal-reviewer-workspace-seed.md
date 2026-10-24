# Fresh fictional reviewer workspace seed export

Source-only review utility. Run `node scripts/proposals/reviewer-workspace-seed.mjs`
from the source checkout for a deterministic graph plus evidence, or use `--graph`
for just the closed graph. Output is stdout only. The utility performs no HTTP,
provider, database, runtime initialization, publication, key or account operation.
It creates no review descriptor, workspace UUID or recipient capability.

It executes the exact SHA-256-pinned `reviewer/job-sample.js` and
`reviewer/sample-adapter.js` with the exact pinned official local location catalog
in a fresh VM, an explicit empty in-memory adapter storage and a fixed fixture
clock. Global browser storage, network and random identity calls refuse. A VM is
not a security sandbox for arbitrary code: unreviewed source fingerprints fail
before execution. There is no state file/stdin/browser-state import. Extra options
and changed source bytes fail. Review a separate new source packet before changing
these pins; do not silently regenerate an expected digest after fixture changes.

## Scope and exact fresh fixture

The current source yields four departments, twenty projects/jobs, twenty-one tasks,
twenty-five tickets, twenty-five explicit saved buildings, eleven fictional people
(the ten field workers plus the demo manager), five contractors and eighteen
canonical plans. Five tickets are incoming/unassigned. `plans` is the only canonical
planning collection; both existing job and plan-repair revision markers must be
present. Orphan `planning` input is not accepted. Fixture asset/resource, Finance,
workflow, note, file/photo/upload, contact, reviewer/comment, credential/auth and
receipt data is excluded by rebuilding exact fields.

Graph SHA-256 under the actual PHP schema canonical encoding is
`1dc4ecb5d67e1ed71e60eb2308efc1db95d4252fb4000afe5b48070a4dcd4a63`;
canonical graph size is 41,011 bytes. The reviewed packet seals the exact JSON and
source/dependency fingerprints separately; source hashes are not live acceptance.

Relationships retain exact explicit source identities. A task must belong to one
source project and its forward/reverse links must agree; missing, shared or
cross-department relationships refuse rather than choosing a project. A linked
task's building can come only from its one exact same-department ticket's saved
location/version, with conflicting links refused. The original water-response task
has a project but no ticket/location: no new ticket or building is fabricated.
Unassigned incoming tickets retain null task/project. Canonical plans require exact
same-department contractor/project foreign keys and retain source planned dates.

People eligibility is taken from the actual per-department sample API directory,
which exposes the campus-wide fictional workers and demo manager in all four
sample departments. This is no native account, employee membership, on-site
presence or staffing-capacity claim. The student-requester label is not a field
worker directory member. Seed ticket ownership is null, not copied from source
`created_by`; an eventual real recipient must create a new ticket under the
shared service's current session capability to own it.

Every imported `updated_at` is null. Due/planned dates remain explicitly fictional
source values; the fixed VM clock does not invent a server modification time.
Projects without a source status code use the source's nonarchived lifecycle
`active`; otherwise source status is retained. Original unclassified tickets use
the broad `general` type, without inventing a specific classification. Ticket
positions retain original fixture order within department/status. Missing priority
is null; conflicting task/request priority refuses. No Finance draft is converted
into a cost, invoice/payment evidence or approved budget. `costs:[]` means no cost
rows exported, not a measured zero cost.

## Validation and release limits

The focused Node tests run the deterministic exporter and a pure PHP validator
using the actual `ReviewerWorkspaceSchema::graph(seed=true)` and actual
`ReviewerWorkspaceProjector`/closed snapshot normalizer. No Kernel, private ENV,
database, real grant or provider is booted. Each department and the full combined
selection validate; combined evidence is 25 sources and 21 tasks, with 21,141-byte
JSON in the pinned validator. Nothing is truncated to fit. PHP negative fixtures
reject missing/cross-department relationships, a nonnull seed recipient capability
and a leaked private field. Tests also cover denied raw-state input, source pin
changes, no prohibited fixture side effects and exact retained source counts.

PHP 8.5 reports the pre-existing projector null-array-offset deprecation for a task
with no building. The probe retains it explicitly in `diagnostics`; it does not
suppress it from evidence or replace the missing building. Root owns any separate
existing-file correction. The source/normal CI PHP version and runtime acceptance
remain separate from this pure local probe.

This is **a fresh source baseline only**. It does not recover any user's browser
edits, deleted/reordered jobs, saved comments, uploads, chosen reviewer names,
current plan edits or other session data. It must never be substituted for evidence
that current browser work was preserved. A new shared-mode publication still needs
the separately reviewed current-data reconciliation/seed approval, immutable
publication transition, actual owner/recipient authority, initialization/retention
procedure, MySQL/hosted/browser acceptance and normal release checks. Existing
proposal links, access, comments, silos, customers and live AI/provider state remain
untouched by running this exporter.
