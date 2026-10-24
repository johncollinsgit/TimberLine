# Invoice handoffs and fund references

This bounded, default-disabled source feature supports the Andrew-attributed
physical invoice/check approval and specialty fund-code problems. The source is
attributed Gemini Quick Notes, not a transcript or an accepted campus procedure.
The college's approval sequence, signers, thresholds, ledger and donor rules
remain discovery items. The feature creates no financial integration, payment,
account, notification, access grant or verified fund balance.

## Procedure and authority

Keep the original invoice/check packet and Finance/AP system authoritative.
Record its reference alongside the project, optional existing contractor,
manually entered amount/currency, fund code, restriction reference and source
reference. The next accountable reviewer is explicit. A blank restriction
reference means unknown; an entered code or reference does not validate it.

The minimum procedure is: prepare a packet, confirm its destination and submit,
record the assigned reviewer's decision/reason, explicitly hand off when needed,
then record external process evidence. `approved` means internally reviewed for
routing, never authorization to pay. `externally_completed` means a user has
recorded an external-process reference, never provider-confirmed payment.

`enterprise.finance_enabled` defaults false. Current active, verified tenant
owners/admins with current area/project access can read and manage packets.
Department coordinators, editors, viewers, requesters and contractor contacts
gain no Finance access. The assigned next owner must be an existing active,
verified owner/admin with the same current project access. This is a reusable
conservative software boundary, not a statement that those users are college
Finance signers. Review real institutional roles before rollout.

## State and audit

`packet.save` creates or edits a draft/needs-changes packet; the project binding
is immutable. It stores the complete submitted content and clears review state.
`packet.submit` requires document, fund-code and authoritative-source references.
`packet.review` requires the current assigned reviewer and a confirmed reason;
the decision is `approved` or `needs_changes`. Server actor/time/version are
recorded, never asserted by the client. Same-person review is possible only for
an already authorized assigned owner/admin; no two-person policy is invented.

`packet.handoff` explicitly assigns another eligible reviewer with a confirmed
reason. `packet.reopen` invalidates a submitted/approved/needs-changes review
before content changes. `packet.external_complete` requires internally reviewed
state, the assigned current owner, confirmation, reason and external reference.
Completed packets are immutable; corrections use a separately linked packet.

Commands serialize under the existing tenant lock. Every command requires
record ID, area ID, expected version and actor-scoped request UUID. Same-body
retries return currently authorized state without another revision. Changed
receipts and stale versions fail. Actor, tenant, area, project and linked-contact
authorization are checked again on reads/replays. Encrypted record data and
encrypted before/after revisions preserve the lifecycle. No secrets or actual
document bytes are stored in references, and references are never fetched.

Reads remain available to authorized owners/admins while writes are paused;
mutations/replays remain paused. Disabling rollout denies reads and writes.
GETs never mutate. Listing returns at most 200 authorized packets from a bounded
scan of 1000, up to 200 project/contact choices and 200 current eligible reviewers;
project/reviewer overflow is explicitly indicated. The Finance navigation feature
is suppressed for nonfinancial roles even when the rollout switch is on;
the response is not a complete ledger or campus total. History is the latest
100 revisions, authorized now. Responses are private/no-store.

## API and acceptance

| Endpoint | Meaning |
| --- | --- |
| `GET /enterprise/finance?area_id=...` | Current authorized list and choices. |
| `GET /enterprise/finance/{id}` | Current restricted packet. |
| `GET /enterprise/finance/{id}/history` | Bounded encrypted history. |
| `POST /enterprise/finance/commands` | JSON-only versioned commands. |

`packet.save` takes title, existing project ID, nullable existing contractor ID,
nonnegative integer amount_minor, three-letter currency, nullable text
document_reference/fund_code/restriction_reference/source_reference, and
next_owner_id. Every transition takes confirmed=true and a nonblank reason.
Review adds decision, handoff adds next_owner_id, external_complete adds
external_reference. Unknown fields, asserted reviewers, payment flags and send
instructions fail validation.

`EnterpriseFinancePacketsTest` covers encrypted persistence, actual HTTP
transitions, exact replay/conflicts, actor-bound decisions, review invalidation,
foreign tenant/area/project/contractor/next-owner denial, stale financial roles,
inactive/unverified users, private reads and write freezes with stray HTTP
prevented. UI/browser tests and protected release are separate integration gates.
No live Finance approval, ledger reconciliation or payment is verified here.
