# Private reviewer workspace maintenance — source candidate

This entry is authenticated-owner HTTP cleanup groundwork. It installs no runtime
configuration, scheduler, CLI, retention job, scanner, provider, account or grant.
The existing live publication, recipient sessions, chosen names and comments stay
unchanged. Root must review/import the exact source, run final-candidate checks,
retain compatible old access and approve the publication/runtime transition.

## Admission and defaults

The new `reviewer_workspace.maintenance_enabled` flag defaults to literal false.
The isolated Enterprise owner configuration reads
`SERVICE_PROPOSALS_REVIEWER_WORKSPACE_MAINTENANCE_ENABLED` only with the existing
strict nonsecret workspace approval JSON, `proposal_owner` purpose and one owner
workspace. Metadata/anchor/policies remain available while all ordinary workspace,
comments, uploads, initialization, financial and proposal writes remain off.
Maintenance never enables any of those flags. The proposal availability/read gate
must still be on; this entry does not bypass `Access.owner` or the owner silo.

Both preview and apply require the existing authenticated, active, verified real
owner/admin and current tenant membership. The route stays under the proposal
runtime boundary, auth/verified middleware and pre-session `block(10,10)` lock,
with six requests/minute. Inside each actual erasure/preview transaction, a scoped
maintenance helper rechecks the current native User active/verified state and
owner/admin active membership with locking reads, even if the request cached an
older User or a MySQL nonlocking snapshot exists. Files repeat that fresh check
before the IO claim and final metadata commit; no database lock spans blob IO.
Global Access and ordinary native authorization are unchanged.
The service rechecks the exact existing owner
client/silo/tenant/private synthetic anchor. A reviewer password, display name,
recipient capability, native campus role or caller-supplied tenant cannot confer
maintenance authority.

The fixed route is:

`POST /enterprise-owner/owner-workspaces/{owner-workspace}/proposals/reviewer-maintenance/{kind}`

`kind` is exactly `graph`, `comments` or `quarantine`. There is no generic service,
record ID, filesystem path, upload URL or native/customer cleanup selector.
The route requires same-origin JSON, session cookies and real `X-CSRF-TOKEN`,
including in test environments. Responses and outer routing/auth/runtime/CSRF
errors are private/no-store, no-referrer, noindex and use fixed error envelopes.
No graph/comment/file body or raw exception enters the maintenance response.

## Preview and explicit apply

The body is a closed JSON object of at most 2KiB. All fields are optional:

```
{"limit":25,"after":null,"apply":false}
```

`limit` is an integer from 1 through 25; `after` is null or a UUID cursor previously
returned for the same kind. `apply` is an exact JSON boolean. Omission defaults to
preview; only `true` erases. Strings, numbers or null for `apply`, unknown fields,
query parameters and caller identities refuse. `{}` is the default preview.

A successful response contains `resource`, `dry_run` and the existing pruner's
bounded `result` counts/cursor. Preview checks current ownership, immutable
bindings and current expiry/revocation without erasing content or emitting erasure
audits. It uses the existing tenant transaction/lock coordination, so it is not a
promise of zero lock-row DML. Counts and cursor describe only this bounded page.

Preview is advisory, not a stored authorization plan. Review a fresh preview at
action time; explicit apply rechecks the same current owner/silo/anchor and each
row's actual expiry/revocation under the native pruner's locks. Newly expired rows
within the requested page may become eligible. Active/nonexpired content is never
made eligible by the `apply` flag, a publication name or a stale preview. Use the
returned cursor deliberately; no background pagination or automatic apply occurs.

Kinds run independently, not as one all-or-nothing transaction. Quarantine cleanup
claims an upload, releases DB locks for private encrypted-blob IO, then rechecks
owner/binding/claim before committing the content-free tombstone. A failed erase
retains `cleanup_pending` and logical allocation; a retirement fence rejects late
writers. Treat `failed > 0`, a live lease/409 or an unknown HTTP result as requiring
fresh inspection, not proof that no erasure happened. No automatic retry is added.
Existing tombstones make a deliberate later cleanup idempotent; no spend ledger,
native file, employee task, membership or customer data is removed.

The graph library gains optional `dryRun:false`; its existing omitted-argument
apply behavior and original `processed/erased/next_cursor` result remain intact.
Only an explicit preview call adds `eligible` and `dry_run:true`. The HTTP entry
always supplies the intended mode. Comments and file pruner defaults stay preview.

## Outstanding operational gates

The entry does not constitute installed retention. Final-source CI, target PHP8.4
and MySQL concurrency/host acceptance, exact runtime metadata/cache attestation,
authorized maintenance activation, a real-owner preview/apply receipt and cleanup
verification remain separate. The modern source manifest includes the new route
and wildcard-owned controller; the pinned legacy renderer/allowlist is unchanged.

No scheduler or deactivation automation is registered. A later schedule must have
its own reviewed real-owner authority and bounded monitoring/reconciliation; it
must not fabricate a User or reuse a recipient session. Deactivation must preserve
anchor metadata and an authorized cleanup path until remaining expired content is
verified erased. Turning off maintenance closes this HTTP entry; it does not erase
anything or attest successful cleanup. No environment/cache mutation, feature
activation, old-edition rebinding or actual cleanup ran while preparing this packet.
