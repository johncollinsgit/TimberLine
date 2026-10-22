# Campus → owner software support: narrow integration handoff

Candidate in PR473. This is a **test-only contract**, not an operational bridge.
No live sender/receiver trust, keys, identity bindings, grants, production inbox
adapter or campus navigation has been installed. All flags default off; the
transport, signer, verifier and controller hard-deny outside `APP_ENV=testing`.
An application configured as production cannot activate this with a flag.

## Components and storage boundary

`app/SoftwareSupport/Transport/` contains `CampusAuthorization`, `CampusOutbox`,
`CampusTransport`, `OwnerInbox`, `DepartmentBinding`, `Packet`, `TestSigner`,
`TestVerifier`, `TestCampusClient`, `TestReceiver` and `TestController`.
`routes/software-support-transport.php` adds the sole API include to
`routes/api.php`: `POST /api/software-support/test-campus`. It does not use or
share a browser session, install a CSRF exception or accept an owner login.

The concrete storage adapters exist ONLY in
`tests/Support/SoftwareSupportTransportFixtures.php`. Campus membership and
frozen outbox commands use one independent SQLite file; the owner inbox and
mutation receipts use a different file. Tests deliberately reuse the number 7
in source and destination references and prove no native support/user record is
created. The owner never queries campus tables, and campus never accesses owner
tables, signing keys, users or sessions. These fixtures are not production
schemas and must not be provisioned in a real workspace.

## Campus owner integration slot

Implement `CampusAuthorization::identity(department, operation)` INSIDE the
campus runtime after its ordinary session, verified account and exact workspace
resolution. Use fresh `WorkspaceAccess::actor()` and exact `TeamArea` profile/
scope checks (`WorkspaceAccess::area`, `canSubmit` as appropriate). A campus
workspace owner role alone must not assert an arbitrary department. Recheck
active membership, current department permission and rollout/write gates on
EVERY attempt, including an uncertain retry. The adapter returns opaque
`issuer`, `campus_workspace`, `department`, `principal` references. Never return
native owner Tenant/User IDs or accept these identity fields from a browser.

The issuer identifies an exact campus runtime installation. Tenant/area/user
identifiers are namespaced by that issuer; numeric equality with an owner
database identifier has no authority. The private receiver-owned mapping matches
**all four** source references exactly, limits operations, then supplies its
own `ownerWorkspace`, `clientReference`, `ownerDepartment`. It does not grant
membership or copy a role. Missing or ambiguous mappings deny access rather
than choosing a destination by array order. Configuration is absent; fixtures are explicit
synthetic examples only. Future real bindings need exact reviewed identifiers,
current authorized principals, revocation policy and audit evidence.

The current native ticket engine has a non-null native creator foreign key.
A real `OwnerInbox` adapter must add reviewed external-principal attribution
through the domain/schema before persisting external tickets. **Never fabricate
a native user, impersonate the owner, or grant tenant membership to satisfy that
foreign key.** This production persistence adapter is deliberately unimplemented.
No campus tour/header, shared renderer or proposal file was edited.

## Packet v1 and authentication

Wire object: `key_id`, integer `issued_at`, exact JSON string `body`, Base64
Ed25519 `signature`. Signature frame is
`evergrove.software-support.test.v1`, direction, key ID, timestamp and exact body,
separated by LF. Directions are `campus-request` and `owner-response`; a packet
cannot cross those purposes. Test private keys are generated in memory and
never written to source, database, logs or configuration. Each verifier holds
only the other side's test public key, keyed by exact key ID/issuer. There is no
shared application/session encryption key. Unrecognized key, wrong issuer/
audience, tampering, age over 60 seconds or clock more than five seconds ahead
is rejected. Request bodies are bounded and strict unknown fields rejected.

Request body fields: version 1, issuer, audience, campus_workspace, department,
principal, request_key UUID, operation, payload. Allowed operations/payloads:

| Operation | Payload | Authority |
| --- | --- | --- |
| submit | subject, category, priority, body | Exact authorized source department |
| read | reference UUID | Current matching owner/client/department mapping |
| reply | reference UUID, body | Same scope and current source write permission |

The response is independently signed by the owner test key and carries version,
issuer, campus audience, SHA256 of the exact request body and result. Campus
checks direction, trusted owner issuer, audience, freshness and request digest
before acknowledging. A valid response for a different request is rejected.
An automatic `saved` receipt explicitly has `human_response=false`.

Native IDs, role/assignment grants, destinations, triage mutations and file bytes
are not protocol fields. Attachments/remote file URLs fail strict payload
validation; no caller URL is fetched. Any future file transport needs its own
approved bounded upload/quarantine/ownership contract. A real network adapter
would also require an exact HTTPS endpoint/receiver identity, no redirects or
fallback origins, bounded timeouts and approved credential/key lifecycle. None
is supplied here, and this test endpoint must not be promoted to production.

## Interrupted requests and replay

Campus outbox freezes the command before transport. A mutation key is scoped by
issuer/workspace/principal/request UUID. Same key/different content is a 409.
Lost or invalid responses preserve `uncertain`; no new key or automatic resend
is generated. A caller may explicitly retry the same frozen command after
reauthorization, with a fresh short-lived signature. The owner atomically
reserves its receipt with the ticket/reply in its own store. Its digest also
freezes the owner workspace/client/department target, so changed routing cannot
reuse an earlier mutation. Reads always recheck the current binding and never
reuse a cached private response. Revocation of campus membership or receiver
mapping denies subsequent requests. Tests reopen both files after a lost
response to verify durable recovery without duplicate tickets/replies.

## Attachment scanning boundary

New native uploads are quarantined. `AttachmentScanner` is an unbound interface;
`AttachmentGate` denies downloads when disabled/unbound/unavailable/failed,
infected, stale, hash-mismatched or from a different scanner policy. A successful
scan must attest the exact SHA256 plus approved scanner/definitions/policy;
download independently verifies current bytes, policy and freshness. Existing
unscanned uploads also default deny. Test scanners are explicitly fictional.

Actual scanning requires approval of engine and version, updated definitions,
health/timeout/resource limits, supported formats/parser isolation, verdict and
quarantine semantics, retry/review handling, private storage/retention and
operational failure visibility. No MIME/extension check or hash establishes that
a file is safe, and no actual scanner is installed or activated here.

## Approved support expectations and remaining review

Shared copy: Monday–Friday, 9 AM–5 PM **America/New_York** (Eastern, including DST),
holidays excluded; initial **human** response within one business day; no
guaranteed resolution time. A saved ticket/notification is an automatic receipt,
not a human response. First authenticated owner reply records a distinct human
response timestamp. Expectations display only for an explicitly approved client
policy, with no global commercial entitlement.

The holiday jurisdiction/date list/coverage was not specified. No holiday list
is guessed. The proposed calculation is eight open service hours, excluding
weekends and configured dates. `ResponseCalendar` returns no calculated target
until both that basis and the excluded-date calendar are explicitly reviewed
for that client. All real bindings/policies remain empty/off. Parent should
coordinate this policy identifier and approved text with the proposal owner.

Live approval bundle must identify actual runtime/workspace/department/principal
mapping and revocation, external-actor persistence design, exact network origin,
service identities/key custody/rotation, operational outbox/reconciliation,
scanner acceptance and calendar. Owner notifications separately require exact
existing approved provider/account/channel/sender/owner destinations/content/
cost/test scope and uncertain-result handling. Keep SendGrid active, SES off and
honor existing Twilio/AWS restrictions. No client notices are authorized.

CI uses `.github/workflows/software-support.yml` to test the support suite and
retained-row migration recovery on disposable MySQL 8.4, generate a data-free
baseline artifact tied to PR head/checkout SHA, verify blank-database boot and
require the reviewed fingerprint committed. It does not weaken the repository's
normal migration, full test/build or protected deployment gates.
