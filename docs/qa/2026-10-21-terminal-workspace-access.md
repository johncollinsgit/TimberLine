# Terminal login and workspace shell verification

Prepared from main `778c6ea4219dd342f2d6a1ee4ec66330e2dd4b3e` on
`codex/terminal-workspace-access-20261021`. This is a draft release candidate,
not a deployed terminal or a payment acceptance result.

## Existing access verified read-only

On 2026-10-21 the operator directory showed the requested existing account as an
Active Admin assigned to **Wren Family Soccer**, tenant 13. The same signed-in
account opened `/payments?tenant=wren-family-soccer`, whose existing controller
requires active verified owner/admin membership. It showed saved card/payout
enabled status checked two days earlier. No refresh, setup, invite or grant was
submitted. The preceding live Stripe inspection matched Watershed Fellowship
Upstate LLC to that tenant/slug and Express routing. No provider identifiers,
bank/customer/card data or credentials are included here.

The workspace name remains Wren Family Soccer. The actual provider legal business
name is not persisted by the existing payment record. The new shell identifies
the selected workspace's own saved connection, states that provider business
identity needs owner verification before live use, and does not hardcode an
email, tenant, merchant or approval into its authorization logic.

## Automated evidence

- **56 new terminal tests / 626 assertions passed.** Active membership and role
  filtering, explicit CSRF selection, session rotation, unchanged ordinary tenant
  selection, actor/session mismatch, inactive/revoked memberships, deactivated
  actors, query/header/raw-query/host denial and unsupported payment endpoints.
- A durable SQLite session-cookie jar reloads guards, session stores and cached
  controllers between requests. Distinct platform and first-party actors prove
  real password login, 2FA/recovery, valid/invalid CSRF selection, terminal access
  and logout all use the same first-party session. The unrelated platform session
  row remains unchanged and its actor can still confirm a password afterward.
- Google coverage uses an existing-account provider mock to prove redirect state
  and error-return session continuity. No actual Google sign-in, account creation,
  new OAuth grant or provider acceptance is claimed.
- Clock cases preserve the original eight-hour boundary across chooser/clear and
  reselection, including a forbidden foreign-tenant selection just before expiry.
  Idle expiry still applies with selection cleared. Legacy selection timestamps
  retain their start; malformed/future/cross-actor state requires fresh sign-in.
- Owner/admin saved readiness is a sanitized selected-tenant projection; managers
  and members cannot see merchant configuration. Fictional Alpha tenant 16 and
  another tenant's account never substitute for the selected workspace.
- HTTP provider requests are prevented, operational records remain unchanged,
  there are no payment actions, and production `PosAccess` remains denied even
  when its simulation configuration is deliberately set true in a test.
- **239 existing auth/mobile/host tests / 2,608 assertions passed** after the
  narrow first-party cookie routing change.
- Full regression: **4,456 passed / 32,681 assertions**, eight existing opt-in
  fixture skips, **85.64 seconds** with four workers. SQLite fixtures and array
  mail/cache/session were used. DNS-capable execution retains unchanged public
  email/URL safety-validation guards; no production records or real payments.
- `npm run build` passed type checks, existing Trajectory/voice/POS JavaScript
  checks and Vite. Eight changed/new PHP files pass Pint; syntax and diff checks
  pass. No dependency, migration or workflow definition change is included.

## Independent review and visual evidence

Independent source review identified and closed two session defects: mixed
platform/field-app cookies could lose login or target the wrong logout, and a
workspace switch or rejected selection could renew the absolute deadline.
The final source and durable-cookie/clock regression tests were reviewed with no
remaining concrete blocker. The reviewer did not claim an independent test rerun.

Four standalone pages were rendered from the actual Blade templates with only
fictional in-memory view data, zero database queries and provider requests. A
temporary loopback static server and isolated in-app browser were used because
the user's Safari/support workflow was active. No actual login or membership
selection was performed through that static visual preview; functional evidence
comes from the actual HTTP/CSRF/durable-session tests above.

Desktop and **390 x 844** phone-width inspection verified the entry, chooser,
owner shell and limited staff shell. The 390px document had a 390px content width;
sign-out and workspace controls measured 44px high. Simulation/live-unavailable
labels, wrapped workspace/account identity and saved-status timestamp were clear.
Staff rendering omitted the saved merchant section. Text contrast was darkened
before final verification. The preview tab, temporary viewport override and its
own loopback server were closed; campus files/services were untouched.

## Remaining release and integration gates

Open the requested draft PR and complete exact-head GitHub checks. The campus
owner was preparing PR 456 and its own release; this terminal feature must not be
merged/deployed without a separate release review and serialized ownership.
No new production terminal URL should be advertised as available from this work.

Native iOS, Terminal activation, provider legal-identity revalidation, device and
peripheral acceptance, tax/returns/postal policy, real cart/payment attempts and
approved financial testing remain separate. The source's `Live payments
unavailable` state is unconditional. Alpha Printing stays Demo/Inactive and its
fictional public demonstration remains separate.
