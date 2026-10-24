# Private shared work comments — source stage1

This bounded source stage implements persistent comments and exact recovery on
shared fictional tickets/projects/tasks. It is not integrated, activated, hosted
or provider verified. No new grant, native User/Team task, runtime initialization,
publication, paid call or scanner change is included. Uploads/files/gallery are
explicitly unavailable (`files_available:false`); a policy claiming uploads are
enabled refuses rather than silently falling back. The broader collaboration
design and durable quarantined upload implementation are separate stages.

Admission is default-off through `service_proposals.reviewer_workspace.collaboration`:
`enabled:false`, `comments_write_enabled:false`, `policies:[]`. A future immutable
Version must additionally have exactly `review_workspace_collaboration` with
`schema_version:1`, an approved `policy_sha256` and `expires_at` no later than its
workspace descriptor. The exact server policy has `schema_version:1`,
`comments_enabled:true`, `uploads_enabled:false`, and a distinct list of allowed
write personas from demo_manager/manager/worker/requester. The policy hash and
source-bound current edition must agree. Existing review links have no automatic
feature admission; this packet does not add/rewrite any descriptor or policy.

The service reuses the current owner-silo/anchor, publication/unlock/session
capability, workspace policy, current selection and actual projected graph. Each
independent thread is bound to exact workspace+Version+ticket/project/task ID.
Incoming tickets need no hidden native task. A routed ticket retains its thread
but its current area/role determines access; text labels and old area metadata
grant nothing. Managers can comment under the editor/config/policy gates;
workers only on assigned tasks or owned/assigned tickets, requesters only their
own current tickets. Project worker writes and executive/viewer writes refuse.
Archived/closed targets and archived parent projects refuse new comments.

The existing chosen name is captured from the server session, checked against
the immutable review names and the form's exact expected name. It remains a
self-selected display label. Name/persona choice creates no real employee access
and cannot take ownership of another browser's receipt. Other legitimately
unlocked computers/phones can read current permitted shared comments. The nonce
is a receipt-ownership boundary, not a physical-device allowlist.

`ReviewerWorkspaceCollaborationStore` owns only encrypted thread and comment
receipt kinds in the verified owner anchor. All bodies/names are encrypted;
generic titles/audits contain no comment text or filename. The work graph and AI
facts/ledger remain unchanged. Comments are append-only literal text (no HTML,
remote URL/image preview, mentions or sending). Suggested parent UI must use
`textContent`, never raw body interpolation into HTML.

Bounds are4,000 characters/16KiB per body,100 comments/256KiB serialized comment
array per thread,512 comments and4MiB serialized comment allowance per workspace.
The4MiB check is a conservative logical content bound; encryption/row envelopes
add storage overhead. No stored content is silently truncated. Reads page25
comments and return an explicit server cursor. They never create a thread.

Comment and receipt commit atomically under the existing tenant lock followed
by current publication locking read, with current target and thread versions.
Same request UUID/digest replays the receipt once. Changed body/name/target/key
refuses. Only an absent receipt plus pure expected-name/version mismatch emits
the fixed definitive409 envelope; other failures stay unknown. Status is read
only, capability-owned and never redispatches. A valid receipt can confirm the
original saved operation after a lost response; no fabricated Saved result.

The new `collaboration-client.js` is a separate closed adapter alongside the ONE
existing shared workspace adapter. Trusted boot key `reviewerCollaboration`
comes from `CollaborationAccess::clientDescriptor`; descriptor endpoint paths
must be exact siblings under the existing shared projection route. The adapter
uses same-origin POST/JSON/CSRF/cookies/no-store/redirect:error only. It validates
closed comment/receipt replies and bounds real response streams256KiB. It has no
native/file/provider URL proxy, history cache or local saved-comments fallback.

Client flow: `show(target,before=null)` returns current comments plus selection,
target/thread versions and `can_comment`; `prepare(view,{request_id,
expected_reviewer_name,body})` captures that exact form; `send(prepared)` persists
the intended edit before IO. `recover()` never sends the comment again. Explicit
`retry(prepared)` uses the original body/UUID/digest. First proven rejection may
be explicitly acknowledged, retaining its draft and a bounded content-free
tombstone; prior unknown cannot be discarded by a later409. Remount restores
unknown intent only, never automatically dispatches or restores old comments.
SessionStorage64KiB/tombstones64 are explicit bounds. Confirmed apply remains
confirmed if optional local cleanup fails; a storage warning is returned.

Root owns detail DOM integration and its per-target draft/Center/Side/focus/
scroll session. Creating/moving a panel must reuse the adapter, not create a new
Save intent or discard the composer. Scope changes abort requests and fence
late replies, including a change away and back. A late verified apply after
destroy returns a content-free receipt with stale_context, not old content.
This client does not itself implement a DOM composer, upload or gallery view.
Both-engine/physical-device placement acceptance remains required after root's
hook integration; Node VM tests do not establish it.

Root receives four SMALL existing-file hooks: read-only current-context/final
fence methods on ReviewerWorkspace; inclusion of the new fixed route file;
the default-off config object; and the existing private boundary's exact three
collaboration paths. Root additionally owns boot/presentation/manifest/CSP/
compiled asset/CI registration and the actual composer. Nothing broadens native
discussion/download or X-Team identity routes. Controller and outer boundary
retain real method/CSRF/runtime/throttle/refusal statuses with private sanitized
JSON; no pre-controller debug trace or provider action is exposed.

`ReviewerWorkspaceCollaborationPruner::prune` is a real-current-owner service
with preview default true, bounded cursor and no recipient route or registered
scheduler/CLI impersonation. It verifies the exact owner context, immutable
Version/workspace/feature binding and expiry/revocation. With feature gates off,
the owner may still erase expired thread bodies/names; content-free bounded
receipt tombstones remain to prevent replay/reset. No live cleanup runs here.
There are no blobs in this stage; later upload cleanup needs its own review.

Focused proof uses actual isolated owner runtime SQLite/real publication unlocks
and fake fictional records, with stray HTTP forbidden. Two sessions observe the
same persisted content; encrypted raw storage, independent threads/routing,
names, actual role/target scope, CSRF/Origin, fixed rejections, disabled zero-SQL,
bounds/paging, owner expiry cleanup and no graph/native/ledger mutation are
covered. Node VM HTTP/storage are synthetic. Existing Workspace/Commands/
Boundary tests remain. PHP8.5 local is not target8.4 or MySQL concurrency proof.
Root must complete independent review and normal exact-candidate CI, then any
separately approved immutable publication transition. Current comments/access
must never be silently rebound to a new edition.
