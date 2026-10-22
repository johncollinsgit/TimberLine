# Ask Everbranch job voice prototype

Source assessment: October 22, 2026. Base: Enterprise integration checkpoint
`7512e27dc71075b909049d3229ceb4bf7a23cf22`. This isolated increment is default-off,
unmerged and undeployed. It does not modify the tour, header, shared Ask markup,
campus fixtures, native applications, credentials, accounts or provider grants.
The Enterprise owner remains the sole owner of final tour/shell integration.

## Readiness

| Capability | Source evidence | Current limit |
| --- | --- | --- |
| Two-way browser speech | Existing `resources/js/ai-voice-conversation.js`: explicit activation, one final utterance, scoped request, browser TTS, next turn after playback. Reused here. | Browser recognition may process speech online. Fake speech and browser automation do not establish physical microphone, accents, speaker echo or Safari/iPad/iPhone acceptance. No provider audio/realtime transport is verified by this work. |
| Enterprise Ask | `AskEverbranch`, `IntelligenceAskModel`, `Intelligence` use authenticated tenant/user/department evidence; approved model selection is a separate gated lane. | Existing Ask returns server-checked record facts and reference selection, not an independently verified live conversational narrator. No live model call was made here. |
| Grounded job lookup | New `VoiceJobs` reuses `Operations::listing/show`, `WorkspaceAccess` and `RequestWorkflow::view`. The endpoint returns current task facts, exact links and a permitted linked request's recorded location. | Narrow title-word matching, at most 200 permitted operational tasks and a bounded 1,000-request location scan. A missing match is incomplete evidence. No general semantic search, campus-wide census, live tracking, file/mailbox search or generated facts. |
| Identity and scope | Existing verified authentication, workspace middleware, canonical membership, department and native task permissions. Exact focus is read again under current policy. | Client scope stamps suppress stale UI; they never authorize the server. Ordinary members retain their existing permissions. Public browser samples cannot prove authenticated privacy. |
| Controls | New optional `Talk`, `Stop`, `Interrupt reply` controls and typed lookup share the existing capture loop. Hiding/page exit stops capture; context changes/disposal cancel pending UI. | The shell must call `contextChanged` on department/user/tenant/section changes and dispose controls when removed. Interrupt is an explicit button; the microphone stays paused during playback. Talk reconnects manually; there is no automatic retry. |
| Session/history/budget | 12 spoken turns, 10-minute wall-clock limit including interrupts, 45-second response wait, existing 90-second capture/180-second playback watchdogs. Only one focus and at most ten choices are retained in memory. | Stopping aborts the client wait; accepted server work may still finish. Existing model accounts retain their independent server budgets/history. This new endpoint dispatches no provider and incurs no AI usage. |
| Email | Existing `EmailDrafts` binds the user's authorized mailbox, exact recipient/subject/body digest, expiry, source/access rechecks, durable idempotent state and content-free audit. | Default-off delivery, own-mailbox/OAuth/provider readiness and live acceptance are separate. Voice lookup only returns `review_required`; it cannot create or confirm a send. |
| SMS/actions | Existing separately bounded trial/provider architecture remains unchanged. | No general voice-to-SMS preview/confirmation/execution adapter is added. Voice cannot approve writes, messages, payments or grants. Do not advertise this as working voice sends. |
| Costs and crew | Voice facts contain the permitted current handler/owner, status, due date, next action, blockers, observation time and source links. | No full crew roster or job cost total is inferred. Costs are deliberately outside this projection; authorized Planning remains the source for recorded project costs. Missing location/date/handler stays unknown. |
| Finale | The feature interface is ready for the existing Ask pane's owner to connect to its current job/detail/map controls. | Tour placement, fixture seeding and actual authenticated shell end-to-end acceptance remain pending. The component browser fixture is not a finished guided tour or live college workspace. |

No Avery key, Jarvis credentials, new credentials/OAuth, paid model/audio call,
live email/text, production grant, hosting change, merge or deployment was used.
The existing combined $5 SMS/email/AI trial allowance is not a voice budget grant.
Any provider-backed voice increment requires exact independent authorization and
verified current provider/rate/budget controls first.

The next actual generative increment, exact requested approval packet, provider
cost bounds, fixture message previews and physical-device acceptance are in
`enterprise-voice-live-decision.md`. Record lookup alone is not the completed
crowning assistant.

## Server contract

`POST /enterprise/voice/jobs` uses the existing Enterprise mount and verified
authenticated middleware, plus a 20/minute throttle. `ENTERPRISE_VOICE_JOBS_ENABLED`
defaults false; Ask, Operations and ordinary Enterprise access gates also apply.
Body: `area_id` UUID, `question` (1–1,500 characters), optional exact task UUID
`focus`. Recognized words stay out of GET URLs. Do not enable body tracing.

Outcomes: `matched`, `choose`, `no_match`, `review_required`. The response includes
server-owned `scope`, `mode=record_backed`, `provider_dispatch=false`, trusted
`sample_data`, timestamp and coverage. Only one unambiguous task is described.
Ambiguity exposes at most ten currently readable titles; selecting one requires
a new authorized read. Message/action language cannot dispatch a provider or
mutation. All responses are private/no-store. No transcript, conversation, usage
reservation or new business/audit row is persisted by a lookup.

The location is a linked permitted request's recorded label/coordinates, explicitly
not GPS or a surveyed position. Missing location produces no pin. The location
source and exact task source use server-generated mounted application links.

## Integration handoff to the Enterprise shell owner

1. Use the existing Ask pane, not a second assistant. Load the manifest entry
   `resources/js/enterprise/voice-jobs-entry.js` through that runtime's existing
   Vite helper. Both retail and independent Enterprise builds include it.
   Loading starts neither a microphone nor a lookup.
2. On `everbranch:voice-jobs-ready` (or immediately if the namespace is already
   present), call `EverbranchVoiceJobs.mount(existingVoiceHost, options)`.
   Keep the name **Ask Everbranch** and its **Talk** control.
3. `context()` returns `{runtime, authenticated, tenantId, userId, areaId}` from
   the current trusted presentation/current authenticated data response. The
   existing `workspace`/`viewer` IDs provide tenant/user; use current `area`.
   Public sample adapters use `runtime: 'sample'`, visibly fictional labels and
   their own local transport. They must not claim server authorization.
4. `lookup(body, {signal})` calls the existing `transport.request` with its
   mounted Enterprise base plus `/voice/jobs`, current tenant query and the
   body. The shared transport now forwards AbortSignal. Never use a provider URL,
   credentials or a generated URL here.
5. `openJob(job, {signal})` invokes the shell's existing task/project detail and
   linked-request map selection. Respect unsaved/pending work and recheck the
   signal before committing navigation. Return `true` only if it opened. With
   no recorded coordinates, show an honest missing-location state. Do not make
   an external map/provider request. `job.source_url` and
   `job.location.source_url` are existing authorized source destinations.
6. `onReply(result)` appends the checked facts, source links, `as_of`, coverage
   and `latency_ms` to the existing Ask log using text nodes. Render `choose`
   candidates as explicit buttons invoking `session.choose(id)`. Keep at most
   twenty visible turns and clear them on context change. Do not call a send or
   write executor for any outcome. `opened=false` must not be labeled opened.
7. Supply existing browser `Recognition`, `Utterance` and `synthesis` objects,
   `onTranscript` for an editable fallback draft and `onError` for visible
   errors. Typed fallback calls `session.ask(question)`. Call
   `session.contextChanged()` before context/section changes and
   `session.dispose()` when unmounting. Do not auto-start Talk in the tour.
8. After integration, place the actual working read/navigation step last in the
   existing tour. The campus owner supplies the fictional students, faculty,
   departments and operational jobs. This branch only supplies synthetic test
   records, not another campus dataset. Use a current actual title such as
   “Show the dorm pipe job”, then “Where is it?” or “Who is handling it?”.

The title matcher and factual narration must be labeled **record-backed job
lookup**. Scripted/fake audio and sample responses must never be labeled live AI.
If the shell or physical speech is not accepted, show a clearly marked preview
with typed fallback; do not promise a working voice finale.

## Verification and remaining release gates

Local checks on October 22:

- 59 focused PHP cases / 814 assertions passed: new voice lookup plus existing
  operations, next slice, requester search, prefix mount and independent runtime.
  New endpoint alone: 5 cases / 66 assertions, rerun after final formatting.
- 11 voice lifecycle/controller Node cases passed, including exact focus,
  ambiguity, revoked scope, cancellation, late responses, explicit interrupt,
  total-time/turn limits and wait timeout without retry.
- Existing build prerequisites passed: TypeScript, 16 Trajectory tests and
  40 POS tests. Both Vite production artifacts compiled successfully. Existing
  dependency annotation warnings do not come from this voice change.
- Edge/Chromium and Windows Playwright WebKit at a 390px viewport passed the component fixture: explicit Talk,
  job/detail and recorded-location display, followup, ambiguity, typed fallback,
  interrupt, stop during wait, hide, permission denial and access revocation.
  Seven loopback lookup calls per engine, zero external requests and zero page errors.
  Screenshot/results: local `storage/app/voice-jobs-acceptance/` (not committed).
- The initially absent WebKit executable was resolved using the repository's
  pinned Playwright 1.62.1 CLI and its official CDN. WebKit 26.5/revision 2336,
  FFmpeg 1011 and Winldd 1007 are local test dependencies under
  `storage/app/playwright-browsers`, not system software or Mac UI. Both engine
  results are saved. Physical iPad/iPhone microphone/speaker acceptance remains
  completely unverified; Windows WebKit does not establish Safari speech support.

Reproduce with `npm run test:ai-voice`, the six PHP test files above, and
`VOICE_BROWSER_CHANNEL=msedge node tests/browser/enterprise-voice-jobs.smoke.mjs`.
Use the documented Windows test-local OpenSSL configuration and in-memory SQLite,
array mail/cache/session and blocked provider requests. No real data is needed.
The browser harness replaces speech and server replies; PHP tests separately
exercise real canonical persistence and policy. Neither is live-provider proof.

Pending: owner shell/tour wiring, actual seeded-workspace end-to-end test,
physical supported-device speech, protected Linux/CI review/release evidence,
explicit paid voice approval if a provider is proposed, and separate reviewed
email/SMS voice adapters. This draft does not activate or approve any of them.
