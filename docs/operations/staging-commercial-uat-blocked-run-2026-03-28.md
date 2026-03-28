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
  - `services.stripe.secret`: not configured at runtime (`stripe_secret_present=no`).
  - `services.stripe.api_base`: `https://api.stripe.com`.
  - `commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled=true`.
  - `commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled=true`.
  - `commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled=false`.
- Local config mapping check:
  - `commercial.stripe_mapping` recurring lookup-key entries for tiers/add-ons are present in config.
  - Stripe-account-side lookup-key existence cannot be verified without runtime Stripe credentials.

## Blocking Reasons
1. No authenticated landlord operator session/credentials were available from this environment, so guarded actions could not be executed through the landlord surface.
2. Runtime Stripe secret is not configured (`services.stripe.secret` missing), so guarded Stripe mutation steps cannot run safely.
3. Guarded live subscription sync is still disabled at runtime (`COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED=false`), so step 3 cannot be executed as required for evidence PASS.
4. Local runtime is non-staging and does not carry staging guarded Stripe secrets/flags, so local execution cannot substitute for a real operator staging run.

## Validation Outcome
- Real staging operator validation was not completed.
- No real guarded 3-step evidence artifact (PASS/FAIL per permutation with screenshots + Stripe object references) was produced in this run.
- Host/runtime reachability for landlord routes is no longer the blocker (`/landlord` and `/landlord/commercial` now resolve and redirect to login when unauthenticated).

## Next Unblock Requirements
1. Execute with a real landlord operator session on the intended staging landlord host.
2. Configure runtime Stripe credentials for staging (`services.stripe.secret` with valid `sk_` key).
3. Enable guarded live subscription sync for the evidence run (`COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED=true`) while keeping broad lifecycle disabled.
4. Verify Stripe-account lookup keys resolve for assigned plan/add-on permutations using the configured staging credentials.
5. Re-run full runbook and complete `docs/operations/staging-commercial-uat-evidence-template.md` for each required permutation.
