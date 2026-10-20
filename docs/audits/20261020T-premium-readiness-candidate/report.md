# F07 premium assignment/readiness candidate

Base: released `00acdc1b05b04e81356c53601f6a6f653dd6f843`. Separate candidate
branch `codex/everbranch-premium-readiness-20261020`. Coordinator retains sole
release ownership. No merge, deployment, provider, configuration or customer
mutation was performed here. Original 75.3 and accepted 87.3 audits are immutable;
this candidate does not claim a new score or live acceptance.

The existing settings tabs now distinguish recorded advanced-software assignment
from the public/purchasable branch catalog. Current-tenant assigned products are
shown in Your branches and in Setup when attention is needed; unassigned products
remain under Add software. No catalog/internal visibility is broadened. Plan
counts distinguish core branches and advanced-software attention.

`TenantModuleAccessResolver::has_recorded_access` is read-only information after
active-date/explicit-disabled/override assignment decisions, before only the
availability/dependency/setup guards. It never changes `enabled` or `canAccess`.
Expired/future/explicitly disabled assignments do not become current ownership.
The adapter combines this canonical metadata with existing product rollout/setup
and authorization sources; membership, financial and provider policies remain
unchanged. Marketing `markets_makers` maps to canonical `markets_and_makers`.

States: Available to discuss; Waiting for rollout; Setup needed; Access unavailable;
Access managed by owner; Workspace available; Unable to confirm. An Open link
requires the actual configured primary workspace plus existing authorization.
TeamWorkAccess, FinanceAccess and verified current-tenant Marketplace operator
checks remain authoritative. Optional Teams components are independent: a ready
secondary component cannot open an unfinished primary, and an unassigned component
is never invented. A ready primary may open while optional setup is explicitly
shown. The Marketplace link uses its canonical app host, not a tenant host fallback.

Product summaries contain names/coarse setup status and authorized links only,
not source/entitlement/billing/price metadata, financial space names, balances,
accounts, credentials or foreign-store details. Active household FinanceAccess
permission remains supported; ordinary business members receive no financial link.
Unexpected source/policy read failures atomically become unknown, not false
ownership. Inactive memberships receive no assignment details or links.

Short native details explain next steps and explicitly distinguish workspace access
from payment/external-connection readiness. Previewing these summaries does not
activate, purchase, connect or send. In particular MarketplaceAccess::settings()
(which can create a settings row) is never called by the adapter.

Verification:

- Final focused contracts/navigation/billing-safety group: 44 pass, 387 assertions;
  five fixture-export cases intentionally skipped in the normal invocation.
  Includes 27 new contract cases for assignment, expiration/override, exact mapping,
  foreign tenants (including owners of both stores), genuinely authorized foreign
  finance spaces, role/financial boundaries, rollout/allowlist/setup, mixed Teams,
  unknown verification and canonical app host. SQL instrumentation checks that
  actual summary calls issue no insert/update/delete/DDL; HTTP is prevented.
- Full isolated PHP suite: 4,118 pass, six intentional fixture-export skips and
  only the two known DNS-dependent cases blocked by the OS network sandbox.
  Both inspected fake-mail/fake-HTTP cases pass separately (11 assertions),
  giving 4,120 unique normal passing cases. Full failure XML and rerun XML are
  preserved instead of hiding the sandbox failures.
- Synthetic Blade fixture export: eight pass, 45 assertions. Five role/state
  fixture variants serve actual built assets over loopback with all non-GET and
  external requests intercepted. This is not production login/session acceptance.
- Chromium/WebKit: 22 browser cases pass, including existing tab/guide keyboard,
  cancellation/Escape/focus return, owned/gated/member/unknown products at
  390/820/1440px, canonical links, no premium purchase action, no POST/page/asset
  errors and no horizontal overflow. Desktop and mobile screenshots reviewed.
- Production build and Pint pass. An initial browser run loaded a stale CSS hash
  during a simultaneous rebuild; fixture export was rerun after the final build,
  and the suite now explicitly checks stylesheet loading and asset HTTP errors.
- `preservation.json` verifies all relevant config, route and product/financial
  authority files match the released base byte-for-byte; canAccess() itself is
  unchanged. The original dirty worktree fingerprint and frozen audit branch
  tip are preserved. No migration or default/rollout/collection gate changes.

The coordinator must review exact candidate CI and live scoped behavior after an
approved serialized release. Genuine provider/payment readiness remains separate.
Any new R4/X3 acceptance belongs in a later versioned audit after review; this
implementation neither awards those points nor implements the optional agent.
