# Forestry Backstage UI System

## Project Goal
Transform Forestry Backstage into a premium, calm, high-trust software experience with one coherent visual system across public marketing, login/auth, backstage admin, and Shopify embedded surfaces.

## User-Fed Requirements
- Make the uploaded Forestry Backstage logo usable for the landing page for forestrybackstage.com
- The site should become a beautiful landing page rather than redirecting straight to login
- Include everything a typical high-end software product site would include
- Zendesk.com is a benchmark for functionality, visual polish, hierarchy, and clarity, but should not be copied
- Branding should feel premium, expensive, modern, calm, and high-trust
- White background is required
- Login page branding should reflect the same style as the landing page
- Font and color should be consistent across landing page, login page, backend, and frontend
- Backend and frontend should look similar in terms of theme
- Tab content should be clearly definable and easy to scan
- Titles of pages and functions should sound human and plain-English rather than overly technical
- On the backend, there should be plain-English explanations for what something does and why it is done
- Documentation must be good enough for future Codex sessions to easily understand and modify the system
- We need a standard documentation structure to track and maintain UI changes
- At the top of that documentation should be all user-fed descriptions/requirements so future agents know what was actually asked for
- Modern Forestry is the alpha client, not the product definition. Product language should stay generic, reusable, and understandable across future clients.

## Non-Negotiable Visual Rules
- Primary surfaces are white.
- Accent system is restrained forest/navy with calm green support tones.
- Avoid noisy gradients, glow-heavy effects, or busy icon clutter.
- Keep strong spacing rhythm and scan-friendly section structure.
- Public/auth/admin/embedded must feel like one product family.

## Feature Metadata
1. Classification: Shared core
2. Tenant scope: Mixed (public global landing + tenant-aware auth + tenant/store scoped admin and embedded)
3. Entitlement/access level: UI layer is globally available; module/plan visibility remains driven by existing entitlement state
4. Canonical dependencies reused: `routes/web.php` home closure, `PlatformProductPagesController`, Fortify auth views, `ResolveAuthTenantContext`, `PostLoginRedirectResolver`, canonical admin shell, Shopify embedded controllers/shell
5. Shopify hooks preserved: embedded context query handling, signed context checks, App Bridge bootstrap, existing embedded routes and subnav
6. Setup/onboarding implications: merchant onboarding and guidance now use one canonical payload family (`onboardingPayload`, `merchantJourneyPayload`, `plansPayload`, `integrationsPayload`) from `TenantCommercialExperienceService`
7. Shopify behavior preservation requirement: embedded home/context logic must remain intact and role-based redirects must remain intact
8. Non-Shopify applicability target: now (shared visual system is used across non-Shopify and Shopify surfaces)

## Merchant Journey UX System (Embedded)
- Canonical first-touch merchant hierarchy (dashboard/app home) is:
  - what this app does
  - next best action
  - setup/import status
  - available now
  - setup next
  - unlock next
- Canonical import state vocabulary is:
  - `not_started` -> `Not started`
  - `in_progress` -> `In progress`
  - `attention` -> `Needs attention`
  - `imported` -> `Imported`
- Import CTA priority rule:
  - if import state is not `imported`, primary orientation CTA should route to import/setup entry points before deeper feature exploration.
- Capability visibility rule:
  - use `Available Now` for currently active modules,
  - `Setup Next` for included modules requiring setup,
  - `Unlock Next` for purchasable/upgrade-eligible modules.
- Customer-surface continuity rule:
  - customer pages should include shared setup/import status context before deep tools to reduce “what do I do next?” friction.
- Copy rule for merchant surfaces:
  - explain outcomes and next action in plain product language; keep provider/integration terms operational and secondary.

## Backend/Frontend Consistency Rules
- Reuse the same token system and typography for public, auth, admin, and embedded.
- Prefer shared classes/components over page-local style blocks.
- Keep page-level exceptions minimal and document them in `docs/ui/UI_CHANGELOG.md`.

## Login/Auth Branding Rules
- Keep tenant presentation hooks intact (`authTenantPresentation`, host tenant context, landlord mode flags).
- Keep Fortify + Socialite flow intact.
- Use the same token system and typography as landing/admin.
- Maintain clear, plain-English login labels and helper text.

## Documentation Maintenance Rules
- Any UI-affecting change must update:
  - `docs/ui/UI_CHANGELOG.md`
  - this file when ownership, patterns, or rules change
- Future Codex sessions should read, in order:
  1. `AGENTS.md`
  2. `README_FOR_AGENTS.md`
  3. `docs/ui/UI_SYSTEM.md`
  4. `docs/ui/UI_CHANGELOG.md`

## Open Questions / Pending Assets
- PNG/favicon renditions can be regenerated from finalized vector assets for broader browser/platform compatibility.

## Design Principles
- Calm over flashy
- Clear hierarchy over dense dashboards
- Human copy over internal jargon
- Safe incremental reuse over parallel UI systems

## Color Tokens
Source of truth: `resources/css/forestry-ui.css` (`:root`)
- `--fb-bg: #FFFFFF`
- `--fb-text: #0D1B1E`
- `--fb-brand: #123C43`
- `--fb-brand-2: #1E5A63`
- `--fb-accent: #2F7D6B`
- `--fb-border: #E7ECEB`
- `--fb-muted: #5D6B6A`

## Typography
- Headline/display: Fraunces (`--fb-font-display`)
- Body/UI: Inter (`--fb-font-ui`)
- Tailwind `--font-sans` is set Inter-first in `resources/css/app.css`

## Spacing Rules
- Use consistent card radii via `--fb-radius-*` tokens.
- Prefer 0.7rem to 1.5rem spacing intervals inside cards/sections.
- Keep section boundaries explicit with subtle border separators on public pages.

## Shell Ownership
- Canonical backstage shell: `resources/views/layouts/app/sidebar.blade.php`
- Embedded wrapper shell: `resources/views/components/shopify-embedded-shell.blade.php`
- Embedded frame primitives:
  - `resources/views/components/app-shell.blade.php`
  - `resources/views/components/app-topbar.blade.php`
  - `resources/views/components/app-sidebar.blade.php`
- Auth shell: `resources/views/layouts/auth/simple.blade.php`
- Public landing: `resources/views/platform/promo.blade.php`

## Route and Layout Ownership
- Root behavior: `routes/web.php` (`/` home closure)
  - embedded context present -> Shopify embedded controller
  - authenticated -> role-based `HomeRedirect`
  - guest -> render public landing through `PlatformProductPagesController::promo`
- Public pages:
  - `/platform/promo` -> `PlatformProductPagesController::promo`
  - `/platform/contact` -> `PlatformProductPagesController::contact`
- Auth pages: Fortify views under `resources/views/pages/auth/*` via `FortifyServiceProvider`

## Component Patterns
- Buttons (canonical):
  - `.fb-btn-soft` (base)
  - `.fb-btn-accent` (primary/emphasis)
  - `.fb-link-soft` (no underline)
- Workflow surfaces (authenticated setup/builder flows):
  - `.fb-workflow-shell` (page wrapper)
  - `.fb-workflow-header` + `.fb-eyebrow` + `.fb-title-xl` + `.fb-subtitle`
  - `.fb-panel` + `.fb-panel-head` + `.fb-panel-body`
  - `.fb-action-row` (footer CTA bar)
  - `.fb-stepper*` (backend-driven wizard step list)
  - `.fb-state*` (empty/error/warn/success banners)
  - `.fb-module-card*` (selected/locked/recommended module selection cards)
  - `.fb-motion-enter` (fast, subtle enter; reduced-motion safe)
- Explanation block (admin/operator screens): `x-ui.page-explainer` + `.fb-page-explainer*`
- Embedded shell primitives: `.app-shell*`, `.app-topbar*`, `.app-sidebar*`

## Public Motion Pattern
- Motion enhancements for public marketing pages are opt-in via `data-premium-motion="public"` on `<body>`.
- Shared motion markup lives in `resources/views/platform/partials/premium-motion.blade.php` and is included by public pages.
- Motion behavior is powered by `resources/js/public-premium-motion.js` (imported by `resources/js/app.js`), with:
  - one-time intro logo per tab/session (`sessionStorage`)
  - cursor ambient glow on fine-pointer devices
  - touch ripple on coarse-pointer devices
  - reveal/parallax helpers.
- Motion hooks:
  - `data-reveal` for entry reveal
  - `data-depth="<number>"` for subtle parallax
  - `data-premium-surface` for elevated surface treatment
- Respect reduced motion:
  - when `prefers-reduced-motion: reduce` is set, intro/ambient/ripple/reveal/parallax animation is disabled.

## Page Header Pattern
Use clear titles plus concise subheadlines that answer:
- what this page is
- what action to take next

## Tab Pattern
- Use short labels with direct nouns/verbs.
- Avoid internal implementation terms in visible labels.
- Keep active state high-contrast but restrained.

## Form Pattern
- White field backgrounds, subtle borders, calm focus ring (`--fb-ring`).
- Primary action labels should be verb-first and plain-English.

## Table/Card Pattern
- White/surface-muted cards
- Soft borders (`--fb-border`)
- Row hover emphasis should be subtle and calm

## Logo Usage
Brand asset directory: `public/brand/`
- `forestry-backstage-lockup.svg`: horizontal lockup
- `forestry-backstage-mark.svg`: primary mark
- `forestry-backstage-favicon.svg`: favicon SVG
- `forestry-backstage-auth.svg`: auth lockup variant
- Current approved asset pass is cache-tagged as `v=fb2` where referenced in shared views.

Primary usage points:
- `<head>` icons/OG image: `resources/views/partials/head.blade.php`
- App logo components:
  - `resources/views/components/app-logo.blade.php`
  - `resources/views/components/app-logo-icon.blade.php`

## Landing Page Section Architecture
`resources/views/platform/promo.blade.php` includes:
- announcement bar
- sticky nav
- hero
- trust/proof
- product overview
- capabilities grid
- workflow section
- product preview
- outcomes
- testimonials
- security/reliability
- FAQ
- plan summary
- final CTA + footer

## Auth Branding Behavior
- `resources/views/layouts/auth/simple.blade.php` renders brand panel + auth card with tenant presentation content.
- `resources/views/pages/auth/login.blade.php` keeps Google login branch and standard Fortify post route.

## Backend Helper-Text Pattern
Use `x-ui.page-explainer` for key admin/operator screens.
- currently applied to:
  - `resources/views/livewire/admin/admin-home.blade.php`
  - `resources/views/livewire/dashboard/launchpad.blade.php`

## Naming and Plain-English Rules
- Prefer "Import Issues" over "Fix Imports" style jargon.
- Prefer "Team Access" over internal role-management phrasing.
- Keep button labels action-oriented (`Sign in`, `Open`, `Review plans`).
- Keep platform wording generic and reusable across clients; avoid client-specific vertical naming in shared UI surfaces.
- Canonical strategy for user-facing loyalty wording is tenant-controlled display labels.
- Display labels resolve in this order:
  - tenant override
  - template default
  - global fallback
- Canonical label keys:
  - `rewards_label`
  - `rewards_balance_label`
  - `rewards_program_label`
  - `rewards_redemption_label`
  - `reward_credit_label`
  - `birthday_reward_label`

## Product Language Guardrail
- Modern Forestry is the alpha client, not the product definition.
- User-facing product language must stay generic and reusable unless explicitly scoped to client data/content.
- Avoid client-specific vertical naming in shared platform copy, labels, tabs, and helper text.
- `Candle Cash` is a valid tenant/template-facing label for candle-oriented tenants.
- Connector ingestion and import-run surfaces (Square sync, connector jobs) now require an explicit tenant/store context, persist `tenant_id`, and fail closed when no tenant is supplied, so downstream data remains partitioned by tenant/account.
- Provider names (for example `Square`, `Shopify`, `Growave`) are internal adapter/integration terms and should not be used as client-facing product identity outside explicit admin/integrations operational context.
- `Rewards` is a valid default for non-candle tenants, but it is not universal canon.
- Keep stable internal `candle_cash*` domains where coupling is high; change presentation labels first.
- Marketing and customer growth are part of the core product narrative (not an optional side area).
- Public copy tone should be concise, premium, human, and direct.
- In public-facing marketing/auth copy, prefer `place` over `system` when meaning remains clear.

## Tenant Boundary Guardrail
- Embedded customer manage/detail/mutation flows must enforce tenant boundaries at the query layer.
- Controller-level context resolution is required before running tenant-scoped embedded customer grid queries.
- Tenant scope resolution for embedded customer surfaces uses store context and applies fail-closed behavior (`tenant_id = store tenant` or `tenant_id is null` when no tenant is mapped).
- Service entry points for embedded customer detail sections should validate tenant scope before loading profile-linked data.
- Embedded dashboard cards, metrics, and aggregate sources must be tenant-scoped at the query layer before rendering.
- Embedded dashboard tenant scope must include base rows and supporting subqueries/rollups (orders, profile links, conversions, referrals, birthdays, reward ledgers).
- Missing or unmapped embedded tenant context must fail closed for dashboard metric queries (`tenant_id is null` scope only), not fallback to global aggregates.
- Embedded rewards editor/data routes must require a mapped tenant context and fail closed when no tenant is mapped.
- Embedded rewards earn/redeem reads and writes are tenant-scoped via tenant override storage at the query/storage layer, with global rows used only as fallback source records.
- Rewards program/refer config writes from embedded flows must persist per tenant and never overwrite another tenant's settings.
- Non-embedded/public rewards runtime endpoints must resolve tenant context explicitly before reading balances, rewards, referrals, birthdays, or redemption rules.
- Public/storefront/proxy reward mutations must enforce tenant scope in service-layer reads and writes; no post-query presentation filtering is sufficient.
- Reward-related runtime config in services, jobs, and reconciliation flows must resolve through tenant-aware settings first and fail closed when tenant context is missing, invalid, or ambiguous.
- Rewards lifecycle jobs/commands must carry explicit tenant context before running reconciliation, reminder, birthday issuance, referral qualification, or lifecycle intent writes.
- Background reward reconciliation must scope redemption/birthday code lookups through tenant-scoped profile joins, not global code matches.
- Async reward/background flows should skip safely and emit explicit `tenant_context_missing` outcomes when tenant context cannot be resolved.
- Maintenance/backfill/reporting commands that mutate or project rewards/attribution state must require explicit tenant context (`--tenant-id`) and fail closed when missing.
- Storefront issue maintenance commands (`repair-storefront-links`, unresolved issue scans) must query and mutate within tenant scope only.
- Store binding for reward discount activation/sync must prove tenant ownership of the resolved Shopify store; ambiguous fallback-to-retail behavior is disallowed.
- Tenant-aware storefront event dedupe should include tenant context when available to avoid cross-tenant event key collisions.
- Shared `marketing_import_runs` readers (recent/latest cards, activity views, diagnostics) must always resolve through tenant-owned filtering.
- Replay/resume consumers of import runs must enforce owner checks (for example `MarketingImportRun::tenantScopedRun`) and fail closed when owner context is missing, mismatched, or ambiguous.
- Growave wishlist legacy backfills must execute with one declared tenant owner per run; downstream wishlist/profile updates must remain tenant-scoped and skip conflicting ownership rows.
- Growave wishlist backfill candidate processing must prove tenant-owned `store_key` mapping before downstream writes; unresolved or cross-tenant store ownership is fail-closed.
- Growave opening-balance backfills must require explicit ownership proof (`--tenant-id` or tenant-owned `--store`) and must not mutate rows when external/profile tenant ownership is missing or mismatched.
- Remaining legacy reporting/read-model surfaces that are not import-backed must resolve tenant/store ownership explicitly at the query layer and fail closed when ownership cannot be proven.
- Where tenant ownership is provable, prefer tenant-owned joins/subqueries over late presentation filtering; where it is not provable, do not invent a tenant alias for a global read.
- Non-import operations/reconciliation surfaces must run behind `tenant.access`, scope issue/redemption/debug queries by tenant at the query layer, and reject cross-tenant route-model actions.
- `marketing:reconcile-redemptions` is tenant-scoped maintenance behavior: it requires `--tenant-id`, applies tenant filtering to Shopify and Square scans, and fails closed when tenant context is missing.
- Legacy Square contact-audit/report helpers in provider integrations must run with an explicit tenant scope and must not support nullable "global" helper execution.
- Legacy messages hub dashboard cards must resolve campaigns/groups/templates via tenant-owned profile joins (recipient/member rails), not by global table scans.
- Legacy campaign/segment/template/recommendation/report helpers must prove tenant ownership via explicit rails (tenant-owned profile joins / resolved campaign owner) before read or mutation.
- Campaigns, segments, and message templates now persist first-class `tenant_id` ownership rails; strict-mode create/edit/duplicate paths must write and enforce those rails directly.
- Legacy campaign/segment/template rows with unresolved ownership (`tenant_id` null) remain intentionally fail-closed in strict mode (hidden from tenant lists and blocked for tenant-sensitive edits).
- Campaign recipient send/retry flows must validate selected recipient IDs against campaign + tenant ownership before execution; mixed or foreign recipient sets are fail-closed.
- Campaign analytics/report helpers must accept tenant context and apply tenant predicates at query time (no aggregate-then-filter behavior).
- Event source mappings now use tenant-owned storage rails (`tenant_id`) and must be queried/mutated by tenant scope; foreign and unresolved mapping rows are fail-closed for edit surfaces.
- Event attribution projection rows now carry tenant ownership (`marketing_order_event_attributions.tenant_id`) so mapping-derived attribution cannot drift across tenant boundaries.
- Historical authoring ownership remediation is command-driven and deterministic: `marketing:remediate-authoring-ownership` inventories unresolved rows, assigns only provable owners with `--apply`, and leaves ambiguous/unprovable rows quarantined.
- Quarantined historical rows (`tenant_id` null) are an explicit fail-closed state for tenant-sensitive authoring/reporting/admin surfaces; they are not compatibility rows and must not be auto-assigned by defaults.
- Customer detail/analytics square-order attribution reads must apply tenant predicates when source ids can exist across multiple tenants (no shared `square_order_id` cross-tenant attribution blending).
- Campaign/admin helper commands (`marketing:send-approved-sms`, `marketing:send-approved-email`, `marketing:generate-recommendations`) require `--tenant-id` once strict mode is active and must block foreign ownership targets.

## Landlord Operator Boundary Guardrail (Post-MT-4C)
- Landlord/operator workflows are internal-only surfaces and must remain host-locked + landlord-auth guarded.
- Tenant-sensitive landlord actions must require explicit selected tenant context; do not infer tenant context from defaults.
- Canonical landlord action confirmation contract is:
  - route tenant id
  - posted tenant id
  - posted tenant slug
  - confirmation phrase `confirm <tenant-slug>`
- Landlord export/restore/customer modify/customer archive flows must fail closed when any tenant confirmation component is missing or mismatched.
- Snapshot export/import/download flows must remain tenant-scoped; snapshot artifacts must not be reused across tenants.
- Restore supports explicit `dry_run` preview mode and explicit `apply` mode; dry-run must not mutate rows.
- Restore apply mode requires typed `apply` phrase `apply <tenant-slug>`.
- Restore overwrite behavior must remain explicit and confirmed; no silent overwrite defaults.
- Restore overwrite mode requires typed overwrite phrase `overwrite <tenant-slug>`.
- Restore artifacts must pass schema/manifest gates (supported schema version, tenant id + slug match, scope-table manifest matching payload tables).
- Tenant operator snapshot upload size must respect configured max-bytes guard; oversize artifacts are fail-closed.
- Snapshot downloads must enforce tenant ownership + tenant path prefix + artifact expiry checks.
- Customer delete in landlord workflows uses safe archive/redaction semantics in this phase (not hard delete).
- Customer modify/archive workflows require typed target profile confirmation (`confirm_profile_id`) before mutation.
- Export/restore/modify/archive operator forms require explicit reason strings so action intent is preserved in audit metadata.
- Landlord operator action logs are append-only and must include actor, tenant, action type, status, target, and result metadata.
- Operator action statuses should remain consistent across flows (`success`, `blocked`, `failed`) to keep diagnostics and runbooks predictable.

## Where Future Codex Should Edit
1. Tokens/system CSS: `resources/css/forestry-ui.css`
2. Tailwind entry + font/accent bridge: `resources/css/app.css`
3. Public route behavior: `routes/web.php`
4. Public page content: `resources/views/platform/promo.blade.php`
5. Auth shell/content: `resources/views/layouts/auth/simple.blade.php`, `resources/views/pages/auth/login.blade.php`
6. Backstage/embedded shell primitives (do not fork new shells):
   - `resources/views/layouts/app/sidebar.blade.php`
   - `resources/views/components/app-shell.blade.php`
   - `resources/views/components/app-topbar.blade.php`
   - `resources/views/components/app-sidebar.blade.php`
   - `resources/views/components/shopify-embedded-shell.blade.php`
