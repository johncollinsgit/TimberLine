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
