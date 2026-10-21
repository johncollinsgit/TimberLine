# Authenticated Everbranch terminal shell

This source candidate adds an existing-user login and workspace selection shell.
It does not activate a POS, Terminal, merchant, reader, cash ledger or native app.
It must remain a draft PR until its tests and release review are complete.

## Entry and authorization

- `/terminal` introduces the normal Everbranch sign-in. Password, Google and 2FA
  remain the existing authentication flows, with their existing credentials,
  checks and scopes. No new identity or merchant sign-in is implemented.
- `/terminal/workspaces` lists only current active memberships with existing
  `owner`, `tenant_owner`, `admin`, `manager` or `member` pivot roles. A global
  platform role or matching email never substitutes for that membership.
- CSRF-protected `POST /terminal/workspace` accepts only `tenant_id` and rotates
  the session ID. It never creates a membership or changes the ordinary app's
  `tenant_id`. No first-workspace fallback, host bootstrap or impersonation is
  used. A user without membership may encounter the usual login onboarding;
  entering the terminal afterward still shows no available workspace.
- `/terminal/register` reloads the active verified actor and selected membership
  on every request. No selection returns to the chooser. Revocation, unsupported
  role or another tenant is denied. Query parameters, raw query strings and
  `X-Tenant` / `X-Tenant-Id` selectors are rejected on the entire terminal surface.
- Only the canonical landlord host is accepted in production. Local/testing
  loopback is available for isolated review. Customer domains are not login doors.

The terminal uses the existing host-only, SameSite=Lax first-party
`everbranch-mobile-auth-session` cookie on the canonical host, selected before
StartSession. Login, 2FA and logout already support that lane. Google redirect and
callback use the same cookie when present on the canonical landlord host only.
Normal requests restore the platform cookie defaults. No new cookie identity,
OAuth scope, token exchange or native app grant is introduced.

## Selection, expiry and sign-out

`terminal_workspace` stores actor, tenant and timestamps; `terminal_clock` keeps
the actor-bound start across chooser visits, switching and rejected selections.
The clock begins at the first protected terminal access. Activity extends the
15-minute idle window but never the eight-hour absolute window. A legacy
selection without the separate clock retains its original start.

Chooser visits and `POST /terminal/workspace/clear` remove only the selection.
A rejected tenant cannot reset the clock. Expiry signs the existing first-party
session out, invalidates it and refreshes CSRF. Malformed/future/cross-user session
state also requires a fresh sign-in. Anonymous entry and ordinary full logout
clear both selection and clock. The existing Fortify POST logout is used.

Sign-out affects this terminal and its shared first-party field-app session.
Other ordinary Everbranch platform sessions may remain signed in. Staff must use
Sign out when leaving a shared counter; this page does not lock a physical device.

## Merchant identity and readiness

The header shows the actual selected tenant name, signed-in account and pivot
role. Only existing owner/admin memberships see a sanitized saved Stripe status:
connection label, cards/payouts flags, routing and UTC check timestamp. Managers
and members receive no merchant configuration. Account identifiers, raw metadata,
credentials, requirements payloads and bank/customer records are not rendered.

This rendering reads saved data only. It never refreshes Stripe, starts hosted
setup, creates an OAuth grant, changes an account or calls a provider. Existing
online readiness checks do not establish Terminal or device acceptance. The
provider's legal business name is not retained by the existing payment record;
the shell labels its connection by the actual workspace and states that owner
verification of provider business identity is still required before live use.

Read-only live observation on 2026-10-21 confirmed the requested existing account
has an active verified Admin membership in **Wren Family Soccer**, tenant 13.
Stripe's live connected-account UI mapped **Watershed Fellowship Upstate LLC** to
that ID/slug and showed Express, payments/payouts active and card capability
active. The application's saved status was two days old. These are dated
observations, not hardcoded access rules or current Terminal acceptance.
No membership, financial record or provider configuration changed in that check.

Alpha Printing, tenant 16, remains a separate Demo/Inactive workspace. Its public
fictional browser register is not embedded in this terminal shell and can never
select Wren's account. Every readiness query follows the selected membership's
own tenant relationship. No record or account is copied between workspaces.

## Functionality and mobile handoff

The shell is read-only: no live cart, charge, cash recording, refund, customer
mutation, inventory commitment or connection-token endpoint exists. Existing
`PosAccess` remains hard-denied in production. A local/testing simulation link
appears only when that existing guard actually authorizes the same actor/tenant;
it is not a production payment route.

The mobile owner may use this reviewed browser-session behavior as an access
contract after its separate release approval. Native iOS integration is not in
this change. It still needs separately scoped device/SDK acceptance, appropriate
provider business/location, server-owned payment attempts and token issuance,
reconciliation and approved payment testing. A browser login or active online
card capability is not proof of native Tap to Pay readiness. Do not reinterpret
this shell as authority to collect or move money.

## Review and release

Run functional login/2FA/logout, real-cookie isolation, CSRF, actor revocation,
role/tenant/header/query denial, fixed expiry, saved-data privacy and unchanged
production payment guards. Keep provider HTTP prevented and fixtures fictional.
Run the normal repository regression/CI checks. Standalone HTML is escaped with
nonced trusted CSS, no scripts, connect-src none, self-only forms, no framing,
no-store/no-referrer/noindex headers.

This increment has no schema, account, environment-secret or workflow changes.
The campus owner was actively preparing its own hosted release when this branch
started; do not merge or deploy concurrently or without a new release review.
Withdrawal should revert the terminal-only routes/service/views and narrow cookie
selection addition through the normal release process. Do not alter shared
membership, merchant mappings, provider configuration or business records.
