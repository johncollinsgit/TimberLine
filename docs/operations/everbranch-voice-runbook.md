# Ask Everbranch voice implementation

## Restoration

The October 16 Mac handoff was restored at backend base
`b6d63ed0061fdac417a3a5fec53fde36b7fd2ca4` and mobile base
`34af0396ccc79d3d53a8e4c2d309558b07fd0f6c`. Every transferred source hash and
complete Git tree matched before edits. Checkpoints: backend `43a6520`, mobile
`5468b01`. Mobile PR 52 is a separate baseline dependency; PR 53 is excluded.
Unrelated PC changes and the incomplete October 12 clone are untouched.

## Contract and coverage

- Existing search accepts `intent=navigate`; ordinary search retains its actions.
  Navigation filters both action fields and action types before ranking/limits,
  including soccer and landlord. Exact ambiguity is decided before truncation.
- Outcomes are `matched`, `choose`, `no_match`, `control`. Only one exact
  authorized page auto-opens. Records require selection; back/cancel are exact
  control phrases, never inferred write commands.
- Mobile adds optional `voice_navigation: {version: 1, pages: [...]}` with stable
  key, title, aliases, availability and either a native destination or an
  unavailable explanation. Unknown versions fail closed. Older clients ignore
  the additive property; older servers retain bootstrap-derived matching.
- Canonical navigation is indexed recursively, including settings, marketing,
  birthdays and help. Native tabs/modules derive from current server permissions.
  Records retain existing scoped search providers. This does not establish
  coverage of every GET route, export or internal page.
- Individual Documents assets cannot currently open through the native list
  renderer and are excluded as voice record destinations. Website-only pages
  have an explicit unavailable state. No guessed URLs or silent web handoffs.

## Interaction and privacy

Microphones start only on explicit activation. Navigation uses device/browser
recognition and deterministic matching, not a paid model call. Typing remains
available. Handle denial, silence, natural end, stop, cancel, timeout and late
callbacks. Navigation transcripts are not saved to recent searches or logs.

Manual web choices refresh access and enforce same origin. Edits, close and
Livewire navigation invalidate old requests. Bounded session history is scoped by
user/workspace and revalidated before back; arbitrary browser history is not used.
Unverifiable previous pages remain unavailable.

Mobile refreshes grants, validates destination support and rechecks historical
record reads. A declined confirmation retains the dialog/draft/history. Complex
editors expose `data-navigation-dirty="true"`; active saves/uploads expose
`data-navigation-pending="true"`. Job editing, dictated notes, staged attachments
and material requests expose state. Native input/change events protect ordinary
forms and rich editors. Active photo queues prevent navigation. New complex
editors must supply explicit state rather than relying solely on native events.

## Local checks

Install dependencies from lockfiles. Use local testing configuration, SQLite in
memory, array mail/cache/session, sync queues and a local `VIEW_COMPILED_PATH` on
Windows. Build backend assets before rendering feature tests. Run navigation,
search, mobile contract and soccer tests, backend build/formatting and mobile
lint/tests/build.

For paired browser acceptance set `VOICE_MOBILE_DIR` to the mobile checkout and
`VOICE_EVIDENCE_DIR` to the evidence directory; run
`node tests/browser/voice-navigation.smoke.mjs`. Set
`VOICE_BROWSER_CHANNEL=msedge` to use installed Edge. The loopback-only harness
uses fictional fixtures, actual production components, fake speech and blocked
external requests. Mobile fixtures live outside its production build entry.
These checks prove control flow, not native speech accuracy or permissions.

## Release state

No schema migration, deployment, push, provider purchase, billing change or
production activation. Physical iOS/Android acceptance and paired signed builds
remain required. Follow protected release procedures; rollback paired navigation
commits while preserving checkpoints and business data.

Executive AI, weekly reports/triage, customer workflows, approval center,
mail/phone and receptionist remain separate increments. Navigation checks do not
establish completion of the combined 35-criterion release.
