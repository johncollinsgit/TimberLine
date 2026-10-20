# Fictional Ask Everbranch pilot

## October 20 real-model authorization

The owner subsequently approved real AI for this existing named pilot with a
**$5 total lifetime provider cap**. See
`ai-voice-pricing-and-rollout.md` for current controls and the separate ordinary
tenant pricing policy. `--provider=openai --budget-dollars=5.00` explicitly records
that mode, current provider settings and the cap. Omitting those options never
silently activates a paid provider. A real-model renewal requires the explicit
cap again and does not reset existing usage. The exact grant and current model
configuration are rechecked immediately before dispatch. Synthetic practice has
no customer credit debit, subscription or invoice. The historical zero-dispatch
description below applies to scripted mode only. Protected maintenance workflow
now accepts the explicit provider and total-cap inputs.

## Original scripted mode

The user selected a fictional workspace and named an existing account to own it.
Production web navigation was deployed through
PR 417 at `f15fc65`; the full Linux suite passed 3,609 tests / 24,893 assertions,
one skipped, and MySQL recovery/formatting passed. This follow-up adds a genuine
synthetic workspace for trying the same scoped note/task/report services, with
scripted answers instead of a paid provider. Native distribution remains deferred.

## Access and provider boundary

No grant means no pilot. Privileged console provisioning records an append-only
`ai_voice.pilot.grant` containing one existing verified owner, scripted provider,
synthetic-only marker and seven-day expiry. The fixed slug is
`everbranch-voice-pilot`; an existing unrelated workspace cannot be taken over.
The access profile must have the owned source `fictional_voice_pilot`, and current
active owner membership and verified email are rechecked. Other owner members
do not inherit the named owner's grant. A later revoke, expiry, changed membership
or `EVERBRANCH_AI_FICTIONAL_PILOT_DISABLED=true` denies access.

The ordinary paid AI rules remain unchanged for real workspaces. The reserved
pilot never falls through to paid AI, even if someone later adds billing records.
It creates no `TenantAccessAddon` or `TenantBillingSubscription`, calls no Stripe
API and changes no existing customer's billing. Canonical trial module grants
apply only to the new fictional workspace and are audited/expiring.

`EnsureTenantAccess` applies pilot configuration only inside the authorized web
request and restores all prior values in `finally`. There is no global AI/context
rollout change and no queue-wide HTTP fake. The provider checks the fresh grant
again before returning a deterministic scripted response. No key/model/rate is
borrowed from production and there is no network path in the scripted response.
Usage has zero tokens/cost/charge and `billing_scope=fictional_no_charge`; this is
not provider billing or answer-quality evidence. Requests remain bounded by the
existing per-workspace allowance and encrypted private chat/memory retention.

The panel displays a persistent fictional/scripted disclosure. Approved notes and
tasks actually change this synthetic workspace through the existing reviewed
services; replies alone execute nothing. Phone practice remains scripted, including
in this precisely granted production pilot. Finance stays unavailable because no
QuickBooks connection/report is fabricated. Do not enter real customer information.

## Provision, verify, revoke

`everbranch:prepare-voice-pilot --owner-email=EXISTING_VERIFIED_EMAIL` previews
without writes. `--apply --authorization=APPROVAL_REFERENCE` creates or renews
the grant; production additionally requires `--force-production`. Renewal preserves
existing work and extends the pilot-owned module grants, without resetting the
owner's account opt-in or overriding a different profile source. `--revoke --apply`
appends a revocation while retaining fictional records and execution evidence.
No password/account is created, changed, emailed or printed.

Use protected workflow `maintenance-provision-voice-pilot.yml` after the exact
code release is deployed. It requires `main`, production environment access,
existing deployment secrets and `confirm_provision=true`, checks `/ready` for its
exact SHA, then previews and applies the fixed command over the established SSH
maintenance path. It never changes deployment code, `.env`, provider credentials
or configuration caches. The run ID is the approval/audit reference.

After success, sign in and open
`/dashboard?tenant=everbranch-voice-pilot`, then **Ask Everbranch**. First ask for
sample work and inspect source links. Prepare an internal note, inspect the exact
preview, approve once, inspect its receipt and try the supported unchanged-note
undo. For a task, select the seeded promise, explicitly choose yourself and a due
date/time, then review and approve its separate preview. Inspect attention/report
coverage and scripted phone-practice outcomes. Do not treat scripted output as
natural-language intelligence, call quality or production accounting acceptance.

Acceptance includes preview/no-write, no fake billing, zero HTTP dispatch,
approval replay, wrong-owner/workspace denial, kill/revoke/expiry, no live-provider
fallback, preserved renewal data and restoration of request-local flags. Protected
Linux/MySQL gates and exact-SHA readiness still govern deployment. No migration.
