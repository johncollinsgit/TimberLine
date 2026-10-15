# Jarvis OAuth branding pages

PR383 was explicitly reviewed and approved by the owner, then merged and
published as `6dd2fb96dbfc86f14e9477ed3289ae2226569846`. All PR checks passed;
production workflow 38298808878 verified the activated Forge release. At
2026-10-12 19:39 UTC the public readiness endpoint reported that exact commit,
all three Jarvis pages returned 200, the logo hashes matched, and app/platform
hosts returned 404 for `/jarvis`. The owner subsequently supplied public support
phone `(864) 616-5468`, linked as `tel:+18646165468`; no address was inferred.
The evidence boundaries below remain factual limitations, not an additional
approval requirement for the already reviewed version.

Prepared on 2026-10-12 for Google project `jarvis-pc-20261012`, number
`381166840141`. These pages describe the separate local Jarvis application;
they do not add Jarvis integrations to Laravel or change Everbranch policy.

## Routes and release

- Homepage: `https://evergrovesoftware.com/jarvis`
- Privacy: `https://evergrovesoftware.com/jarvis/privacy`
- Terms: `https://evergrovesoftware.com/jarvis/terms`
- Logo: `public/brand/jarvis-mark.svg`; 512px site raster:
  `public/brand/jarvis-logo.png`; 120px Google upload:
  `public/brand/jarvis-google-logo.png`.
- All three pages use the existing Evergrove navigation and type/design system,
  plus a scoped stylesheet and Jarvis policy navigation. The controller checks
  the canonical `evergrove.hosts` allowlist on every page and returns 404 for
  other hosts. Existing `/privacy` and `/terms` are untouched.
- No new OAuth flow, credentials, database migration, tenant operation,
  analytics, billing or infrastructure setting is introduced.

Authoritative code is `johncollinsgit/TimberLine`. Base commit:
`8f1bb4b7106c5f50636b3151cd10ae74140329c0`. Existing Team checkout is unrelated
and was left untouched. `config/evergrove.php` supplies the public hosts.
The live Evergrove homepage matches `resources/views/evergrove/home.blade.php`.
Production uses the existing main-branch GitHub test/build gate and atomic
Forge deployment, documented in AGENTS.md and `.github/workflows/deploy.yml`.
Do not push directly to production, bypass the gate or manually replace assets.

## Disclosure evidence

The Jarvis source coordinator supplied bounded facts from installed source
`R-20261012.google-client-1` on 2026-10-12:

- Client credentials, refresh grants and PKCE material have Windows CurrentUser
  protection, with account/consent metadata in local SQLite. Other SQLite
  records are not all encrypted.
- Dedicated consent requests `gmail.compose`, `calendar.events.owned`,
  `calendar.calendarlist.readonly`, `openid`, `email`/`userinfo.email`.
- Separate older Gmail read grant uses `gmail.readonly` plus identity. Its
  selected-thread request uses `format=full`, which can return HTML and other
  MIME parts. `plainBody` extracts bounded `text/plain` content; the reader does
  not display HTML or call the separate attachment-download API. In-memory
  cache is at most 20 entries, at most five minutes. Selective monitoring can
  persist excerpts (up to 4000 characters), findings, evidence, checkpoints and
  notifications. Chat/tasks/calendar records also persist.
- The reviewed Gmail classifier optionally uses local Ollama. Whole-app
  Google-data/provider/logging flows have not been comprehensively audited.
- Installed Gmail external send/mailbox mutation remains disabled. Broader
  candidate actions are not installed. Google permission scope must not be
  confused with narrower application availability.
- No uniform Google-derived-content deletion UI or fixed retention period was
  verified. Do not promise automatic purge or deletion on disconnect.
- Verified Google support/developer email: `johncollinsemail@gmail.com`.

Google's current scope descriptions were checked at
<https://developers.google.com/workspace/gmail/api/auth/scopes> and
<https://developers.google.com/workspace/calendar/api/auth>. Compose manages
drafts (including deletion) and sends mail; it is not unrestricted mailbox
deletion. Owned calendar-event permission includes event deletion.

## Existing logo provenance

The source coordinator identified the installed app's existing J favicon at
`C:/Users/father/Documents/Codex/2026-09-24/i-h/outputs/jarvis/public/favicon.svg`.
It is a dark rounded square with a cyan ring and J lettermark. The SVG is copied
unchanged to `public/brand/jarvis-mark.svg` (SHA256
`943da2fed0c0669a6201f423172f813edaffc5e2ef2eb707605ac95a26aa1072`).
The PNGs are raster exports of this asset using an isolated headless browser's
sans-serif rendering, not new logos. Trademark origin and licensing were not established;
these pages make no brand-license claim.

## Disclosure evidence boundaries

These pages are not a completed OAuth verification statement. The initial
owner review covered these explicit boundaries:

1. Public operator identity and support contact. The verified developer email
   is used; no unverified company legal name, postal address or jurisdiction is
   asserted.
2. Complete Google-data flows to AI providers, telemetry, logs, backups and
   other recipients, including purposes and any retention/training terms. The
   current draft discloses processing variability; it does not certify that an
   incomplete audit satisfies Google's verification requirements.
3. Applicable retention/deletion process and support procedure. There is no
   invented deletion deadline, automatic purge or all-data encryption claim.
4. Whether additional binding license, commercial or legal terms are intended.
   The draft contains basic usage expectations, no new payment promise,
   liability waiver, arbitration term, warranty or other legal guarantee.
5. Any Google Limited Use compliance statement must be supported and approved;
   do not publish or submit a blanket compliance/training claim without evidence.

The browser coordinator subsequently inspected Branding through the supported
operator: saved app name, support/developer email and `evergrovesoftware.com`
domain are confirmed; homepage, privacy, terms and logo fields are blank. Google
accepts a square JPG/PNG/BMP under 1 MB and prefers 120 by 120 pixels. Do not
bypass browser policy or assume that filling these fields completes verification.

After approved merge, wait for GitHub/Forge success, verify all three live URLs
and correct host isolation, verify `/ready` release evidence, then provide actual
public links and logo to the sole browser operator for Branding. Do not fill
Google's production fields with undeployed or unreviewed draft URLs.
