# Enterprise record-backed voice and usage checkpoint

Source candidate, October 22, 2026. This continues the existing Enterprise stack;
it neither hosts a client nor grants access or activates billing. The integration
reuses the reviewed job-voice implementation from `b2bddb0` (draft #472) and the
executive/member-preview milestone (draft #474). Later work in other owners'
branches is not silently included.

## Working source and safety boundaries

The existing Ask pane supplies explicit Talk, Stop and Interrupt controls, a typed
fallback, source links and a canonical job detail. Opening the page or replaying
the seven-scene, three-minute tour starts no microphone, model request or message.
The last scene opens the actual Ask section; availability in the private runtime
requires both Ask and the independently default-off `voice_jobs_enabled` flag.

The authenticated lookup rechecks existing tenant, department and native project/
task permissions. Before presenting a returned job, the shell reads its canonical
task again and checks department, project and both versions. A concurrent change
requires a new question. A context change aborts pending UI and clears evidence;
late replies cannot reopen a departed pane. Failed lookups clear old details and
message previews. Record text uses text nodes, and links stay in the trusted
mounted application. Unknown locations have no invented map pin. Recorded
coordinates are explicitly neither live GPS nor verified placement.

This is deterministic, **record-backed job lookup**, not verified generative AI.
The client retains at most twenty visible turns and one selected job in memory.
Recognized requests to send or change work return review-required information;
there is no mutation or provider dispatcher in this endpoint. Email/text preview
dialogs show facts and links with **Preview / not sent**, no selected recipient
and no send control. They do not replace the separate exact-confirmation Gmail
flow. Browser speech recognition may process audio online; it requires an explicit
Talk click. No application-held model/audio credential is added by this work.

The public reviewer uses the same shell with a pure isolated sample transport.
Its lookup and usage GET bypass business-mutation receipts and save no question,
transcript or business state. Executive/worker/student perspectives remain sample
projections, never authentication. Fictional student and faculty requests/jobs use
the existing official public location catalog; faculty is not a real account.
The public CSP still forbids connections, workers and frames. Its reviewed voice
bundle is embedded as a fixed self-contained manifest entry; missing/importing
assets leave voice unavailable rather than relaxing the policy.

## Admin usage

`GET /enterprise/ai-usage` reads the existing encrypted `ent_ai_usage` ledger.
Only a freshly authorized owner/admin may aggregate currently permitted Enterprise
departments. The output has no prompts, user identities, response content, record
or provider identifiers, credentials or private project fields. It separates
reported tokens, usage-derived settled charges, original reservations, unresolved
exposure, missing evidence and bounded scan coverage. No provider is called by GET.
An empty ledger is not proof of zero external account spend. Ledger estimates are
not verified invoices. Numerical licensing/usage caps and final commercial terms
are deliberately absent.

The sample admin page contains fixed, clearly **fictional** department/feature
usage and provider-cost estimates. These are not calculated college metrics or
actual paid tests. Budget/readiness is a discovery/configuration status, not an
activated subscription or a promised allowance. Core manual workflows continue
to function with AI disabled. AI assistance, agents and voice preferences belong
to the separate proposal discovery implementation, not this page's authority.

Stable tour scene/page metadata and `data-demo-page="enterprise-<section>"` exist
only for navigation/review integration. A mentor's selected name supplies neither
identity nor tenant permissions; the proposal owner retains the comment engine.

## Verification and remaining gates

The dedicated-kernel tests exercise real prefixed CSRF rejection, valid-session
acceptance, method restrictions, throttling, private-project denial, fresh
membership revocation and finance/content exclusion. Usage tests exercise the
existing fake Responses transport through the real ledger and verify that GET
adds neither calls nor writes. Public sample tests prove unchanged state/storage.

`enterprise-voice-shell-browser.mjs` runs inside both the actual public HTTP
renderer and the private authenticated persistent fixture. Speech capture and
playback are simulated; records/permission APIs, source navigation and the shell
are real. Desktop, phone and iPad portrait/landscape checks are viewport simulations.
Neither these tests nor CI establish physical microphone/acoustic/iPad acceptance,
installed-app behavior or a live generative-provider connection.

Keep the entire stack draft until required independent reviews and exact-head CI
are complete. The authorized public demo release still follows protected GitHub
to Forge. Hosting the durable client requires the exact database/runtime/initial
access actions in `enterprise-persistent-workspace-approval.md`. No credential,
OAuth grant, real send/recipient, deployment or backup/restore operation occurred
in this source milestone. Related paid calls from this work remain zero.

Department-head setup and COO activation is **plan only** in
`../architecture/enterprise-department-activation-design.md`. Current broad
owner/admin authority is not misrepresented as the proposed head-only setup gate.
Searchable deleted-item recovery remains separately plan-first.
