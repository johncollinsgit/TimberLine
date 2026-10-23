# Department acceptance and activation design

Status: **plan only**, October 22, 2026. No schema, permission, entitlement,
subscription, schedule, provider or hosted workspace is changed by this document.
The latest owner direction is to work through setup with the department head,
obtain COO clearance and keep department staff out until clearance. This is a
reusable Enterprise process rather than a Lees-McRae-specific role bypass. The
exact approval policy and commercial terms still require review.

## Recommended initial process

Separate readiness, acceptance, commercial approval, access and billing. None
is evidence that another step happened. Login, opening a proposal, completing
a tour, visiting a workspace, reaching a date or saving a layout must not enable
staff, accept terms, start a subscription or create a charge.

| Stage | Minimum work and evidence | Authority / boundary | Result |
| --- | --- | --- | --- |
| Discovered | Identify the actual department, head, accountable process owners, source systems and problems to fix. Record unknowns and source references. | Approved discovery participants; no staff operational access implied. | Versioned scope and acceptance checklist draft. |
| Setup in progress | Configure approved workflows, shared versus restricted records, role matrix, locations and demonstration examples. Track blockers and deferred items. | Explicit provisional setup grant for the named head and approved implementer only. | A reviewable candidate, not a live department or billable subscription. |
| Acceptance ready | Demonstrate agreed scenarios with fictional/approved records; attach evidence, exceptions, known limitations and the exact version/configuration under review. | Head reviews their department; visibility obeys the setup policy. | Frozen checklist and candidate version awaiting decisions. |
| Head validated | Head explicitly accepts the operating procedure and checklist, or requests changes with a reason. | Current authorized department-head approval capability; never infer from a job title or first login. | Version-bound operational acceptance receipt. |
| COO commercial / activation review | Review the same candidate, readiness, exceptions, support scope, proposed department activation, staff roster and price/term acknowledgement. | Explicit commercial/activation approver capability for this client; read preview is a separate capability. | Approved/rejected changes with a server actor/time/reason and frozen terms reference. |
| Activation prepared | Choose the effective date/time and timezone; review the exact staff access changes and subscription action, including any approved pilot exception. | An authorized operator presents a dry run. No writes or provider call during preview. | Exact activation plan hash, expiry and explicit confirmation. |
| Activation applied | Recheck both approvals under the selected policy, candidate/terms/roster versions, date, actor authority and current runtime readiness; apply the explicitly confirmed plan. | Dedicated activation service and existing canonical entitlement/access services. | Durable activation/access receipts; billing requested/confirmed is tracked separately. |
| Operating / follow-up | Confirm each intended user can perform only their permitted work, record training/adoption follow-up and unresolved exceptions. | Current department roles and ordinary record ACLs. | Acceptance evidence, accountable next action and a supported operating department. |

Recommendation for the first release: require both head validation and COO
activation/commercial approval, with staff remaining blocked until their exact
activation plan is applied. **This is a proposed default, not a settled approval
policy.** Whether both approvals are always mandatory, authorized delegates may
substitute, and one person may hold both capabilities remain decisions to record.
Missing decisions or approvals fail closed; the implementation must not silently
pick a less restrictive policy.

The effective date is an acknowledged term, not an automatic permission or billing
trigger. A scheduled plan remains pending until its approved execution rule and
freshness checks permit an explicit application. A future scheduler may execute
an already confirmed, still-valid access plan only under a separately reviewed
policy; it must not invent billing consent or create replacement charges.

## Provisional setup, executive preview and maintenance

Before activation, the named head may use a narrowly scoped setup workspace to
review configuration and acceptance examples. Other staff do not gain operational
read or write access merely because they are employees, appear in a roster, share
an email domain or are proposed assignees. The head's setup access must have an
approved purpose, department scope, permitted actions, expiry/review date and
revocation route. It must not masquerade as an already activated subscription.

COO preview should allow agreed readiness/checklist summaries and authorized
operational summaries where separately granted. Commercial approval, changing
configuration, financial detail, notes, files, mailboxes and acting as another
user are independent capabilities. A read-only preview cannot grant itself
approval or mutation authority. Cross-department access needs explicit scope;
do not make a client-wide viewer a global record administrator.

Platform-owner maintenance is also separate: approved purpose, target client and
department, minimum actions, time limit, reason and immutable audit. Routine
support should not require tenant-owner impersonation, hidden membership creation
or a universal data bypass. Emergency access policy, if needed, requires its own
review and must not be inferred here. Client admin roles remain client-scoped;
the word owner does not imply landlord access across clients.

Identity verification must precede the grant/approval. Reuse existing verified
identities where suitable, bind actions to the signed-in actor, and require an
approved provisioning/invitation process for new ones. Do not copy retail
passwords/MFA/provider grants, mark an arbitrary email verified or substitute the
platform owner's mailbox. Identity proof, invitation delivery, delegations and
expiry rules remain explicit decisions rather than assumed campus policy.

## Existing source and concrete gaps

| Current source | What exists | What this activation design still needs |
| --- | --- | --- |
| [`ClientProvisioner`](../../app/Enterprise/Provisioning/ClientProvisioner.php), [`ClientManifest`](../../app/Enterprise/Provisioning/ClientManifest.php), [provisioning runbook](../operations/enterprise-client-provisioning.md) | Exact-hash structural plan/apply/status; independent client binding; one tenant, departments and approved catalog/default configuration. Replays preserve later edits. Provisioning deliberately creates zero users, memberships, entitlements or billing. | Department discovery/acceptance lifecycle, approval authority, terms and activation plans are absent. Initial provisioning must not be expanded into an implicit access or billing command. |
| [`WorkspaceAccess`](../../app/Enterprise/WorkspaceAccess.php), [`TeamWorkAccess`](../../app/Services/Team/TeamWorkAccess.php), [`ResolveWorkspace`](../../app/Enterprise/ResolveWorkspace.php) | Fresh active/verified tenant membership, canonical `team_work` entitlement, rollout/write gates and department/native record ACLs. Tenant owners/admins currently have broad department authority; shared areas can be read by eligible tenant members without area membership. | No department activation gate, head-only provisional setup capability, commercial approver role, COO-purpose grant or maintenance-purpose grant exists. **Mapping every COO/head/support actor to tenant owner/admin would violate this proposed boundary.** Existing active shared-area semantics cannot enforce pre-activation staff denial on their own. |
| [`WorkspacePreferences`](../../app/Enterprise/WorkspacePreferences.php) | Display/intake settings; department-workspace licensing presentation with pricing explicitly uncommitted and billing unchanged. | Saving settings is not acceptance or activation; preferences must never become the source of paid status, staff access or approval. |
| [`RolePreview`](../../app/Enterprise/RolePreview.php), [preview runbook](../operations/enterprise-executive-and-member-preview.md) | Existing-owner/admin read-only projection intersects observer/member department/native ACLs. Public COO persona and rollout stages are explicitly simulated. | Neither is a production COO approval service, provisional setup policy or department subscription. Preserve the sample boundary and do not treat its persona as a real identity. |
| [`module_catalog.php`](../../config/module_catalog.php), [`TenantModuleAccessResolver`](../../app/Services/Tenancy/TenantModuleAccessResolver.php), [`TenantModuleActivationPolicyService`](../../app/Services/Tenancy/TenantModuleActivationPolicyService.php) | Canonical tenant module definitions, access/activation policy and entitlement resolution. | A department-scoped commercial unit and activation gate must compose with these tenant-level checks, not bypass or duplicate them with ad hoc price/role flags. Catalog retail offers do not establish Enterprise prices or allowances. |
| [`LandlordCommercialConfigService`](../../app/Services/Tenancy/LandlordCommercialConfigService.php), [`LandlordOperatorActionAuditService`](../../app/Services/Tenancy/LandlordOperatorActionAuditService.php) | Existing landlord entitlement/configuration mutations and before/after audit with billing-impact metadata. | These methods are not the new two-stage approval/idempotent department activation coordinator. The dedicated Enterprise provider does not compose the retail landlord UI/observers. Reuse reviewed contracts deliberately; do not boot unrelated retail billing/provider behavior to enable setup. |

This document is source inspection and design, not a tested implementation of the
new gate. Existing negative tenant/role tests remain useful foundations, but do
not prove the proposed head/COO/staff activation rules are enforced today.

## Proposed records and service boundaries

Use additive, versioned client-owned records; final schema choices follow design
review. Keep these concepts distinct even if some reuse encrypted operation
records initially:

- **Department onboarding dossier:** client/tenant/department binding, discovery
  source references, agreed problems, procedure owners, acceptance checks, evidence
  references, exceptions, candidate version/digest and lifecycle version.
- **Purpose-scoped authorization:** existing verified identity, setup/preview/
  approve/activate/maintenance purpose, explicit department scope, permitted
  actions, approved grantor, reason, effective/expiry/revocation and audit. It
  grants no role merely from a display label or acknowledged price.
- **Approval receipts:** head validation and COO decision as independent events,
  with authenticated actor, authority snapshot, UTC timestamp, candidate/checklist/
  terms digests, decision, reason and any reviewed exception. Material changes
  invalidate affected approvals instead of silently carrying them forward.
- **Commercial acknowledgement:** approved proposal/term version, department unit,
  currency, cadence, amount and setup/support scope only when actually agreed,
  activation date/timezone, payer authority and acknowledgement receipt. Unknown
  fields remain unknown and block paid activation; a sample amount is never copied.
- **Activation plan/receipt:** exact candidate, approval/policy, roster/access
  changes and entitlement/billing intent versions; actor-scoped idempotency key,
  canonical input hash, expected version, expiry, before/after state and result.
- **Billing action/status:** separate requested, uncertain, confirmed or rejected
  outcome with the selected authoritative billing process reference. A local flag,
  login or HTTP redirect is not provider-confirmed subscription activation.

Planned service hooks:

1. `DepartmentSetupAccess` resolves provisional identity/purpose without making
   an inactive department pass ordinary `TeamWorkAccess`. Setup routes expose
   only the permitted configuration/acceptance material. This is a separate
   server policy, not an exception that turns off tenant checks.
2. `DepartmentAcceptance` owns dossier versions, evidence and head decisions.
3. `DepartmentActivationPolicy` verifies the configured head/COO approvals and
   terms against the exact candidate and proposed staff scope.
4. `DepartmentActivation` produces a no-write preview and explicitly confirms an
   exact plan under a tenant/department lock. Every route rechecks current actor,
   client, tenant, purpose, department and record authority.
5. A department activation check composes with `WorkspaceAccess` and native graph
   access across lists, search, maps, files, exports, requester updates, AI and
   direct command routes. Hiding navigation alone cannot enforce the boundary.
6. Existing membership/entitlement contracts apply only the approved staff changes.
   A role/record assignment, workflow run, invitation, provider connection or
   subscription is never an incidental side effect of setup or first login.
7. Billing orchestration remains separately confirmed and idempotent through the
   existing reviewed billing lane or an explicitly agreed manual process. No
   external billing request belongs inside an uncommitted database transaction.

For safe retries, the same actor/key/body returns the original authorized result;
changed bodies or stale candidate/terms/roster versions fail. Approval revocation,
expired grants, changed commercial terms or scope widening invalidate pending
plans. Concurrent confirmations serialize. External uncertainty retains one
durable pending operation and requires reconciliation before replacement; no
double subscription/charge or blanket rollback of unrelated access. Partial
application stays visibly pending and fails staff activation closed until the
exact intended result is reconciled. Disabling access preserves evidence rather
than deleting records or silently cancelling billing.

## Initial release and later work

The smallest usable implementation is one real department dossier with a short
acceptance checklist, head-only setup, read-only COO review, explicit versioned
head/COO decisions, clear activation date/terms acknowledgement, a dry-run staff
roster and confirmed activation receipt. Keep notifications manual/in-app and
billing inactive until its real process, authority and terms are approved. An
explicitly agreed pilot can use a separately recorded pilot state; it is not an
automatic free or paid subscription. Demonstrate the full process with fictional
identities/records in isolated tests before any client permission is applied.

Later enhancements may include richer evidence, delegated approvers, reusable
checklist templates, renewal/change approvals, expiry reminders, approved identity
providers, provider billing reconciliation and scoped support sessions. None is
required to pretend the initial approval gate already exists. Proposal editing/
signing and its future correct-client top-bar link belong to the separate reusable
Proposal plan; reference its immutable accepted version rather than duplicating
that system here.

Minimum acceptance checks for a future implementation: unactivated staff cannot
read or mutate through any UI/API/file/AI route; provisional head cannot cross
departments; COO preview cannot mutate or approve without the separate capability;
support purpose cannot become tenant owner; wrong client, revoked identity,
expired delegation, stale approval and changed terms are denied. Confirmed exact
retries and concurrent actions must not duplicate grants or billing, GET must
not activate anything, and a date/first login must never create access or a charge.
Staff can operate only after the approved activation receipt exists and current
ordinary membership/entitlement/record checks also pass.

## Included support versus separately quoted work

No verified Enterprise support-hour or meeting-allowance agreement was found in
the inspected source/runbooks. Do not invent an included count, response SLA,
onsite allowance or accepted price. The separate proposal owner must supply the
approved terms and their source/version before these fields can be acknowledged.

Candidate scope categories for that agreement, **not newly promised inclusions**:

| Category | Definition to agree and record |
| --- | --- |
| Included product/support work | Use of the contracted existing product; documented configuration and onboarding/training tasks expressly listed in the accepted scope; support triage and correction of a reproducible defect against agreed behavior. Hours, meetings, channels and service levels only as the approved terms state. |
| Consulting | Process discovery/redesign, reporting/accounting interpretation, data cleanup/mapping, onsite facilitation or extra training beyond the agreed scope. Quote deliverables, owner, assumptions and acceptance before work is chargeable. |
| Integration | New or materially changed source system/API, import, synchronization, provider configuration, security approval or external access. Quote discovery and acceptance separately; never imply that a reference link is a working integration. |
| Development | New screens, workflows/domain rules, bespoke reports or other functionality beyond the accepted product/version. Use a reviewed change request, estimate/price basis and explicit scope approval; a brainstorm is not a commissioned feature. |

Ambiguous defect/configuration/change requests need a recorded classification and
owner decision; do not silently consume an invented allowance or bill the client.

## Decisions collected for later review

These are unresolved design/commercial decisions, not requests to interrupt the
current user conversation:

- Must both head and COO approve every department, and must they be different
  people? What delegation, exception and reapproval policy is acceptable?
- Which identity proof, verified-domain/invitation process, grant duration and
  revocation authority are approved? Who names the real head and COO approver?
- Must cross-department staff have explicit memberships even for shared areas,
  and what can COO summaries reveal without granting private-record access?
- Which initial staff roles/roster and maintenance purposes are approved, and
  how are setup drafts/private evidence separated from operating records?
- What exact price, currency/cadence, pilot terms, activation timezone/date,
  payer authority, billing process and subscription-confirmation evidence apply?
- What support hours, meeting allowance, training/on-site scope and change-request
  terms are actually approved in the separately owned proposal?
- What blocks activation, what counts as an accepted exception, and how do later
  suspension, offboarding, renewal or material scope changes affect access and
  subscription status independently?

Per-client backup/restore and searchable deleted-item recovery remain their
separate **plan-first** design. This activation plan neither activates a backup
job nor establishes recovery retention, operational monitoring or restorability.
