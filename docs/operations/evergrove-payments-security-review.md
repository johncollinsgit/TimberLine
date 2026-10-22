# Evergrove Payments independent acceptance matrix

This matrix applies to the separate native/backend payment lane. It does not authorize collection, enable the existing POS simulator in production, or certify PCI compliance. Implementation review and executable results are recorded separately once the agents finish their milestone.

## Independent review checkpoint — 2026-10-22

The credential-free foundation is acceptable for continued source review and rehearsal after the fixes below. **Real collection remains NO-GO.** The backend is an unregistered internal domain with no enabled authorizer, no provider implementation, no HTTP endpoints and a SQLite reference journal restricted to test bindings. The native app reuses the existing Alpha browser register offline inside an Evergrove-branded SwiftUI shell; the separate native recovery lab is fixture-only. These components are not an authenticated end-to-end checkout or native Terminal integration.

### Findings resolved before acceptance

1. **One canonical provider effect could credit two sales.** An independently written test reproduced two distinct sales becoming succeeded from the same synthetic provider payment ID. The backend now fingerprints the exact provider/merchant/environment, rejects reference reuse across tenants/registers/locations within an operation kind, and enforces a unique index inside the serialized transaction. Mismatch, reference reuse and terminal contradictions emit fixed audit events. The independent reproduction now passes.
2. **A native crash after durable reservation left no recovery action.** Restart restored `.reserved`, while the UI permitted recovery only for `.unknown`. The core now restores both reserved and processing attempts as unknown and retains the original attempt/request identity. An independent compiled Swift scenario verifies recovery without replacement and continued live denial.
3. **Native/backend request limits differed.** Native now matches the backend's 1–50 lines, quantity 1–99, positive unit minor amounts, nonnegative line tax and maximum 100,000,000 minor-unit total. Both use the same snake-case DTO and explicitly typed merchandise/printing/service/postage semantics; production price/tax authority remains server-owned. A shared fixture totals 3,730 minor units in both test suites.
4. **Wrapper policy and print copy needed clarification.** Native explicitly denies popup/new-window navigation, external/custom schemes and navigation to any file other than its exact bundled index document (with only optional `#main`). Folder-scoped read access separately permits the bundled script/style/image subresources. The adapted HTML now describes receipt previews; physical printing remains unimplemented/unverified. Canonical JavaScript/CSS bytes are unchanged.
5. **A delegate near-match warning and ambiguous encoded path required hardening.** The optional WKNavigationDelegate method now uses the correct main-actor/sendable completion signature; the final build must have no near-match warning. Independent testing then found the original folder-prefix predicate accepted encoded `..%2f` navigation. No out-of-folder read was demonstrated, and WebKit's folder read access was a separate boundary. Replacing the navigation predicate with exact percent-encoded index-path equality removes that ambiguity; uppercase separators, backslash, null, external/custom schemes, foreign files/hosts and popups all pass independent deny tests.

### Executed checks and evidence limits

- Backend base commit `f2ae6c2a5eb8cc3c3f4f8df8ce639f7cc73bd925`: independently executed the author's 16-scenario harness, including three separate eight-process races for request replay/once-only dispatch, competing unresolved register sales and refund budget reservation. All passed against disposable synthetic SQLite/provider data.
- Independently authored backend adversarial harness: 8/8 passed, covering default authorization/live denial, every immutable binding dimension, changed idempotency terms, unknown/replacement behavior, reserved refund budget, duplicate canonical effects and strict payload/money validation.
- Reused Alpha register Node engine/UI tests: 20/20 passed. These execute canonical JavaScript with synthetic DOM/state; they are not a physical reader, printer, scanner or WKWebView device test.
- Independent native bundle/provenance checks: 24/24 passed, including source hashes, unchanged bundled asset bytes, original DOM identifiers, local resource paths, restrictive CSP, no symlink escape, hidden print control, nonpersistent browser storage, no script-message handler/payment bridge, explicit popup denial and correct delegate completion signature. These are static source checks.
- Independently ran the full native Swift suite: 15/15 tests passed, including the shared backend fixture and navigation policy. Initial invocation failed because the outer sandbox prevented SwiftPM creating a nested sandbox; the supported `--disable-sandbox` option with cache/config/security/scratch directories inside the review workspace succeeded without an approval escalation. This is a local package with no external dependencies or build plugins.
- Independent compiled native regression: 4/4 core checks and 16/16 navigation cases passed for crash-after-reservation recovery, retained identity, blocked replacement, live denial and exact bundled-document allowlisting. The native file journal is a single-engine/single-process fixture store; it is not a production concurrency or authenticity mechanism.
- Native agent performed unsigned simulator/device-target builds and simulator UI tests. Its final exact-source evidence is recorded in the native README/build receipts; the independent source/core checks here do not substitute for those runs. No physical iPhone, Apple entitlement, merchant onboarding, real PSP test-mode, live charge/refund or production deployment was verified.

The Mac inventory found Xcode 26.3/SDK 26.2, historical Everbranch and separate Modern Forestry iOS projects, and development signing identities. App-specific distribution entitlement/authority remains unverified. Known iPhones were unavailable to developer tooling; cached device/OS information is not a physical availability or SDK compatibility check. Personal Context retrieval was unavailable, but local `.claude/projects` memory notes were found and read; they are historical project-location context, not proof of current signing/provider readiness.

### Reproducible independent commands after repository integration

Run from the repository root. All financial inputs are synthetic; no environment file, network or provider credentials are loaded by the independent PHP/Swift harnesses.

```sh
php tests/PaymentsCore/adversarial.php
python3 scripts/qa/evergrove-payments-bundle.py --repo-root . --native-root native/evergrove-payments-ios
swiftc -module-cache-path native/evergrove-payments-ios/build/review-module-cache native/evergrove-payments-ios/Sources/EvergrovePaymentsCore/*.swift native/evergrove-payments-ios/scripts/NativeAdversarial.swift -o native/evergrove-payments-ios/build/native-adversarial
native/evergrove-payments-ios/build/native-adversarial
```

Preserve exact-head final test/build receipts when integrating these sources; a prior commit's green results do not establish a later merge/release. Future gates include an authenticated and tenant-scoped mobile API, server pricing/tax and actor/device permission checks, a production durable repository with MySQL acceptance, one verified PSP adapter, scoped Terminal tokens, signed/canonical reconciliation and original-payment refund servicing. The owner/provider/Apple/physical acceptance steps are in `evergrove-payments-iphone-launch.md`.

## Ongoing acceptance matrix

| Area | Adversarial case | Required behavior |
| --- | --- | --- |
| Scope | Alpha attempts to use Wren merchant; foreign tenant/register, provider or environment supplied | Resolve scope from current authorized server session; reject before provider call |
| Binding | Merchant changes after attempt reservation or before a refund | Preserve original tenant/provider/account/location/environment; never silently rebind |
| Authorization | Operator role removed; session/device expires; collection disabled after reservation | Recheck current permission for each operation; servicing gate independent of new collection |
| Authority | Client submits prices, tax, paid flag, fake provider ID/callback, unknown fields | Server owns final quote/tax/binding; client data cannot establish provider success |
| Money | Negative/zero payment, floating point/string, overflow, excessive quantities/tax/lines, duplicates | Bounded integer minor amounts; consistent native/backend contract; reject invalid payload |
| Semantics | Printing/service or postage treated as stock item | Explicit line kind and confirmed policy; inventory only for actual merchandise |
| Idempotency | Same key/same payload after restart | Return original attempt, no second collection |
| Idempotency | Same key/different amount/sale/currency/binding | Conflict before provider call |
| Concurrency | Two processes submit same key/sale; different keys for same sale | Atomic durable reservation and one provider effect; one active/succeeded sale |
| Crash | Process dies after reservation, provider creation or before persistence | Unknown/recovery state; query original provider with original operation key |
| Recovery | Timeout/null status, key expires, unavailable original provider | Keep unresolved; do not create replacement or infer failure from silence |
| Reconciliation | Duplicate/stale/reordered/mismatched signed events | Verify canonical identity and monotonic state; immutable terminal success; flag contradiction |
| Refunds | Concurrent partial refunds exceed paid total | Reserve refund balance atomically, including processing/unknown reservations |
| Refunds | Refund from foreign/same-provider different merchant; altered original transaction | Reject before provider call; original account/transaction only |
| Refunds | Replayed same refund key, changed amount; failed/unknown provider response | Original outcome/recovery only; pending funds stay reserved until canonical release |
| Storage | Local journal tampered, corrupt file, persistence failure or stale second writer | Fail closed and keep in-memory state consistent; never unlock a new collection |
| Privacy | Card/PIN/CVC/tracks/identity/bank secrets supplied or returned | No raw card input/storage/logging; provider SDK handles encrypted card acceptance |
| Tokens | Unauthenticated/foreign client requests Terminal connection token | Deny; backend-only key; bind to trusted authenticated merchant/location; no token caching/logging |
| UX | Local fixture completes, app relaunches, network disappears | Show test-only/unknown state truthfully; no real paid receipt or duplicate-charge invitation |
| Native lifecycle | Double taps, background/foreground, killed app, stale async completion | Serialize commands, preserve original attempt, suppress duplicate effect |
| Release | Old tests pass but source changes or integration base moves | Run relevant checks at exact final head; report never-run device/provider/live checks |

No standalone local journal or in-memory fake-provider success establishes external settlement. Any future adapter must add a trustworthy canonical status and refund implementation, durable production repository, authenticated API, restricted token endpoint, reconciliation worker/webhook verification and merchant readiness checks before rollout.

Adapter state/version contract: a version must represent a trustworthy monotonic provider revision or a serialized fresh canonical retrieval. Webhook arrival time, delivery timestamp and arbitrary client sequence numbers are insufficient. `failed` and `cancelled` may be emitted only when the adapter proves a definitive terminal result that cannot later complete; retryable declines, `requires_payment_method`, timeouts and ambiguous cancellations must remain processing/unknown/review as appropriate. This matters because the reference core preserves terminal decisions. Contradictions must remain visible to servicing/audit rather than erase settled value.
