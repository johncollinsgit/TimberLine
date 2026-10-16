# Everbranch AI Voice acceptance evidence

Local implementation checkpoint, October 16, 2026. Source: the restored product
and implementation plan, criteria 1–35. This matrix distinguishes implemented
paths from combined release acceptance. No production activation is authorized
by these results. Fictional fixtures and fake providers are used throughout.

Evidence references:

- N: `tests/Feature/Search`, `tests/Unit/NavigationSearchProviderTest.php`,
  `tests/Feature/Mobile/EverbranchMobilePlatformTest.php`, mobile navigation and
  speech lifecycle tests.
- A: `tests/Feature/AiBranch/AiBranchTest.php` and
  `tests/Feature/AiBranch/WorkspaceContextTest.php`.
- B: `tests/browser/voice-navigation.smoke.mjs`, paired with mobile browser
  fixture. Includes fake recognition, permission failure, ambiguity, revoked
  destination, unsaved draft/upload cancellation, focus containment/restoration,
  stale responses, safe generated text, memory, approval/undo and weekly pagination.
- M: mobile lint, 94 unit tests and TypeScript/Vite production build. Physical
  Android/iOS acceptance is not supplied by a browser build.

| # | Requirement | Implemented evidence and remaining gap |
|---|---|---|
| 1 | Buy/configure number | Open: provider provisioning, exact purchase preview and number ownership. No purchase made. |
| 2 | Port number | Open: eligibility, port lifecycle and status reconciliation. |
| 3 | Make/receive supported calls | Open: telephony adapters, call lifecycle and physical devices. |
| 4 | Hours/routing | Open: versioned rules, timezone/holiday and fallback tests. |
| 5 | Voicemail | Open: storage, access, delivery and retention. |
| 6 | Tenant-isolated call/message records | Existing message scope is preserved; new phone record isolation remains open. |
| 7 | Accurate usage/billing | A proves existing AI reservation/replay boundaries. New call/SMS usage reconciliation remains open; no billing changes. |
| 8 | Provider outage visibility | A proves uncertain AI allowance, no automatic retry. Phone/mail health and outage paths remain open. |
| 9 | Emergency calling | Open: geography/configuration, behavior and disclosure. No real calling enabled. |
| 10 | Authorized shared conversations | Existing messaging retained. New phone/mail shared grants and customer linkage remain open. |
| 11 | Consent/recording/AI permissions | Explicit browser/native capture and existing AI opt-in retained. Phone recording/consent remains open. |
| 12 | Existing behavior | Focused regressions/builds pass. Full suite evidence has Windows-specific gaps; release CI remains required. |
| 13 | Voice/text permitted use | N/B/M implement typed/dictated navigation, ambiguity, keyboard fallback and interruptible spoken answers. Physical speech acceptance and full inventory remain open. |
| 14 | Financial reconciliation | A tests saved exact QuickBooks totals, current connection, scope and freshness, with no refresh/write. Arbitrary financial conversations/period comparisons remain open. |
| 15 | Customer rankings | Open: explicit invoiced/collected/profit criteria and authorized deterministic source reports. |
| 16 | Mail compatibility/hosted mail | Open: named-provider adapter compatibility, hosted provisioning/delivery/restore. Existing mail infrastructure unchanged. |
| 17 | Complete weekly packet | A/B implement encrypted registered task/note/customer snapshots, exact counts beyond chat limits, 169-hour DST week, audience intersection and print. Coverage is explicitly incomplete across all modules; broader ledger/print acceptance remains open. |
| 18 | Sourced prospects | Open: discovery provider, citations, dedupe, save/note approval and no outreach. |
| 19 | Conversational invoice drafts | Open: missing-field conversation, deterministic local totals and replay-safe draft save. No QuickBooks writes added. |
| 20 | Scoped persistent memory | A/B implement existing encrypted explicit private memory, forget, expiry, context invalidation and user/tenant isolation. Shared approved knowledge and real device continuity remain open. |
| 21 | Restricted inbound callers | Open: verified number-to-tenant mapping and separate restricted caller context. |
| 22 | Native phone handoff truth | Existing behavior retained. Dedicated service-versus-device messaging outcome acceptance remains open. |
| 23 | Adversarial isolation | N/A/B cover navigation, records, native API, report print, memory and in-flight revocation. New mailbox/websocket/background phone/export surfaces remain open. |
| 24 | Untrusted content boundaries | Existing provider has no tools; A proves minimal context/no secrets and B text escaping. New mail/web/caller tool adversarial tests remain open. |
| 25 | Duplicate/cap/uncertain execution | A proves AI UUID reservation, caps, timeout uncertainty, exact note approval and compensating undo. New communications webhook/billing cases remain open. |
| 26 | Existing platform protections | No provider/accounting/billing/store changes. Broad regression results recorded separately; protected CI and MySQL gates remain required. |
| 27 | Operational/native acceptance | B proves desktop/browser interaction; M builds. No Java/Android SDK, physical devices or Mac/iOS build evidence here. Device checks are deferred at the user's request. Load/provider failure, restore and monitored pilot remain open. |
| 28 | Every accessible destination | N/B recursively cover canonical pages/settings and current native renderer inventory, with explicit unsupported states, current access/back/draft guards. Every GET/native screen coverage audit and physical acceptance remain open. |
| 29 | Source-linked commitments | Open: promise classification, exact timezone/date, reviewed task save/reminders. |
| 30 | Shared Needs attention | A/B now cover registered Customer Loop follow-ups with 50-item pages, 5-minute composing claims, assignment, timezone-aware snooze, resolve/reopen, encrypted idempotent receipts and stale-version rejection. Other source adapters, legacy-screen lease enforcement, non-admin grants and real MySQL concurrent transactions remain open. |
| 31 | Cross-channel relationship search | Existing scoped navigation/record search retained. Cross-mail/message/document evidence and incomplete-coverage search UI remain open. |
| 32 | Playbook/practice | Open: approved version publication, restricted practice sessions, no-side-effect scenarios and provider caps. |
| 33 | Activity/approval center | A/B implement current scoped internal note preview, review, receipt and version-checked compensating archive. Other action types and uncertain external lifecycle views remain open. |
| 34 | Human transfer/callback recovery | Open: supported live call transfer, permitted context, rejection/timeout and callback ownership. |
| 35 | Employee voice/photo closeout | Existing dictated notes/photos and pending-upload navigation protection retained. Reviewed multi-action closeout, offline recovery and new employee grants remain open. |

## Increment boundaries

1. Imported checkpoints and permissioned navigation are separate local commits.
2. Assistant shell, private memory, saved finance, weekly registered snapshots and
   internal note review/undo form the next paired increment; no new schema.
3. Shared attention requires a durable, tenant-scoped versioned state model and
   explicit per-role server grants. Do not reuse private memory as shared state.
4. Connected customer workflows should reuse existing job/task/local invoice and
   customer services, adding reviewed source-linked proposals. Keep finance and
   QuickBooks writes excluded.
5. Mail/provider reliability must retain repository-required branch separation.
   Phone adapters and number/call/usage models need additive migration recovery,
   provider contract tests and a fake-only practice surface before activation.
6. Receptionist public knowledge and external sessions are separate from executive
   context. Human transfer/device acceptance cannot be inferred from HTTP fakes.

All new migrations require the repository migration safety gate and MySQL recovery
evidence. Real communications, provider purchases, billing changes, production
activation and store publication are outside this local milestone.
