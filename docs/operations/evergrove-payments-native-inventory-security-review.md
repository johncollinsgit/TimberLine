# Independent security and test evidence — 2026-10-25

Scope: the resumed native API/fixture checkout and shared Website inventory candidate in `task/checkout`, based on `de0172171c62e23d760966f23946928a9a49f464`. Results below were run against the working candidate, not a committed integration head. Final exact-head CI remains the parent owner's gate. No production, account, merchant, credential, desktop UI, simulator UI or physical-phone operation was performed.

## Independently executed results

- PHP synthetic provider authority: **8 cases, 25 assertions passed**. Covers durable response-loss recovery, changed immutable terms, binding/kind isolation, runtime production denial even with flags injected, and exclusion of extra untrusted data from the fixture ledger.
- Real local MySQL **8.4 / REPEATABLE READ: 9 cases passed**, using the exclusive disposable security-test database. Covers rollback after an actual hold insert, missing-site canonical identity retention, duplicate canonical effect across tenants, all-line stock review before any write, same-version repair exactly once, legacy Website settlement counting NULL-order/NULL-expiry unknown holds, revoked settlement authority and terminal contradiction, unknown refund budget/no restock, and production denial through explicit fixture composition.
- Full Swift package: **35 tests passed**, including **12 independently authored boundary/recovery tests**. Covers exact origin/redirect denial, credential controls and redaction, selection-only request bodies, hostile browser money/paid fields/coercion, canonical response validation, quote/payment/refund replay after restart, original UUID preservation after quote expiry, durable intent before suspension, actor reentrancy, foreign response rejection, corrupt/foreign scope restoration and noncanonical processing-to-unknown updates.
- Owned PHP files pass Pint. `git diff --check` passes.

Evidence logs are `review/native-inventory-simulation-final.log`, `review/native-inventory-mysql-final.log`, and `review/native-inventory-swift-final.log`. Swift used a separate scratch/cache directory and `--disable-sandbox` because nested SwiftPM manifest sandbox initialization is unavailable in the execution sandbox. No desktop or application was opened.

## Defects found and corrected by the implementation owners

1. Native response decoder accepted canonical success with evidence version zero. Terminal responses now require a positive evidence version; the independent regression passes.
2. Native monotonic comparison rejected a legitimate processing(version 0) to unknown(version 0) status transition, since backend uncertainty can change without new provider evidence. The bounded noncanonical transition is now accepted while immutable identity, backward-version and terminal-regression checks remain; the independent regression passes.
3. An unavailable original inventory site could throw out of canonical settlement and lose the durable provider-effect identity. The expected missing-site path now enters inventory review while retaining canonical effect/version; the independent deleted-site check passes.

Current tax policy is rechecked by the explicitly injected local reservation authority inside the journal transaction, including after locked catalog reads. The default inventory authority and tax policy remain unavailable. Canonical stock review retains its terminal payment result and holds; SQL/storage faults still roll back.

## Reviewed design boundaries

Payment intent and all shared holds commit together before provider access; settlement and movement commit together. Core-owned holds use mutually exclusive operation ownership and no expiry, without creating Website financial orders. Shared Website/POS exclusion queries include NULL order owners. Marketplace retains its preexisting future-expiry behavior and additionally counts every active core hold with a current locking read. Provider effects are globally unique for the exact provider/merchant/environment before stock mutation. Refunds reserve monetary budget and do not restock.

The unchanged bundled register remains a browser simulation. A native button reads a fixed selection-only script, validates canonical SKU/quantity, and requires a fresh fixture quote plus separate native confirmation. There is no browser message handler, imported browser payment status, card collection or browser token handoff. The typed HTTP layer constructs scoped requests and performs strict roundtrips through an injected executor; the default executor rejects networking and the app uses its fixture transport. No live dispatcher, URLSession or credential reader ships. Fixture ledger/client stores are local single-writer rehearsal storage, not production persistence or backend integration proof.

## Remaining gates and limits

- Production flags/registers/providers and the new PKCE tuple remain closed; production collection remains hard-denied even with injected fixture flags/capabilities.
- No actual URLSession/TLS/auth exchange, token vault, approved PSP SDK, entitlement, signing/distribution, merchant account/location, real tax decision, printing/service/postage mapping, physical install, live charge/refund, or remote device update was verified.
- Native Swift package tests execute on macOS. iOS compilation evidence belongs to the native owner. Interactive UI/physical-device checks were not run under the user's restriction.
- Inventory race/migration recovery suites and HTTP integration tests are owned by their implementation agents; their reported counts are separate from the independent results above. The parent must run the integrated committed head and publish only a draft candidate through existing authorized CI.
- Final read-only defect review and rebase conflict review follow this authored/tested checkpoint. Any findings from that phase are returned directly to the parent for correction; no implementation was edited by this reviewer.

## Final frozen-source review

Read-only review result: **No findings.** The ownership CHECK comparison now parses its finite NULL/AND/OR grammar while preserving grouping and precedence; the exact empty-retained-table regression passes without deleting evidence. Native recovery accepts the backend's unresolved processing-to-unknown transition at the same canonical revision for both zero and positive revisions, while retaining immutable money/identity and terminal guards.

The native owner's final run passed **43 Swift tests**, including seven injected-HTTP roundtrip tests, and **two canonical-renderer handoff tests**. Generic unsigned iOS build-for-testing and unsigned Release device-target compilation passed. The complete bundled register, including HTML/JavaScript/CSS/assets, has no diff. Backend owner verification passed **30 MySQL tests / 148 assertions**, and API owner verification passed **six MySQL HTTP tests / 86 assertions**. These include implementation-owner results; independent counts above remain separate.

The prior-layer main rebase at e297508d received a separate read-only conflict-resolution review: no findings. Final integrated source, refreshed schema and exact-head CI remain the coordinating owner's recorded gate. No physical install, interactive UI test, real networking/authentication, PSP/merchant/tax acceptance or production activation is established.
