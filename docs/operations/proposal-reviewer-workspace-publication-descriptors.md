# Target-free files/images boot admission

This separate source delta supplements immutable image-v1 and quarantine packets.
It does not activate flags/scanner, publish/reseed/replace any current edition,
create native identity, or change a target endpoint. Root owns presentation/DOM,
assets/CSP, config/source closure and final release integration.

Presentation can occur before a ticket/project/task exists or is selected.
Therefore it must call the new trusted publication-only descriptor methods:

- `ReviewerWorkspaceAttachmentsAccess::publicationDescriptor(Request, token,
  binding, selection)`
- `ReviewerWorkspaceImagesAccess::publicationDescriptor(Request, token,
  binding, selection)`

Do not guess a first/hidden/invented target to call an older target-bound method.
The existing `clientDescriptor` APIs remain available unchanged for callers that
already possess a genuinely resolved target. All descriptor JSON keys/endpoints
remain identical; browser clients and the closed workspace descriptor do not
gain fields, fallback modes or target authority.

Publication admission uses the actual password unlock, immutable Version/source/
build, current owner silo/anchor, workspace/graph, current selection/capability,
independent feature policy and expiry. Collaboration supplies a server-only
publication context; attachment policy validation has one implementation shared
by target and publication branches. Image publication admission reuses that
trusted attachment context and its existing independent image policy validator.
No record, reviewer name or `can_upload` is invented or serialized in boot.

Before returning endpoints, admission is resolved again and exact bindings,
capability, normalized selection, workspace revision/graph, policies and controls
are compared. Independent flags and expiry are checked at the end. Image policy/
scanner configuration is re-derived after the last fresh publication callback,
followed by a pure final expiry fence. Boot performs no reservation, upload/blob
mutation, scanner/evidence socket call or provider request. The scanner policy
resolver only verifies current approved configuration/source pins; it does not
perform a scan.

An empty permitted work graph can therefore boot and later create its first
ticket using the existing actual command API. Every files/images POST still
requires its unchanged exact current target FK/projector/scope/policy/expiry
checks. Advertising read endpoints to a read-only persona grants no begin/write/
scan permission. Chosen reviewer names remain self-selected labels checked by
actual write APIs; no real employee/User access is inferred.

Disabled/absent features produce no admitted descriptor. Present malformed or
unapproved descriptors, stale source/build/Version, expired/revoked grants or
wrong silo fail closed. Root presentation must preserve this distinction and
must not fall back to sample/browser/native contexts. It must not expose the
server-only publication context arrays themselves to the browser.

Local tests use actual isolated fictional publication/password/command/SQLite
and private storage, plus explicitly fake scanner/evidence for the one optional
post-boot clean-read test. No real scanner, provider, hosted/mobile/browser
acceptance is claimed. Existing per-target/native tests remain mandatory, as do
normal final-candidate PHP8.4/MySQL/concurrency/browser checks, independent review,
explicit publication transition and specific scanner/runtime permission.
