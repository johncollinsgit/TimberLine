# Alpha Printing fixture-only browser demo review

Prepared on `codex/alpha-printing-browser-demo-20261021` from released main
`7da0444c301827b1b2f1dcb71f0a514b51592269`. This increment is not deployed.

Proposed route: `https://app.theeverbranch.com/platform/demos/alpha-printing-pos`.
It is public fictional content, not tenant 16 access. Only canonical-host
GET/HEAD requests are accepted. No parameters, mutation API, login bypass,
merchant selection, credentials, provider calls or operational records are
accepted. The actual POS production guard and payment flags are unchanged.
See the [operating contract](../operations/alpha-printing-browser-demo.md).

## Tested evidence

- **30 HTTP feature tests / 184 assertions passed.** Tests use the real
  middleware pipeline, including production-mode rendering. They verify host,
  raw and parsed query, method and sub-API boundaries; per-response nonces/CSP;
  no cookies or SQL writes on the read-only demo route; no session/heartbeat
  mutations; signed-in/anonymous fixture equivalence; no private marker leakage;
  unchanged customer/order/stock/session records; and the unchanged production
  `PosAccess` denial and false real-payment flags.
- **14 pure engine tests passed.** These cover integer cents and fixed fixtures,
  frozen request identity, explicit approval, stock once, unknown/cancel recovery,
  cash/change, capped original-sale refunds, confirmed customer/guest isolation,
  bounded validated journal restoration and reset.
- **6 mounted UI tests passed.** These execute the actual engine and browser
  interaction code through card/receipt/history, unknown refresh/reconciliation,
  cash underpayment/change/refund, scanner rejection, generated sample customers,
  memory fallback, namespace-only confirmed reset and keyboard tab controls.
- `npm run test:pos`: **40 passed**, including the existing 20 POS helper tests.
  Frontend type checks, existing JavaScript checks and Vite build passed. All
  four changed/new PHP files pass Pint; syntax and `git diff --check` pass.
- Independent read-only source review found no remaining blocker after correcting
  the GET CSRF/session dependency, rejecting nonempty raw query strings, and
  making the Reset control visible at phone widths.

The full PHP suite was initially interrupted for campus priority, then resumed
after that artifact was delivered. The final DNS-capable local run passed
**4,400 tests / 32,055 assertions**, with eight existing opt-in fixture skips.
The 57.41-second parallel run used in-memory SQLite, array mail/cache/session,
and existing provider fakes. The restricted sandbox could not resolve public DNS
for two unchanged wholesale/email-validation tests; their isolated DNS-capable
rerun passed without changing either safety guard.

The resumed full run also found inherited Livewire request assets could inject
scripts/styles outside the demo nonce policy. A narrow follow-up to initial
commit `5f598caa19d0f2f62d0b758348d623205464dc04` clears transient Livewire
request state after host/query authorization and before this standalone view.
The regression forces prior injection, verifies four nonced inline elements,
and proves a later normal page can still request its own Livewire assets.
Independent source review cleared the fix. No schema, dependency, operational
record, production POS guard or global asset-policy change is included.

PR #455 is the review/release candidate. Exact-head GitHub checks and owner
release review remain mandatory before merge/deployment.

## Native Safari walkthrough

The actual Laravel route was served on Mac loopback port 4319 using a public
fixture key, an in-memory SQLite database and a local-only landlord-host setting.
The first local preview hit the existing flagship resolver before that local host
setting was corrected; no production configuration changed. The accepted demo
page then ran without an operational database or external provider.

The native browser walkthrough explicitly added `ALPHA-SHIRT`, selected fictional
Jordan Sample, prepared `demo-payment-1`, chose **Approve demo card**, and opened
receipt `DEMO-0001`. It showed $25.00 subtotal, $2.06 fictional tax and $27.06 total.
The customer history showed one confirmed identified sale, gross/net $27.06 and
refunds $0.00. These were browser-only fictional values, not a card transaction.

Safari Responsive Design Mode at **390 x 844** showed the customer history,
receipt controls, simulation banner and visible Reset control without horizontal
overflow. This is browser layout evidence, not a physical iPhone or native iOS
Tap to Pay acceptance test. No print job was sent. The local preview and the
unfinished full regression process were stopped for campus priority.

## Review and release gates

Resume the complete regression/normal PR checks, verify the current main base,
and serialize with the campus release before requesting this named demo release.
After an approved release, independently check the active commit, exact route,
headers, fictional card/cash journey, customer/guest history, recovery and reset
in desktop and phone browser layouts. Do not infer provider or hardware readiness.

The public demo uses one browser-session namespace and never syncs across
devices. Fictional prices/products/tax are not Alpha Printing's real services or
policy. Real merchant onboarding, native iPhone payments, peripherals, postal
workflow, tax/returns policy and operational Retail acceptance remain separate.
