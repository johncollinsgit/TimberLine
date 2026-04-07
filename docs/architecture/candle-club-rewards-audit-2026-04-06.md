# Candle Club Rewards Audit (2026-04-06)

## Scope
- Request type: Shopify / rewards stabilization work
- Classification:
  - Core platform capability: rewards ledger, expiration handling, reminder delivery, redemption validation, order earning, membership eligibility resolution
  - Tenant configuration option: rewards display name, wallet copy, expiration timing, redemption cap, Candle Club multiplier settings, Candle Club free-shipping enablement, expiration SMS timing
  - Temporary tenant-specific override: none planned unless the current architecture forces a Modern Forestry-only bridge
- Tenant scope:
  - Multi-tenant by default for settings and customer-facing terminology
  - Candle Club naming can stay tenant-facing while the current implementation still uses the Modern Forestry concept internally
- Entitlement / billing model:
  - No new billing model introduced
  - SMS reminder behavior must continue to respect existing SMS channel readiness / access checks
- Canonical contracts to reuse:
  - `TenantRewardsPolicyService`
  - `CandleCashService`
  - `CandleCashOrderEventService`
  - `CandleCashTaskService`
  - `TenantRewardsReminderScheduleService`
  - `TenantRewardsReminderDispatchService`
  - `MarketingShopifyIntegrationController`
  - storefront app-proxy theme contract already consumed by `modernforestry-live-theme`
- Non-Forestry viability:
  - This work should function for non-Forestry tenants without code changes where the behavior is settings-driven
  - The only current constraint is that Candle Club membership detection is still tied to Modern Forestry’s current product / group / source-channel patterns unless a stronger subscription source is found

## Readme Guidance Applied
- Rewards trustworthiness is a current release priority.
- Laravel remains the canonical system of truth.
- Storefront behavior should continue to flow through signed app-proxy contracts.
- Do not create a parallel rewards ledger or duplicate settings store.
- Keep this work on the stabilization path rather than broad platform expansion.

## What Already Exists

### Rewards policy and admin settings
- Tenant-scoped rewards policy management already exists through `TenantRewardsPolicyService`.
- Existing settings already cover:
  - rewards program display name / terminology
  - wallet label / customer-facing copy
  - redeem increment
  - max redeemable amount per order
  - expiration mode and duration
  - email and SMS reminder enablement and timing
- Embedded rewards notifications/admin UI already exists and is backed by policy APIs.

### Reminder and SMS infrastructure
- Existing tenant-scoped reminder scheduling, dispatch, logging, analytics, and audit paths exist.
- Twilio SMS delivery already exists and is integrated through `TwilioSmsService`.
- Reminder queueing already has duplicate protection via unique jobs and reminder history checks.

### Rewards task / storefront experience
- Candle Cash task engine exists and already supports:
  - auto-awarded tasks
  - manual / pending tasks
  - Candle Club gated tasks
  - referral rewards
  - second-order reward
- Storefront central / wallet contract already exists through `MarketingShopifyIntegrationController`.
- Theme-side rewards runtime already supports celebration hooks and event logging, though the current celebration UX is lighter than requested.

### Redemption enforcement
- Storefront redemptions already enforce:
  - active reward requirement
  - balance availability
  - max open code count
  - issued-code expiry for redemptions
- The customer-facing contract already exposes redeem increment and per-order cap.

## What Is Partially Implemented

### Candle Club membership detection
- Current membership detection is heuristic and centralized in `CandleCashTaskEligibilityService::membershipStatusForProfile()`.
- Current signals include:
  - `source_channels` containing `candle_club`
  - customer group names that look like Candle Club
  - historical order lines / Shopify customer order lines containing Candle Club product text
- This is enough to identify some members, but it is not a reliable active subscription source of truth.
- I did not find a canonical active subscription status table or sync path for Candle Club membership in the rewards domain.

### Expiration support
- Rewards policy and reminder logic already model expiration timing.
- Redemptions themselves carry `issued_at`, `expires_at`, and status.
- Earned Candle Cash transactions do not currently carry per-entry expiration metadata in the canonical ledger path.
- Current balance resolution still relies on `candle_cash_balances`, so expired earned buckets are not being netted out centrally.

### Review reward behavior
- Native product review rewarding exists.
- Automatic Google review rewarding exists.
- Current dedupe prevents exact duplicates or reuse by configured product/order matching mode.
- Review reward cadence is now partially upgraded:
  - first rewarded Google review in a 7-day window now earns Candle Cash
  - first rewarded website product review in a 7-day window now earns Candle Cash
  - additional reviews in the same 7-day window remain allowed but do not mint additional Candle Cash
  - the weekly cadence is currently task-driven via `candle_cash_tasks` metadata / completion rules

## What Is Missing

### Active membership source of truth
- I have not found a canonical active Candle Club subscription state that can reliably distinguish active vs canceled membership.
- Without additional persisted membership state or subscription sync input, cancellation-aware 2x eligibility cannot be considered fully implemented today.

### Automatic member multiplier for qualifying orders
- I did not find a true order-based Candle Cash earning path that automatically grants routine order-earned Candle Cash and applies a membership multiplier.
- `CandleCashOrderEventService` currently awards milestone / join / referral flows, not a general qualifying-order earn flow.
- The current Shopify order ingest path stores useful totals (`subtotal_price`, `discount_total`, `shipping_total`, `total_price`) but does not yet persist a canonical Shopify financial / cancellation status that would let the rewards engine safely decide “qualifying paid order” from current order rows alone.

### Free shipping for active members
- I did not find an existing Candle Club member free-shipping implementation in the current Shopify reward path.
- Birthday free-shipping reward support exists, but that is a different mechanism.

### Earned-ledger expiration enforcement
- Per-earned-entry `earned_at` + `expires_at` + status handling is missing from earned Candle Cash transactions.
- There is no canonical earned-entry expiry job currently reducing usable balance while preserving full ledger history.

### Admin controls for member boosts
- This was missing at audit start.
- The canonical rewards policy service and embedded rewards admin UI now expose:
  - Candle Club multiplier enabled/disabled
  - Candle Club multiplier value
  - free shipping for active Candle Club members enabled/disabled
- Runtime enforcement for those settings is still pending.

### Premium celebration modal
- Theme runtime already has celebratory hooks and confetti, but not the requested premium center-screen modal with backdrop blur, boosted/member-specific messaging, and a purely celebratory “Claim Cash” interaction.

## Important Rules / Assumptions Confirmed During Audit
- Legacy converted points that became Candle Cash must remain non-expiring.
- Expiration should apply only to true earned reward entries moving forward and to any historical earned entries that are explicitly safe to classify as expirable.
- Imported / grandfathered opening balances should remain exempt from expiration. Existing ledger normalization already has exempt/grandfathered concepts that can be reused.
- Internal review and Google review rewards should allow unlimited submissions, but only the first review in the weekly window should award Candle Cash.

## Planned Implementation Direction
- Extend the canonical Candle Cash transaction ledger instead of creating a second earned-balance system.
- Add earned-entry expiration metadata and status handling in a backward-safe way.
- Keep legacy imported / grandfathered balances non-expiring by treating them as expiration-exempt.
- Centralize membership resolution in a dedicated service or stronger canonical helper rather than scattering checks.
- Add tenant-scoped Candle Club settings through the existing rewards policy/admin path.
- Reuse the existing reminder/SMS infrastructure for upcoming expiration notices.
- Extend storefront payloads so wallet/central messaging uses settings-driven terminology and expiration / redemption-cap copy.
- Upgrade the existing theme celebration layer into the requested modal flow rather than introducing a separate one-off widget.

## Unresolved Gaps To Document Honestly
- Unless a hidden subscription sync source is discovered during implementation, the app cannot honestly claim perfect active-membership truth today.
- If no canonical Shopify subscription source exists in the current codebase, the safest implementation path may require persisting a membership status snapshot from the available signals and documenting that as an interim bridge.

## Change Log
- Initial audit completed before implementation.
- Implemented a browser-safe Google review launch flow in the storefront theme so the review window opens synchronously and is less likely to be swallowed by popup blocking.
- Implemented a weekly review reward window for `google-review` and `product-review` tasks by updating canonical task eligibility logic and task configuration.
- Preserved the rule that legacy converted / grandfathered Candle Cash remains non-expiring; no expiration logic has been applied to imported opening balances.
- Centralized Candle Club membership resolution into a dedicated service and expanded the current signal set to include external profile `vip_tier` / `source_channels` alongside existing group and order-history heuristics.
- Updated the policy-layer default expiration window from 30 days to 90 days wherever the tenant has not explicitly saved a different reward expiration setting.
- Added tenant-aware Candle Club multiplier and free-shipping controls to the canonical rewards policy admin flow and surfaced the saved values through `CandleCashService` runtime config for later enforcement work.
- Extended the storefront rewards contract so balance / history / central payloads now include settings-driven expiration messaging, redemption-cap messaging, and Candle Club benefit state for frontend wallet / status experiences.
- Simplified the customer-facing task presentation in the theme by:
  - hiding the redundant `referred-friend-bonus` earn card from Central while preserving the underlying referral automation
  - removing internal-looking task eyebrow/category labels like `Verified review`
  - renaming the product review card to plain-language Modern Forestry website copy
- Additional requested UI restructuring is still pending:
  - standardized desktop card sizing/grid
  - split `Tasks` and `Status` tabs in Central
  - move member messaging, task history, and saved reward codes under `Status`
