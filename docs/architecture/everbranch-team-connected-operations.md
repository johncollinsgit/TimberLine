# Teams connected operations candidate — 2026-10-20

Status: local implementation behind default-off controls; not a production or full Phase 2 acceptance claim.

## Product structure

Events and Wholesale are separate tenant-owned work areas inside the shared `/teams` and `/team` application. Area access supplements company, project, source and field permissions. Events navigation follows **Plan & prepare → Run the event → Review & improve**. Wholesale follows **Relationships → Orders & follow-up → Grow & improve**. Profile configuration owns view grouping, terminology and pipeline stages. Modern Forestry connection bindings are a configurable preset, not universal Wholesale defaults.

The universal `teams_wholesale_workspace` entitlement supports a CRM workspace without requiring Shopify. Existing commerce reads and mutations independently require `wholesale_operations`; missing commerce never masquerades as an empty complete import. Existing order identities, qualification and prospect workflows remain authoritative.

## Records and sources

The additive operations migration creates work areas/members, encrypted provider connections, operational records/revisions, immutable source snapshots and durable attempts. Asana projections are identified by provider + workspace + source ID; multi-project memberships do not create native task copies. Wholesale can map one client per Asana project and tasks to account follow-ups. Native work keeps its established command/service path. Legacy Event tables are not reused because reliable tenant mapping is absent.

Each person connects their own accounts. Runtime OAuth is independent of both Codex connectors and pre-existing automation connections. Source changes increment a connection generation; cursors and writes reject late generations. Live record access is checked before display. Refresh jobs checkpoint bounded pages, resume incomplete enumeration, retain Gmail history watermarks, and withdraw records only after a successful complete scan. Failed reads retain explicit partial/stale coverage.

Sheets remain the compensation and recorded-financial authority. Each tab is normalized independently; formula values and original rows are retained. Missing amounts differ from zero. Duplicate identities, repeated shared costs, source discrepancies and ambiguous allocations are excluded from accepted totals. Hidden identifier initialization requires a current backed-up mapping preview, separate write consent and its rollout gate. Post-write verification checks both unchanged business cells and expected identifier cells. No real workbook was changed during local acceptance.

Gmail files are handled per attachment, deduplicated by checksum, validated by MIME/content, quota constrained and scanned before publication. Unique filename/name/year evidence can auto-link only when relevant source coverage is verified. Uncertain matches require review. Tax/bank/payroll filenames receive admin visibility. Linked documents remain Teams enrichment; no Asana attachment write occurs.

## Templates and automation

Reusable `email_template` records are area scoped. Admins edit/publish them; coordinators can use published wording. Events and Wholesale have separate generic starters. Only a fixed operational token vocabulary is interpolated; source notes require explicit field review before use. Source payloads, compensation and arbitrary custom tokens are excluded. Missing values produce confirmation placeholders and block preparation until resolved.

The formatted preview and multipart message use the established `ShopifyEmbeddedEmailComposerService` rendering infrastructure, without requiring a Shopify store or DNS migration. The browser renders previews in a sandbox with a restrictive content policy. Users edit normal text and optional HTTPS buttons rather than raw HTML. Template publication does not send. Preparation stores the reviewed recipient, connection generation, record version, subject, HTML and plain-text alternative. Sending rechecks current permissions/entitlements/consent and rejects changed records. Unknown outcomes stay in the outbox for reconciliation rather than blind resend.

Automation options include morning digests, day-before event briefings, missing-information reminders, due follow-ups, reorder intervals, assignment changes and stage changes. Preview shows current authorized matches per named recipient. Schedule/timezone and configurable quiet hours are validated. The current automatic executor delivers **Teams Inbox reminders** with daily deduplication and fresh recipient/record checks. Assignment/stage triggers compare revision history. Cancelled work is hidden from reminders. Email/SMS dispatch remains a separate reviewed action. Automated external campaign delivery is not enabled; the canonical campaign/suppression/reply-stop workflow still needs end-to-end integration.

## Financials, weather and AI

Admin event reporting aggregates the complete authorized financial dataset rather than a visible task page. Margin uses workbook net sales minus recorded total expense (which already includes COGS). Square is allocation/reconciliation evidence, never additive revenue. Missing refund coverage stays unknown. Forecasts distinguish recorded actuals, incomplete planning rows and low/base/high estimates; unconfirmed occurrences and insufficient history remain unknown. Reviewed overrides are bound to occurrence dates and source versions. Comparisons include month, exact-title series, location and year; reviewed comparable-series mapping remains pending.

Weather uses an independently gated commercial Open-Meteo adapter, confirmed coordinates/timezone and honest forecast horizons. Distant events receive no invented daily forecast. AI briefings use permitted operational evidence and the existing tenant usage reservation/settlement ledger. There is no separate Teams wallet. Synchronization, financial arithmetic and reminders are deterministic; paid AI is optional, metered and disabled by default.

## Remaining acceptance and implementation

Current Events Asana project access through the connected account is denied. Runtime OAuth clients, both mailbox grants, current project mapping, commercial weather access and scanner readiness must be configured privately for a pilot. Full external sequences/reply stops, campaign execution UI, staff-to-verified-contact review, richer allocation/comparable-event review, prospect-discovery controls, sender preferences and automatic email/SMS digests remain tracked Phase 2 work. Some calendar/list detail reads intentionally cover a page and are labelled accordingly.

The complete user plan is not yet accepted. Real MySQL durable-DDL recovery, Linux runtime/scanner checks and the protected GitHub/Forge release gates remain required before rollout. Default-off flags must not be enabled globally on the strength of fictional screenshots or SQLite tests.
