# Tenant Rewards Management Layer

## Feature Metadata (Required)

1. Classification: `Shared core`
2. Tenant scope: `tenant-scoped` with global fallback read behavior through existing marketing settings keys
3. Entitlement/access level: rewards module access from canonical tenant entitlement resolver; non-eligible tenants are read-only/upsell, eligible tenants can edit
4. Canonical model/service dependency:
   - `candle_cash_*` execution tables/services remain canonical (`CandleCashService`, task/redeem flows, reconciliation)
   - `tenant_marketing_settings`, `tenant_candle_cash_task_overrides`, `tenant_candle_cash_reward_overrides`
   - `TenantMarketingSettingsResolver`, `TenantModuleAccessResolver`
5. Shopify-specific hooks:
   - embedded admin routes under `/shopify/app/rewards` and `/shopify/app/api/rewards/*`
   - existing bearer-token Shopify embedded auth preserved
6. Setup/onboarding implications:
   - tenant must be mapped to store
   - tenant-scoped rewards tables must exist
   - rewards module access must be granted by plan/add-on
7. Shopify behavior preservation requirement:
   - existing wallet, code issuance, redemption lifecycle, reconciliation, and storefront routes remain unchanged
8. Non-Shopify applicability target: `later` (policy model is shared-capable, current surface is Shopify embedded admin)

## 1) Implementation Scope Document

Implemented scope in this phase:
- Added tenant rewards policy resolver/update service on top of existing Candle Cash runtime settings
- Added entitlement-aware policy API endpoints:
  - `GET /shopify/app/api/rewards/policy`
  - `PATCH /shopify/app/api/rewards/policy`
- Extended rewards payload metadata with policy + access state
- Added read-only/edit gating using existing module entitlement state
- Added business-language notifications/settings page in embedded rewards surfaces
- Added guardrail validation for core policy incompatibilities
- Added automated feature coverage for policy read/write/isolation/validation/lock behavior

Out of scope for this phase:
- New rewards execution engine
- Changes to canonical customer identity architecture
- Broad multi-tenant refactors outside existing tenant-scoped rewards storage

## 2) Data Model and Migration Plan

### Reused Existing Structures (No New Tables Required)
- `tenant_marketing_settings` stores tenant policy documents and runtime key/value config
- `tenant_candle_cash_task_overrides` for earn rule row edits
- `tenant_candle_cash_reward_overrides` for redeem rule row edits

### Policy Keyspace in `tenant_marketing_settings`
- `candle_cash_policy_config`
- `candle_cash_program_config`
- `candle_cash_frontend_config`
- `candle_cash_notification_config`
- `candle_cash_finance_config`
- `candle_cash_access_state`

### Migration Plan
- Current implementation does not require schema migration
- Existing tenants continue to resolve from global defaults until tenant settings are saved
- Backward compatibility preserved through fallback resolution order

## 3) Service Extension Plan

Primary extension:
- `App\Services\Marketing\TenantRewardsPolicyService`
  - resolves tenant policy domains into business-ready structure
  - validates compatibility/guardrails
  - persists policy into existing tenant settings keyspace
  - produces plain-English policy summary + warnings

Existing services extended:
- `ShopifyEmbeddedRewardsService`
  - exposes policy read/update via policy service
  - includes policy metadata in rewards payload
- `ShopifyEmbeddedRewardsController`
  - adds policy endpoints and edit gating
  - applies fail-closed write behavior when module access is locked
- `TenantMarketingSettingsResolver`
  - added cache flush hook for post-write consistency

## 4) Admin UX Content and IA

Embedded rewards notifications/settings surface now provides business-language controls grouped as:
1. Program setup
2. How rewards turn into savings
3. How customers use rewards
4. Expiration and reminder settings
5. Finance and launch controls

UX behavior:
- Program summary auto-generated in plain English
- Risk warnings shown inline
- Save actions blocked when tenant access is read-only

## 5) Validation Rule Matrix

Implemented validation rules:
- Points mode requires valid conversion (`points_per_dollar > 0`)
- Redemption increment must be > 0
- Max redeemable per order must be >= redemption increment
- Multiple codes per order blocked unless platform multi-code support is explicitly enabled
- Shared codes blocked when per-customer attribution is required
- SMS reminders blocked when SMS module access is unavailable
- Reminder offsets must occur before expiration window in day-based mode
- Scheduled launch state requires schedule timestamp

Warnings (non-blocking):
- No-expiration liability growth warning
- Max redeem exceeding minimum purchase warning
- Selected stacking mode without selected promo types warning
- Low fraud sensitivity warning

## 6) Notification Settings Model

Notification policy fields (tenant-scoped):
- `expiration_mode`: `days_from_issue | end_of_season | none`
- `expiration_days`
- `email_enabled`
- `sms_enabled`
- `reminder_offsets_days[]`
- `sms_max_per_reward`
- `sms_quiet_days`
- template fields:
  - `subject_line`
  - `preview_text`
  - `sms_body`
  - `email_headline`
  - `email_body`
  - `email_cta`

## 7) Alpha Client Recommended Configuration

Recommended starter policy:
- Program name: `Candle Cash`
- Mode: `cash`
- Redeem increment: `$10`
- Max redeem per order: `$10`
- Minimum purchase: `$50`
- Second-order reward: `$10`
- Code strategy: `unique_per_customer`
- Stacking: `no_stacking` (allow shipping-only only after platform-safe verification)
- Exclusions: wholesale, sale items, subscriptions enabled
- Expiration: `days_from_issue` / `90`
- Email reminders: `14,7,1`
- SMS reminders: enabled only with SMS module access, max `1` per reward
- Launch state: `published` after review sign-off

## 8) README / Documentation Updates

- Added this architecture doc as canonical implementation reference for tenant rewards management layer
- API behavior and guardrails are now covered by `ShopifyEmbeddedRewardsTest`
