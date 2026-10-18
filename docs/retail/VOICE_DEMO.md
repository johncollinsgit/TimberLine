# Retail conversational voice demonstration

Use **Talk with AI → Start conversation** in the installed Retail app. The business
selector starts with the storefront being shown. Speak naturally, ask follow-up
questions, and interrupt a reply. Mute stops sending microphone audio while replies
remain audible. End, closing the dialog, navigation, backgrounding, a lost connection,
and the five-minute limit stop the media tracks and close the peer connection.
Returning online checks readiness but never restarts the microphone automatically.

## Context and connection

The owner-authenticated server supplies the selected demo catalog (up to 100
products, including all 62 Sawyer source products), canonical Evergrove offer, and
a bounded snapshot of fictional customers, sample orders, reward activity and
sample staff notes. **Ask AI about this customer** opens voice with that profile
selected; **Ask AI about this workspace** preserves the current staff section.
Conversation state stays within
the current Realtime session. Changing businesses starts a fresh conversation.
The connected snapshot resolves only the selected `ww-demo-*` tenant with a
verified showroom marker. Customers require both `is_demo=true` and an `.invalid`
email address. Only simulated orders for those customers or anonymous demo shoppers
are included. Emails, phone/address fields, staff identities, actor IDs and request
keys are excluded. The snapshot is bounded to 50 customers, 30 recent orders, six
notes per customer and twenty reward entries, with truncation indicated.

Tablet-only sample activity uses the selected business storage key. It sends only
allowlisted fields; the server derives the two canonical fictional names, validates
merchant/customer IDs, catalog line identities and integer-cent totals, and rejects
extra/private fields. Connected and tablet sources are never merged. Sawyer never
queries its real customer workspace; it can use only fictional tablet profiles.
Private prospect/CRM notes, research, credentials and real customer/tenant records
are not attached. The UI shows the source, customer/order counts and focused
profile. Context refreshes through get_demo_context after local demo edits. Seven allowlisted
tools can navigate, open a fictional profile, fill allowed fields, save a fictional
customer or invoice, and prepare a marketing draft. Completed tool calls are checked
against strict local argument/ID allowlists and deduplicated by call ID. Cancelled
calls do not execute. No live search, message send, payment or real account mutation
is available to the AI. Recipient confirmation and actual delivery are separate UI
actions described in AVERY_DEMO.md. In-person point of sale and social marketing remain Coming Soon.
Demo prices and shipping are illustrative. The voice introduces itself as AI.

“Internet aware” means the app checks its connection, the authenticated demo server
and provider/model readiness before requesting the microphone, then observes the
actual WebRTC connection. Model readiness is briefly cached and does not guarantee
a successful audio connection on every network. Weak networks show an unavailable
reason; connection loss stops audio. Storefronts and photos remain usable offline.
Live conversation always requires internet; this feature does not search the web.

Current presenter installs use the isolated HTTPS host described below and need only
the tablet and internet. Verify the actual home launcher URL, not a hosted page
inside a legacy localhost WebAPK. During quiet QA, set the audio element muted and
volume zero before Start; microphone mute alone leaves AI replies audible.
The legacy localhost install still requires the Mac server and ADB reverse.

## Server configuration

Voice is disabled by default. Enable only on the authorized demo server:

- `RETAIL_SHOWROOM_VOICE_ENABLED=true`
- `RETAIL_SHOWROOM_VOICE_MODEL=gpt-realtime-2.1`
- Existing server-side OpenAI credential through `services.openai.api_key`, with
  existing AI Branch/Bud fallbacks. Never expose it in JavaScript or tablet storage.
- `RETAIL_SHOWROOM_VOICE_SESSIONS_PER_HOUR=6` by default, bounded to twenty.
- A session lasts up to 300 seconds in the supported client. There is a separate
  six-attempts-per-minute route throttle. Failed negotiations count against quota.
  The session duration is enforced by the client; it is not a provider billing cap.

`GET /retail/showroom/api/voice/status` verifies model access without inference.
`POST /retail/showroom/api/voice/session` accepts only an audio SDP offer, an
allowlisted merchant slug and page. The server exchanges the offer through the
[OpenAI Realtime WebRTC calls interface](https://developers.openai.com/api/docs/guides/voice-webrtc?voice-api=realtime).
The browser receives only the SDP answer. The server restores the terminating CRLF
removed by Laravel's string normalization before forwarding SDP. Provider bodies,
call IDs and keys are not returned or logged. Session input also accepts a validated
`sample_activity` snapshot and optional
`focus_customer`; raw tenant IDs and arbitrary model instructions are rejected.
Voice APIs are owner-only, verified, CSRF protected, rate limited, no-store and noindex.

WebRTC carries audio directly to OpenAI. The app keeps a short, escaped transcript
only in dialog memory and clears it when closed/backgrounded or the business
changes. It does not save audio/transcripts to the application database, local
storage, offline queue or service worker cache. This is not a claim about provider
retention. Session tracing is disabled. Cache v19 includes the public voice/action modules and shell, preserving the complete
142-photo pack. Private APIs and real recipient details are never cached.

For this local demonstration the existing authorized hosted credential was loaded
into the local server process environment only. No production setting or file was
changed. Restarting that process requires reconnecting its protected credential.
Do not add a production key to a committed file or copy production configuration.

## Verification commands

```sh
php artisan test --compact tests/Feature/Retail
node scripts/retail/voice-check.mjs
node scripts/retail/voice-records-check.mjs
node scripts/retail/photo-offline-check.mjs
node scripts/retail/sales-upgrade-check.mjs
```

The voice lifecycle regression uses a fake WebRTC transport and microphone. It checks
selected context, turn rendering, escaping, interruption state, mute/end, connection
loss, readiness/auth failures, denied and delayed microphone permission, session
expiry, typed navigation, transcript privacy and portrait/landscape layout.

The separate real-provider test requires explicit opt-in and synthetic speech:

```sh
mkdir -p output/retail/voice
say -o output/retail/voice/qa-question.aiff 'Which sample chocolate fudge could I put in a gift box?'
ffmpeg -hide_banner -loglevel error -y -i output/retail/voice/qa-question.aiff -ar 48000 -ac 1 output/retail/voice/qa-question.wav
say -o output/retail/voice/qa-followup.aiff 'Which of those would you suggest for a chocolate lover?'
ffmpeg -hide_banner -loglevel error -y -i output/retail/voice/qa-followup.aiff -ar 48000 -ac 1 output/retail/voice/qa-followup.wav
RETAIL_QA_LIVE_VOICE=1 node scripts/retail/voice-live-check.mjs
```

It starts one paid session, sends synthetic spoken questions through WebRTC, checks
input transcription and spoken replies, receives audio packets and ends the media
tracks. It never opens the Mac's physical microphone. Generated evidence lives in
ignored `output/retail/voice/`. Check `VERIFICATION.md` for completed runs and physical
Galaxy results. Production publication still requires authorization.

The Galaxy QA driver is an additional explicit paid opt-in:
`RETAIL_QA_GALAXY_VOICE=1 node scripts/retail/voice-galaxy-check.mjs`, with the
verified Galaxy Chrome DevTools forward on port 9224. It creates a real microphone
track but mutes it before transport, verifies a provider reply and track cleanup,
and preserves sample data. It does not validate a visitor's spoken microphone input
or speaker audibility in festival noise. Keep Lenovo on hold.

The additional opted-in `voice-records-live-check.mjs` uses
`qa-records-question.wav` and `qa-records-followup.wav` in the same output directory.
Its synthetic questions ask what Avery Morgan bought and the total, then her reward
balance and staff note. It creates sample activity only in a fresh QA browser,
opens voice from the fictional customer profile, and verifies spoken answers.
Use `RETAIL_QA_LIVE_VOICE=1 node scripts/retail/voice-records-live-check.mjs`.


## Untethered HTTPS presenter preview and recovery

The tablet-and-internet demo uses a separate Forge preview, with an independent
SQLite database seeded only with showroom fixtures, its own app key and host-only
secure session cookie. Do not point it at the production database, copy production
configuration, merge this mixed preview branch to main, or enable push-to-deploy.
Publish only an exact GitHub-gated preview SHA, build a new candidate directory,
verify it, and atomically change only the preview pointer. Production's current
release and protected Forge deployment flow are unchanged.

A new host is a new PWA identity. Back up only Retail local records privately,
retain the old app and Studio, authenticate normally at the hosted origin, transfer
sample records/carts without cookies or tokens, then regenerate the full public
offline pack and install the hosted PWA. Verify retained data, offline cold launch,
reconnection, real audio and conversation tools with all ADB reverse mappings
removed. The Mac must not be required for runtime.

`GET /retail/showroom/api/session` reauthorizes the active verified configured owner
and supplies the current session CSRF token with private/no-store headers. The
public shell never stores that token. Recheck refreshes the session before checking
provider readiness; live writes refresh it too. No uncertain POST is automatically
replayed, and reconnect never starts the microphone. Expired authentication
continues to require sign-in. Check with
`node scripts/retail/connection-recovery-check.mjs` (mocked audio; real auth/session
and offline shell).

The one-message SendGrid/Twilio presenter transport additionally permits only an
explicitly enabled exact staging host (`RETAIL_PRESENTER_TRANSPORT_ENABLED` and
`RETAIL_PRESENTER_HOST`). Its protected private settings and durable delivery audit
must migrate together. Production remains blocked even if that flag is set. No
queue worker or production webhook is enabled on the preview.


The isolated host is `https://everbranch-retail-demo.on-forge.com` (Forge site
3432615 on the existing server). Its secure file session lasts 43,200 minutes
(30 days) between use, independently of the legacy localhost cookie.

Forge's default MIME map does not recognize `.mjs`. In this preview site's Nginx
configuration only, preserve all existing directives and add:

```nginx
location ~ ^/retail/[a-z0-9-]+\.mjs$ {
    types { application/javascript mjs; }
    default_type application/javascript;
    add_header Cache-Control "no-cache";
    add_header X-Content-Type-Options "nosniff";
    try_files $uri =404;
}
```

Verify `Content-Type: application/javascript` over public HTTPS for every module;
otherwise Chromium leaves the app at its loading screen. Forge validates/reloads
this change. Do not edit production site 3053351's configuration for this preview.

Rapid live acceptance exposed an OpenAI token-per-minute limit after several
commands: repeated full snapshots had enlarged each reply's context. V18 keeps all
records in the UI but sends a compact initial index and relevant tool results
(83% smaller in the Avery regression, with all 14 customers and 77 catalog options
retained). It uses a 6,000-token post-instruction history budget and 0.7 retention
ratio, following the [Realtime cost guidance](https://developers.openai.com/api/docs/guides/voice-latency-cost).
For an explicit provider rate-limit response, the client pauses microphone input
and retries that reply once after a bounded 3–30-second delay. It does not replay
tools, resend a message or retry an uncertain provider POST. End/close/background
cancel the timer and stop media. Other failures still stop the conversation.
Run `voice-context-check.mjs` and `voice-check.mjs` for coverage of payload size,
retained facts, transient recovery, diagnostics privacy and cancellation.
