# Actual generative voice: approval decision for one synthetic acceptance session

Prepared October 22, 2026. This is a proposed next increment, not an approval,
provider activation, implementation-complete claim or promise for tomorrow.
PR 472 currently implements record-backed lookup and browser voice controls.
That alone does not complete the requested crowning conversational assistant.

## Minimal useful first live scope

Use the existing explicit browser speech input, **OpenAI Responses with literal
model ID `gpt-6-luna`**, and browser spoken playback. This would be real model
interpretation/narration of current authorized fictional job facts. It is a
turn-based voice assistant with explicit interrupt, not a Realtime/full-duplex
audio session. GPT-6 Luna produces text and does not accept audio, so no OpenAI
audio/TTS/Realtime endpoint belongs to this first scope. Browser speech may use
the browser/device vendor's online service; disclose this before Talk.

The model/endpoint already exist in owned `ResponsesTransport` and the server
model rate card. Current official [model documentation](https://developers.openai.com/api/docs/models/gpt-6-luna)
and [Standard pricing](https://developers.openai.com/api/docs/pricing), opened on
October 22, specify $0.10 per million input tokens and $0.50 per million output
tokens. These sources do not prove that any particular existing project/key is
permitted or operational. No account/model probe was made.

Reuse authenticated tenant/user/department policy, canonical job/location reads,
and the existing committed usage reservation/UUID/uncertain-outcome rails.
Add a separate disabled `voice_answer` purpose rather than changing the current
reference-only Ask schema. It must accept only server-built authorized evidence,
return bounded plain text plus allowlisted refs and a finite read/clarify/draft
intent, re-read selected records before opening, and never execute model tool
calls. Generated words and recipient suggestions are untrusted proposals.
Fake tests and independent review must pass before the live approval is used.

## Concrete approval packet

| Decision | Exact proposed scope | What is not yet established |
| --- | --- | --- |
| Provider/model | OpenAI `POST https://api.openai.com/v1/responses`, `gpt-6-luna`, Standard tier, `reasoning.effort=none`, `store=false`; no external/billable tools, fallback model, audio endpoint or automatic retry. | Existing Enterprise provider account/project permission and actual model availability. |
| Credential source | An **already existing, explicitly permitted Enterprise OpenAI project/key**, identified by nonsecret project and key ID by the owner. Do not display/read/copy the key during planning. | Tracked evidence identifies only Avery's `Everbranch AI Branch` key, whose reuse is forbidden. No eligible Enterprise key has been verified. This is the specific missing owner selection, not permission to create one. |
| Credential destination | Only the approved independent Enterprise runtime's secret configuration `ENTERPRISE_AI_API_KEY`; freeze exact runtime/mount, synthetic tenant ID, model/rates and credential fingerprint. Existing configured permitted key should stay in place. | Approval to configure or reuse a particular existing key for this new purpose. Never use `EVERBRANCH_AI_BRANCH_API_KEY`, Jarvis, browser code, GitHub artifacts or another customer's grant. A new key would require a separate explicit creation approval. |
| Session/expiry | One named existing verified presenter, one existing explicitly fictional tenant/department, one 10-minute session, 12 spoken turns. Two initial paid connection/evidence probes are included in the same campaign. Expire all live allowance by `2026-10-24T00:00:00Z`. | The owner must identify the actual existing synthetic tenant/area and presenter IDs; this proposal creates no accounts or grants. |
| Hard spend request | **$0.05 total lifetime provider cost** for the entire new voice campaign, at most 14 calls including the two probes, with all prior voice acceptance usage counted. No monthly reset, top-up, subscription or customer charge. This is a new requested approval, not a use of the existing $5 trial allowance. | No extra voice spend is approved. A durable campaign/session cap must be implemented and tested before any call; the existing per-tenant ledger alone must not multiply the approved total across tenants/sessions. |
| Per-call reservation | At most 12,000 input bytes including instructions/framing/history and 800 output tokens. At current Standard rates, reserve `ceil((12000*100000 + 800*500000)/1000000) = 1600` micro-USD, **$0.0016**, before HTTP. All fourteen maxima total **$0.0224**, inside the requested $0.05 ceiling. | Revalidate rates immediately before approval/use; regional/other billing treatment must be included or fail closed. This ceiling is not proof of actual charged usage. No regional endpoint or cache-writing feature is proposed. |
| Conversation state | At most the previous six user/assistant pairs, always within the same input byte bound. Current evidence is rebuilt each turn. No provider conversation storage. No student/private campus data. | The new generative purpose/history projection is not implemented in PR 472. Use existing encrypted/expiring scoped storage if persistence is needed; do not persist memory by implication. |
| Failure | Preserve reservation and freeze paid continuation on timeout, missing/over-bound usage or invalid structured output. Repeated UUID cannot dispatch twice; changed input cannot reuse it. Stop cancels capture/playback/wait without claiming accepted server work was canceled. | Actual provider/timeout/idempotency acceptance has not happened. |

The exact dollar arithmetic above is derived from the cited Standard rate card
and the proposed bounds; it is a reservation ceiling, not a guarantee of pricing
or account access. The implemented transport already conservatively charges all
input at the uncached rate. Do not enable a new purpose until its own reviewed
approval/campaign counter is durable and cannot reset or bypass other trial caps.

## Email and SMS: exact previews first, real delivery separate

The recommended first live voice session permits **zero real sends** and no
OAuth. The assistant may offer a draft and open the existing review composer;
it cannot silently approve or send it. This is a working draft workflow only
after its model-to-composer adapter is implemented and fake-tested; PR 472
currently blocks these requests with `review_required`.

Freeze these fake-provider acceptance previews:

- Email to `voice-preview@example.invalid`, subject `Everbranch voice demonstration test`.
  Body: `This is an Everbranch demonstration using fictional campus work. The fictional dorm pipe repair is awaiting an update. No real college record was changed. No action is required.`
- SMS to `+12025550101`, body: `Everbranch demonstration test: fictional campus work only. No real college record was changed. No action is required.`

These are **fictional fixture destinations**, not approved or verified live
recipients. Use only the fake transport. Show the exact channel, sender,
recipient, subject/body, source timestamp and maximum cost. Confirmation must
be an explicit separate human UI action, bound to the immutable digest and
current source/grant versions. Recognized speech must not confirm a send.

If real delivery is later requested, the owner must separately approve:

1. One exact owner-controlled receiving email and one exact consented E.164 phone
   number. `john@evergrovesoftware.com` is documented as the intended own-user
   Gmail sender account; that does not verify it as a receiving test destination
   or approve any SMS number. Do not guess or reuse contact/profile addresses.
2. Existing verified service sender/account and permitted credential source:
   SendGrid into `ENTERPRISE_TRIAL_SENDGRID_API_KEY` / `EMAIL_FROM`, and Twilio
   into `ENTERPRISE_TRIAL_TWILIO_ACCOUNT_SID` / `AUTH_TOKEN` / `SMS_FROM_NUMBER`.
   None is currently established as approved for this voice work. Do not create
   a sender, number, registration, OAuth consent or credential as a shortcut.
3. The exact rendered preview, one email plus one SMS maximum, approved inclusive
   cost per attempt, and an explicitly shared durable budget including all prior
   trial/voice acceptance spend. No additional $5 or independent voice ledger may
   bypass the existing combined trial cap. Never route existing reviewer trial
   actions through general Enterprise AI billing to escape that ledger.
4. Existing durable claim/idempotency/audit and unknown-outcome reconciliation.
   Provider acceptance is not delivery; a real send requires provider receipt and
   the recipient's actual observation. No automatic retry after uncertainty.

## Physical acceptance before presenting the finale as working

The Enterprise owner wires the real Ask pane and seeded synthetic dorm job first.
On the exact protected accepted release and intended HTTPS mount:

1. Use the actual presentation iPad/iPhone and supported browser. Confirm the
   selected presenter, tenant and department, fictional-data label and model/budget
   disclosure. Explicitly tap Talk and grant the physical microphone permission.
2. Speak naturally: “Bring up the dorm pipe job.” Verify the actual authorized
   job opens, its recorded map location appears if present, source links work,
   and the model accurately states status, handler, due date, next action and
   blockers. Unrecorded data must remain unknown. Compare against the saved source.
3. Ask “Who is handling it?” and “Where is it?” Test two similarly named jobs and
   require explicit disambiguation. Switch to a restricted department/user and
   verify the previous job, speech and history cannot leak or navigate afterward.
4. Test speaker echo at presentation volume, ordinary room noise, a second accent,
   denial/revocation of microphone access, silence, interrupted playback, app
   backgrounding, network loss/reconnect and Stop during the paid response wait.
   Listening must pause during playback; no late response may restart capture.
5. Record capture-to-visible-answer and capture-to-audio latency across at least
   five turns and note device/browser/build/network. The presenter must judge it
   usable; no numerical latency or acoustic success is inferred from browser tests.
6. Dictate the email/SMS fixture drafts, inspect exact previews, cancel one and
   confirm the other only against the fake transport. Verify no external send.
   Real delivery acceptance, if separately approved above, is a different step.
7. Exhaust time/turn/spend limits, replay an accepted UUID and simulate an uncertain
   provider outcome. Verify no extra call or duplicate action. Stop/dispose, reopen
   and confirm there is no ambient microphone or hidden persistent conversation.

Until those steps pass, call the last tour item a clearly marked preview. Neither
record lookup, canned audio, fake speech, Windows WebKit nor a successful model
HTTP response alone completes the requested crowning assistant.
