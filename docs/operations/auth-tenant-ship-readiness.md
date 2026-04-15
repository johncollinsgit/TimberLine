# Auth Tenant Ship Readiness

Date: 2026-03-26

## Manual Validation Checklist (Browser)

Pre-checks:
- confirm test users exist with known tenant membership
- clear browser cookies or use separate incognito windows per scenario
- keep `storage/logs/laravel.log` open while testing

1. Modern Forestry password login
- open `https://app.theeverbranch.com/login` (canonical)
- login with an active user who is a member of `modern-forestry`
- expect successful login and normal landing page
- check logs:
  - `auth.tenant_context.resolved` with `classification=flagship`
  - `auth.post_login.redirect_decision` with `auth_method=password`
  - strategy should be `tenant_intent` (or `intended_url` if you purposely set an intended URL)

2. Modern Forestry Google login
- open `https://app.theeverbranch.com/login` (canonical)
- click `Continue with Google` and finish consent
- expect successful login and normal landing page
- check logs:
  - `auth.google.oauth.callback_success`
  - `auth.post_login.redirect_decision` with `auth_method=google`
  - strategy should be `tenant_intent` (or `intended_url` when applicable)

3. Non-flagship tenant password login
- open tenant host login page, e.g. `http://acme.theeverbranch.test/login` (local) or `https://acme.theeverbranch.com/login` (canonical production pattern)
- login with user who is a member of that tenant
- expect successful login and tenant-correct landing
- check logs:
  - `auth.tenant_context.resolved` with `classification=generic`
  - `auth.post_login.redirect_decision` strategy `tenant_intent` (or `intended_url`)

4. Non-member hitting tenant-branded login
- open tenant host login page, e.g. `http://acme.theeverbranch.test/login` (local) or `https://acme.theeverbranch.com/login` (canonical production pattern)
- login with user who is not a member of that tenant
- expect successful auth but safe fallback landing (not tenant-forced)
- check logs:
  - `auth.post_login.redirect_decision`
  - `tenant_intent_exists=true`
  - `tenant_membership_passed=false`
  - strategy `role_fallback` (or `safe_fallback` if role home is empty)

5. Google consent canceled by user
- open tenant-branded login
- click Google login and cancel consent at Google
- expect redirect back to login with user-safe error
- check logs:
  - `auth.google.oauth.callback_failure` warning
  - `phase=callback`
  - `attempt=provider_error_query`
  - current failure class is expected to be `unknown_oauth_failure` for `error=access_denied`
- then login via password and confirm there is no cross-tenant escalation

6. Logout then login on a different tenant
- login to tenant A
- logout
- open tenant B login and authenticate with a user who has tenant B access
- expect tenant B landing context (no carryover from tenant A)
- check logs:
  - new `auth.tenant_context.resolved` for tenant B host
  - post-login strategy should use tenant B intent (or intended URL)

7. Password reset continuation path
- start from tenant-branded `forgot-password`
- open reset link from email and complete reset (including cross-host reset if applicable)
- login after reset
- expect tenant intent is honored only if membership passes
- check logs:
  - `auth.tenant_context.resolved` around `password.reset`, `password.update`, `login.store`
  - `auth.post_login.redirect_decision` with safe strategy selection

8. Fallback when tenant cannot be resolved
- open `http://unknown.local/login` (or another unresolved host)
- expect request rejected (`404`) due canonical runtime host guard

## Release Note (Operator-Facing)

What changed:
- pre-auth tenant context resolution now runs on auth entry routes
- tenant intent persists through password login, 2FA challenge, Google callback flow, and password-reset continuation
- post-login landing now validates tenant membership before applying tenant intent
- redirect decisions are logged with explicit strategy classification

What is now tenant-aware:
- login entry context (`flagship`, `generic`, `none`)
- tenant-aware post-login redirect decisions
- membership-gated tenant landing behavior

What remains globally scoped:
- Google OAuth credentials (`services.google.*`) remain global
- no per-tenant Google client ID/secret selection yet

Key logs when tenant landing looks wrong:
- `auth.tenant_context.resolved`
- `auth.post_login.redirect_decision`
- `auth.google.oauth.callback_success`
- `auth.google.oauth.callback_failure`
- `auth.google.oauth.preflight_failed`

Expected redirect strategies:
- `intended_url`
- `tenant_intent`
- `role_fallback`
- `safe_fallback`
