# Generative voice: remaining activation decision

October 22, 2026. Draft PR 472 now contains disabled source for grounded generation,
replaceable transport/reservation interfaces, owned bounded history, cancellation
and exact human-recipient draft previews. Tests use fake providers and speech.
No model call, paid use, credential probe/configuration, live send, merge or deploy
has occurred. Record lookup alone is not the completed crowning assistant.

## Proposed smallest live acceptance scope (unapproved)

| Decision needed | Concrete proposed scope | Still missing |
| --- | --- | --- |
| Provider/model | Existing fixed `POST https://api.openai.com/v1/responses`; literal `gpt-6-luna`, Standard tier, `reasoning.effort=none`, `store=false`, no tools/fallback/retry. Browser recognition and browser TTS are separate vendor capabilities. | Account permission and actual model availability/quality. No audio/realtime provider is configured. |
| Existing credential | Owner identifies one **already existing explicitly permitted Enterprise** OpenAI project and key ID, without revealing its secret. Destination is the exact isolated Enterprise runtime's `ENTERPRISE_AI_API_KEY`, bound to tenant/model/rates/fingerprint. | No eligible key has been verified. Avery's Everbranch AI Branch key and Jarvis credentials cannot be reused. A new key/account/OAuth needs separate exact approval. |
| Actor/runtime | One existing verified presenter ID, one clearly fictional tenant and department ID, accepted build/HTTPS mount; no accounts/grants created. | Owner supplies exact nonsecret IDs and destination; campus owner integrates the existing Ask pane/job/map. |
| Internal acceptance spend | One ten-minute session, at most 12 calls, **proposed $0.05 inclusive remaining lifetime ceiling on that single tenant's existing Enterprise AI ledger**, counting prior usage/reservations; no probes outside this same session/ceiling. Freeze allowance and expiry before first call. | No additional voice spend approved. Freeze current used/reserved total, approved inclusive rates and exact settings; if less remaining capacity, reduce test scope. No per-session/tenant multiplication, reset, top-up or shared-trial escape. These are suggested test safety bounds, not customer pricing or included usage. |
| Per-call bound | 12,000 input bytes including instructions/framing/history, 800 output tokens; current cited Standard rates imply a worst-case reservation of 1,600 micro-USD ($0.0016) per call and $0.0192 for twelve calls. | Revalidate applicable rate/tier/region immediately before approval; fail closed on uncertainty. Estimated usage is not settled provider invoice cost. |
| Content/retention | Eight current permitted job projections, six messages (three pairs), no finance/mailbox/private student data; ten-minute expiry and indexed bounded content scrub, including usage values. | Configure and prove `enterprise:voice-prune --limit=1000` cadence for idle data in the intended runtime before activation. No live scheduler has been enabled. |
| Actions | Generated answers/open/read/clarify and **draft proposals only**. Human exact email/E.164 entry, immutable preview, five previews/session, no confirmation or send endpoint. | Recipient identity/consent and sender are unverified. No live messages are approved; never describe a preview as delivery. |

Official [Luna model documentation](https://developers.openai.com/api/docs/models/gpt-6-luna)
and [Standard pricing](https://developers.openai.com/api/docs/pricing), opened on
October 22, list text input/output at $0.10/$0.50 per million tokens. Luna is a
text model; browser speech is not OpenAI audio acceptance. Arithmetic above is
an inference from those rates and conservative input bounds, not proof of actual
account access, billing or authorization. No server key was inspected.

Source includes only the existing Responses adapter. Future browser+Luna,
Groq Whisper turbo+Luna+Deepgram Aura, or Gemini Live/OpenAI Realtime comparison
requires task-specific latency/quality/interruption benchmarks and its own
approval. No speculative new providers were implemented or activated.

## Exact draft acceptance fixtures

Use fake transports only:

- Email: `voice-preview@example.invalid`; subject `Everbranch voice demonstration test`;
  body `This is an Everbranch demonstration using fictional campus work. The fictional dorm pipe repair is awaiting an update. No real college record was changed. No action is required.`
- SMS: `+12025550101`; body `Everbranch demonstration test: fictional campus work only. No real college record was changed. No action is required.`

These are fictional fixtures, not verified receiving destinations. The current
prototype displays exact recipient, subject/body, digest/expiry and unverified
sender/recipient status. `can_confirm=false` and `delivery_ready=false` throughout.
Stop cancels the draft session; after draft playback the microphone pauses for
manual review. Any later send adapter must separately recheck current permission,
recipient identity/consent, sender, exact body, cost, immutable explicit human UI
confirmation and durable claim/idempotency/audit. Model text or recognized speech
must never authorize a send.

For a future real delivery request, owner approval must name one owner-controlled
receiving email and one consented E.164 number, verified sender/service and
existing permitted credential source/destination, exact rendered previews and
inclusive maximum attempts/cost under the shared ledger. No new sender, phone,
registration, OAuth or credential may be created implicitly. Provider acceptance
is not recipient-observed delivery; uncertain outcomes never auto-retry.

## Physical acceptance before presenting as working

On the exact accepted build, approved mount, presenter, fictional tenant/department
and intended iPad/iPhone/browser:

1. Explain browser speech handling; tap Talk and grant physical mic permission.
   Ask naturally to bring up the fictional dorm pipe job. Verify actual detail/map
   navigation, recorded provenance and working source links. Compare every status,
   handler, date and blocker against current records; missing data remains unknown.
2. Ask who handles it and where it is. Test similarly named jobs, explicit choice,
   missing facts and restricted scope changes. Historical facts must not leak after
   source edits, project revocation, tenant/user/department change or Stop.
3. Test speaker echo, room noise, another accent, permission denial/revocation,
   silence, interruption, backgrounding, network loss/manual restart and Stop during
   response wait. No late callback restarts capture; no automatic paid retry.
4. Record capture-to-visible and capture-to-audio latency over at least five turns,
   with device/browser/build/network. The presenter decides usability; fake browser
   timing proves neither physical acoustics nor a latency promise.
5. Dictate fixture drafts; verify mic pauses and exact manual previews, cancel one,
   inspect disconnected-delivery flags. Do not claim a real send or successful
   confirmation; neither is connected.
6. Fake tests exhaust time/turn/spend bounds and uncertain usage before live use.
   Match approved live calls to retained cost receipts and reconcile actual provider
   usage. Operator disables generation again when the approved window ends.

If those checks are incomplete, the finale must show actual accepted read/navigation
capability or be plainly labeled Preview. No guaranteed tomorrow-ready claim.
