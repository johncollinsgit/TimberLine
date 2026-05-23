# Everbranch Shopify Readiness Audit

Status: PR 19 screenshot/operator evidence checklist prepared.
Date: 2026-05-21.

## Mission

Keep Shopify as the flagship integration path while proving what is ready in code, what must be verified in the Shopify Partner Dashboard, and what remains blocked before App Store review.

## Current Shopify App Identity From Code

Source: `shopify.app.toml`.

- Client ID is present in TOML.
- App name is still `Modern Forestry Backstage`.
- Handle is still `modernforestrybackstage`.
- Embedded app is enabled.
- Dev store is `modernforestry.myshopify.com`.
- API version is `2026-01`.

PR 17 operator confirmation:
- The Shopify Partner/dev dashboard app to use is currently named `Modern Forestry Backstage`.
- The current TOML handle is `modernforestrybackstage`.
- The current dev store is `modernforestry.myshopify.com`.
- Use `Modern Forestry Backstage`, not Everbranch, when looking in Shopify for the current evidence pass.

PR 18 evidence capture:
- `shopify app info` confirmed the CLI-linked app is `Modern Forestry Backstage`, client ID `197d01d6597c938c96b3b35fae6a087c`, dev store `modernforestry.myshopify.com`, and the broad TOML access scope set.
- Live app proxy health was partially captured: direct unsigned canonical route returns `401` with missing signature headers, while `https://theforestrystudio.com/apps/forestry/health` returns `200` JSON through the Shopify storefront app proxy.
- No Shopify app deploy/release was run.
- No webhook trigger was sent.
- Partner Dashboard screenshots and dev-store install/reinstall evidence remain pending.

PR 19 evidence finalization prep:
- `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` defines required screenshot slots and expected values.
- `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md` gives the operator a step-by-step capture sequence.
- Partner Dashboard evidence remains pending until screenshots or written operator verification are attached.
- Deploy/release remains blocked until the operator explicitly approves the exact command and expected effect.

Branding caveat:
- Everbranch is the platform brand in Laravel product surfaces.
- Modern Forestry remains the flagship tenant/customer context.
- Shopify Partner Dashboard app name/handle/icon/listing must be deliberately reviewed before public App Store submission. Do not rename Shopify app identity casually inside a readiness PR because that can affect installs, review state, extensions, and Partner Dashboard continuity.
- PR 17 confirms the current internal/alpha Shopify app identity as `Modern Forestry Backstage`; public Everbranch Shopify app branding remains a later decision.
- `docs/operations/shopify-scope-branding-decision-record.md` keeps the scope and public branding decision explicit.

## Canonical URLs

Source: `shopify.app.toml`, `routes/web.php`, `ShopifyAuthController`, and `ShopifyWebhookSubscriptionService`.

| Surface | Expected canonical value | Code status |
| --- | --- | --- |
| App URL | `https://app.theeverbranch.com/shopify/app` | Present in `shopify.app.toml`; embedded route exists as `shopify.app`. |
| OAuth redirect: retail | `https://app.theeverbranch.com/shopify/callback/retail` | Present in TOML; runtime OAuth emits canonical `app.theeverbranch.com`. |
| OAuth redirect: wholesale | `https://app.theeverbranch.com/shopify/callback/wholesale` | Present in TOML. |
| Reinstall route | `/shopify/reinstall/{store}` | Exists and delegates to the same auth flow. |
| App proxy URL | `https://app.theeverbranch.com/shopify/marketing/v1` | Present in TOML. |
| App proxy prefix/subpath | `/apps/forestry` | Present as `prefix = "apps"` and `subpath = "forestry"`; legacy Forestry subpath is intentional until Shopify proxy/listing migration is planned. |
| Embedded start | `/shopify/app/start` | Present. |
| Embedded App Store | `/shopify/app/store` | Present. |
| Embedded integrations | `/shopify/app/integrations` | Present. |

Runtime host guardrail:
- Tests verify Shopify OAuth does not emit `app.grovebud.com` or `app.forestrybackstage.com`.
- Legacy hosts should remain edge redirects only, not Laravel-accepted runtime hosts.

## Requested Scopes

Runtime default from `config/services.php`:

```text
read_products,read_orders,read_all_orders,read_reports,read_analytics,read_customers,write_customers,read_discounts,write_discounts,read_pixels,write_pixels,read_customer_events
```

`ShopifyOAuth` also ensures these are present:
- `read_customers` unless `write_customers` is already present.
- `read_pixels`
- `write_pixels`
- `read_customer_events`

TOML currently lists a much broader scope set than the Laravel runtime default. This is a readiness risk, not a PR 9 behavior change.

Required follow-up:
- Compare Partner Dashboard scopes, TOML scopes, and `SHOPIFY_SCOPES`.
- Remove or justify broad scopes before public App Store review.
- Confirm customer data scopes are reflected in privacy policy, data handling docs, and app listing copy.
- PR 15 documents the current scope matrix and recommendation in `docs/operations/shopify-scope-branding-decision-record.md`. No scopes were changed.

## Webhook Inventory

Configured required webhook topics in `config/shopify_webhooks.php`:

| Topic | Route name | Route path |
| --- | --- | --- |
| `orders/create` | `shopify.webhooks.orders.create` | `/webhooks/shopify/orders/create` |
| `orders/updated` | `shopify.webhooks.orders.updated` | `/webhooks/shopify/orders/updated` |
| `orders/cancelled` | `shopify.webhooks.orders.cancelled` | `/webhooks/shopify/orders/cancelled` |
| `refunds/create` | `shopify.webhooks.refunds.create` | `/webhooks/shopify/refunds/create` |
| `customers/create` | `shopify.webhooks.customers.create` | `/webhooks/shopify/customers/create` |
| `customers/update` | `shopify.webhooks.customers.update` | `/webhooks/shopify/customers/update` |

Evidence:
- `ShopifyWebhookSubscriptionService::requiredTopicsWithCallbacks()` generates canonical `https://app.theeverbranch.com/...` callbacks.
- `ShopifyWebhookSubscriptionEnforcementTest` covers verification, drift detection, and repair behavior for configured webhooks.
- OAuth callback invokes webhook enforcement after token persistence.

## Privacy Webhook Status

PR 11 adds conservative, HMAC-verified privacy webhook endpoints and durable evidence records. These handlers do not perform destructive deletion/redaction yet; every valid event is recorded with a payload hash, minimal non-sensitive summary, and `manual_review_required` status.

| Mandatory privacy topic | Code status | Readiness decision |
| --- | --- | --- |
| `customers/data_request` | Route `shopify.webhooks.customers.data-request` at `/webhooks/shopify/customers/data-request`; handled by `ShopifyPrivacyWebhookController::customersDataRequest` | Ready for local code evidence; Partner Dashboard/CLI deployment evidence still required. |
| `customers/redact` | Route `shopify.webhooks.customers.redact` at `/webhooks/shopify/customers/redact`; handled by `ShopifyPrivacyWebhookController::customersRedact` | Ready for local code evidence; manual review/redaction policy still required before automation. |
| `shop/redact` | Route `shopify.webhooks.shop.redact` at `/webhooks/shopify/shop/redact`; handled by `ShopifyPrivacyWebhookController::shopRedact` | Ready for local code evidence; manual review/shop data policy still required before automation. |

HMAC behavior:
- `ShopifyWebhookVerifier` verifies `X-Shopify-Hmac-Sha256` against the raw request body using the configured Shopify client secret candidates.
- Missing or invalid HMAC requests return `401` and are not recorded.
- Unexpected topics or invalid JSON payloads return `422` and are not recorded.

Evidence storage:
- `shopify_privacy_webhook_events` stores topic, shop domain, webhook ID when present, payload SHA-256 hash, minimal `payload_summary`, status, action-required flag, handled timestamp, review timestamp, and notes.
- `payload_summary` includes non-secret identifiers such as shop domain, shop/customer/order IDs, webhook headers, and hashed email/phone values when present.
- Full raw payloads are not stored by the PR 11 handler.

Current conservative handling policy:
- `customers/data_request`: record evidence and mark manual review required.
- `customers/redact`: record evidence and mark manual review required.
- `shop/redact`: record evidence and mark manual review required.
- No destructive customer, shop, tenant, order, or marketing-profile deletion/redaction runs in PR 11.

TOML/App-specific subscription status:
- `shopify.app.toml` includes app-specific compliance subscriptions for all three topics using canonical `https://app.theeverbranch.com/webhooks/shopify/...` endpoint URLs.
- These compliance topics are also listed under `config/shopify_webhooks.privacy_topics` for local route/test evidence.
- They are intentionally not included in `config.shopify_webhooks.required_topics` because the existing `ShopifyWebhookSubscriptionService` verifies and repairs shop-specific operational webhooks through the Admin API.

External evidence status:
- PR 12 adds `docs/operations/shopify-partner-dashboard-evidence-runbook.md` with exact Partner Dashboard, Shopify CLI deploy, privacy webhook trigger, dev-store install/reinstall, app proxy, privacy review, scope review, and evidence storage steps.
- PR 15 adds dated evidence packet `docs/operations/evidence/shopify/2026-05-21/README.md`.
- Local Shopify CLI is present (`3.92.1`), help output for deploy/webhook trigger commands has been inspected, and PR 18 captured `shopify app info` for the confirmed target app.
- No Shopify app deploy/release or webhook trigger was run in PR 15 or PR 18.
- Live Shopify CLI deployment/version release evidence is still pending.
- Partner Dashboard screenshots are still pending.
- Dev-store privacy webhook delivery rows in production/staging `shopify_privacy_webhook_events` are still pending.

## Embedded App Surfaces

Current embedded routes include:

- `/shopify/app`
- `/shopify/app/start`
- `/shopify/app/plans`
- `/shopify/app/store`
- `/shopify/app/integrations`
- `/shopify/app/customers/*`
- `/shopify/app/rewards/*`
- `/shopify/app/assistant/*`
- `/shopify/app/messaging/*`
- `/shopify/app/settings`

Embedded App Store status:
- Uses the canonical module catalog and PR 7 display-only metadata.
- Shows safe module context and passive pricing language.
- Explicitly says checkout is not active.
- Existing module activation/request paths are still guarded by embedded context and tenant/store mapping; PR 9 did not change them.

## App Proxy Expectations

Partner Dashboard expected values:

- App proxy URL: `https://app.theeverbranch.com/shopify/marketing/v1`
- Prefix: `apps`
- Subpath: `forestry`
- Storefront URL shape: `/apps/forestry/...`

Code routes:
- `/shopify/marketing/v1/*` and `/shopify/marketing/*` are served by `MarketingShopifyIntegrationController`.
- Requests are guarded by `marketing.storefront.verify`.
- `MarketingStorefrontRequestVerifier` supports Shopify app-proxy signatures when `marketing.shopify.app_proxy_enabled` and a proxy secret are configured.

Manual evidence still required:
- Confirm Partner Dashboard app proxy URL/prefix/subpath match TOML.
- Confirm Partner Dashboard app proxy URL/prefix/subpath match the live behavior captured in `docs/operations/evidence/shopify/2026-05-21/app-proxy-evidence.md`.
- Confirm proxy signature validation succeeds on a dev store.
- Capture browser/screenshot evidence for `/apps/forestry/health` from the dev-store or primary storefront.

## Install And Reinstall Expectations

Code expectations:
- `/shopify/auth/{store}` starts OAuth for configured store keys.
- `/shopify/reinstall/{store}` delegates to the same auth flow.
- `/shopify/callback/{store}` validates state, HMAC, expected shop domain, token exchange, scope capture, `shopify_stores` persistence, alpha bootstrap, pixel cache flush, and webhook enforcement.
- Store-to-tenant mapping is derived from `shopify_stores.store_key -> tenant_id` through tenant resolver services.

Manual dev-store evidence required:
1. Install from Partner Dashboard/dev link using a clean dev store.
2. Confirm OAuth redirect uses `https://app.theeverbranch.com/shopify/callback/{store}`.
3. Confirm callback creates or updates exactly one `shopify_stores` row for the store key.
4. Confirm tenant mapping is deterministic and not inferred from an unknown host or first tenant.
5. Confirm embedded app opens at `/shopify/app`.
6. Confirm `/shopify/app/start`, `/shopify/app/store`, and `/shopify/app/integrations` render.
7. Run `php artisan shopify:webhooks:verify --required-only`.
8. Run reinstall and confirm it updates the existing store record idempotently.
9. Confirm app proxy health works from storefront.
10. Capture screenshots/log snippets for Partner Dashboard, redirect URL, embedded app open, webhook verification, and reinstall.

## Partner Dashboard Checklist

Must be manually verified before App Store review:

- App URL: `https://app.theeverbranch.com/shopify/app`.
- Allowed redirection URLs:
  - `https://app.theeverbranch.com/shopify/callback/retail`
  - `https://app.theeverbranch.com/shopify/callback/wholesale`
- App proxy:
  - URL `https://app.theeverbranch.com/shopify/marketing/v1`
  - prefix `apps`
  - subpath `forestry`
- Embedded app setting is enabled.
- Scopes match approved runtime need; broad TOML scopes are justified or reduced.
- Required operational webhooks are registered on canonical host.
- Privacy webhooks are configured and backed by tested handlers.
- Privacy webhook Partner Dashboard/CLI version deployment evidence is captured.
- App name, handle, icon, screenshots, support email, privacy policy, terms, and listing copy match Everbranch launch posture.
- Distribution/App Store listing settings are complete.
- Shopify Billing configuration is intentionally inactive unless a future billing PR explicitly implements it.
- Test store install/reinstall evidence is captured.

Detailed evidence runbook:
- `docs/operations/shopify-partner-dashboard-evidence-runbook.md`

Current dated evidence packet:
- `docs/operations/evidence/shopify/2026-05-21/README.md`
- PR 18 evidence files:
  - `docs/operations/evidence/shopify/2026-05-21/evidence-summary.md`
  - `docs/operations/evidence/shopify/2026-05-21/cli-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/partner-dashboard-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/dev-store-install-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/app-proxy-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/privacy-webhook-delivery-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/scope-review-evidence.md`
  - `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md`
  - `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md`

Scope and branding decision record:
- `docs/operations/shopify-scope-branding-decision-record.md`

## Billing Lane Note

Shopify Billing / Shopify App Pricing remains a future App Store lane, not an active PR 9 or PR 10 feature. For merchants who install Everbranch through a public Shopify App Store distribution path, app-related charges should use Shopify App Pricing or the Shopify Billing API unless Shopify explicitly approves another arrangement.

Existing Stripe/direct billing code must stay separate from Shopify App Store merchant billing:

- Do not enable tenant-facing Stripe checkout for Shopify App Store customers in readiness work.
- Do not link Stripe checkout from the embedded Shopify app.
- Do not imply embedded App Store module cards can be purchased through Stripe.
- Keep Stripe for direct SaaS, non-Shopify tenants, custom module retainers, service work, and manual contracts only if a future billing decision explicitly approves that lane.
- Keep Shopify Billing inactive until privacy webhooks, Partner Dashboard evidence, App Store billing requirements, support/refund policies, and install/reinstall evidence are complete.

## Automated Evidence

Run:

```bash
./vendor/bin/pest tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php
./vendor/bin/pest tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php
./vendor/bin/pest tests/Feature/Everbranch/ShopifyPartnerEvidenceRunbookTest.php
./vendor/bin/pest tests/Feature/ShopifyAuthDomainMigrationTest.php
./vendor/bin/pest tests/Feature/ShopifyCommercializationPagesTest.php
./vendor/bin/pest tests/Feature/ShopifyWebhookSubscriptionEnforcementTest.php
```

PR 9 evidence covers:
- TOML canonical app URL, redirect URLs, app proxy URL, and embedded flag.
- Runtime OAuth canonical callback host.
- Required webhook callback canonical host generation.
- Embedded App Store passive checkout/pricing copy.
- Billing lifecycle flags remain disabled.
- Privacy webhook implementation evidence after PR 11:
  - canonical TOML compliance subscriptions exist;
  - local routes exist;
  - HMAC verification accepts valid requests and rejects invalid/missing HMAC;
  - evidence records default to `manual_review_required`;
  - raw sensitive payload data is not stored;
  - no destructive deletion/redaction is performed.
- Modern Forestry mobile remains outside generic Shopify App Store readiness.

PR 15 evidence covers:
- Dated external evidence packet exists and marks unresolved external evidence as pending.
- Scope/branding decision record exists and includes current app name, handle, and TOML scopes.
- Readiness audit links to the evidence packet and decision record.
- Billing remains disabled.

PR 18 evidence covers:
- Read-only Shopify CLI app-info output for `Modern Forestry Backstage`.
- Partial live app proxy evidence for direct unsigned-route rejection and storefront app-proxy health.
- Initial scope review evidence file.
- Explicit pending evidence files for Partner Dashboard screenshots, install/reinstall, and live privacy webhook delivery.
- No deploy/release, webhook trigger, billing, OAuth, scope, app identity, module, or entitlement changes.

PR 19 evidence prep covers:
- Required screenshot filenames and expected values for Partner Dashboard, dev-store, embedded app, app proxy, privacy webhook row, and scope review evidence.
- Operator checklist for non-mutating evidence capture before any deploy/release decision.
- Explicit instruction not to run deploy/release/webhook-trigger/dev commands without operator approval.

## Non-Goals

PR 9 does not:
- Change Shopify OAuth/install behavior.
- Change app scopes.
- Activate Shopify Billing, Stripe billing, checkout, or paid module purchasing.
- Install modules or change entitlements.
- Automate privacy deletion/redaction.
- Implement new Shopify features.
- Change tenant resolution.
- Generalize Modern Forestry mobile behavior.

## Blockers

- Privacy webhook handlers now exist, but Partner Dashboard/Shopify CLI deployment evidence and a tested deletion/anonymization policy remain missing.
- Dated evidence packet exists and now contains partial PR 18 CLI/app-proxy evidence plus PR 19 screenshot/operator checklist prep, but still does not complete external verification.
- Partner Dashboard values and scopes still need manual verification against this repo.
- TOML scopes appear broader than runtime defaults and need reduction or written justification.
- Shopify app name/handle are confirmed as Modern Forestry Backstage / `modernforestrybackstage` for internal/alpha evidence. Public Everbranch App Store branding still needs a deliberate decision.
- Live dev-store install/reinstall evidence has not been captured. App proxy health has partial live evidence, but Partner Dashboard screenshot and browser evidence remain pending.
- PR 12 documents the exact evidence process, but does not complete external Partner Dashboard or CLI deployment evidence.

## Recommended Next Step

Complete the remaining external evidence manually: capture Partner Dashboard screenshots, approve and run a Shopify CLI deploy/release or draft deploy when safe, perform dev-store install/reinstall, trigger privacy webhooks with secret handling, verify `shopify_privacy_webhook_events`, and decide scope/branding posture. Do not activate billing or change OAuth behavior during that evidence pass.
