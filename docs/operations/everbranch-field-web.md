# Everbranch Field installable web application

The shared Field client is served at `https://app.theeverbranch.com/field/` by the existing production site. This source candidate is not a live-release claim: verify the normal protected deployment and `/field/version.json` before distributing its link.

The first build is `2.3.30-web.1`, based on the newest local Field Showcase release 2.3.29 plus the scoped crew assistant. Source is `johncollinsgit/everbranch-mobile`, branch `codex/collins-field-webapp-showcase-20261028`, commit `1ea26a4`. It remains the shared Field source; Collins branding and access come from its existing workspace. No native binary is submitted and no other workspace is migrated or activated.

Sign in with the existing Everbranch email/password and 2FA when applicable. On iPhone open the verified URL in Safari and use Share → Add to Home Screen. On Android use Install app or the browser's Add to Home screen menu. Users need their existing active Collins membership; the public shell grants no workspace access. Existing jobs, tasks, photos, messages and hours stay in the same database.

`FieldWebSession` exchanges the existing mobile password response for an encrypted Secure/HttpOnly/SameSite=Strict cookie. JavaScript receives a noncredential marker. The same revocable mobile token, Sanctum abilities, tenant resolver and resource guards apply to every API call. Origin/fetch-site checks fence the cookie lane. Persistent token access, revocation, cross-origin refusal and streamed-photo handling are covered by tests.

Job priority order is personal, stored by user and workspace in existing preferences with current visible-job validation and locked merge. Public Showcase covers are decorative fallback images only; real team-visible job/task photos take precedence and are never replaced. Home overlays workspace branding on permitted current-work photography. Existing photo names/bytes remain unchanged; new web captures use context/date names.

The asset service additionally accepts checksum-checked local JPEG/PNG/WebP chunks, retains exact uploaded bytes and verifies actual image type/dimensions before promotion. Existing PDF rules remain. Task/material links validate tenant, job, uploader, current work authority and immutable target identity. Job-note image uploads use the same linked asset IDs as existing PDF comments. Team message uploads retain their existing checked channel transport. Text line breaks and blank lines are preserved in sent rendering, and the visual viewport keeps an expanding composer above the keyboard.

My Hours adds day/week/month charts over server totals. The saved server clock continues when the web app closes. A web clock action with an unknown network outcome requires reconnect/refresh, rather than silently replaying the action later with a new payroll time. Browser background GPS and lock-screen notifications cannot replace the native app; keep the native app on phones that must report continuous crew location. Authorized fleet viewing uses the existing Fleet Tracker and vehicle integrations. Web push is not added here.

The worker caches only the public shell/assets under `/field/`. It does not cache API records or authenticated files. The shell works offline; fresh business reads and confirmed writes require connectivity. Messages retain local drafts by user/workspace/channel; logout clears them. Foreground checks find new bundles, and a waiting update offers a safe reload. Mobile browsers may suspend uploads and cannot guarantee closed-app work.

## Read-only crew assistant and Collins activation

The new crew adapter is separate from owner financial/write assistant authority. It uses the existing `ResponsesTransport`, configured AI credential, approved model/rates and `TenantAiUsageEvent` ledger. It retrieves bounded current permitted jobs/tasks/materials/team notes, excludes owner notes, customer contacts, financial records, private channels and other employees' hours, and rechecks membership and source access before returning an answer. User/workspace request hashes bind exact retries; uncertain usage stays reserved. Replies in retry receipts are encrypted.

Activation is existing `TenantAccessProfile.metadata.field_crew_assistant = {enabled: true, monthly_cap_cents: 2500}` plus enabled `AiBranchAccount` with the approved 2500-cent cap. Preserve unrelated metadata and ledger rows. Record the actual operator approval with `LandlordOperatorActionAuditService`, including before/after values and the user's explicit $25/month direction. Do not copy credentials, use a fictional demo grant, create a paid subscription, reset usage, or modify another tenant. Normal Field/AI module entitlement and active verified membership remain required.

The account-row lock serializes reservations in both the crew adapter and existing owner chat. Their combined settled/reserved/uncertain provider spend is bounded by the cap over the America/New_York calendar month. This rollout does not charge an invoice or replenish credits. Current approved model pricing expiry remains fail-closed. Disable only `field_crew_assistant.enabled` for an adapter rollback; preserve usage and any later owner-approved account changes.

After protected deployment, verify Nate's canonical bootstrap still contains Fleet Tracker, verify original data counts are preserved, activate only Collins with an audit receipt, and make one bounded live-provider question under its current role permissions. Do not alter an employee's payroll or send a team message as a smoke test. The focused tests cover actual clock contracts; browser clock tests use fictional responses.

## Retained verification

- Frontend: 72 tests and TypeScript/production web build.
- Backend: focused Field web/cookie/photo/access/shared-budget tests plus existing message, employee clock, Collins employee-access and owner-AI contracts.
- Browser: actual compiled bundle in Chromium and WebKit, 390×844 and reduced 390×490 viewports, fictional API fixtures; sign-in/greeting, fleet visibility, reorder/save/reload, multiline messages, gallery controls/gesture, composer, clock controls, three hours views and assistant UI.
- Chromium: manifest, service-worker public-shell cache, offline shell, absence of API data in the cache.
- These are browser/viewport checks, not physical iPhone or Android installation, keyboards, camera permissions, GPS or push acceptance. Ask the recipient to install the verified deployed URL and confirm their own current jobs, clock and photos; do not report those device checks as already run.

## Google sign-in and app sharing follow-up

Field web.2 adds Sign in with Google through the existing stateful Google OAuth connection and the mobile S256 PKCE broker. The fixed HTTPS Field return and code exchange are bound to the original challenge; browser return parameters are scrubbed before exchange, and the existing HttpOnly cookie lane receives the token. Field Google login refuses unverified/unknown/inactive accounts and does not use the legacy stateless fallback or create memberships. Native callback behavior and existing non-Field Google flows remain unchanged. Source895b430. No new Google client, callback registration, Gmail inbox scope or credential is added.

Share app invokes navigator.share from an explicit tap with only the public canonical app URL; unsupported browsers copy that URL. It does not claim to open Safari’s Add to Home Screen action. Android installation uses its existing browser prompt; iPhone installation still requires Apple’s system action.


## Field web.3 voice, navigation and phone layout

The microphone above Send starts an explicit browser speech conversation: one final spoken question uses the existing scoped crew endpoint, the answer plays through browser speech synthesis, and listening resumes after playback. The microphone stays off during requests and speech. Stop, hidden/pagehide, session replacement and unmount stop capture/playback; late answers never restart capture or navigate after Stop. Exact request IDs and pending request history survive uncertain attempts in memory. Sessions are bounded to 12 turns/10 minutes and retain the existing shared budget and route throttle. No new voice provider, key, subscription, billing activation or customer access is introduced. Browser speech may be processed by the device/browser service; real microphone and speaker acceptance remains a physical-device check.

Explicit open/show requests use `FieldCrewNavigation`, never model-generated URLs. Fixed work pages are allowlisted; named/current source jobs are rechecked against current tenant/job access, ambiguity asks for a choice, and customer/contact navigation requires current admin authority plus the existing Collins client guard. The client refreshes bootstrap and verifies job access before routing, and keeps unsent job/message drafts from being abandoned. Conversational context can focus on the open job; a foreign or unavailable context is rejected before provider dispatch. Other read-only answers retain the same sampled permitted records and cap.

Field keeps bottom tabs available in branch views and gives an opened team chat room above the bottom bar, including employee preview. Native layout is unchanged. Documents and job PDFs use a scrollable three-column preview grid. Near-visible first PDF pages render with pinned PDF.js 6.4.299, two loads at a time, using the existing authenticated/signed-range reads; image cards use checked thumbnails. A failed thumbnail still opens the existing full preview. PDF workers use `.js` on the existing host. No private document bytes are added to public/offline caches.

The home branding banner cycles through up to eight distinct permitted current-job cover photos every four seconds. Its logo/text stay fixed; rotation pauses while hidden and for reduced-motion preference. These changes do not redesign the broader assistant interface or add durable assistant conversation storage; those remain paused follow-ups.

Share app is now in Account only, with the same explicit system-share/clipboard fallback and canonical public URL. It is removed from the top of all Field pages. Sourcedfb7bd0, web.3 buildd08540dcf4e2e25d.


The admin home tile Materials requested sits beside Manage Employees under Contact Team and opens a full list with Home/Work controls, job links and cursor pagination. The server requires an active tenant admin/owner, selects outstanding manually requested materials across permitted jobs (including older jobs), and excludes purchased/recycled/foreign records. It uses a currently permitted material-linked team image, then a job image; the client retains the decorative electrical fallback. Employee Home adds View Jobs directly above Clock In. These are Field-only presentation changes; native controls retain their current contract.

Ask Everbranch answers live clock status through the same manager/crew boundary as Dispatch: managers/admins may see active crew clock status; members receive only themselves. Running and paused/break status are distinct and inactive memberships are excluded. Hours questions use canonical `FieldServiceTimeHoursService` analytics behind the existing Time Tracking module guard. A member receives only self totals, while a manager may name a permitted workspace person or request the crew total. Ambiguous names and missing periods ask for clarification; a period-only follow-up retains the prior hours question. Today/yesterday, current/previous week/month, the current pay period and valid bounded ISO date ranges are explicit. Answers identify dates/timezone, include submitted/approved completed time and exclude active timers. Rejected/foreign entries are excluded by the canonical totals contract. No time entry is changed and these direct reads make no paid model call.

The minimized voice Stop control remains above opened chat/file panels so the user can end microphone capture from the page they asked to open.


## Field web.4 first-question throttle and speech modes

Ordinary authenticated mobile reads and the assistant previously shared Laravel's unprefixed per-user throttle key. Twelve normal startup reads could therefore make the first assistant question return HTTP429 Too Many Attempts. The assistant now uses the distinct `field-crew-assistant:` prefix while retaining ten turns/minute/user and the enclosing120/minute mobile guard. The HTTP regression keeps middleware enabled:12 startup GETs, ten successful direct clock questions, eleventh429 with Retry-After, another ordinary GET and an independent second user. It sends no provider request and writes no AI usage event.

Dictate, above Send, captures final text into the current draft without sending it. Voice conversation is a separate explicit control: one final utterance, existing scoped assistant answer, browser speech playback, then another listening turn. Mute, Interrupt and Stop retire old callbacks; capture remains off during requests/playback and stops on hidden/pagehide/unmount. The initial empty silent speech activation happens synchronously during the Conversation tap before microphone capture, matching WebKit's user-gesture restriction. Real iPhone speaker/microphone acceptance is still required; synthetic recognition/synthesis tests are not acoustic proof.

Retry-After controls a visible countdown with no automatic retry. Expired sign-in blocks new questions and leaves the draft; Check sign-in only verifies an already-restored session. Deliberate Retry question reuses the original request ID/payload. Unknown provider usage remains held for review. Slow/late responses cannot restart speech or navigate after Stop, and an in-flight question prevents overlap or an update reload. Typed drafts stay separate from spoken questions. Existing workspace/job/customer/clock/hours scope, monthly2500-cent cap, ledger reservations and native controls are unchanged. No activation, credentials or paid smoke rerun is part of this release.

Source `b4e01b4ee1e0646a20b5e230d8c02ce81568f06d`, web.4 build `3bba8aafdd18e7c2`. Local verification:86 frontend tests and TypeScript/web build;23 focused backend tests/407 assertions. The broader local Collins usability suite has one missing backend Vite-manifest failure (56 other tests pass); the normal exact-head CI builds those assets and remains the release authority.


The full release gate exposed an unrelated synthetic image-fixture clock boundary: collaboration, attachments and images computed separate second-precision expiries. One shared fixture expiry preserves their intended equality and the unchanged production attachment<=collaboration guard. A controlled1.1-second gap reproduced the original503 refusal; the corrected fixture passed the same delayed case (1test/15assertions), then its unchanged full class passed23tests/280assertions without the probe. No probe delay, application access change, test removal, scanner activation or workflow change is retained. The corrected candidate requires fresh exact-head checks.


## Field web.5 updater identity and activation

The live CDN served `/field/sw.js` with a four-hour max-age and the prior web.3 cache ID while the network shell and version descriptor were web.4. New shells now include an allowlisted same-origin fingerprinted worker URL in their HTML and matching version descriptor. Each build emits `sw-<build>.js`; `sw.js` is retained only for compatibility. No CDN credential, purge or host configuration change is required. Foreground checks request a cache-busted, no-store public descriptor, validate the exact build/worker pair and discover a later fingerprint even when the app stays open. Legacy/mismatched/external worker descriptors are refused.

A fresh shell already running a build activates only its matching public-shell cache without another prompt or reload. A genuinely newer waiting worker offers Update. Activated/redundant worker references are cleared. Only a deliberate update can reload an older page; concurrent activation in another tab does not discard this tab's draft. Existing input/in-flight guards remain, and an unsent assistant draft stays protected even after its dialog closes. Drafts begun during activation defer reload to an explicit Reopen. Timeouts do not cause automatic retry/reload loops. Public-shell-only caching, login, authorization, clock data and AI cap are unchanged.

Source `429cc11a09f2da9f76761951a755c227c71bd677`, web.5 build `8d341e053b343f2e`. Validation:96 frontend tests and TypeScript/web build; fingerprint/descriptor/cache asset closure checked. The browser fixture retains an old stable worker while the new release uses a distinct fingerprint; current-shell activation and reopening leave no update banner, and a later descriptor is detected without reopening. Physical iPhone standalone acceptance remains separate from browser fixtures and the required exact-head protected release.


## Field web.6 live workspace voice

The Talk to Everbranch control uses GPT-Live over browser WebRTC for a continuous, interruptible voice conversation. The signed-in display name is used for the initial greeting. Live speech delegates work questions to the existing Field crew assistant endpoint, which supplies current scoped records, applies the same role/job/customer guards, and returns only permitted answers and navigation destinations. Voice can navigate to authorized jobs and pages; it cannot modify jobs, messages, clocks, or other workspace records. Customer contact data stays admin-only. Dictate remains available as a separate speech-to-text draft action.

The session is created server-side with the existing Everbranch AI API key and `store:false`. A ten-minute maximum duration is reserved in `tenant_ai_usage_events` under `field_crew_assistant`; the voice reservation and delegated assistant queries both count against Collins' existing shared $25 monthly cap. GPT-Live duration is currently $0.05/minute and billed per second; delegated assistant calls retain their existing model-cost accounting. Graceful session close waits for final usage; if usage cannot be confirmed, the maximum reservation remains held. No new provider, credential, workspace access, or native-app change is introduced. Physical iPhone/Android microphone, output, and installed-app acceptance remains required.

Source and paired web build: `2.3.30-web.6`, build `1e615c72ed0a78df`. TypeScript and the production Field bundle build pass locally. No paid-provider smoke call was made for this candidate. Protected exact-head CI and Forge `/ready` evidence remain the release authority; do not distribute the candidate until the paired backend release is live.

## Field web.8 job-message review and dashboard entry

The selected job page can request an on-demand summary of only that job's latest eight permitted channel messages. Each included message is capped at 500 characters. The assistant returns source-message references, explicit material-request drafts and an optional job-team reminder. Message text is treated as untrusted input; no orders or messages are sent automatically. Material requests require a confirmed quantity and use an idempotency key. Reminder text opens the existing job chat composer; recipient, channel and timing require confirmation, and the user presses the existing Send button.

Before a reviewed write, the server verifies the signed-in user's current job access and checks that all captured source messages remain present and unchanged and that no newer channel message arrived. Retries of an already completed reviewed write return the original receipt without another notification or message. Source text, tenant/job scope, the existing AI provider and shared monthly cap remain unchanged. The regular signed-in dashboard now exposes an explicit Field link only to active tenant members who currently have Field access; `/field/` keeps its existing guards.

Source and paired web build: `2.3.30-web.8`. The backend adds a narrowly scoped material-review idempotency index and MySQL recovery coverage. The focused mobile/dashboard tests, TypeScript checks and web production build pass locally. MySQL recovery executes in the protected disposable-MySQL CI job; the local environment has no `.env` or disposable MySQL, so that integration test could not run here. Physical iPhone/Android acceptance remains required.

## Field web.9 job-switch and update compatibility

The job-message review state clears when the selected job changes, and a late assistant response from the prior job is ignored. The paired web build is `2.3.30-web.9`, build `c29e8dbc8e656ddc`. It keeps the previous fingerprinted shell assets and service workers available so already-open or installed clients can finish their transition while the new worker caches only the new shell. The protected PR checks and deployment readiness check passed; the live `/field/version.json` reports the deployed build.

## Future direction: reusable defaults for new trade workspaces

Product direction: make the reusable Field experience developed for Collins the starting experience for newly provisioned trade-template workspaces. Use each tenant's own branding, jobs, job photos, members, permissions, and settings. Collins' logo, photos, customer/job data, users, provider credentials, sender details, and $25 AI cap are workspace-specific and must never be copied to other tenants.

Most Field presentation and job-review behavior is tenant-scoped and reusable. The assistant activation is not a template default today: it requires `TenantAccessProfile.metadata.field_crew_assistant.enabled`, a positive per-tenant `monthly_cap_cents`, an enabled tenant `AiBranchAccount`, and Field/AI module access. Missing metadata defaults to disabled/zero. The job-review UI is present in the web Field job view, but the backend refuses provider use unless those tenant gates are configured. A separate onboarding/defaults design must decide how a new trade customer explicitly opts into paid AI and chooses its own cap; do not turn on paid AI or assign Collins' cap by default.

Onboarding uses business templates such as electrician and landscaping as well as separate trade presets. Before implementation, define one canonical mapping from each new-trade template to the Field work profile and module defaults, preserving existing role, assignment, customer-contact, fleet, clock, and AI guards. This is future work, not part of web.9.
