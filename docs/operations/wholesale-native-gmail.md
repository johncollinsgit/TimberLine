# Native wholesale customer email

November 5 source candidate. Owner direction supersedes manual Gmail paste as
the preferred follow-up workflow. Copy/Open Gmail remains an optional fallback.
This change does not connect Google, change provider settings, deploy, or send
live messages by itself. It covers reviewed existing-customer reorder notes,
not the separately authorized Macon invitations or automated cold campaigns.

## Operator workflow

Open the customer draft, edit its subject/personal message, then choose Review
and send. The server rechecks the customer and renders the exact branded email,
including current product links/photos, business mailing address and unsubscribe.
The private preview displays From, Reply-To, To and subject. Send email is the
only customer-send action; page GET, connecting, copying and review never send.
Accepted by Gmail means provider acceptance, not delivery, an open or an order.
Gmail retains the sent message in the connected mailbox.

A durable per-store/customer/source-order intent prevents different reviews or
copy edits from creating duplicate sends. A competing confirmation returns the
original result. Accepted messages suppress another recommendation for fourteen
days; pending/uncertain sends remain held without a time-based automatic retry.
An uncertain browser result retains its exact review reference. Staff must
reconcile Gmail Sent manually; the app requests no inbox or message-read scope.
No automatic follow-up completion, customer merge, order creation, reply, paid
provider fallback, SES switch or bulk-send authorization is introduced.

## Configure an approved Google client

Use a separately owner-approved confidential Google web client for this deployment.
Do not copy Codex grants, Enterprise credentials, retail login credentials or
mailbox passwords. Register exactly the canonical HTTPS `APP_URL` plus
`/wholesale/gmail/callback` with Google. Enable the Gmail API and make the consent
screen/testing state available to the intended account. Google consent and
verification/admin restrictions cannot be bypassed by this implementation.

Configuration (no real secrets in Git):

- `WHOLESALE_GMAIL_CLIENT_ID` and `WHOLESALE_GMAIL_CLIENT_SECRET`.
- `WHOLESALE_GMAIL_REDIRECT_URI` matching that exact canonical callback.
- `WHOLESALE_GMAIL_TENANTS`, a JSON object keyed by actual tenant ID. Each entry
  contains `shop` (the verified wholesale myshopify.com domain), `mailbox`
  (the approved Google login), `from`, `reply_to`, `name`, and `mailing_address`.
  The store mapping must still belong to that tenant. No tenant or sender default
  is inferred from a browser-supplied ID.
- `WHOLESALE_GMAIL_CONNECTION_ENABLED=true` after the exact client/deployment
  configuration is approved. `WHOLESALE_GMAIL_SEND_ENABLED` defaults false and
  remains false until release and sender acceptance are cleared.
- For controlled acceptance only, configure the approved internal `test_recipient`
  in the tenant binding and set `WHOLESALE_GMAIL_TEST_ENABLED=true`. Review works
  in this mode, but Send email remains disabled. Send internal test uses only
  that server-bound address, a separate deduplicated test journal, and never
  suppresses or contacts the customer. Its unsubscribe token is bound to the test
  inbox rather than the real buyer. No browser-provided test recipient is used.

For John's requested route the approved login is johncollinsemail@gmail.com and
From/Reply-To is info@theforestrystudio.com. The broken Wholesale Gmail alias is
not used. Confirm the actual tenant/store mapping and current business address
before setting that deployment's binding; do not infer IDs from these examples.

The Connect Gmail button opens a top-level Google consent tab. It requests
`openid`, `email`, `gmail.send` and `gmail.settings.basic`. The last permission is
needed to read Gmail's accepted send-as alias; Google has no narrower alias-only
read scope. The implementation only GETs send-as settings and never changes them.
It does not request inbox, draft, message, history or attachment-read access.
The verified Google email must match the approved login. Current identity and
accepted From alias are checked again before each send. Authorized operators of
this store share the configured business sender; this is not a tenant access grant.

Connection launch is one-use and short-lived, with PKCE and a first-party Secure,
HttpOnly, SameSite=Lax browser cookie. It is explicitly host-only, independent of
Backstage's shared login-cookie domain, including when expiring the cookie. Callback validates exact origin/path,
browser/state, configuration, generation and current operator/store access. A
durable exchange claim precedes HTTP. Wrong account/scopes, missing refresh
permission, replay, changed configuration or replaced consent closes the attempt.
Refresh uses a serialized durable claim, rechecks identity and retains the review
generation only for an ordinary successful token refresh. Disconnect erases the
local tokens and supersedes pending consent/reviews, even when sending/setup is
paused. It does not silently revoke a Google grant used elsewhere; account owners
can remove Google's authorization through their Google account controls.

## Records and recovery

Existing `team_operation_records.data` encrypted storage is reused in the reserved
private namespace `e083635e-84fb-4c33-9aba-0858ca637a8a`, with `wg_connection`,
`wg_state`, `wg_review`, `wg_send` and `wg_test` kinds. This namespace is never a Teams work area
or grant. Every query includes tenant, namespace and kind; native writes also
recheck current operator membership, role, module and store mapping. No DDL change.
Generic action audits contain record IDs/provider results, never tokens or MIME.

Before provider send the journal commits `sending`, including actor, recipient,
From/Reply-To, reviewed-content hash and stable RFC Message-ID. Acceptance retains
Gmail message/thread IDs. A timeout, rejection, unexpected response or crash does
not release the unique intent. Gmail rejection never falls back to another sender.
Use the exact RFC Message-ID and sender/recipient/time to reconcile Sent. Do not
delete a claim, change its order key, reconnect or edit copy to force a retry.
Provider acceptance and delivery remain distinct. Address-level unsubscribe,
permanent-bounce and suppression states use the established marketing suppression
service, in addition to current wholesale history and do-not-contact checks.

## Verification and release

Run `WholesaleNativeGmailTest`, `WholesaleCustomerOutreachDraftTest`, relevant
wholesale/digest/surface suites, Pint and the normal app build. Run
`node tests/e2e/wholesale-native-gmail.mjs` and the existing copy browser check.
Tests use fictional identities and intercept provider HTTP; no real send or grant.
Use protected GitHub/Forge release gates and verify the deployed SHA at `/ready`.
Do not push directly to production or bypass the established release gate.

After deployment, the owner must complete the approved Google consent. Verify an
explicitly authorized controlled internal send from this exact Backstage client,
recipient-side From/Reply-To/authentication and an actual reply. Existing Codex
alias tests are evidence for that route, not acceptance of this new deployed
client. Verify a real recipient-side receipt before claiming live readiness.
Enable live customer sends only after that separate acceptance is recorded.

The public privacy page includes a Gmail-specific disclosure at
`/privacy#wholesale-gmail`; the connection prompt links it before Google consent.
Publish the current disclosure before authorizing the live mailbox.

Protocol references:
[Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server),
[Gmail send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send),
[Send-as aliases](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.settings.sendAs/list).
