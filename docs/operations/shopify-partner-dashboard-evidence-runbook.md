# Shopify Partner Dashboard Evidence Runbook

Status: PR 19 screenshot/operator checklist prepared. PR 18 evidence packet contains read-only CLI app-info and partial app-proxy evidence. Live Partner Dashboard, Shopify CLI deploy/release, dev-store install/reinstall, and privacy webhook delivery evidence remain pending until a human operator captures them.
Date: 2026-05-21.

## Purpose

Use this runbook to prove Everbranch's Shopify App Store readiness configuration without changing OAuth behavior, scopes, billing, module entitlements, tenant resolution, or privacy deletion behavior.

This runbook is evidence-only. If a step cannot be completed from the current environment, record that honestly in the evidence notes instead of marking it complete.

Partner Dashboard and Shopify CLI deployment evidence is pending until operator-captured artifacts are stored in the dated evidence packet.

## A. Required App Identity And Config Values

Source of truth for local expected values:
- `shopify.app.toml`
- `config/shopify_webhooks.php`
- `routes/web.php`
- `docs/operations/everbranch-shopify-readiness-audit.md`

Required values to verify in Shopify Partner Dashboard:

| Surface | Expected value | Evidence needed |
| --- | --- | --- |
| App URL | `https://app.theeverbranch.com/shopify/app` | Dashboard screenshot and TOML diff/output. |
| Redirect URL: retail | `https://app.theeverbranch.com/shopify/callback/retail` | Dashboard screenshot and install callback log. |
| Redirect URL: wholesale | `https://app.theeverbranch.com/shopify/callback/wholesale` | Dashboard screenshot or written decision if wholesale is not part of the App Store app. |
| App proxy URL | `https://app.theeverbranch.com/shopify/marketing/v1` | Dashboard screenshot and dev-store proxy request evidence. |
| App proxy prefix/subpath | `apps` / `forestry` | Dashboard screenshot and storefront `/apps/forestry/...` evidence. |
| Privacy webhook: data request | `https://app.theeverbranch.com/webhooks/shopify/customers/data-request` | TOML deployment output plus test delivery evidence. |
| Privacy webhook: customer redact | `https://app.theeverbranch.com/webhooks/shopify/customers/redact` | TOML deployment output plus test delivery evidence. |
| Privacy webhook: shop redact | `https://app.theeverbranch.com/webhooks/shopify/shop/redact` | TOML deployment output plus test delivery evidence. |
| Embedded app | Enabled | Dashboard screenshot and embedded app open evidence. |
| Billing | Disabled/not active for now | Dashboard screenshot or written setting note. |
| App name | Currently `Modern Forestry Backstage` | Branding decision required before public App Store review. |
| App handle | Currently `modernforestrybackstage` | Branding/continuity decision required before public App Store review. |
| Dev store | Currently `modernforestry.myshopify.com` | Use this dev store for current internal/alpha install/reinstall evidence. |

Current PR 17 operator confirmation:
- Use `Modern Forestry Backstage` when looking for the app in the Shopify Partner/dev dashboard.
- Use handle `modernforestrybackstage`.
- Use dev store `modernforestry.myshopify.com`.
- Everbranch public Shopify app branding is not expected in the Partner Dashboard yet.
- Do not rename the app or handle during evidence capture.

PR 18 captured evidence:
- `shopify app info` confirms the current CLI-linked app is `Modern Forestry Backstage` with dev store `modernforestry.myshopify.com`.
- Storefront app proxy health is partially captured in `docs/operations/evidence/shopify/2026-05-21/app-proxy-evidence.md`.
- No deploy, release, or webhook trigger has been run.

PR 19 operator pack:
- Screenshot manifest: `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md`
- Operator checklist: `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md`
- Capture screenshots `01-partner-app-overview.png` through `12-scope-review-notes.png` before marking dashboard/dev-store evidence complete.
- Do not run deploy/release/webhook-trigger/dev commands until the operator explicitly approves the exact command and expected effect.

## B. Shopify CLI Deployment Steps

Observed local CLI:

```bash
shopify version
# observed during PR 12: 3.92.1
```

Inspect command help before deployment:

```bash
shopify app deploy --help
shopify app webhook trigger --help
```

Recommended non-destructive review before deploying:

```bash
git diff -- shopify.app.toml
rg -n "compliance_topics|application_url|redirect_urls|app_proxy|scopes" shopify.app.toml
```

Deploy the app configuration and extensions after code is merged and production web routes are deployed:

```bash
shopify app deploy \
  --path . \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --allow-updates \
  --message "Everbranch PR 12 privacy webhook readiness"
```

Notes:
- `shopify app deploy` creates and releases an app version containing the TOML configuration and extensions. It does not deploy the Laravel web app.
- Use `--no-release` only if creating a draft version for inspection.
- Do not use `--allow-deletes` or `--force` unless the operator has reviewed extension/config deletion impact.
- If the CLI prompts for auth or organization/app selection, capture the prompt/output in the evidence notes.

Trigger privacy webhook deliveries against the canonical deployed app after deployment:

```bash
shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic customers/data_request \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/customers/data-request

shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic customers/redact \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/customers/redact

shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic shop/redact \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/shop/redact
```

If CLI requires an app client secret for signed HMAC test delivery, provide it through the interactive prompt or `--client-secret` from a secure secret manager. Never commit or paste the secret into this repo.

Confirm evidence after each trigger:

```sql
select topic, shop_domain, status, action_required, handled_at, reviewed_at
from shopify_privacy_webhook_events
order by id desc
limit 10;
```

## C. Partner Dashboard Manual Checklist

Capture screenshots or notes for each item:

- App URL equals `https://app.theeverbranch.com/shopify/app`.
- Allowed redirection URLs include:
  - `https://app.theeverbranch.com/shopify/callback/retail`
  - `https://app.theeverbranch.com/shopify/callback/wholesale`
- App proxy:
  - URL `https://app.theeverbranch.com/shopify/marketing/v1`
  - prefix `apps`
  - subpath `forestry`
- Compliance/privacy webhooks are present for:
  - `customers/data_request`
  - `customers/redact`
  - `shop/redact`
- Operational webhooks are aligned or verified through:
  - `php artisan shopify:webhooks:verify --required-only`
- Scopes are reviewed against runtime need. Do not change scopes in PR 12.
- Embedded app setting is enabled.
- App name, handle, icon, support email, privacy policy URL, terms URL, screenshots, and listing copy are reviewed.
- Distribution/App Store listing status is recorded.
- Billing configuration is disabled/not active for now.
- Test/dev store install evidence is captured.
- Test/dev store uninstall/reinstall evidence is captured.

Use `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` for the required screenshot filenames and expected proof for each slot.

## D. Dev-Store Test Evidence

Capture evidence that:

1. Install succeeds from the dev/Partner flow.
2. OAuth redirect resolves through `https://app.theeverbranch.com/shopify/callback/{store}`.
3. Callback creates or updates exactly one `shopify_stores` row for the store key.
4. Embedded app opens at `https://app.theeverbranch.com/shopify/app`.
5. Embedded `/shopify/app/start`, `/shopify/app/store`, and `/shopify/app/integrations` render.
6. App proxy returns expected health/content from the storefront `/apps/forestry/...` path.
7. Reinstall is idempotent and updates the existing store record instead of creating duplicates.
8. Privacy webhook test deliveries create `shopify_privacy_webhook_events` rows for all three topics.
9. Invalid webhook HMAC rejection is covered locally by `ShopifyPrivacyWebhookReadinessTest`.
10. Billing/checkout remains absent from embedded module/App Store surfaces.

Suggested commands:

```bash
php artisan shopify:webhooks:verify --required-only
./vendor/bin/pest tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php
./vendor/bin/pest tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php
./vendor/bin/pest tests/Feature/ShopifyAuthDomainMigrationTest.php
./vendor/bin/pest tests/Feature/ShopifyCommercializationPagesTest.php
./vendor/bin/pest tests/Feature/ShopifyWebhookSubscriptionEnforcementTest.php
```

## E. Privacy Manual Review Runbook

When a `shopify_privacy_webhook_events` row appears:

1. Identify `topic`, `shop_domain`, `webhook_id`, `handled_at`, and `payload_summary`.
2. Locate the related `shopify_stores` row by `shop_domain`.
3. Locate the related tenant from `shopify_stores.tenant_id`, if present.
4. For `customers/data_request`, use the summary customer/order identifiers to assemble a manual export/review package according to the future privacy policy.
5. For `customers/redact`, identify related customer/profile/order records, but do not delete or anonymize until an approved deletion/anonymization policy exists.
6. For `shop/redact`, identify related store/tenant data, but do not delete or anonymize until an approved shop-data retention policy exists.
7. Record operator action, follow-up owner, and decision in `notes`.
8. Set `reviewed_at` only after a human review is complete.
9. Set `status = completed` and `action_required = false` only after the required manual action is done and documented.

Current PR 12 policy:
- Privacy webhook handling is evidence and manual-review only.
- Destructive deletion/anonymization is intentionally not automated.
- Wrong deletion is worse than conservative intake plus documented manual review.

## F. Scope Review Runbook

Do not change scopes in PR 12. Review and document.

TOML scopes are currently broad. Runtime defaults in `config/services.php` are narrower. Before App Store submission:

1. Extract TOML scopes:
   ```bash
   rg -n "scopes =" shopify.app.toml
   ```
2. Extract runtime defaults:
   ```bash
   rg -n "SHOPIFY_SCOPES|services.shopify.scopes|read_products|read_orders|read_customers|write_customers|read_pixels|write_pixels|read_customer_events" config app tests
   ```
3. Build a scope matrix with:
   - scope
   - route/controller/service using it
   - customer data impact
   - App Store listing/privacy policy justification
   - keep/reduce/defer decision
4. Confirm Partner Dashboard scopes match the approved matrix.
5. Capture screenshots or CLI output after any future scope deployment.

Known pending concern:
- `shopify.app.toml` lists broader scopes than Laravel runtime defaults. This remains a blocker until reduced or justified.

## G. Evidence Storage Convention

Store evidence under:

```text
docs/operations/evidence/shopify/YYYY-MM-DD/
```

Recommended files:

```text
partner-dashboard-checklist.md
shopify-cli-deploy-output.txt
privacy-webhook-trigger-output.txt
privacy-webhook-db-query.txt
dev-store-install-reinstall-notes.md
app-proxy-evidence.md
scope-review.md
screenshots/
```

Do not create empty evidence folders. Create the dated folder only when attaching real evidence artifacts.

Current PR 15 packet:
- `docs/operations/evidence/shopify/2026-05-21/README.md`

This packet records local CLI version/help evidence and explicitly marks external deploy, Partner Dashboard, dev-store, app proxy, and live privacy webhook delivery evidence as pending.

## PR 12 Evidence Status

Completed locally:
- Runbook created.
- Dated PR 15 evidence packet created.
- Local tests cover canonical TOML values, privacy webhook routes, HMAC verification, evidence records, and inactive billing posture.
- Local Shopify CLI help was inspected for deployment and webhook trigger command shapes.

Pending external evidence:
- Shopify CLI app deploy/version release output.
- Partner Dashboard screenshots.
- Dev-store install/reinstall screenshots/logs.
- Privacy webhook trigger deliveries against the deployed app.
- App proxy storefront evidence.
- Scope review and branding/handle decision.
