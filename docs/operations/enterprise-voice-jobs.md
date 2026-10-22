# Ask Everbranch voice prototype

## Integrated private-shell increment (October 22)

The Enterprise integration branch mounts the reviewed optional generation,
exact-session cancellation and preview adapters in the existing Ask pane and
final tour screen. Completed typed/spoken turns retain server history; replacing
an unresolved turn or explicit Stop/context/disposal cancels the exact old scope.
Generated, simulated and record-backed replies are labeled separately before
and after explicit requests. Source job opening still re-reads current canonical
task/version/department authority. No model-created URL or recipient is trusted.

Private actual-kernel Chromium/WebKit acceptance replaces only the provider and
speech devices, preserving authentication, CSRF, encrypted history and the real
reservation ledger. It covers two typed turns and a spoken follow-up, manual
email/SMS recipient previews and exact replay/conflict, cancellation after
departure or department change, late-result suppression and phone/tablet layouts.
This does not verify real microphone hardware, provider delivery or paid AI.

Unknown AI outcomes now pause new tenant reservations/dispatch claims until
reconciled, without zeroing unknown exposure or blocking completed exact replay.
Already-dispatched costs are not a guaranteed account cap. The controlled trial
continues to refuse generation until its shared-ledger adapter is reviewed.
See [the concrete runtime decision packet](enterprise-runtime-approval-packet.md).

Source-only, default-off increment in draft PR 472, based on Enterprise checkpoint
`7512e27dc71075b909049d3229ceb4bf7a23cf22`. No merge, deployment, provider activation,
credentials, paid calls, real messages, accounts or grants. The Enterprise owner
alone owns the Ask pane, header, fictional campus fixtures and final tour placement.
Record lookup alone is not the completed crowning conversational assistant.

## Implemented source versus verified behavior

| Capability | Implemented source | Acceptance evidence and limits |
| --- | --- | --- |
| Speech conversation | Existing explicit browser capture, scoped request, browser TTS; 12 turns/10 minutes, 45-second wait, capture/playback watchdogs, Stop/Interrupt, manual restart and typed fallback. | Fake speech in Chromium and WebKit; no physical microphone, echo, accent, mobile Safari or acoustic acceptance. Browser speech can use its vendor's online service. No realtime audio provider is connected. |
| Record fallback | `VoiceJobs` reads canonical current task permissions and linked permitted request location. Title matching checks up to 200 tasks and 1,000 request locations. | Actual server/policy fixtures tested; browser server replies are synthetic. Missing matches are incomplete evidence. No costs, private student records, mailbox, files or live GPS. |
| Generative adapter | `VoiceAssistant`, `VoiceCompletionProvider`, `ResponsesVoiceProvider`, `VoiceCostReservation` reuse existing `ResponsesTransport` and `Intelligence` accounting. Separate `voice_answer` purpose; no tools or send executor. | Fake provider only, including its explicit `provider_simulated=true` and `usage.state=simulated_estimate`. Real Responses account/model/credential permission and quality remain unverified. |
| Grounding and privacy | Current tenant/user/department and native task access; eight supplied projections including focus/history, at most six historical messages (three pairs), server-only history, 12 turns and ten-minute expiry. Revalidate **every supplied source**, regardless of model-selected citations, before dispatch, reply, replay and draft preview. | Bounded evidence is not campus-wide semantic search. The question does not search all tasks. Generated narrative can still be wrong; task benchmarks and physical acceptance are required. |
| Budget and replay | Commit worst-case reservation before IO; approved positive existing tenant lifetime cap/base rates and separate default-zero inclusive voice ceilings (input at least 1.25x base, output at least base), no automatic retry, immutable per-user request UUID/input and accounting receipt; uncertain usage retains maximum reservation. Voice clamps input bytes including instructions/framing to 12,000 and output to 800 tokens. | Controlled shared trial mode deliberately falls back without model dispatch, preventing escape of its combined budget. No voice spend is authorized. Session ceiling is 12 bounded calls under the existing cumulative tenant cap, not a customer pricing term. |
| Cancellation | Client abort plus authenticated server cancellation/tombstone handles Stop-before-arrival, wait timeout, stale responses and successor-session races. Post-IO permission/source/cancel checks suppress late replies. | Accepted provider work may finish and retain cost. Cancellation failure is visible and never promises a refund. Manual new session is required after uncertain/pre-dispatch failure; no paid automatic reconnect. |
| Email/SMS proposals | Strict finite intent and exact schema; model cannot authorize recipients, tools or confirmation, or provide trusted action/navigation URLs (arbitrary generated text is untrusted). Human explicitly enters one email/E.164 recipient for server-held immutable subject/body preview. Editor permission, source/version rechecks, expiry, exact replay, five previews/session and content-free audit. | `delivery_ready=false`, `can_confirm=false`, `recipient_verified=false`. No sender selection, recipient identity/consent verification, confirmation route or send adapter. Never advertise this as working voice sends. Draft playback pauses mic for review; explicit Talk resumes and Stop invalidates the session. |
| Retention | Indexed ten-minute content expiry; bounded scrubbing removes session/history/pending prompts, recipients/drafts and `ent_ai_usage.value`, preserving costs/idempotency and content-free audit. Late completion cannot restore scrubbed content. | New sessions opportunistically scrub 100 expired tenant rows. Isolated `enterprise:voice-prune --limit=1000` handles idle records. An operator must configure/verify maintenance cadence before live activation; no scheduler or live database was changed here. Backups/log/provider cache and abuse-monitoring retention need separate runtime review; local expiry and store=false do not promise provider deletion. |
| Navigation and source links | Only server-projected exact jobs can invoke the supplied existing opener. Explicit ambiguity choice re-reads through deterministic lookup. Linked locations carry recorded/synthetic provenance. | Campus owner must connect and test actual detail/map/source navigation in the accepted shell; component screenshots do not prove the guided tour is integrated. |

## Endpoint contract

All endpoints reuse verified authentication, resolved workspace and area policy,
private/no-store JSON and existing mounted route generation. Default-off flags:
`ENTERPRISE_VOICE_JOBS_ENABLED`, `ENTERPRISE_VOICE_GENERATION_ENABLED`,
`ENTERPRISE_VOICE_DRAFTS_ENABLED`; Ask/Operations/access/spend gates also apply.
Inclusive voice rates require `ENTERPRISE_VOICE_INPUT_CEILING_MICROUSD_PER_MILLION` and `ENTERPRISE_VOICE_OUTPUT_CEILING_MICROUSD_PER_MILLION`, both default zero. Missing/insufficient ceilings retain zero-dispatch record lookup. They must include applicable cache-write/account/tier/region charges. No flag or provider secret was activated by this work.

- `POST /enterprise/voice/jobs`: `area_id`, question (1-1,500 chars), optional task
  UUID `focus`. Returns `mode=record_backed`, `provider_dispatch=false`, scope,
  timestamp, coverage and matched/choose/no_match/review_required. No persistence.
- `POST /enterprise/voice/turns`: same fields plus client-generated UUID
  `session_id` and unique per-turn `request_id`. Client history/confirmation are
  prohibited. Missing permitted configuration retains zero-dispatch fallback.
  Configured generation adds exact session/turn IDs, trusted simulation marker,
  answered/matched/choose/draft_proposed, current source links and usage receipt.
- `POST /enterprise/voice/sessions/{id}/cancel`: area ID, exact private session;
  may create an early cancellation tombstone. Never reverses accepted costs.
- `POST /enterprise/voice/previews`: area/session/turn/preview UUIDs and **human**
  `to`. Client subject/body/confirmation prohibited. Returns exact immutable
  preview, digest, expiry and disconnected-delivery flags. Does not send anything.

JSON output is untrusted text. Do not enable request/response body tracing or
render model text as HTML. Exact source links are server-generated; never follow
model-provided URLs. No general assistant conversation storage is implied.

## Existing Ask pane integration handoff

1. Load `resources/js/enterprise/voice-jobs-entry.js` from its runtime Vite manifest.
   `EverbranchVoiceJobs.mount(existingVoiceHost, options)` adds optional controls
   without starting capture. Keep Ask Everbranch and Talk. Do not edit another
   assistant or start the microphone automatically during the tour.
2. Provide `context()` with runtime private/sample, authenticated=true for private,
   exact current tenantId/userId/areaId. Context stamps suppress stale UI, never
   authorize server data. Public/sample runtime always uses lookup, never generate.
3. `lookup(body,{signal})` calls the existing server jobs route using existing
   CSRF-aware transport. For an approved private generative integration only,
   optionally supply `generate(body,{signal})`, `cancelSession(body)` (required
   together), and `preparePreview(body,{signal})` to the above routes. No provider
   URL or secret goes into the browser. Keep all generation flags false pending
   the decision in `enterprise-voice-live-decision.md`.
4. `openJob(job,{signal})` uses existing detail/map navigation, respecting pending
   edits and signal before committing UI. Return true only if opened. Missing
   coordinates mean no pin; no external map request is required. Render current
   server source links, timestamp, coverage and latency alongside the answer.
5. `onReply`, `onTranscript`, `onError`, `onState` use existing Ask log/text nodes.
   Render choose candidates as explicit `session.choose(id)` buttons; keep at
   most twenty visible turns and clear on context change. Generated citations
   do not establish truth; simulation must remain plainly labeled.
6. Use `session.ask(question)` for typed fallback. `previewDraft(exactTo)` is a
   separate human UI operation; optional mounted controls display exact text
   without any Send/Confirm button. No outcome calls a write/send executor.
7. Call `contextChanged()` **before** department/user/tenant/section changes and
   `dispose()` when unmounting. Hidden/page-exit stops capture/cancels pending work.
   Stop invalidates the draft; draft playback pauses capture while review stays
   available until expiry. Talk resumes explicitly; no background listening.
8. Campus owner connects the existing fictional dorm pipe job and final tour step.
   No private student/faculty data, extra campus fixture set, shared header or tour
   markup is added here. Show only actual tested capability; otherwise mark Preview.

## Evidence and remaining gates

Focused PHP tests use synthetic tenants/tasks, in-memory data and fake model,
with HTTP stray requests prohibited. They exercise policy, all-source freshness,
owned replay, exact preview, cost reservation/uncertainty, cancellation and
retention. Node tests exercise fake capture/playback, stale cancellation races,
manual preview and mic pause. Browser component tests run fake speech and
synthetic endpoints in Chromium/WebKit at 390px, with all external requests
blocked, no page errors and no Send/Confirm control. Evidence is written to
ignored `storage/app/voice-jobs-acceptance/`, including explicit `liveAI=false`
and `physicalAcousticAcceptance=false` markers.

Additive expiry/index migrations are restart guarded, retain data on code
rollback, and have manifest-registered durable MySQL recovery tests. Local lint
and SQLite tests are distinct from actual disposable MySQL CI execution.
Independent review and exact-head CI results belong in the PR; earlier-head green
checks are not evidence for a changed head. No merge/deployment is authorized.

Remaining: final owner integration, approved existing provider/key and inclusive
spend scope, retention maintenance and runtime review, fake-to-real model task
benchmarks, physical iPad/iPhone acoustics/latency/reconnect acceptance. Live sends
require a separate recipient/sender/preview/budget approval and a reviewed executor.
No customer fee, included usage amount or numerical customer cap is established.
