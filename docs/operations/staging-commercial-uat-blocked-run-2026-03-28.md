# Staging Commercial UAT Blocked Run Record (2026-03-28)

Status: Blocked before guarded step 1.
Run type: Repository-side operator-enablement pass (no real staging guarded sequence executed).

## Scope Checked
- Guarded landlord Stripe sequence path:
  1. customer sync
  2. subscription-prep sync
  3. live subscription create/sync
- Host/runtime reachability
- Runtime config/secret availability (local + deployed)

## Checks Performed
- Local runtime keys in `.env`:
  - `APP_ENV=local`
  - `APP_URL=http://localhost:8000`
  - `TENANCY_LANDLORD_HOSTS=app.fireforgetech.test`
  - Guarded Stripe keys and Stripe secrets are not present in local `.env` for staging execution:
    - `COMMERCIAL_STRIPE_CUSTOMER_SYNC_ENABLED`
    - `COMMERCIAL_STRIPE_SUBSCRIPTION_PREP_ENABLED`
    - `COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED`
    - `STRIPE_SECRET`
    - `STRIPE_API_BASE`
- Landlord host checks (follow-up revalidation on 2026-03-28):
  - `https://app.forestrybackstage.com/login` returned `200` (reachable).
  - `https://app.forestrybackstage.com/landlord` returned `302` to `/login` (route reachable, unauthenticated).
  - `https://app.forestrybackstage.com/landlord/commercial` returned `302` to `/login` (route reachable, unauthenticated).
- Deployed runtime checks (`/home/forge/backstage.theforestrystudio.com/current`, read-only):
  - `services.stripe.secret`: configured at runtime (`present`, `sk_` prefix valid).
  - `services.stripe.publishable_key`: configured at runtime (`present`, `pk_` prefix valid).
  - `services.stripe.api_base`: `https://api.stripe.com`.
  - `commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled=true`.
  - `commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled=true`.
  - `commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled=true` (enabled for guarded step 3 run window).
- Stripe lookup-key verification probe (runtime-authenticated read-only check):
  - expected recurring lookup keys from config:
    - `tier_starter_monthly`
    - `tier_growth_monthly`
    - `tier_pro_monthly`
    - `addon_referrals_monthly`
    - `addon_sms_monthly`
    - `addon_additional_channels_monthly`
    - `addon_bulk_email_marketing_monthly`
    - `addon_future_niche_modules_monthly`
  - root-cause follow-up: previous `401` was caused by runtime placeholder Stripe key/secret values (length/pattern mismatch versus intended sandbox keys), not request formatting.
  - runtime Stripe key + secret were corrected to intended sandbox credentials and config cache was rebuilt.
  - Stripe API auth now succeeds (`/v1/account` `200`; `/v1/prices` lookup probe `200`).
  - lookup readiness result: no required recurring lookup keys were returned by Stripe for this account.
- Local config mapping check:
  - `commercial.stripe_mapping` recurring lookup-key entries for tiers/add-ons are present in config.
  - Stripe-account-side lookup-key existence is now verified as missing for all required recurring keys.
  - supplementary probe: Stripe account currently returns `0` active prices (`/v1/prices?active=true&limit=3`).

## Blocking Reasons
1. No authenticated landlord operator session/credentials were available from this environment, so guarded actions could not be executed through the landlord surface.
2. Required Stripe recurring prices/lookup keys are missing in the sandbox account, so guarded live subscription create/sync cannot satisfy staging readiness.
3. Local runtime is non-staging and does not carry staging guarded Stripe secrets/flags, so local execution cannot substitute for a real operator staging run.

## Validation Outcome
- Real staging operator validation was not completed.
- No real guarded 3-step evidence artifact (PASS/FAIL per permutation with screenshots + Stripe object references) was produced in this run.
- Host/runtime reachability for landlord routes is no longer the blocker (`/landlord` and `/landlord/commercial` now resolve and redirect to login when unauthenticated).
- Runtime guarded Stripe toggles are now loaded for the run window and Stripe sandbox authentication now works, but lookup-key readiness remains blocked because required recurring prices/lookup keys are missing.

## Next Unblock Requirements
1. Execute with a real landlord operator session on the intended staging landlord host.
2. Create/enable Stripe sandbox recurring prices with these lookup keys:
   - `tier_starter_monthly`
   - `tier_growth_monthly`
   - `tier_pro_monthly`
   - `addon_referrals_monthly`
   - `addon_sms_monthly`
   - `addon_additional_channels_monthly`
   - `addon_bulk_email_marketing_monthly`
   - `addon_future_niche_modules_monthly`
3. Re-run runtime lookup-key verification and confirm all required recurring prices resolve.
4. Keep guarded live subscription sync enabled for the evidence run (`COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED=true`) while broad lifecycle remains disabled.
5. Re-run full runbook and complete `docs/operations/staging-commercial-uat-evidence-template.md` for each required permutation.
