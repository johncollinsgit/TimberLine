# Tenant Rewards Management Layer

## Feature Metadata (Required)

1. Classification: `Shared core`
2. Tenant scope: `tenant-scoped` with global fallback read behavior through existing marketing settings keys
3. Entitlement/access level: rewards module access from the canonical tenant entitlement resolver; non-eligible tenants are read-only/upsell, eligible tenants can edit and publish
4. Canonical model/service dependency:
   - Candle Cash execution remains canonical:
     - `CandleCashService`
     - existing wallet, code issuance, redemption, and reconciliation flows
   - existing tenant-scoped settings/override storage remains canonical:
     - `tenant_marketing_settings`
     - `tenant_candle_cash_task_overrides`
     - `tenant_candle_cash_reward_overrides`
   - existing access/settings resolution remains canonical:
     - `TenantMarketingSettingsResolver`
     - `TenantModuleAccessResolver`
5. Shopify-specific hooks:
   - embedded admin routes under `/shopify/app/rewards` and `/shopify/app/api/rewards/*`
   - bearer-token Shopify embedded auth remains unchanged
   - existing embedded rewards controller/service flow remains canonical
6. Setup/onboarding implications:
   - tenant must be mapped to store
   - tenant-scoped rewards tables/settings must exist
   - rewards module access must be granted by plan/add-on
   - tenant email/SMS sender setup still determines reminder launch readiness
7. Shopify behavior preservation requirement:
   - existing wallet, code issuance, redemption lifecycle, reconciliation, and storefront routes remain unchanged
8. Non-Shopify applicability target: `later` (policy model is shared-capable, current operator surface is Shopify embedded admin)

## 1) Scope Implemented So Far

### Foundation + Phase 2
- Added a tenant rewards policy resolver/update service on top of existing Candle Cash runtime settings
- Added entitlement-aware policy API endpoints:
  - `GET /shopify/app/api/rewards/policy`
  - `PATCH /shopify/app/api/rewards/policy`
  - `POST /shopify/app/api/rewards/policy/review`
  - `POST /shopify/app/api/rewards/policy/defaults/alpha`
- Extended rewards payload metadata with policy + access state
- Added business-language rewards notifications/settings UI in embedded rewards surfaces
- Added guardrail validation for incompatible policy combinations
- Added field-level control metadata:
  - `editable`
  - `editable_with_warning`
  - `restricted`
- Added plain-English program summary generation
- Added non-blocking business warnings (`errors`, `warnings`, `info`)
- Added customer-facing SMS/email preview generation from live tenant policy
- Added lightweight policy version metadata/history in tenant settings
- Added append-only policy audit history via the existing landlord/operator action model
- Added review-and-publish UX with live summary, pending summary, and change preview
- Added Alpha starter defaults path and confirmation summary

### Phase 3
- Added reminder scheduling/readiness logic on top of existing tenant policy settings
- Added reminder event logging/history without creating a new rewards messaging system
- Added launch-readiness evaluation for operators in plain business language
- Added safer publish review context with clearer change visibility before live edits
- Added policy-to-runtime traceability for reminder scheduling/history
- Added customer reminder history visibility in the embedded rewards notifications workspace

### Phase 4
- Added live reminder dispatch orchestration on top of the existing schedule/logging layer
- Reused the current email and SMS delivery paths instead of introducing a new messaging platform
- Added merchant-facing reminder activity/reporting in the embedded rewards settings experience
- Added stronger exclusion controls and channel strategy controls in the existing tenant policy model
- Added Alpha launch checklist / next-step guidance for safer go-live decisions
- Added narrow internal support tooling through a reminder processor command with traceable filters

### Phase 5
- Added reminder explainability/debug support on top of the existing schedule and dispatch layers
- Added lightweight finance visibility for liability, breakage, realized discounts, and expiring rewards
- Added tenant-scoped CSV exports for reminder history, issuance, redemption, and expiring rewards
- Added optional queued reminder dispatch for safer scale handling and retryable delivery orchestration
- Added richer support tooling in the existing embedded rewards workflow:
  - explain one reminder
  - review one customer reminder history
  - requeue one eligible reminder
  - mark one reminder as skipped with a reason
- Added filterable reminder reporting, health signals, and lightweight impact projections in the rewards workspace

### Phase 6
- Added tenant rewards operations/runtime state without introducing a parallel rewards system
- Added automation health tracking for reminder processing:
  - automation mode (`automatic` / `manual`)
  - default mode is `manual` until a tenant explicitly switches automation on
  - last run time
  - last success time
  - last failure time
  - failure count
  - last error message
- Added scheduled finance-report delivery on top of the existing export and email stack
- Added signed CSV download links for scheduled finance delivery
- Added lightweight team access controls for edit / publish / automation-mode / support actions using existing user + tenant membership roles
- Added module-usage visibility for commercial/package readiness
- Added lightweight policy-impact simulation using recent tenant reward activity

### Explicitly Out of Scope
- A new rewards engine
- A parallel configuration store
- A new customer identity model or sidecar loyalty profile system
- Any rewrite of wallet, issuance, redemption, or reconciliation logic
- A brand-new outbound messaging platform

## 2) Data Model and Storage Reuse

### Reused Existing Structures (No New Rewards Tables)
- `tenant_marketing_settings` stores tenant policy documents and runtime-compatible key/value settings
- `tenant_candle_cash_task_overrides` stores tenant-specific earn rule edits
- `tenant_candle_cash_reward_overrides` stores tenant-specific redeem rule edits
- `marketing_automation_events` now also stores reminder event history for rewards reminder scheduling/logging
- `marketing_email_deliveries` stores live email reminder delivery rows
- `marketing_message_deliveries` stores live SMS reminder delivery rows
- existing landlord/operator action records remain the append-only audit trail for policy changes

### Tenant Settings Keyspace
- `candle_cash_policy_config`
- `candle_cash_program_config`
- `candle_cash_frontend_config`
- `candle_cash_notification_config`
- `candle_cash_finance_config`
- `candle_cash_access_state`
- `candle_cash_policy_version_meta`
- `candle_cash_policy_versions`
- `candle_cash_operations_config`
- `candle_cash_team_access_config`
- `candle_cash_automation_state`

### Migration / Compatibility Plan
- No schema migration was required for the tenant rewards policy layer itself
- Existing tenants continue to resolve from global defaults until tenant settings are saved
- Existing issued reward codes and wallets remain valid
- Existing runtime settings continue to resolve through the same Candle Cash engine
- Reminder timing preserves backward compatibility:
  - legacy `reminder_offsets_days` still resolves
  - new policy prefers `email_reminder_offsets_days` and `sms_reminder_offsets_days`

## 3) Service Extension Plan

### Canonical Policy Service
- `App\Services\Marketing\TenantRewardsPolicyService`
  - resolves tenant policy domains into a business-ready structure
  - validates compatibility and guardrails
  - persists policy into existing tenant settings keyspace
  - returns summary, warnings, previews, readiness, versioning, audit history, and reminder history
  - applies Alpha defaults in-place

### Supporting Policy Services
- `App\Services\Marketing\TenantRewardsPolicySummaryService`
  - renders plain-English program summary from normalized tenant policy
- `App\Services\Marketing\TenantRewardsPolicyWarningService`
  - returns non-blocking `errors` / `warnings` / `info` advisories
- `App\Services\Marketing\TenantRewardsPolicyMessagePreviewService`
  - builds customer-facing SMS/email previews from current tenant policy
- `App\Services\Marketing\TenantRewardsPolicyAuditService`
  - records and reads append-only policy audit entries via the existing operator audit model
- `App\Services\Marketing\TenantRewardsPolicyReadinessService`
  - evaluates live readiness, channel readiness, schedule readiness, risk counts, and Alpha preset match state
- `App\Services\Marketing\TenantRewardsFinanceSummaryService`
  - summarizes estimated tenant rewards liability and realized discount value from existing Candle Cash runtime data
- `App\Services\Marketing\TenantRewardsExportService`
  - generates tenant-scoped CSV-ready data sets for reminder history, issuance, redemption, and expiring rewards
- `App\Services\Marketing\TenantRewardsOperationsService`
  - resolves automation/reporting settings and team access settings from the existing tenant settings layer
  - records reminder automation runtime health in-place
  - exposes automation status, alerts, permissions, usage indicators, and policy-impact simulation in the policy payload
  - sends scheduled finance-report emails using the existing SendGrid path plus signed export links

### Reminder Services
- `App\Services\Marketing\TenantRewardsReminderScheduleService`
  - calculates reminder schedule instances for a reward from tenant policy
  - returns:
    - what should send
    - what is upcoming
    - what was skipped
    - why it was skipped
  - prevents reminders:
    - after expiration
    - after redemption
    - after cancellation
    - when duplicate reminder timing is already recorded
  - enforces SMS cap and quiet-period selection logic
  - includes `policy_version` in runtime schedule output
- `App\Services\Marketing\TenantRewardsReminderLogService`
  - records structured reminder history into `marketing_automation_events`
  - exposes tenant-wide recent history and reward-specific history
  - stores reminder context such as:
    - reward identifier
    - reward code
    - channel
    - timing days before expiration
    - scheduled / attempted / sent / skipped timestamps
    - skip reason
    - policy version
    - reminder key
- `App\Services\Marketing\TenantRewardsReminderDispatchService`
  - consumes outstanding earned reward buckets from existing Candle Cash analytics state
  - evaluates due reminders through the existing schedule service
  - routes live email sends through `SendGridEmailService`
  - routes live SMS sends through `TwilioSmsService`
  - writes delivery rows into existing marketing delivery tables
  - records attempted / sent / failed / skipped outcomes through the existing reminder log service
  - respects expiration, redemption, cancellation, duplicate prevention, SMS caps, launch state, and channel readiness
  - exposes reminder explain/debug output for one scoped reward/customer/channel/timing
  - can queue due reminders through a unique queued job when inline processing is not desired
- `App\Services\Marketing\TenantRewardsReminderAnalyticsService`
  - summarizes reminder sends, failures, skips, upcoming reminders, expiring-soon rewards, and top skip reasons
  - powers the merchant-facing reminder activity section in the embedded rewards workspace
  - applies date/channel/status/reward-type filters
  - returns health signals and lightweight impact projections

### Existing Services Extended In Place
- `App\Services\Shopify\ShopifyEmbeddedRewardsService`
  - exposes policy read/update/review/defaults flows via the canonical policy service
  - returns enriched policy/readiness metadata in rewards payloads
- `App\Http\Controllers\ShopifyEmbeddedRewardsController`
  - continues to host the embedded rewards settings UI and API routes
  - passes review/default/readiness endpoints into the existing Blade workspace

## 4) Reminder Scheduling and Readiness Model

### Scheduling Inputs
Reminder scheduling is derived from:
- reward earned date
- reward expiration date
- tenant reminder settings
- enabled channels
- customer channel contactability
- SMS cap and quiet-period rules
- tenant policy version

### Scheduling Output
`TenantRewardsReminderScheduleService` returns:
- `should_send`
- `upcoming`
- `skipped`
- `history`
- `summary`

Each schedule instance includes:
- `reward_identifier`
- `reward_code`
- `tenant_id`
- `marketing_profile_id`
- `channel`
- `timing_days_before_expiration`
- `scheduled_at`
- `policy_version`
- `reminder_key`
- `earned_at`
- `expires_at`

### Skip Rules
Reminder instances are skipped when:
- the channel is turned off
- expiration is missing
- reminder timing would be invalid
- the customer is not contactable on that channel
- the reward is already redeemed
- the reward is already canceled
- the reward is already expired
- the same reward/channel/timing was already recorded

### Readiness Evaluation
`TenantRewardsPolicyReadinessService` converts policy + environment state into business-readable launch status:
- overall status
- headline
- summary
- live/draft state
- current policy version
- last updated timestamp
- warning / error / info counts
- channel readiness
- reminder schedule validity
- Alpha preset match state

The readiness layer reuses:
- `MarketingEmailReadiness`
- `TwilioSenderConfigService`
- tenant reminder schedule preview data

### Current Delivery Scope
- Reminder settings now drive real send orchestration through the current delivery stack
- This phase still does **not** introduce a brand-new messaging system
- Dispatch reuses:
  - `MarketingEmailReadiness`
  - `SendGridEmailService`
  - `TwilioSenderConfigService`
  - `TwilioSmsService`
  - `MarketingDeliveryTrackingService`
- Reminder dispatch is processed by:
  - `php artisan marketing:process-tenant-rewards-reminders`
  - scheduled hourly in `routes/console.php`
  - optional queued mode via `php artisan marketing:process-tenant-rewards-reminders --queue`
  - queued job: `App\Jobs\DispatchTenantRewardsReminderJob`
- Scheduled finance reporting is processed by:
  - `php artisan marketing:send-tenant-rewards-finance-reports`
  - scheduled daily in `routes/console.php`
  - existing export generation plus SendGrid-delivered signed CSV links

### Dispatch Behavior
- due rewards are sourced from existing outstanding earned Candle Cash buckets
- dispatch is skipped when:
  - the program is still draft
  - launch is scheduled for the future
  - test mode is still on
  - email or SMS live sending is not ready
  - the reward is expired, redeemed, canceled, or already recorded for the same timing/version
- duplicate prevention remains version-aware:
  - reminder keys now include policy version when available
  - older history without versioned keys remains backward-compatible
- queued reminder dispatch remains idempotent:
  - queued jobs are unique by tenant, reward, channel, timing, and policy version
  - retries use bounded backoff
  - failed attempts can be retried without creating duplicate sent rows because reminder history is checked again at dispatch time

### Internal Support Actions
The reminder processor command supports narrow, traceable support actions without adding a new admin subsystem:
- `--dry-run` previews due reminders without writing send rows
- `--reward` scopes work to one reward identifier
- `--profile` scopes work to one customer
- `--channel` limits to email or SMS
- `--mark-skipped="reason"` records a skip instead of sending
- `--force` ignores prior reminder history for a scoped support replay

`--force` and `--mark-skipped` require `--reward` or `--profile` so support actions stay narrow and auditable.

Phase 5 also adds embedded support endpoints in the existing rewards admin flow:
- `GET|POST /shopify/app/api/rewards/policy/reminders/explain`
- `GET /shopify/app/api/rewards/policy/reminders/customer-history`
- `POST /shopify/app/api/rewards/policy/reminders/requeue`
- `POST /shopify/app/api/rewards/policy/reminders/skip`

These endpoints all reuse existing policy resolution, schedule evaluation, reminder dispatch, and audit logging. They do not introduce a separate reminder admin subsystem.

## 5) Automation, Alerts, and Team Access

### Automation Runtime State
`TenantRewardsOperationsService` stores reminder automation runtime state in the existing tenant settings layer:
- `last_run_at`
- `last_success_at`
- `last_failure_at`
- `last_status`
- `failure_count`
- `last_error_message`
- last-summary snapshot for the most recent reminder processor run

This runtime state is intentionally separate from policy version history so operational health updates do not rewrite policy versions.

### Automation Status Rules
Automation is considered operational when:
- rewards module plan access is enabled
- automation mode is `automatic`
- the rewards program is live
- test mode is off
- at least one live reminder channel is ready
- the reminder processor has run recently enough to avoid a stale-health state

When automation mode is `manual`, the hourly reminder processor skips that tenant. Reminder sends only run through a scoped operator action such as:
- `php artisan marketing:process-tenant-rewards-reminders --tenant=<id>`

New tenants and tenants without an explicit stored automation choice resolve to `manual` by default.

The embedded rewards workspace surfaces this as plain-English status:
- `Automation is running`
- `Automation is off`
- `Automation needs attention`

### Alerts
Operator alerts are derived from existing reminder reporting + finance summary state. Current alert types include:
- no reminders sent within the configured window when due reminders exist and automation mode is `automatic`
- high skip rate
- delivery failure spike
- large expiring reward volume
- liability above threshold
- automation needs attention
- text reminders turned on without a ready live SMS channel

Alert visibility is surfaced in the readiness/review workspace, and optional alert-email delivery reuses the existing SendGrid path.

### Team Access Controls
The tenant rewards workspace now supports lightweight team access rules for:
- who can edit program settings
- who can publish live changes
- who can switch automation mode
- who can use reminder support tools

This reuses the existing Backstage user role + tenant membership model and preserves Shopify-admin fallback behavior when there is no signed-in Backstage user context.

## 6) Admin UX, Review, and Publish Safety

Embedded rewards notifications/settings now provide business-language controls grouped as:
1. Program setup
2. How rewards turn into savings
3. How customers use rewards
4. Expiration and customer reminders
5. Finance and launch controls
6. Review and launch readiness

### Operator Experience
- Program summary is generated in plain English from the live tenant policy
- Risk warnings are shown inline and in review context
- Restricted fields are hidden based on service-layer metadata
- Review action produces non-persisted summary, warnings, readiness, and validation preview
- Review now shows:
  - current live version
  - pending version
  - current live summary
  - pending summary
  - plain-English change preview where feasible
- Publish continues to map to policy save in the existing architecture
- Launch readiness panel shows:
  - live/draft status
  - current policy version
  - last updated timestamp
  - reminder channel readiness
  - reminder schedule summary
  - risk counts
  - Alpha preset match state
  - customer reminder history
  - launch checklist status
  - recommended next steps before going live

### Reminder Activity Reporting
The embedded rewards notifications workspace now includes merchant-facing operational reporting for:
- reminders sent
- reminders skipped
- reminders failed
- upcoming reminders due soon
- rewards expiring soon
- channel breakdown by email vs SMS
- top skip reasons
- current live version and readiness state

The reporting surface is intentionally lightweight:
- summary cards
- recent reminder activity
- skip-reason summaries
- expiring-soon counts

Phase 5 extends reporting with:
- filters for:
  - date range
  - channel
  - status
  - reward type
- health signals for:
  - SMS not configured
  - no reminders sent recently
  - high skip rate
  - large expiring reward volume
- a lightweight impact view showing:
  - estimated reminder volume
  - estimated expiring rewards
  - estimated redemption exposure

Phase 6 extends reporting/readiness with:
- automation headline and status
- alert list
- team access summary
- commercial/module usage indicators
- policy simulation summary

### Finance Visibility
Finance visibility is intentionally lightweight and derived from the existing Candle Cash ledger/runtime state rather than a parallel accounting subsystem.

`TenantRewardsFinanceSummaryService` exposes:
- estimated outstanding rewards liability
- rewards issued
- rewards redeemed
- unredeemed reward value
- breakage estimate based on observed expired/canceled value
- expiring-soon reward value
- realized discount value

This finance summary is embedded directly into the tenant rewards policy payload so operators and finance stakeholders can evaluate exposure without leaving the current rewards workspace.

### Export Capabilities
The embedded rewards API now exposes tenant-scoped CSV exports:
- `GET /shopify/app/api/rewards/policy/exports/reminder_history`
- `GET /shopify/app/api/rewards/policy/exports/reward_issuance`
- `GET /shopify/app/api/rewards/policy/exports/reward_redemption`
- `GET /shopify/app/api/rewards/policy/exports/expiring_rewards`
- `GET /shopify/app/api/rewards/policy/exports/finance_summary`
- `GET /rewards/policy/exports/signed/{tenant}/{type}` for scheduled finance-link delivery

Exports are date-filtered and reuse existing runtime data sources:
- reminder history from `marketing_automation_events`
- issuance from `candle_cash_transactions`
- redemption from `candle_cash_redemptions`
- expiring rewards from outstanding earned reward buckets + current policy expiration logic
- finance summary from the existing Candle Cash ledger + outstanding reward analytics state

## 7) Validation and Runtime Guardrails

### Blocking Validation
- Points mode requires valid conversion (`points_per_dollar > 0`)
- Redemption increment must be greater than `0`
- Max redeemable per order must be greater than or equal to redemption increment
- Multiple reward codes per order are blocked unless platform support is explicitly enabled
- Shared reward codes are blocked when per-customer attribution is required
- Text reminders are blocked when SMS plan access is unavailable
- Reminder timing must occur before expiration for day-based expiration mode
- Scheduled launch state requires a schedule timestamp

### Non-Blocking Warnings
- No-expiration liability growth warning
- Max redeem exceeding minimum purchase warning
- Selected stacking mode without selected promo types warning
- Low fraud sensitivity warning
- Text reminders enabled without a text reminder timing warning
- Too-permissive exclusion warning when sale items and subscriptions are still included
- Channel strategy info/warning messaging when show behavior is enabled or unsupported combinations are selected

### Runtime Scheduling Guardrails
- No reminder is scheduled after expiration
- No reminder is scheduled after redemption
- No reminder is scheduled after cancellation
- Duplicate reward/channel/timing reminders are skipped
- SMS cap and quiet-period logic are enforced before a reminder is marked as due/upcoming

## 8) Notification Settings Model

Tenant notification policy fields include:
- `expiration_mode`: `days_from_issue | end_of_season | none`
- `expiration_days`
- `email_enabled`
- `sms_enabled`
- `reminder_offsets_days[]` (legacy compatibility alias)
- `email_reminder_offsets_days[]`
- `sms_reminder_offsets_days[]`
- `sms_max_per_reward`
- `sms_quiet_days`
- template fields:
  - `subject_line`
  - `preview_text`
  - `sms_body`
  - `email_headline`
  - `email_body`
  - `email_cta`

Phase 3 operator-facing behavior now separates:
- reminder email timing
- text reminder timing

This makes readiness, preview, and launch messaging more accurate without breaking older saved policy payloads.

## 9) Exclusions and Channel Strategy Controls

### Exclusions
Merchant-facing exclusions are still resolved through the existing tenant policy model and current redemption flow. The current policy UI now supports:
- wholesale
- sale items
- subscriptions
- bundles / gift sets
- limited releases
- excluded collections
- excluded product tags
- excluded products

The summary layer exposes a plain-English "what rewards do not apply to" description for operators.

### Channel Strategy
Merchant-facing channel strategy now maps to explicit business options:
- `online_only`
- `show_issued_online_redeemed`
- `exclude_shows`
- `online_show_hybrid` shown as unavailable until runtime support is confirmed

This keeps the UI honest about what is supported today while preserving current reward execution behavior.

## 10) Audit, Versioning, and Runtime Traceability

### Policy Versioning
- each policy save increments version metadata
- current version remains active
- previous versions are preserved in tenant settings
- existing rewards and redemptions are not rewritten

### Audit History
- policy changes are recorded through the existing append-only landlord/operator action audit model
- audit entries include changed fields, before/after state, actor, and version metadata where available

### Runtime Traceability
- reminder schedule preview includes the active `policy_version`
- reminder event logs persist `policy_version`
- policy/readiness payloads expose current live version and runtime traceability metadata
- support actions also store policy version, reward identifier, customer scope, and reason in the existing audit trail
- reminder exports and reporting rows surface policy version where available

This gives operators a lightweight way to understand which policy version was active when reminder behavior was generated or recorded.

## 11) Alpha Launch Preset

The Alpha starter path applies the recommended launch policy in-place through the existing tenant policy service:
- Program name: `Candle Cash`
- Display mode: `cash`
- Reward value mapping: `$1 reward = $1 savings`
- Earn rule: second order reward
- Reward amount: `$10`
- Minimum order to redeem: `$50`
- Max reward per order: `$10`
- Redemption increment: `$10`
- Expiration: `90` days
- Reminder emails: `14`, `7`, and `1` days before expiration
- Text reminder: `3` days before expiration
- Max text reminders: `1` per reward
- Stacking: `off`
- Reward code type: `unique_per_customer`
- Exclusions: wholesale, subscriptions, sale items

Operator UX now also shows:
- whether the active tenant policy still matches the Alpha starter setup
- a business-readable Alpha confirmation summary in the readiness/review workspace
- a launch checklist covering program setup, reminders, channels, exclusions, and live publish state
- recommended next steps when Alpha launch readiness is incomplete

## 12) Commercial / Monetization Readiness

The rewards workspace now exposes lightweight commercial-readiness state without adding a billing system:
- rewards module enabled state
- usage indicators for rewards issued and reminder sends
- included-limit watch/high states where commercial limits exist

This is intentionally visibility-first. Billing enforcement remains outside the rewards runtime.

## 13) Tests and Verification

Targeted rewards coverage lives in `tests/Feature/ShopifyEmbeddedRewardsTest.php` and now covers:
- tenant-scoped policy read/write behavior
- restricted field enforcement
- policy summary generation
- warning generation
- policy review and publish preview context
- audit history creation
- policy version increment behavior
- Alpha defaults behavior
- reminder scheduling generation
- reminder skip rules for expiration / redemption / cancellation
- duplicate reminder prevention
- SMS cap enforcement
- live reminder dispatch through the existing email and SMS delivery stack
- reminder send / failed / skipped logging behavior
- reminder reporting payloads and launch checklist output
- reminder explain/debug payload output
- finance summary payload output
- reminder export endpoints
- support requeue / mark-skipped / customer-history actions
- queue retry and idempotency behavior
- filterable reporting and health signal output
- stronger exclusions and channel strategy persistence
- reminder history exposure in policy payloads
- readiness panel/runtime traceability context
- automation status, alerts, and simulation output
- scheduled finance-report delivery
- signed finance export links
- signed-in team access restrictions for publish/support actions
- backward compatibility for existing embedded rewards flows

## 14) Documentation References

- README summary: `README.md` under `Tenant Rewards Policy Layer`
- Embedded routes and controllers:
  - `routes/web.php`
  - `routes/console.php`
  - `App\Http\Controllers\ShopifyEmbeddedRewardsController`
- Reminder processor command:
  - `App\Console\Commands\MarketingProcessTenantRewardsReminders`
- Queue job:
  - `App\Jobs\DispatchTenantRewardsReminderJob`
- Scheduled finance report command:
  - `App\Console\Commands\MarketingSendTenantRewardsFinanceReports`
- Core rewards policy services:
  - `App\Services\Marketing\TenantRewardsPolicyService`
  - `App\Services\Marketing\TenantRewardsPolicySummaryService`
  - `App\Services\Marketing\TenantRewardsPolicyWarningService`
  - `App\Services\Marketing\TenantRewardsPolicyMessagePreviewService`
  - `App\Services\Marketing\TenantRewardsPolicyAuditService`
  - `App\Services\Marketing\TenantRewardsPolicyReadinessService`
  - `App\Services\Marketing\TenantRewardsFinanceSummaryService`
  - `App\Services\Marketing\TenantRewardsExportService`
  - `App\Services\Marketing\TenantRewardsReminderScheduleService`
  - `App\Services\Marketing\TenantRewardsReminderLogService`
  - `App\Services\Marketing\TenantRewardsReminderDispatchService`
  - `App\Services\Marketing\TenantRewardsReminderAnalyticsService`
  - `App\Services\Marketing\TenantRewardsOperationsService`
