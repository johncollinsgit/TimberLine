# UI Changelog

## 2026-04-06 — Embedded Messaging Responses Inbox

### What changed
- Added a new embedded Shopify Messaging subnav tab: `Responses`
  - route: `/shopify/app/messaging/responses`
  - placement: adjacent to `Message Analytics`
- Added a compact operator inbox UI with:
  - channel toggle for `Text` and `Email`,
  - left-side conversation list,
  - thread/history pane,
  - reply composer,
  - conversation actions for read/unread, assign-to-me, close, and archive.
- Added response summary cards for unread text, unread email, open conversations, and opted-out-today counts.
- Surfaced subscription/opt-out state directly in the thread header so operators can see when SMS replies are blocked.

### Why
- Messaging Analytics answers how a blast performed, but operators also needed a native place to work the human replies that came back.
- Reply handling had to feel like part of Backstage rather than a separate provider console or webhook-log screen.

### Scope boundary
- This pass is focused on the embedded Shopify Messaging surface and the new Responses workspace.
- It does not attempt a multi-user support queue system with SLA/routing rules; the current workflow is intentionally lightweight and operator-first.

## 2026-04-06 — Embedded Messaging Cost Guardrails + MMS Cost Routing

### What changed
- Added live SMS cost awareness to the embedded Shopify messaging composer:
  - GSM-7 vs Unicode indicator,
  - SMS segment count,
  - estimated SMS cost per recipient,
  - estimated MMS cost per recipient,
  - exact review-step total cost for the selected audience.
- Added a review-step cost card that shows the predicted delivery path (`SMS`, `MMS`, or `mixed`), average per-recipient cost, total estimated cost, and any blocking safety message.
- Added the configured bulk spend ceiling directly into the review-step cost card so operators can see the auto-block threshold before send.
- Updated the preview/smoke/send pipeline so the backend normalizes smart punctuation before send, uses the same cost math as the UI, and blocks oversized bulk sends when the projected total exceeds the configured safety ceiling.
- Added MMS-aware cost routing for embedded text sends:
  - when long text to eligible `+1` recipients is cheaper as MMS than segmented SMS, the send pipeline now requests Twilio `sendAsMms`,
  - if the provider rejects that MMS path, the sender can fall back to SMS.

### Why
- Merchants needed to see cost before sending, not after Twilio billing landed.
- Long texts were especially risky because segmented SMS could quietly multiply both outbound message cost and carrier fees.
- Twilio’s current docs allow long text to be delivered as a single MMS in the US/Canada, which can be cheaper than multi-segment SMS for longer bodies.

### Pricing assumptions used for UI estimates
- SMS outbound per segment uses the current Twilio US list price (`$0.0083`).
- MMS outbound per message uses the current Twilio US list price (`$0.022`).
- Carrier-fee estimates are based on current observed account billing behavior from April 2026 and are intentionally configurable in environment/config.

### Scope boundary
- This pass updates the embedded Shopify messaging workspace only.
- Estimates are guardrail math, not invoice guarantees; real carrier outcomes and provider responses still win.
- MMS cost routing currently targets recipients eligible for North America MMS delivery based on the resolved phone number format.

## 2026-04-04 — Messaging Analytics SMS Run Rollups

### What changed
- Updated the embedded Shopify `Message Analytics` table to roll batched SMS deliveries into one logical send run when the content/source match and the batches were dispatched close together.
- Added lightweight operator-facing context in the table so SMS rows can show when multiple batches were rolled up into one analytics record.
- Updated the detail metadata card to describe a logical SMS run in human terms (`Run batches`) instead of exposing the synthetic internal run key.
- Added explicit explanatory copy when attributed orders exist without tracked clicks so operators can tell the difference between inferred attribution and true click-capture gaps.

### Why
- Large text blasts were being split into many tiny rows because analytics grouped strictly by `batch_id`, which made attribution and recipient totals look incomplete even when the underlying deliveries were correct.
- Operators needed the page to match how they think about a send: one blast, not dozens of queue chunks.

### Scope boundary
- Email analytics remain grouped by their existing batch model.
- This pass changes presentation/grouping only; it does not claim retroactive click recovery for old untracked SMS sends.

## 2026-04-04 — Shopify Messaging Analytics + Chart Toggle Reliability

### What changed
- Added a first-class embedded Shopify `Message Analytics` surface under Messaging sub-navigation:
  - route: `/shopify/app/messaging/analytics`
  - legacy redirect: `/messaging/analytics`
  - discoverable through the existing command/search registry with messaging analytics keywords.
- Introduced an operational analytics UI (table-first) with:
  - summary cards (sent, delivered, opens, unique opens, clicks, unique clicks, attributed orders, attributed revenue, click-to-order conversion),
  - practical filters (date, channel, campaign/message, opened/clicked state, attributed orders, URL/domain, customer),
  - message-level table and detail drill-down (metadata, open timeline, URL-level performance, attributed orders).
- Added the engagement trend XY chart for messaging analytics with toggleable metrics (email + text delivery/engagement + attributed orders), following the same interaction model as rewards chart controls.
- Fixed rewards chart metric-toggle active-state styling reliability by hardening the control state CSS (`is-active` + `aria-pressed=true`) and improving active/focus contrast.

### Why
- Messaging needed a real operational analytics surface inside the unified embedded Backstage shell, not a standalone dashboard fragment.
- Operators also needed stable visual feedback when toggling chart series, including in rewards where active-chip color state could appear stuck.

### Scope boundary
- No parallel app shell or alternate search path was introduced.
- Messaging analytics remains gated by existing tenant/module access resolver patterns and Shopify tenant/store context.

## 2026-04-02 — Backstage Light-Surface Consistency Sweep (Deep Feature Views)

### What changed
- Continued the light-surface migration across deeper Backstage areas, focusing on analytics widgets, birthdays internals, admin/operator Livewire views, market planners, wiki surfaces, and marketing detail pages.
- Removed remaining legacy dark-surface utility patterns from scoped Backstage templates:
  - hard-coded deep backgrounds (`bg-[#101513]`, `bg-[#0f1412]`, `bg-[#0b1411]`, related near-black variants),
  - dark translucent slab wrappers and heavy glass effects,
  - white-on-dark table divider/border patterns (`divide-white/*`, `border-white/*`),
  - forced dark-mode utility branches (`dark:*`) still present in catalog admin.
- Normalized modal/drawer and command palette surfaces to the shared light treatment (white/surface-muted cards, soft border, restrained shadow, lighter overlay intensity).
- Reworked shared marketing section wrappers to use light semantic surfaces and simplified accent cards/chips for readability-first hierarchy.
- Corrected wiki/admin/public-marketing text contrast drift introduced by legacy dark-class carryover (faint zinc/sky/emerald text on light backgrounds).

### Why
- A broad landlord-first pass was complete, but deep feature routes still contained mixed-era dark wrappers and low-contrast text patterns that broke visual consistency and scanning speed.
- This pass aligns those remaining screens with the canonical light-background UI system while preserving existing behavior and route logic.

### Scope boundary
- No route/controller behavior changes were introduced in this pass.
- Focus remained on shared visual consistency, surface tokens, and readability improvements in existing templates/components.

## 2026-04-01 — Backstage Light-Surface Consistency Pass (Landlord + Shared Shells)

### What changed
- Standardized a semantic light token layer in `resources/css/forestry-ui.css` and aligned legacy aliases to it:
  - `pageBackground`, `surface`, `surfaceSubtle`, `surfaceMuted`,
  - `border`,
  - `textPrimary`, `textSecondary`, `textMuted`,
  - `accent`, `success`, `warning`, `danger`,
  - `focusRing`,
  - `shadowSm`, `shadowMd`.
- Added reusable shared primitives for admin/operator surfaces:
  - `.fb-page-surface*`, `.fb-chip*`, `.fb-btn-soft`, `.fb-btn-accent`, `.fb-code-block`.
- Hardened `mf-app-card` compatibility overrides so legacy dark utility classes in existing views resolve to readable light surfaces without introducing a parallel system.
- Migrated shared shells/components to the light language:
  - `x-app-layout` (removed forced dark mode class, improved page-level composition),
  - landlord/app sidebar quick actions + search chip styling,
  - toast surface/status styling,
  - command palette surface and result cards,
  - admin help-hint component tones,
  - shared marketing/candle-cash/birthdays section wrappers.
- Converted landlord tenant directory/detail and key admin pages away from dark slab cards:
  - `resources/views/landlord/tenants/index.blade.php`
  - `resources/views/landlord/tenants/show.blade.php`
  - `resources/views/admin/index.blade.php`
  - `resources/views/pouring/index.blade.php`
- Aligned landlord commercial action accents to the shared brand accent direction while preserving existing workflow logic.

### Why
- Landlord and adjacent operator screens still carried high-contrast dark slabs that conflicted with the project’s white-surface system and reduced scanability.
- This pass centralizes light-surface behavior in shared theme primitives first, then removes major dark remnants from high-frequency operational screens.

### Scope boundary
- No controller, route, or tenant isolation behavior changed.
- No commercial workflow contracts changed; this pass is visual-system and readability focused.

## 2026-04-01 — Landlord Commercial Becomes Tenant Management Surface

### What changed
- Elevated `/landlord/commercial` from a config-first landlord console into a true tenant-management workspace:
  - new `Tenant Management` header and action row,
  - KPI strip for tenant count, recurring run rate, sales, rewards, onboarding, and user seats,
  - dominant activity analytics card powered by ApexCharts,
  - compact filter rail + more-filters panel,
  - sortable tenant table with export, column toggles, and focused tenant detail rail.
- Kept the existing commercialization controls intact underneath the new analytics surface so landlord pricing/module/template workflows remain available without route changes.
- Added landlord dashboard bootstrap payload generation in `LandlordCommercialConfigurationController` so the redesign reuses existing commercial state instead of inventing a parallel system.

### Why
- The existing landlord page had already improved commercially, but it still read like a configuration console rather than the tenant-performance and revenue-management surface the product needed.
- This pass makes the landlord surface easier to scan, more obviously operational, and much closer to a true management cockpit while preserving the shipped pricing and entitlement controls.

### Scope boundary
- No App Store, shell, search, or module-discovery behavior changed here.
- Existing commercialization writes/routes remain intact; this is a landlord-surface redesign on top of the current architecture.

### Transparent analytics caveats
- `Subscription income to date` currently uses configured recurring run rate, not true captured billing-history income.
- `Revenue generated by module` currently uses configured commercial mix, not true attributed module billing revenue.

### Follow-up
- GitHub issue `#8` tracks replacing both proxy metrics once canonical billing history and module-attribution revenue history exist.

## 2026-03-30 — Public Premium Motion Pack (Intro, Ambient, Touch, Reveal)

### What changed
- Added a new public-page motion layer with restrained premium effects on marketing surfaces:
  - one-time centered intro logo overlay on first load per browser tab/session,
  - cursor-responsive ambient glow on fine-pointer devices,
  - touch ripple bloom on coarse-pointer devices,
  - reveal-on-scroll and subtle depth parallax helpers.
- Added shared motion markup partial:
  - `resources/views/platform/partials/premium-motion.blade.php`
- Enabled motion layer on public pages:
  - `resources/views/platform/promo.blade.php`
  - `resources/views/platform/contact.blade.php`
  - both now use `data-premium-motion="public"` and include the shared motion partial.
- Added motion runtime module:
  - `resources/js/public-premium-motion.js`
  - imported via `resources/js/app.js`
- Added motion styles in `resources/css/forestry-ui.css` with scoped selectors under `body[data-premium-motion="public"]`.
- Added regression assertions in `tests/Feature/ShopifyCommercializationPagesTest.php` so public pages keep rendering the motion shell hooks.

### Why
- The public brand surface was already polished, but lacked subtle motion cues that increase perceived quality and hierarchy.
- This pass adds depth and interactivity while preserving performance and accessibility defaults.

### Accessibility and performance guards
- Motion is disabled when `prefers-reduced-motion: reduce` is active.
- Ambient loop is enabled only for fine-pointer devices.
- Touch effects only run on coarse-pointer devices.
- Motion styles are scoped to public pages only; no admin/embedded behavior changes.

## 2026-03-30 — Public/Auth Brand Alignment (Approved Tree Logo + Route Behavior Verification)

### What changed
- Replaced interim public/auth brand SVGs with the approved Forestry Backstage tree-forward identity across canonical brand assets:
  - `public/brand/forestry-backstage-mark.svg`
  - `public/brand/forestry-backstage-lockup.svg`
  - `public/brand/forestry-backstage-auth.svg`
  - `public/brand/forestry-backstage-favicon.svg`
- Updated shared logo references and cache-busting tags to `?v=fb2` in:
  - head metadata/icons
  - promo navigation lockup
  - auth shell lockup
  - shared app logo/topbar/sidebar logo references.
- Kept root/public/login routing architecture intact while adding focused regression coverage proving:
  - `/` defaults to the marketing landing for guests
  - `/login` renders the dedicated light auth shell
  - authenticated users are still redirected to their application workspace.

### Why
- The routing behavior and light template primitives were already correct; the remaining mismatch was brand asset drift against the approved logo system.
- This pass makes the rendered UI reflect the approved identity without introducing any parallel theme or auth flow logic.

### Scope boundary
- No auth controller/Fortify workflow changes were introduced.
- No tenant/Shopify boundary behavior was modified.

## 2026-03-30 — Merchant Experience Consolidation (Landing/Onboarding/Import/Customers)

### What changed
- Reworked embedded merchant landing/dashboard (`/shopify/app`) into a guided hierarchy that makes first actions obvious:
  - product orientation and value summary
  - next best setup/import action
  - customer/setup snapshot metrics
  - post-import value flow explanation
  - capability grouping (`Available Now`, `Setup Next`, `Unlock Next`)
  - recommended action list with clear CTA intent.
- Standardized setup/import framing across Start Here, Plans, and Integrations surfaces by reusing canonical tenant commercial payloads (`merchantJourneyPayload`, `onboardingPayload`, `plansPayload`, `integrationsPayload`) instead of introducing parallel state models.
- Improved customer workspace orientation:
  - customer manage/activity/questions surfaces now carry shared setup/import readiness context
  - empty and pre-import states now direct merchants toward import/setup actions in plain language.
- Clarified feature discovery and monetization visibility:
  - active vs setup-needed vs purchasable capability state is now consistently surfaced in merchant-facing copy and card hierarchy
  - upgrade opportunities remain discoverable without becoming spammy or disruptive.
- Performed merchant-facing wording cleanup to reduce implementation jargon and keep provider terminology as integration context, not product identity.

### Why
- Post-MT-4C tenant safety is strong enough to prioritize commercial usability.
- Merchants need immediate clarity on what to do first, whether import is complete, what tools are usable now, and what value is available next.
- A single journey system reduces UX drift and lowers onboarding confusion across embedded routes.

### State and behavior conventions established
- Canonical import states for merchant orientation:
  - `not_started`, `in_progress`, `attention`, `imported`
- Canonical capability framing:
  - `Available Now`, `Setup Next`, `Unlock Next`
- Canonical first-touch sequencing:
  - import/setup guidance first, then deeper customer workflows and upgrade discovery.

### Bounded scope that remains intentional
- No broad backend architecture rewrite was introduced in this pass.
- No MT-4C tenant-protection rollback or fail-open behavior was introduced.
- Advanced visual snapshot testing remains deferred; behavior/state gating tests remain the current regression guardrail.

## 2026-03-30 — Post-MT-4C: Operator Hardening Follow-Up (Recovery/Destructive/Diagnostics)

### What changed
- Hardened landlord restore/import recovery path with deeper validation and safer execution modes:
  - `LandlordTenantOperationsService::restoreTenantSnapshot` now supports explicit `dry-run` mode with projected table impact and no mutations.
  - restore now enforces schema/version + manifest gates:
    - schema version must be supported
    - source tenant id + source tenant slug must match selected tenant
    - `scope.tables` manifest must match `data` table payload keys exactly
  - restore now enforces max artifact size guard from tenancy config (`tenancy.landlord.tenant_ops.max_snapshot_bytes`).
- Hardened restore confirmation controls:
  - apply mode (non-dry-run) now requires typed phrase `apply <tenant-slug>`
  - overwrite mode now requires typed phrase `overwrite <tenant-slug>` in addition to checkbox confirmation.
- Hardened export/download lifecycle controls:
  - export now records `artifact_bytes`, `generated_at`, and `expires_at` metadata.
  - download path now blocks expired artifacts and enforces tenant path-prefix checks for stored artifact paths.
- Hardened destructive customer controls:
  - customer modify/archive now require typed target confirmation (`confirm_profile_id` equals selected `profile_id`).
  - operator reason capture is now required for export/restore/modify/archive actions and stored in audit confirmation metadata.
- Improved operator diagnostics/visibility on tenant detail:
  - recent action status summary cards (total/success/blocked/failed)
  - richer action detail rendering (mode, reason, expiry metadata, blocked error context).
- Added operator tenancy config controls in `config/tenancy.php`:
  - `landlord.tenant_ops.snapshot_retention_days`
  - `landlord.tenant_ops.max_snapshot_bytes`

### Why
- The MVP landlord layer was safe but still shallow in recovery/destructive ergonomics.
- This pass focuses on reducing operational risk by making restore behavior more explicit, destructive intent more deliberate, and action diagnostics easier for operators to trust.

### Bounded scope that remains intentional
- Restore remains a bounded tenant snapshot workflow, not full cross-environment restore orchestration.
- Artifact lifecycle is now bounded with retention/expiry enforcement on download paths, but full async artifact lifecycle tooling is still deferred.
- Dual-operator approval is still deferred; this pass strengthens single-operator confirmation and audit evidence instead.

## 2026-03-30 — Post-MT-4C: Landlord Operator Tenant-Ops MVP + Consistency Pass

### What changed
- Added a guarded landlord tenant-operations layer with explicit tenant selection and confirmation:
  - `LandlordTenantOperationsController`
  - landlord routes for tenant selection, snapshot export/restore, customer modify/archive, and snapshot download.
- Added bounded tenant-ops service and append-only audit infrastructure:
  - `LandlordTenantOperationsService` (tenant-scoped export/restore + bounded customer modify/archive workflows)
  - `LandlordOperatorActionAuditService`
  - `landlord_operator_actions` storage (`create_landlord_operator_actions_table` migration)
  - `LandlordOperatorAction` model now enforces append-only behavior (update/delete blocked).
- Added landlord tenant operations UI to existing landlord surfaces:
  - explicit tenant selector on landlord dashboard and tenant directory
  - guarded export/restore/customer modify/customer archive forms on tenant detail
  - tenant action trace table with export artifact download links.
- Standardized landlord operator confirmation and fail-closed conventions:
  - canonical tenant confirmation contract (`tenant_id` + `tenant_slug` + `confirm <tenant-slug>` phrase)
  - explicit overwrite confirmation for restore overwrite mode
  - blocked cross-tenant restore/modify/archive/download behavior with audit trace evidence.
- Added focused regression coverage in `tests/Feature/Tenancy/LandlordTenantOperationsMvpTest.php` for:
  - explicit tenant selector behavior
  - tenant-scoped export payloads
  - cross-tenant restore blocking
  - cross-tenant customer modify blocking
  - safe customer archive/delete behavior with before/after audit evidence
  - tenant-locked snapshot download + blocked/success download audit traces
  - append-only operator action record enforcement.

### Why
- MT-4C closed tenant ownership hardening, but landlord/operator workflows still needed a first guarded operational layer for tenant recovery and support actions.
- This pass adds practical operator capabilities while preserving MT-4C fail-closed semantics and standardizing tenant-context conventions across UI/controller/service/audit boundaries.

### Bounded MVP Scope
- Snapshot restore/import supports landlord-generated tenant snapshot artifacts from this phase.
- Restore/import is intentionally limited to tenant-owned marketing/customer dataset tables in the snapshot scope.
- Broad cross-environment migration tooling and unrestricted operator mutation flows remain intentionally deferred.

## 2026-03-30 — MT-4C: Unresolved Ownership Tail Remediation + Closure Verification (Pass 4)

### What changed
- Added explicit unresolved-tail remediation/inventory tooling:
  - new service: `MarketingAuthoringOwnershipRemediationService`
  - new command: `marketing:remediate-authoring-ownership`
- The new command now provides deterministic ownership inventory + remediation across:
  - `marketing_campaigns`
  - `marketing_segments`
  - `marketing_message_templates`
  - `marketing_event_source_mappings`
  - `marketing_order_event_attributions`
- Remediation behavior is explicit and fail-safe:
  - dry-run mode classifies unresolved rows as `provable`, `ambiguous`, `unprovable`, or `unsupported`
  - `--apply` assigns `tenant_id` only for deterministically provable rows
  - ambiguous/unprovable/unsupported rows remain intentionally quarantined (`tenant_id` null) and fail closed
- Hardened customer detail/analytics attribution reads for shared Square source ids:
  - tenant predicates now apply to square-order lookups and event-attribution joins in customer detail/read-model helper paths
  - prevents foreign-tenant attribution blending when the same `square_order_id` exists in multiple tenants
- Added focused MT-4C Pass 4 regression coverage in `tests/Feature/Marketing/MarketingMt4CPass4OwnershipClosureTest.php` for:
  - deterministic remediation assignment behavior,
  - unresolved quarantine persistence,
  - shared-square-order tenant isolation in customer detail.

### Why
- Pass 3 established schema-backed tenant rails and safe fail-closed behavior, but a bounded historical unresolved tail remained.
- Pass 4 converts that tail from implicit uncertainty into explicit operational behavior: deterministically remediated when provable, otherwise quarantined and visible.

### MT-4C Closure Result
- MT-4C is now considered complete for the scoped hardening objective.
- Remaining historical unresolved rows (if any) are explicitly bounded, visible through command inventory, and intentionally fail-closed rather than silently inferred.

## 2026-03-30 — MT-4C: Schema-Backed Ownership Rail Closeout (Pass 3)

### What changed
- Added first-class tenant ownership rails for remaining legacy marketing authoring/storage entities:
  - `marketing_campaigns.tenant_id`
  - `marketing_segments.tenant_id`
  - `marketing_message_templates.tenant_id`
  - `marketing_event_source_mappings.tenant_id`
  - `marketing_order_event_attributions.tenant_id` (to keep mapping attribution rows tenant-partitioned)
- Added tenant-aware unique/index constraints needed for deterministic multi-tenant authoring:
  - campaign slug uniqueness now tenant-scoped
  - segment slug uniqueness now tenant-scoped
  - event mapping uniqueness now tenant-scoped (`tenant_id + source_system + raw_value`)
  - order-event attribution uniqueness now tenant-scoped (`tenant_id + source_type + source_id + event_instance_id`)
- Added ownership backfill logic for provable legacy rows:
  - campaigns backfilled from tenant-owned profile/group/conversion evidence rails
  - segments backfilled from tenant-owned campaigns
  - templates backfilled from tenant-owned campaign variants
  - event source mappings backfilled only when source-name/tax evidence resolves to one tenant
  - event attributions backfilled only when source order ownership resolves to one tenant
- Re-enabled previously blocked strict-mode authoring where storage is now tenant-owned:
  - segment create/store
  - segment duplicate (tenant-bound)
  - template create/store
  - tenant-owned event mapping create/edit/update/list in providers-integrations
- Updated query/service alignment to the new storage truth:
  - `MarketingTenantOwnershipService` now prefers first-class tenant columns for campaigns/segments/templates and fail-closes unresolved rows.
  - `MarketingProvidersIntegrationsController` now scopes mapping list/edit/write by `tenant_id` and rejects foreign/unresolved mapping edits.
  - `MarketingEventAttributionService` now scopes mapping resolution and attribution rows by tenant rail where available.
  - `MarketingAllOptedInSendService` now writes tenant-owned campaigns in strict mode and scopes audience selection by tenant context.
  - `MarketingPagesController` and tenant-sensitive customer helper queries now consume the canonical tenant-ownership service rails rather than legacy campaign-recipient inference.
- Added focused MT-4C Pass 3 regression coverage in `tests/Feature/Marketing/MarketingMt4CPass3SchemaOwnershipRailsTest.php` for:
  - tenant-owned writes at create time,
  - cross-tenant edit/duplicate denial,
  - unresolved legacy fail-closed behavior,
  - migration backfill of provable legacy rows.

### Why
- Pass 2 intentionally fail-closed unsafe create/edit surfaces because first-class storage ownership was missing.
- Pass 3 replaces those temporary blocks with deterministic tenant-owned storage where proof is possible, while keeping unresolved legacy rows blocked instead of guessed.

### Remaining After This Pass
- A tail of legacy rows can still remain unresolved (`tenant_id` null) when ownership cannot be proven safely; these rows remain fail-closed by design.
- MT-4C is materially closer to closure, but unresolved-row remediation still exists as a bounded follow-on item.

## 2026-03-30 — MT-4C: Campaign/Report + Shared Mapping Ownership Closure (Pass 2)

### What changed
- Hardened remaining legacy campaign/report/helper paths around deterministic tenant ownership rails:
  - Added `MarketingTenantOwnershipService` ownership-rail usage across campaign/segment/template/recommendation/messages controller flows.
  - Campaign recipient send/retry paths now validate selected recipient IDs against campaign + tenant ownership before mutation.
  - `MarketingSmsExecutionService` and `MarketingEmailExecutionService` now enforce tenant-owned campaign/recipient execution context and skip foreign/unproven rows.
  - `MarketingPerformanceAnalyticsService` now supports tenant-scoped report computation; campaign show now passes tenant context so variant/recipient/delivery/conversion aggregates exclude foreign-tenant rows at query time.
- Hardened shared/global mapping slice behavior where deterministic tenant ownership does not exist:
  - `MarketingProvidersIntegrationsController` now treats `marketing_event_source_mappings` edits as unsafe in multi-tenant mode and fails closed for create/edit/update actions.
  - Mapping list/read surface on providers-integrations now suppresses shared mapping rows in multi-tenant mode rather than exposing global rows as tenant-safe data.
- Hardened admin helper command surfaces tied to campaign/report mutation:
  - `marketing:send-approved-sms`
  - `marketing:send-approved-email`
  - `marketing:generate-recommendations`
  - These now require `--tenant-id` when tenant strict mode is active and block foreign campaign/recipient ownership.
- Added focused regression coverage in `tests/Feature/Marketing/MarketingMt4CPass2CampaignReportIsolationTest.php` proving:
  - tenant A/B campaign visibility isolation,
  - fail-closed selected-recipient mutation behavior,
  - tenant-scoped campaign performance reporting,
  - fail-closed shared mapping edit behavior in multi-tenant mode,
  - tenant-required command guards and foreign-campaign blocking.

### Why
- MT-4C Pass 1 closed Growave/backfill + messages-hub risks, but campaign/report/helper chains and shared mapping bridges still had high-leverage cross-tenant ambiguity.
- This pass moves those remaining high-risk surfaces to deterministic ownership checks or explicit fail-closed behavior.

### Remaining After This Pass
- MT-4C is still not fully closed.
- Campaign/segment/template creation flows still rely on legacy tables without first-class tenant ownership columns; strict mode intentionally fails closed for unsafe creation paths.
- Shared event source mappings still need a tenant-owned storage rail to safely re-enable multi-tenant edit workflows without global side effects.

## 2026-03-30 — MT-4C: Legacy Dashboard/Backfill Ownership Hardening (Pass 1)

### What changed
- Hardened remaining Growave migration-era backfill paths to require explicit tenant ownership proof before writes:
  - `GrowaveWishlistBackfillService` now validates candidate `store_key` ownership against the run tenant before profile resolution or wishlist writes.
  - Candidates with unresolved or cross-tenant store ownership now fail closed (skipped + logged) instead of being processed opportunistically.
  - `marketing:import-growave-opening-balances` now requires tenant ownership rails (`--tenant-id` or tenant-owned `--store`), persists a tenant-owned `marketing_import_runs` row, and blocks conflicting store/tenant ownership combinations.
  - Opening-balance candidate queries now require both external row tenant ownership and tenant-owned `marketing_profiles` joins before transaction writes.
- Hardened residual dashboard/report query helpers where nullable tenant context previously allowed broad behavior:
  - `MarketingPagesController` message and overview cards now read through tenant-proven campaign/group/template/segment rails only.
  - `MarketingProvidersIntegrationsController` Square contact-audit/read-model helpers now require explicit tenant scope (no nullable global-mode helper execution).
- Added targeted regression coverage for:
  - Growave wishlist fail-closed behavior when store ownership cannot be proven.
  - Growave opening-balance fail-closed behavior for missing ownership proof and conflicting store/tenant ownership.
  - Cross-tenant exclusion in messages hub cards (tenant A does not see tenant B groups/campaigns/templates).
  - Messages hub fail-closed behavior when tenant context cannot be proven.

### Why
- MT-4C still had practical leakage risk in migration-era backfill paths and legacy read-model helpers that tolerated weak ownership proof.
- This pass moves ownership proof to query/service boundaries and blocks ambiguous backfill execution before downstream writes occur.

### Presentation-Only vs Internal Refactor Boundary
- Preserved tenant-controlled display label resolution order (`tenant override -> template default -> global fallback`).
- Preserved stable `candle_cash*` internals and existing public/login/admin visual language.
- Applied narrow enforcement in command/service/query boundaries without broad schema redesign.

### Remaining After This Pass
- MT-4C is not closed yet.
- Legacy campaign/report surfaces that still rely on models without first-class tenant ownership rails remain the highest-risk follow-on area.
- Any remaining read-model slices without deterministic ownership rails should continue to fail closed until ownership can be proven safely.

## 2026-03-30 — MT-4B: Tenant-Scoped Legacy Reporting/Read-Model Isolation

### What changed
- Hardened the remaining non-import legacy reporting/read-model surfaces to resolve tenant context explicitly and query through tenant-owned joins/subqueries where ownership is provable:
  - `MarketingPagesController` overview and customer-discovery cards now render tenant-scoped profile, overlap, import, and birthday/candle-cash diagnostics.
  - `MarketingProvidersIntegrationsController` now scopes source-overlap diagnostics and tenant-owned attribution refreshes.
  - `BirthdayPagesController` now requires tenant context for customers, analytics, campaigns, rewards, settings, and birthday import activity surfaces.
  - `BirthdayReportingService` birthday summary/campaign/reward metrics now require an explicit tenant id.
  - `BirthdayCsvImportService` now writes tenant-owned birthday import runs and carries tenant ownership into canonical identity payloads.
- Added regression coverage proving:
  - birthday pages fail closed without tenant context,
  - birthday import runs are tenant-owned,
  - birthday reporting stays tenant-scoped,
  - marketing overview and provider overlap dashboards ignore foreign-tenant records.
- Updated system guidance to reflect the final MT-4B rule: if a read-model cannot prove tenant ownership, it should fail closed rather than inventing a tenant boundary.

### Why
- MT-4B closes the remaining legacy reporting/read-model leakage risk that was still relying on late or mixed ownership resolution outside the import-run replay paths.
- The pass keeps the existing UI polish and stable internal domains intact while tightening the legacy dashboard/report surfaces that users still rely on day to day.

### Presentation-Only vs Internal Refactor Boundary
- Preserved stable `candle_cash*` internals and the existing tenant-controlled display-label strategy.
- Applied hardening at controller/query boundaries and import-run ownership handoff points rather than introducing a parallel reporting system.

### Remaining After MT-4B
- Some legacy provider/integration summaries still aggregate global read-models where the underlying storage does not yet expose a first-class tenant boundary.
- Follow-on work should continue with the remaining legacy read-model slices that can be safely partitioned without broad schema redesign.

## 2026-03-30 — MT-4A: Non-Import Legacy Reporting/Ops Tenant Boundary Hardening

### What changed
- Hardened marketing operations reconciliation surfaces to require explicit tenant context and query-level tenant scoping:
  - `MarketingOperationsController@reconciliation` now scopes storefront events, redemption queues, issue-type breakdowns, and summary counts to the active tenant.
  - `resolveIssue`, `markRedemptionRedeemed`, and `storefrontRedemptionDebug` now enforce tenant ownership and fail closed when ownership is missing or cross-tenant.
  - Debug profile lookup is now tenant-scoped by id/email and no longer resolves profiles outside the active tenant.
- Moved marketing operations reconciliation routes under `tenant.access` middleware so requests always execute with a validated tenant context.
- Hardened `marketing:reconcile-redemptions` command for non-import maintenance execution:
  - `--tenant-id` is now required,
  - Shopify and Square scans are both tenant-filtered at query time,
  - tenant-less execution now fails closed instead of scanning globally.
- Added MT-4A regression coverage for:
  - tenant-scoped operations reconciliation visibility,
  - fail-closed foreign-tenant issue resolution and manual redemption mutation attempts,
  - tenant-scoped storefront debug lookup behavior,
  - fail-closed reconciliation command execution when tenant context is missing,
  - tenant-scoped Square reconciliation command behavior.

### Why
- MT-4A targets the remaining non-import legacy reporting/ops paths where tenant ownership was still inferred late or inconsistently enforced.
- Operations and reconciliation flows are high-impact maintenance surfaces; they now follow the same tenant-first, query-level, fail-closed rules as prior MT hardening phases.

### Presentation-Only vs Internal Refactor Boundary
- Preserved existing UI polish, copy direction, and tenant-controlled display-label strategy.
- Kept `candle_cash*` internals stable.
- Applied hardening at route/middleware, controller query boundaries, and command execution boundaries without a broad architecture rewrite.

### Remaining After MT-4A
- Core non-import operations reconciliation surfaces are now tenant-safe by default.
- Remaining tenancy work should continue in legacy/global marketing reporting/read models that still intentionally operate outside tenant-scoped route boundaries.

## 2026-03-30 — MT-3: Tenant-Scoped Shared Import-Run Consumers + Replay/Backfill Ownership

### What changed
- Enforced tenant-owned replay/resume behavior for import-run consumers in connector commands:
  - `marketing:sync-square-customers`
  - `marketing:sync-square-orders`
  - `marketing:sync-square-payments`
  - `marketing:sync-growave`
- Resume/checkpoint lookups now resolve through tenant-owned run access (`MarketingImportRun::tenantScopedRun`) and fail closed when the run owner is missing, mismatched, or ambiguous.
- Hardened birthday import activity surfaces to require tenant context and scope activity/import-related feed queries by tenant owner.
- Tightened Growave wishlist backfill ownership execution:
  - each run is tied to a single resolved tenant owner,
  - candidate processing skips out-of-owner rows,
  - wishlist row writes enforce tenant ownership consistency before create/update.
- Tightened tenant-scoped diagnostics in marketing customer empty-state import candidate summary so connector candidate counts and run diagnostics stay within the active tenant scope.
- Added MT-3 regression coverage for:
  - tenant-isolated recent import run visibility in Birthday and Marketing import-related views,
  - fail-closed behavior for anonymous legacy import command execution,
  - fail-closed replay resume when run ownership is cross-tenant or missing,
  - Growave wishlist backfill single-owner enforcement and conflict fail-closed behavior.
  - provider-integration naming guardrail check on touched non-integration activity surfaces (no provider branding leakage).

### Why
- MT-3 closes the remaining shared `marketing_import_runs` consumer gap where replay/resume and import-status views could still rely on global or late-derived ownership assumptions.
- The pass locks ownership at run-consumer boundaries and blocks unsafe fallback behavior so tenant A cannot replay or read tenant B import state.
- Provider names remain adapter-level terms in admin/integrations operations, not client-facing product identity.

### Presentation-Only vs Internal Refactor Boundary
- Preserved existing UI polish and tenant-controlled display-label strategy.
- Kept stable `candle_cash*` internals and connector domain naming intact.
- Applied hardening at tenant ownership enforcement points (controller queries, command resume lookups, backfill write paths, regression tests).

### Remaining After MT-3
- Shared connector/import ownership has been enforced for the run-consumer and replay/resume paths in scope; the next leverage point is broader tenant-boundary verification across non-import legacy ops/reporting slices that do not consume `marketing_import_runs`.
- No broad deep rename of `candle_cash*` internals was attempted in this pass.

## 2026-04-05 — MT-2D: Square/Connector Source Partition + Tenant-Scoped Import Runs

### What changed
- Added tenant/account ownership (`tenant_id`) to Square source tables (`square_customers`, `square_orders`, `square_payments`) plus tenant-scoped unique indexes and tenant-aware `marketing_import_runs`.
- Tenant context is now required for every Square sync (`marketing:sync-square-*`) command, the admin Square sync controller, and the underlying `SquareMarketingSyncService`; missing context fails closed with tenant-specific error states.
- Square source writes and import run creation now carry the resolved tenant, and all downstream admin metrics, diagnostics, and helper queries filter by that tenant or fail when it is absent.
- Added regression coverage proving tenant A’s Square ingestion/import run cannot affect tenant B and vice versa.
- Documented the new tenant/account partitioning rules and fail-closed behavior in the UI system guidance.

### Why
- Source ingestion was still global, so Square syncs and import runs could leak across tenants; MT-2D focuses on partitioning those flows before any downstream connectors consume the data.
- Tenant context is now baked into the sync service, commands, controller, and diagnostics so the platform fails closed instead of silently using unsafe defaults.
- The new regression tests act as a safeguard for future maintenance or connector work by locking in tenant-specific expectations.

### Presentation-Only vs Internal Refactor Boundary
- Stable `candle_cash*` internals and existing UI polish remain untouched.
- Tenant/store hardening occurs at the entry points (commands/controller/service queries) and storage layer (`tenant_id` columns) without a broad architectural rewrite.
- Added new documentation guidance instead of changing marketing copy or UI patterns.

## 2026-03-30 — MT-2C: Tenant-Safe Maintenance/Reporting + Store-Binding Hardening

### What changed
- Hardened remaining maintenance/backfill command paths to require explicit tenant context and fail closed when missing:
  - `marketing:backfill-attribution-source-meta`
  - `marketing:backfill-order-attribution-meta`
  - `marketing:backfill-conversion-attribution-snapshots`
  - `marketing:repair-storefront-links`
  - `marketing:scan-unresolved-marketing-issues`
- Enforced tenant-scoped query joins/subqueries for the above commands so related links/referrals/issuances/redemptions/events are resolved inside tenant boundaries.
- Hardened attribution reporting commands for tenant-scoped operation:
  - `marketing:report-order-attribution-coverage`
  - `marketing:report-conversion-attribution-coverage`
  - `marketing:report-attribution-coverage-comparison`
  - Added tenant-aware scoping in their report services for orders/conversions/profile joins.
- Tightened strict store-binding in rewards discount activation paths:
  - `BirthdayRewardActivationService` now fails closed when tenant context is missing or when resolved store is not tenant-owned.
  - `CandleCashShopifyDiscountService` now requires tenant-owned store resolution and blocks ambiguous cross-tenant store fallback.
- Hardened storefront event dedupe to include tenant-aware matching when tenant context is present, preventing cross-tenant dedupe collisions on shared request keys.
- Added MT-2C regression tests covering:
  - command fail-closed behavior without tenant context,
  - tenant-scoped maintenance mutations/logging,
  - strict store-binding fail-closed behavior,
  - tenant-aware storefront event dedupe behavior.

### Why
- MT-2C targets the remaining non-request-cycle leakage risk in maintenance/reporting/backfill and ambiguous background store-resolution paths.
- Previous phases hardened request-cycle and core async runtime paths; this pass closes the operator/admin/background surface area still prone to implicit global behavior.

### Presentation-Only vs Internal Refactor Boundary
- Preserved stable `candle_cash*` internal domains and existing request-cycle architecture.
- Applied tenant/store hardening at command/service/query execution boundaries, plus strict ownership checks for store resolution.
- Deferred broader Square source-layer tenancy redesign to a later dedicated phase.

## 2026-03-30 — MT-2B: Rewards Lifecycle Job/Command Tenant Context Enforcement

### What changed
- Hardened async reconciliation services to require tenant-safe execution:
  - `CandleCashRedemptionReconciliationService` now resolves tenant context first, scopes code lookups by tenant-owned profiles, and fails closed with `tenant_context_missing` when unresolved.
  - `BirthdayRewardRedemptionReconciliationService` now applies the same tenant-scoped code lookup and fail-closed behavior.
- Tightened async/background entrypoints:
  - `marketing:reconcile-redemptions` now accepts `--tenant-id` and propagates tenant context into reconciliation services.
  - `marketing:issue-birthday-rewards` now requires `--tenant-id` and only evaluates birthday profiles for that tenant.
  - `marketing:candle-cash-lifecycle-preview` now requires `--tenant-id` and fails closed otherwise.
  - `SyncMarketingProfileFromOrder` now skips rewards reconciliation paths when tenant context is missing.
- Hardened lifecycle/referral/reminder services for background safety:
  - `CandleCashOrderEventService` now requires tenant context and skips order-event awarding when unresolved.
  - `CandleCashReferralService` now resolves referrer codes within tenant scope and fails closed when tenant context is missing.
  - `CandleCashLifecycleService` skips intent writes for rows without tenant context and excludes missing-tenant rows from qualified cohorts.
  - `CandleCashEarnedReminderService` now blocks manual sends without explicit tenant context.
- Added focused regression tests covering:
  - tenant-scoped reconciliation command behavior,
  - missing-tenant fail-closed behavior for Candle Cash + birthday reconciliation,
  - tenant-required birthday/lifecycle commands,
  - async order-sync behavior when tenant context is missing.

### Why
- MT-2B priority is closing cross-tenant leakage risk in non-request/background execution where global defaults are easiest to miss.
- The request-cycle protections from MT-1/MT-2A are preserved, while async paths now follow the same tenant-first, fail-closed rules.

### Presentation-Only vs Internal Refactor Boundary
- Kept stable `candle_cash*` internal domain names, storage, and route keys intact.
- Applied tenant hardening at service/command/job execution boundaries and query joins, without deep internal renames.
- Preserved previously completed UI polish and tenant-controlled display-label behavior.

## 2026-03-29 — MT-2A: Public Rewards Runtime + Storefront Endpoint Tenant Isolation

### What changed
- Added tenant-aware marketing settings resolution for rewards runtime/config reads via `TenantMarketingSettingsResolver` using `tenant override -> global fallback`.
- Hardened non-embedded/public rewards runtime services to require tenant-aware config/runtime resolution for storefront rewards, redemption rules, referral settings, and birthday reward behavior.
- Updated Shopify storefront/public rewards controllers to fail closed when tenant context is missing (`tenant_context_required` / `missing_tenant_context`) instead of silently using global behavior.
- Enforced tenant-aware behavior across reward lookup/redeem flows so tenant A cannot read or mutate tenant B reward state through storefront/public entry points.
- Hardened birthday reconciliation/config resolution paths to use tenant-aware reward config, including tenant-specific birthday code prefix handling.
- Added focused regression tests proving:
  - storefront/public rewards endpoints fail closed without mapped tenant context,
  - tenant-scoped rewards lookup/redeem behavior remains functional in normal-state flows,
  - tenant-scoped birthday storefront behavior is preserved,
  - tenant-aware runtime config resolution works (tenant override precedence in `CandleCashService`).

### Why
- MT-2A priority is moving tenant isolation beyond embedded surfaces into public/storefront runtime paths where leakage risk remained.
- Public rewards and birthday flows previously accepted tenant context for parts of request handling but still had unsafe global config assumptions in core runtime reads.
- This pass preserves stable `candle_cash*` internals while enforcing tenant safety where runtime data/config is resolved.

### Presentation-Only vs Internal Refactor Boundary
- Kept stable `candle_cash*` core routes/tables/classes intact.
- Applied tenant enforcement at controller/service/config resolution boundaries used by runtime flows.
- Avoided broad architecture rewrites and preserved Phase 8 + MT-1 UI and embedded behavior.

## 2026-03-29 — MT-1C / MT-1D: Embedded Rewards Tenant Isolation + Regression Wall

### What changed
- Reworked embedded rewards availability checks to require a mapped tenant and fail closed (`tenant_not_mapped`) when tenant context is missing.
- Replaced global rewards editor reads/writes with tenant-scoped behavior in `ShopifyEmbeddedRewardsService`:
  - tenant-scoped earn rule overrides,
  - tenant-scoped redeem rule overrides,
  - tenant-scoped rewards program/referral config persistence.
- Added tenant-scoped rewards storage tables and models:
  - `tenant_marketing_settings`
  - `tenant_candle_cash_task_overrides`
  - `tenant_candle_cash_reward_overrides`
- Hardened controller update endpoints to resolve rule IDs through service-level tenant-aware resolution before mutation.
- Updated embedded rewards overview data to render from tenant-scoped payloads so mapped stores no longer depend on global reward previews.
- Added/updated regression coverage proving:
  - tenant A cannot read tenant B reward editor state,
  - tenant A cannot overwrite tenant B reward edits,
  - unmapped tenant context fails closed for rewards routes,
  - embedded customers detail/manage and dashboard metric isolation still hold under the rewards hardening pass.

### Why
- MT-1C/MT-1D priority is actual data-boundary enforcement for rewards config/editor flows, not additional copy or visual changes.
- The previous rewards path used global rows/settings and blocked mapped tenants; this pass removes that blocker while preserving stable `candle_cash*` internals.
- Tenant-scoped overrides provide the narrowest safe isolation layer without broad risky refactors.

### Presentation-Only vs Internal Refactor Boundary
- Kept stable underlying `candle_cash*` core tables/classes/routes intact.
- Applied tenant isolation in the presentation/service storage layer for embedded rewards (override + scoped settings tables).
- Avoided broad deep renames or unrelated architecture rewrites.

## 2026-03-29 — MT-1B: Embedded Dashboard Query-Level Tenant Isolation

### What changed
- Updated embedded dashboard metric/data services to enforce tenant scope at the query layer across conversions, birthdays, referrals, order rollups, profile-link rollups, and reward ledger-derived metrics.
- Updated embedded dashboard reward value provider snapshots to require tenant context and filter redeemed, gift, and birthday cost sources by tenant-scoped profile joins.
- Hardened earned analytics tenant behavior to fail closed for null tenant contexts (`tenant_id is null`) instead of reading global aggregates.
- Added regression tests proving:
  - tenant A cannot see tenant B dashboard metrics,
  - tenant-scoped normal-state metrics still render correctly,
  - tenant-scoped empty states remain intact when no in-scope data exists,
  - missing tenant context fails closed for dashboard metrics.

### Why
- MT-1B priority is true tenant-boundary enforcement for embedded dashboard cards and aggregates.
- Query-level scoping prevents cross-tenant leakage in dashboard totals and supporting rollups.
- Fail-closed null-tenant behavior avoids accidental global fallback in unmapped embedded contexts.

## 2026-03-29 — MT-1A: Embedded Customer Query-Level Tenant Isolation

### What changed
- Updated `ShopifyEmbeddedCustomersController@manage` to resolve embedded context first and only execute customer grid queries with a resolved tenant scope.
- Added fail-closed grid handling for non-authorized page contexts to avoid running unscoped embedded customer queries.
- Updated `ShopifyEmbeddedCustomersGridService` to require tenant-aware query construction across base query and embedded customer aggregate/search subqueries.
- Hardened embedded customer detail gating to use query-scoped profile existence checks instead of model-only tenant comparisons.
- Added tenant-scope guard checks in `ShopifyEmbeddedCustomerDetailService` entry points (`build`, `buildCritical`, `buildDeferredSections`).
- Added regression tests proving tenant-isolated embedded manage page/json behavior for both normal-state and empty-state results.

### Why
- MT-1A priority is tenant-boundary correctness in embedded customer flows, not copy or visual redesign.
- Query-level tenant scoping prevents cross-tenant data leakage in list/detail/manage customer paths.
- Fail-closed behavior keeps embedded UX intact while ensuring unauthorized or unmapped contexts do not return customer rows.

## 2026-03-29 — Recovery R1/R2: Tenant-Controlled Display Labels

### What changed
- Added a centralized tenant display-label resolver (`TenantDisplayLabelResolver`) and reused it across commercialization + embedded navigation + touched public flows.
- Formalized canonical presentation keys (`rewards_label`, `rewards_balance_label`, `rewards_program_label`, `rewards_redemption_label`, `reward_credit_label`, `birthday_reward_label`).
- Changed label resolution order to: tenant override -> template default -> global fallback.
- Updated candle template defaults to keep `Candle Cash` valid for candle-oriented tenants, while non-candle contexts can default to `Rewards`.
- Converted touched user-facing surfaces from hardcoded loyalty wording to label-driven rendering (public event/rewards pages, consent flows, section registries, embedded rewards load errors).
- Updated legacy `CandleCashPagesController` user-facing toast/config descriptions to resolve loyalty wording from tenant display labels.
- Updated commercialization copy from `entitlements default` wording to `global fallback` wording where applicable.

### Why
- Correct Phase 8 language strategy drift where `Rewards` was treated as universal canon.
- Keep Phase 8 visual quality and stable internals while making wording tenant-aware and reusable across clients.
- Avoid risky deep refactors by applying presentation-layer renames first.

### Presentation-Only Rename Policy Used
- Internal `candle_cash*` classes/routes/tables were intentionally preserved.
- User-facing labels, helper text, headings, and fallback copy were switched to resolver-driven display labels.
- Deep internal renames were deferred by design to protect launch-critical behavior.

## 2026-03-29 — Phase 8 Acceptance Polish and Product-Language Cleanup

### What changed
- Fixed low-contrast login submit styling by adding an explicit high-contrast auth submit pattern.
- Resolved launchpad and admin-users dark-theme bleed by applying shared light tokenized card primitives.
- Added a safe catalog tab fallback when `catalog_item_costs` is missing so the tab still renders for visual QA.
- Increased embedded topbar breathing room and improved mobile wrapping to reduce clipping/crowding.
- Replaced remaining hard-coded dark container styles on the admin catalog scents surface with shared light primitives.
- Replaced user-facing "Candle Cash" wording with reusable product language across public and Shopify embedded surfaces.
- Replaced public/auth marketing phrasing from "system" to "place" where it improved tone and clarity.
- Updated hero/subcopy language to keep marketing/customer growth explicitly represented without overloading headline copy.

### Why
- Close Phase 8 acceptance blockers without reopening architecture work.
- Keep product language reusable across future clients while preserving current underlying domain models.
- Improve visual consistency and readability across auth, dashboard, admin tab surfaces, and embedded pages.
- Keep Forestry Backstage positioned as a reusable software product rather than a candle-specific app shell.

### Files touched
- `resources/css/forestry-ui.css`
- `resources/views/pages/auth/login.blade.php`
- `resources/views/livewire/dashboard/launchpad.blade.php`
- `resources/views/livewire/admin/users/index.blade.php`
- `app/Livewire/Admin/Catalog/CostsCrud.php`
- `resources/views/livewire/admin/catalog/costs.blade.php`
- `resources/views/livewire/admin/catalog/scents.blade.php`
- `resources/views/platform/promo.blade.php`
- `config/commercial.php`
- `app/Http/Controllers/ShopifyEmbeddedRewardsController.php`
- `app/Http/Controllers/ShopifyEmbeddedCustomersController.php`
- `app/Services/Shopify/ShopifyEmbeddedRewardsService.php`
- `app/Services/Shopify/ShopifyEmbeddedCustomerDetailService.php`
- `app/Services/Shopify/ShopifyEmbeddedCustomersGridService.php`
- `app/Services/Shopify/ShopifyEmbeddedCustomerMessagingService.php`
- `app/Services/Shopify/Dashboard/ShopifyEmbeddedDashboardConfig.php`
- `app/Services/Shopify/Dashboard/ShopifyEmbeddedDashboardDataService.php`
- `app/Services/Shopify/Dashboard/ShopifyEmbeddedDashboardCandleCashValueProvider.php`
- `resources/views/shopify/rewards.blade.php`
- `resources/views/shopify/rewards-overview.blade.php`
- `resources/views/shopify/rewards-placeholder.blade.php`
- `resources/views/shopify/customers-manage.blade.php`
- `resources/views/shopify/partials/customers-manage-results.blade.php`
- `resources/views/shopify/partials/customers-detail-activity-section.blade.php`
- `resources/views/shopify/customers-detail.blade.php`
- `resources/views/shopify/customers-questions.blade.php`
- `docs/ui/UI_SYSTEM.md`

### Intended visual/UX effect
- Better auth accessibility, cleaner embedded header spacing, and fewer dark legacy remnants in light surfaces.
- More reusable, plain-English product labels across public and embedded UX.
- Premium public copy tone that feels direct and human while preserving operational clarity.

### Follow-up debt
- Internal model/service names still use `candle_cash*` identifiers by design for safety in this phase.
- A deeper internal rename should only happen with explicit migration/test budget and rollout planning.

### Notes for future Codex sessions
- Prefer presentation-label changes over deep internal renames unless explicitly requested.
- Treat tenant-controlled display labels as canonical for user-facing loyalty wording.
- `Candle Cash` is a valid tenant/template-facing label; `Rewards` is a valid default, not universal canon.
- Presentation-only rename policy for this phase:
  - UI labels, headings, helper text, toasts, and storefront copy were updated.
  - Internal tables, model/service classes, and route keys were intentionally left unchanged to avoid risky refactors.

## 2026-03-29 — Premium White System Foundation

### What changed
- Added a centralized UI token and component stylesheet for public/auth/admin/embedded surfaces.
- Reworked public promo and contact pages into the new premium white visual system.
- Changed guest root `/` behavior to render the landing page while preserving embedded and authenticated branches.
- Rebranded auth shell/login view using the same token system.
- Migrated large inline CSS out of high-value shell files:
  - canonical admin shell
  - embedded app shell/topbar/sidebar primitives
  - key embedded pages (`start-here`, `plans-addons`, `customers-layout`, `customers-activity`, `embedded-app`)
- Added reusable backend page explanation component and applied it to key admin/dashboard surfaces.
- Introduced production brand asset directory and updated logo/favicon usage points.

### Why
- Enforce one consistent product family across public, auth, backstage, and embedded contexts.
- Improve readability and trust through a calmer, clearer, plain-English UI.
- Reduce UI maintenance risk by moving style logic out of inline Blade blocks into canonical CSS.

### Files touched
- `resources/css/app.css`
- `resources/css/forestry-ui.css`
- `routes/web.php`
- `app/Http/Controllers/ShopifyEmbeddedAppController.php`
- `app/Http/Controllers/ShopifyEmbeddedCustomersController.php`
- `resources/views/partials/head.blade.php`
- `resources/views/platform/promo.blade.php`
- `resources/views/platform/contact.blade.php`
- `resources/views/layouts/auth/simple.blade.php`
- `resources/views/pages/auth/login.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/components/app-logo.blade.php`
- `resources/views/components/app-logo-icon.blade.php`
- `resources/views/components/app-shell.blade.php`
- `resources/views/components/app-topbar.blade.php`
- `resources/views/components/app-sidebar.blade.php`
- `resources/views/components/shopify-embedded-shell.blade.php`
- `resources/views/components/shopify/customers-layout.blade.php`
- `resources/views/shopify/start-here.blade.php`
- `resources/views/shopify/plans-addons.blade.php`
- `resources/views/shopify/customers-activity.blade.php`
- `resources/views/shopify/embedded-app.blade.php`
- `resources/views/components/ui/page-explainer.blade.php`
- `resources/views/livewire/admin/admin-home.blade.php`
- `resources/views/livewire/dashboard/launchpad.blade.php`
- `public/brand/forestry-backstage-lockup.svg`
- `public/brand/forestry-backstage-mark.svg`
- `public/brand/forestry-backstage-favicon.svg`
- `public/brand/forestry-backstage-auth.svg`
- `public/favicon.svg`
- `tests/Feature/ExampleTest.php`
- `docs/ui/UI_SYSTEM.md`

### Intended visual/UX effect
- White, premium, calm, high-trust surfaces across all major UI contexts.
- Stronger hierarchy and scanability in public and operator workflows.
- Consistent branding, typography, and CTA language.

### Follow-up debt
- Some page-level legacy style blocks still exist in deeper feature views and should be tokenized incrementally.
- Final high-resolution uploaded logo source should replace interim vector interpretations when available.
- Optional PNG/favicon variants should be regenerated from finalized vector assets.

### Multi-tenant connector coverage
- Shopify-linked import runs (customer birthdays, metafields, Growave sync) now capture `tenant_id` and resolve store ownership before persisting `marketing_import_runs`.
- Shared import-run consumers are now expected to read the tenant-aware ownership tokens and fail closed if the tenant context cannot be resolved.

### Notes for future Codex sessions
- Do not reintroduce large inline style blocks in shell/layout files.
- Update this changelog and `docs/ui/UI_SYSTEM.md` whenever ownership or token rules change.
- Preserve root routing order: embedded context -> authenticated redirect -> guest landing.
