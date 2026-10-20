# Contained Teams settings candidate — 2026-10-20

The standalone account link previously opened `/settings/profile` in the general
Everbranch shell. Premium Teams now opens a native dialog over the current page.
Both `/teams` and embedded `/team` expose Your profile, Connections and Reminders.
The same implementation applies to every currently eligible premium Teams tenant.
Legacy/default-off behavior and provider gates are unchanged.

## Implementation and boundaries

- Reuse the existing Livewire profile component and its canonical validation,
  own-account updates and email-verification reset. A Teams-only presentation
  embeds it without the platform navigation or destructive account controls.
- Lock the originating viewer and tenant in the component. Recheck current
  active membership, verification and Teams access on subsequent requests.
- Connections and admin reminder links use existing authorized work-area
  navigation and services. They do not provision areas, tokens or memberships.
- Dialog close/Escape retains in-page edits and restores visible focus. The
  mobile menu closes through its existing state/inert handler before settings
  opens. Native task drafts remain under the existing preservation controls.
- Prevent native profile submission from navigating or placing fields in a URL
  if Livewire is unavailable. The component has one unconditional HTML root so
  its form is included in Livewire's actual browser boundary.
- Email verification and provider consent remain explicit authentication flows.
  This UI change does not configure missing production OAuth credentials, connect
  accounts, send updates or complete real-source acceptance.

## Local verification

- **240 Laravel tests / 2,218 assertions passed:** all Teams and Wholesale feature
  tests, canonical profile tests and module-catalog consistency.
- JavaScript work contracts: **60 passed**; graph assertions: **47 passed**.
- Production TypeScript/Vite build passed; changed PHP/SFC style checks passed.
- Actual Laravel browser checks used a new marked disposable SQLite fixture at
  loopback port 8775, synthetic accounts and fake providers. Production records
  and account settings were not mutated.
- Browser: profile save remained on `/teams`; the canonical profile form also
  saved within embedded `/team`; Events connection navigation stayed in Teams;
  closing/reopening retained a profile draft; a separate unsaved native task title
  survived opening settings and resumed unchanged; closing restored Settings
  focus, and phone Escape restored Open navigation focus.
- Desktop, 1024px tablet and 390px phone layouts were reviewed. The 1440px desktop
  modal had an 850px width inside the available page width. Viewport overrides
  were reset. Screenshots under `docs/qa/2026-10-20-teams-contained-settings/` use
  fictional data only. This is browser evidence, not physical-device acceptance.

## Release and coordination

No migration or runtime configuration change is required. Run the mandatory
exact-head PR quality/Laravel/MySQL gates and use the protected release process.
The Mac release coordinator owns the merge/deployment order alongside guided
settings PR #444 and the separate Marketplace work. Do not independently merge
or deploy this candidate or rewrite the existing checkpoints.

Initial source files do not overlap PR #444's application paths. README/agent
orientation and the UI changelog have small additive notes that must retain the
other release's documentation. The shared account-profile component remains a
specific integration-review point because both products use the same settings
infrastructure. This document records a tested candidate, not live acceptance.
