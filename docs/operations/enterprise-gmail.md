# Enterprise Gmail send-only connection

Source candidate, October 22, 2026. This is a per-user OAuth adapter for the
dedicated Enterprise application, using its encrypted operation records and
existing exact-preview email dispatch. It is not a connection to the owner's
mailbox on behalf of other users. All connection and send flags default off.
No live credentials, OAuth grants, real messages or deployment are created by
this source change.

## Approval and provider prerequisites

The intended first sender is `john@evergrovesoftware.com`; that is owner context,
not a hardcoded sender or an access grant. Every connection must verify a Google
email exactly matching the fresh signed-in Enterprise user's verified email.
The separately approved **$1 total synthetic AI-test budget** does not authorize
credential transfer/configuration, Google consent, actual email, or deployment.
It is not a Gmail billing arrangement or a per-request AI allowance.

The subsequent reviewer-trial plan supersedes that test allowance with **$5 total
shared lifetime provider spend**, including SMS, email, AI and acceptance checks.
Its service-sender model does not require reviewer mailbox access. This Gmail
adapter remains an optional signed-in user's own-mailbox capability, not the
reviewer trial's sender or an authorization to connect John. No paid test has run.

Before a real connection, the owner must separately approve the exact Google
Cloud project/client, its intended deployment and callback, credential storage
and the account consenting. Existing retail Google login credentials and its
`/auth/google/callback` are not automatically approved for this integration.
No credential was read from retail configuration or copied by this work.

Required deployment configuration is Enterprise-only:

- `ENTERPRISE_GMAIL_CLIENT_ID` and `ENTERPRISE_GMAIL_CLIENT_SECRET` for the
  separately approved confidential web client.
- `ENTERPRISE_GMAIL_REDIRECT_URI`, exactly the canonical HTTPS Enterprise
  `app.url` origin plus `/enterprise/gmail/callback`. A different host, path,
  query, fragment, userinfo or HTTP URI is refused. This exact URI must be
  registered with Google by the authorized owner.
- An explicit tenant in `ENTERPRISE_GMAIL_TENANT_ALLOWLIST` and separate
  `ENTERPRISE_GMAIL_CONNECTION_ENABLED=true`, only after connection approval.
- Separate real-send approval before `ENTERPRISE_GMAIL_SEND_ENABLED=true`.
  Global Enterprise writes must also be enabled for connection, refresh and send.

The owner must verify the Gmail API is available for the selected project and
that its consent-screen/testing or publishing state permits the intended user.
Google's app verification and Workspace administrator policies can affect consent;
the code cannot bypass them. No Google project/client, credential, OAuth consent
screen, test-user grant, API setting or administrator policy is configured here.

## Scope and identity contract

The authorization request asks only for `openid`, `email` and
`https://www.googleapis.com/auth/gmail.send`, with offline access, account choice,
explicit consent and `include_granted_scopes=false`. The token response must
contain that exact scope set, accepting Google's canonical `userinfo.email`
spelling for the `email` alias. A broader/missing Gmail scope or absent offline
refresh token closes the attempt. There is no inbox, message search, Gmail draft,
history, attachment-read, profile-read or mailbox administration permission.

Google identity is checked through the fixed HTTPS OpenID userinfo endpoint
using the exchanged access token. A verified email must exactly match the current
Enterprise account, and a stable Google `sub` is retained. The identity is checked
again on token refresh. An ID-token body, client-supplied sender, account owner
role or a matching mailbox display name is never treated as authorization.

Protocol references: [Google's web-server OAuth flow](https://developers.google.com/identity/protocols/oauth2/web-server),
[OpenID userinfo](https://developers.google.com/identity/openid-connect/openid-connect),
[Gmail scope definitions](https://developers.google.com/workspace/gmail/api/auth/scopes),
and [Gmail messages.send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send).

## Connection and state

`POST /enterprise/gmail/connect` takes the current authorized `area_id`. The
response is a private, noncached authorization URL; the UI navigates only after
the signed-in user explicitly clicks Connect. The service freezes tenant, user,
department, fresh email, browser/session binding, client/secret fingerprint and
registered redirect, with PKCE S256 and a ten-minute state expiry.

Starting a reconnect begins a new local consent epoch, immediately invalidating
old previews and superseding old OAuth states. It does not preserve a usable old
token fallback if consent is cancelled. There is one local connection per user
and department. This creates no tenant membership, role, native mailbox user
grant, new Enterprise account or other user's connection.

The fixed callback route resolves its tenant from the one-use encrypted state,
not a caller tenant selection. It requires the original active, verified user,
the exact browser session and callback origin/path, current department permission,
the same configuration and an unexpired unsuperseded state. A durable `exchanging`
claim commits before token exchange. Expiry, denial, replay, identity mismatch,
unknown token outcomes or invalid output cannot reuse the code. Closed states
discard their PKCE verifier; authorization codes are never persisted.

Callbacks never preserve OAuth parameters in a login intended URL. Guest or
invalid callbacks receive a generic private error, with no-referrer/no-store
headers; valid callbacks return a 303 to the user's own Ask Everbranch context.
Provider error text, codes, state and tokens are not reflected in that redirect.

## Token refresh and disconnect

Access and refresh tokens exist only in encrypted `ent_gmail_connection` data;
audit revisions contain event metadata, not credentials or raw OAuth responses.
Listing/status do not call Google or refresh tokens. Refresh occurs only when
the confirmed send needs a token, under a per-tenant serialized claim committed
before HTTP. Concurrent refresh claims refuse another exchange. Invalid grants,
timeouts, changed identity/scope or unverifiable responses locally invalidate
the connection and require reconnect; there is no blind refresh retry.

Successful routine refresh retains the consent binding version, so it does not
invalidate an otherwise current reviewed draft. Reconsent, revocation, changed
Google subject/email, changed Enterprise account email or configuration change
does invalidate that preview. A late callback/refresh cannot overwrite a newer
consent epoch. Failed or superseded responses discard their tokens locally;
they do not automatically revoke Google grants and disrupt newer consent.

Disconnect is local first and remains available for the current user's owned
connection with current tenant/department read access, even while connection,
send or write flags are paused. The local binding and tokens are removed before
the best-effort fixed-endpoint Google revocation. Reconnect to that binding is
fenced while revocation is in flight. An uncertain revocation stays locally
disconnected and blocks blind reconnect/revocation replay until its provider
outcome is reviewed. No automatic reconciliation or grant restoration is claimed.

**Google revocation can affect the same Google account/client's other department
connections.** This bounded adapter changes only the selected local connection;
other bindings may require reconnect and fail closed when Google rejects their
tokens. It does not alter another tenant's records or another user's connection.
An accepted revocation response is not a guarantee of instantaneous propagation.

## Confirmed dispatch

The Gmail adapter requires an existing native `ent_email` draft already claimed
as `dispatching`, with the exact current sender binding, recipient, subject,
body and unexpired human confirmation. It cannot send arbitrary chat/workflow
output or accept a new sender. It commits a separate encrypted `ent_gmail_send`
receipt before provider IO, preserving a stable draft-based Message-ID and
idempotency claim. MIME is text-only and bounded; this slice does not send files.

The only message operation is fixed `POST .../gmail/v1/users/me/messages/send`,
with base64url-encoded MIME and the user's own token. There are no automatic
HTTP redirects, API retries or refresh-and-resend loops on 401/403. A verified
success stores Gmail's message ID as **accepted**, not delivered. A timeout or
unverifiable response stays uncertain and cannot automatically resend; the
existing exact draft and provider evidence must be reconciled.

Current active membership, account, department, email and consent configuration
are rechecked at callback, listing, refresh, preview, confirmation and dispatch.
Cross-user/tenant IDs and stale bindings fail closed. Refreshing or granting a
connection never grants access to Enterprise work records.

## Verification limits

Focused tests use fake Google HTTP only: PKCE/session/state claims, expiry,
denial, wrong user/tenant/department/origin, client changes, identity/scope/refreshtoken
rejection, refresh concurrency and uncertainty, stale-consent races, local-first
disconnect, guest callback secrecy and exact confirmed idempotent dispatch.
They do not prove real Google consent, OAuth verification readiness, token refresh,
mail acceptance or delivery. Real per-account connection and one explicitly
approved delivery/reconciliation remain later acceptance steps. No live test
send or connection is authorized by these source tests.
