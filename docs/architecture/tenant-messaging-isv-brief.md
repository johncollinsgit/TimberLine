# Codex build brief — Everbranch Tenant Messaging (ISV / platform‑as‑sender)

> Implementation brief for an AI coding agent working on the Everbranch monolith
> (`Code/myapp`, Laravel 12 + Livewire + Pest). Goal: let tenants send Email + SMS
> **through Everbranch** without ever touching Twilio/SendGrid — Everbranch is the
> platform of record (ISV model): it provisions an isolated per‑tenant sub‑account
> programmatically, handles A2P/domain compliance behind a friendly form, meters
> usage, and charges per message. Replaces today's single global Twilio + shared
> SendGrid fallback.

## Non‑negotiable guardrails (read first)
1. **Never break the flagship (tenant 1 / Modern Forestry) live sending.** Everything
   is additive and behind feature flags that default **off**. Flagship email/SMS must
   behave identically when the flags are off.
2. **Enforce tenant isolation.** Every new table uses `tenant_id` + the
   `App\Models\Concerns\BelongsToTenant` trait (global `TenantScope` + `HasTenantScope`
   helpers). No query may read another tenant's messaging accounts, credits, or logs.
   Add cross‑tenant guardrail tests.
3. **Gate at the request layer.** New modules go in `config/module_catalog.php`
   (canonical) and are enforced with the existing `module:{key}` middleware
   (`App\Http\Middleware\EnsureModuleAccess`).
4. **No secrets in code or git.** Read provider credentials from config/env. Use HTTP
   fakes / sandbox creds in tests. Never commit real Twilio/SendGrid keys.
5. **The full Pest suite must stay green** (it's the CI deploy gate on `main`). Add
   tests for every new path.
6. **Reuse existing infrastructure — do not reinvent:**
   - `App\Models\IntegrationConnection` + `App\Services\Integrations\ConnectionManager`
     + `App\Services\Integrations\Contracts\ProviderConnector` (the per‑tenant,
     encrypted provider‑connection store — extend this pattern).
   - `TenantUsageCounter`, `MarketingEmailDelivery`, `MarketingMessageDelivery`,
     `MarketingMessageJob`, `MessagingConversation`, `MessagingConversationMessage`
     (metering + per‑send records already exist and are tenant‑scoped).
   - `TenantEmailSetting` (the clean per‑tenant email pattern).
   - `LandlordCommercialConfigService`, `config/module_catalog.php`,
     `TenantModuleAccessResolver` (commercial/entitlement layer).
   - `config/services.php` (existing `twilio` + `sendgrid` keys).
   - See `docs/architecture/module-standardization-and-readiness-2026-07-07.md`.

## Target architecture

### 1. Provider abstraction
Add a `MessagingProvider` layer with two responsibilities — **provisioning** and
**sending** — implemented per channel:
- **SMS → Twilio (ISV):** one master account holds a **Subaccount per tenant**
  (isolates numbers/usage/billing), plus **A2P 10DLC** Brand + Campaign registered
  via the **Trust Hub API** (secondary customer profiles for ISVs), and a
  **Messaging Service** per tenant bundling their number(s) + campaign.
- **Email → SendGrid (ISV):** one master account holds a **Subuser per tenant**
  (isolates sending reputation + stats) plus an **authenticated sending domain**.
  (Note for the owner: Subusers require a SendGrid Pro+ plan — flag as a decision.)
  Amazon SES (config sets + per‑domain verification) is the cheaper alternative;
  keep the provider swappable behind the interface.

Persist per‑tenant provider identity in a new `tenant_messaging_accounts` table
(BelongsToTenant): `channel`, `provider`, `subaccount_sid`/`subuser_id`,
encrypted credentials, `registration_status` (pending/approved/failed/suspended),
`sender_id`/`from_domain`, `metadata`. Encrypt tokens like `IntegrationConnection`.

### 2. Provisioning / compliance flow (the "one app to rule them all" part)
A **domain‑neutral** onboarding form in Everbranch collects: legal business name,
tax id/EIN, address, contact, desired sending domain, use‑case description, sample
messages, and opt‑in details. On submit, run **async jobs**:
- **Twilio:** create Subaccount → create Trust Hub Customer Profile → submit A2P
  Brand → submit A2P Campaign → create Messaging Service → provision number.
  Track status via webhooks/polling. Surface a "SMS pending carrier approval"
  state — the tenant can send **email immediately** while A2P is pending.
- **SendGrid:** create Subuser → create authenticated domain → return the DNS
  records for the tenant to add → verify (auto‑poll for completion).

All statuses surfaced in the tenant UI and in a landlord oversight view. The tenant
**never** sees a Twilio/SendGrid console.

### 3. Metering + prepaid credits + enforcement
- Every send writes a usage/ledger event (extend the existing delivery records +
  `TenantUsageCounter`) with `tenant_id`, `channel`, `segments`, `provider_cost`,
  `price_charged`.
- New per‑tenant **prepaid credit wallet** (`tenant_message_credits` + a ledger).
  Sends decrement it; when insufficient, refuse/queue with a clear error. (Stripe
  metered billing can come later — start with prepaid credits.)
- **Pricing** in config (per‑email, per‑SMS‑segment) with margin over provider cost;
  leave the numbers as TODO placeholders for the owner to set.
- An enforcement service/middleware refuses sends when over limit or suspended, and
  attributes provider cost per tenant for margin reporting.

### 4. Entitlement + channel wiring
- Add modules `messaging_email` and `messaging_sms` to `config/module_catalog.php`
  with plan gating; apply the `module:{key}` gate to the messaging routes.
- Refactor the current global `sendSms()` to be **tenant‑aware** (route through the
  tenant's Subaccount/Messaging Service) — behind the flag, flagship path unchanged
  when off.

## Phased delivery (each phase independently shippable + tested)
1. **Data model + provider interface** — tables (`tenant_messaging_accounts`,
   `tenant_message_credits` + ledger), models (BelongsToTenant), `MessagingProvider`
   interface, Twilio/SendGrid connector **stubs (mocked, no live calls)**,
   ConnectionManager integration. Isolation + unit tests.
2. **Metering + prepaid wallet + enforcement service.** Tests.
3. **SendGrid Subuser + domain‑auth provisioning** (real API behind flag; HTTP‑fake
   tests). Tenant DNS‑record UI + verification polling.
4. **Twilio Subaccount + A2P registration** (Trust Hub API) — the compliance core.
   Async jobs, status tracking, webhooks. HTTP‑fake tests. This is the hardest phase.
5. **Tenant onboarding UI** (domain‑neutral forms + status dashboards) + landlord
   oversight/console.
6. **Pricing/billing config + landlord cost reconciliation + reporting.**

Start with Phase 1 and stop for review before Phase 3 (the first phase that talks to
a real provider API).

## What Codex must NOT do (needs a human)
- Do not invent or hardcode real Twilio/SendGrid credentials; use env + fakes.
- Do not submit real A2P/production registrations — keep it behind a flag defaulting
  off and use Twilio **test credentials / sandbox** in tests.
- Do not set final pricing tiers — leave config placeholders + a `TODO(owner)`.
- Do not write legal/compliance copy (opt‑in language, ToS) — stub it and flag for
  legal review.

## Acceptance criteria
- Full Pest suite green; new paths covered (provisioning + send via HTTP fakes).
- All new feature flags default off; flagship sending byte‑for‑byte unchanged when off.
- Isolation tests prove tenant A cannot read/use tenant B's messaging accounts,
  credits, numbers, or logs.
- No secrets committed; `config:doctor` still passes.
- New modules gated via `config/module_catalog.php` + the `module:{key}` middleware.
