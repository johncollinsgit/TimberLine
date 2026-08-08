# Customer Loop and Bud architecture

## Purpose

Customer Loop makes a business moment useful after it happens. It gives the
team one queue for thoughtful next steps: follow-ups, review requests, email,
text, and social drafts. It is built for retail, service, project, and
owner-led teams, but is not a replacement for a CRM, a marketing-consent
system, or a message-delivery provider.

Bud is the tenant-aware assistant layer. **Bud Core** is included and
deterministic. **Bud AI** is a separate, disabled-by-default paid capability
for metered model and future voice use.

## Customer Loop contract

- Tables `customer_loop_activities` and `customer_loop_actions` are additive,
  tenant-owned records. They do not write to legacy `orders`, Shopify, Modern
  Forestry, Website Commerce, or marketing-delivery tables.
- Activities are append-only business context. Actions are reviewable queue
  items with `suggested`, `prepared`, `completed`, `dismissed`, and `snoozed`
  states.
- The first release creates drafts only. Creating, preparing, completing, or
  snoozing an item never sends email/SMS, posts to social, changes marketing
  consent, or updates an external provider.
- Customer Loop is available to an active tenant admin, manager, or marketing
  manager. The server resolves the current tenant and rejects cross-tenant
  action IDs.
- Workflow Studio has one Customer Loop action:
  `everbranch.customer_loop.draft.prepare`. It can turn a supported trigger
  into a queue item, is protected by the existing workflow action receipt, and
  therefore cannot create a duplicate on retry. It is explicitly draft-only.
- The included templates are the simple path. Workflow Studio remains the
  advanced, drag-and-drop if/then path. Do not create a second automation
  builder for Customer Loop.

## Bud contract

- `BudConversationService` remains deterministic Bud Core. It may explain
  available capabilities and read tenant-scoped summaries supplied by
  `BudWorkspaceContextService`; it must not invent record details it has not
  been given.
- `BudCapabilityRegistry` is the release handoff registry. Any new
  tenant-facing capability must add a plain-language label, example questions,
  and its allowed actions there, then add only the minimum safe context query
  to `BudWorkspaceContextService` and regression coverage. This is required
  before claiming Bud understands a new module.
- Bud Core can never edit files, execute code, access credentials, cross tenant
  boundaries, publish a website, send a message, or post to a social account.
  It can prepare and explain work, then route a person to the typed UI.
- `EVERBRANCH_BUD_AI_ENABLED=false` and `EVERBRANCH_BUD_VOICE_ENABLED=false`
  are the production defaults. No external model call, voice stream, or bill is
  active merely because this architecture is deployed.
- A future Bud AI tool must be typed, tenant-scoped, auditable, budgeted per
  workspace, and require a final visible human confirmation before any send,
  publish, or material state change. Model context must use a minimal,
  permission-checked snapshot rather than raw database access.
- A workspace requests **Bud AI** separately from included Bud Core. An operator
  must approve it and set a hard monthly dollar cap before any provider is even
  eligible to run. Provider credentials remain global deployment secrets, never
  tenant data. The current release stores this paid-cap decision but does not
  make a provider call yet.
- Future speech-to-speech support is an adapter on top of the same typed tool
  contract. It requires explicit microphone permission, live transcript/status,
  user interruption, a usage cap, and the same final confirmation rule.

## Modern Forestry and external systems

- This work must not alter Modern Forestry Shopify credentials, scopes, routes,
  checkout, orders, rewards, Candle Club, shipping, customer records, or
  message delivery. A Customer Loop item is a new Everbranch-owned draft and
  only exists if an authorized user creates one or publishes a workflow that
  creates one.
- External social publishing and AI-based delivery are intentionally not part
  of this release. Connection, consent, channel policy, media approval,
  provider error handling, and delivery audit requirements must be implemented
  before enabling them.

## Verification

- `tests/Feature/CustomerLoopTest.php` covers draft-only creation, workflow
  idempotency, and tenant isolation.
- `tests/Unit/BudConversationServiceTest.php` covers practical and misspelled
  Customer Loop questions and confirms the human confirmation rule.
- Any future Bud AI provider or voice release needs adversarial prompt,
  authorization, budget, and delivery-confirmation tests before a tenant can
  enable it.
