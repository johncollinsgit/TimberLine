# Retail conversational voice demonstration

Use **Talk with AI → Start conversation** in the installed Retail app. The business
selector starts with the storefront being shown. Speak naturally, ask follow-up
questions, and interrupt a reply. Mute stops sending microphone audio while replies
remain audible. End, closing the dialog, navigation, backgrounding, a lost connection,
and the five-minute limit stop the media tracks and close the peer connection.
Returning online checks readiness but never restarts the microphone automatically.

## Context and connection

The owner-authenticated server supplies a bounded public catalog sample for the
selected demo plus the canonical Evergrove offer. Conversation state stays within
the current Realtime session. Changing businesses starts a fresh conversation.
Private CRM notes, customer records, research, credentials and live tenant data are
not attached. No action tools, live search, messages, purchases or account mutations
are available. In-person point of sale and social marketing remain Coming Soon.
Demo prices and shipping are illustrative. The voice introduces itself as AI.

“Internet aware” means the app checks its connection, the authenticated demo server
and provider/model readiness before requesting the microphone, then observes the
actual WebRTC connection. Model readiness is briefly cached and does not guarantee
a successful audio connection on every network. Weak networks show an unavailable
reason; connection loss stops audio. Storefronts and photos remain usable offline.
Live conversation always requires internet; this feature does not search the web.

The existing local install requires the Mac server and ADB reverse connection for
sign-in and starting a session. Keep the Mac awake. The tablet also needs an internet
route to OpenAI for WebRTC audio. Lenovo installation work remains on hold.

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
call IDs and keys are not returned or logged. Voice APIs are owner-only, verified,
CSRF protected, rate limited, no-store and noindex.

WebRTC carries audio directly to OpenAI. The app keeps a short, escaped transcript
only in dialog memory and clears it when closed/backgrounded or the business
changes. It does not save audio/transcripts to the application database, local
storage, offline queue or service worker cache. This is not a claim about provider
retention. Session tracing is disabled. Cache v6 includes only the public voice
module and existing public shell/photos, preserving the complete 75-photo pack.

For this local demonstration the existing authorized hosted credential was loaded
into the local server process environment only. No production setting or file was
changed. Restarting that process requires reconnecting its protected credential.
Do not add a production key to a committed file or copy production configuration.

## Verification commands

```sh
php artisan test --compact tests/Feature/Retail
node scripts/retail/voice-check.mjs
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
