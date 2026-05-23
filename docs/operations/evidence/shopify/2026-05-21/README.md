# Shopify External Evidence Packet

Date: 2026-05-21.
Status: PR 19 screenshot/operator checklist prepared; PR 18 partial external evidence captured; Partner Dashboard, deploy/release, install/reinstall, and live privacy webhook delivery evidence remain pending.

## Environment

| Item | Status | Notes / action needed |
| --- | --- | --- |
| Local repo | Captured | This packet was created from `/Users/johncollins/Code/myapp`. |
| Shopify CLI | Captured | `shopify version` returned `3.92.1`; `shopify app info` confirmed `Modern Forestry Backstage`, `modernforestrybackstage`, and `modernforestry.myshopify.com`. |
| Staging | Pending | Capture app deploy/release output and Laravel route availability after staging deployment. |
| Production | Pending | Capture only after an operator confirms production deploy timing. |
| Shopify dev store | Partially captured | `shopify app info` confirms `modernforestry.myshopify.com`; install/reinstall evidence remains pending. |
| Partner Dashboard | Pending | Manual screenshot/field evidence still required. |

## Confirmed Target Shopify App

Use this app/store for the current evidence run:

| Field | Confirmed value | Notes |
| --- | --- | --- |
| Shopify Partner/dev dashboard app name | `Modern Forestry Backstage` | This is the current internal/alpha app connected to this repo. |
| TOML handle | `modernforestrybackstage` | Do not change in this PR. |
| Dev store | `modernforestry.myshopify.com` | Use for dev-store install/reinstall and app proxy evidence. |

Everbranch naming is not expected inside the Partner Dashboard yet. Everbranch remains the platform/product direction, while public Shopify App Store branding is a later decision.

## Required App Configuration Evidence

| Evidence item | Expected value | Evidence status | Required artifact |
| --- | --- | --- | --- |
| App URL | `https://app.theeverbranch.com/shopify/app` | Pending external verification | Partner Dashboard screenshot plus Shopify CLI deploy/status output. |
| Redirect URL: retail | `https://app.theeverbranch.com/shopify/callback/retail` | Pending external verification | Partner Dashboard screenshot plus install callback log. |
| Redirect URL: wholesale | `https://app.theeverbranch.com/shopify/callback/wholesale` | Pending external verification | Partner Dashboard screenshot or written decision if wholesale is excluded from public App Store app. |
| App proxy URL | `https://app.theeverbranch.com/shopify/marketing/v1` | Partially captured | Live route/proxy health captured in `app-proxy-evidence.md`; Partner Dashboard screenshot still pending. |
| App proxy prefix/subpath | `apps` / `forestry` | Partially captured | Storefront `/apps/forestry/health` returns through primary domain; Partner Dashboard screenshot still pending. |
| Embedded app enabled | `true` | Pending external verification | Dashboard screenshot and embedded app open screenshot/log. |
| Billing disabled | Disabled/not active | Pending external verification | Dashboard screenshot or written setting note; local tests still verify Laravel billing flags are disabled. |

## Privacy Webhook Delivery Evidence

| Topic | Expected URL | Evidence status | Required artifact |
| --- | --- | --- | --- |
| `customers/data_request` | `https://app.theeverbranch.com/webhooks/shopify/customers/data-request` | Pending live delivery | CLI/Partner trigger output plus `shopify_privacy_webhook_events` row showing topic, shop, status, `action_required=true`. |
| `customers/redact` | `https://app.theeverbranch.com/webhooks/shopify/customers/redact` | Pending live delivery | CLI/Partner trigger output plus `shopify_privacy_webhook_events` row showing topic, shop, status, `action_required=true`. |
| `shop/redact` | `https://app.theeverbranch.com/webhooks/shopify/shop/redact` | Pending live delivery | CLI/Partner trigger output plus `shopify_privacy_webhook_events` row showing topic, shop, status, `action_required=true`. |

Local code status:
- Routes exist.
- HMAC verification exists.
- Valid local test webhooks record minimal hashed/summarized evidence.
- Invalid or missing HMAC does not record evidence.
- No destructive deletion/redaction is automated.

## PR 18 Evidence Files

| File | Status | Purpose |
| --- | --- | --- |
| `evidence-summary.md` | captured | Summary of captured, pending, and blocked evidence. |
| `cli-evidence.md` | captured | Read-only Shopify CLI version/help/app-info evidence. |
| `partner-dashboard-evidence.md` | pending | Partner Dashboard screenshot/manual verification checklist. |
| `dev-store-install-evidence.md` | pending | Dev-store install/reinstall evidence checklist. |
| `app-proxy-evidence.md` | captured_partial | Live direct-route and storefront app-proxy health evidence. |
| `privacy-webhook-delivery-evidence.md` | pending_operator_approval | Live webhook trigger and DB evidence instructions. |
| `scope-review-evidence.md` | captured_partial | Initial code-search scope review evidence; final decision still pending. |
| `screenshot-manifest.md` | prepared_pending | Required screenshot filenames, expected values, and completion rules. |
| `operator-checklist.md` | prepared_pending | Step-by-step operator instructions before any deploy/release decision. |

## Shopify CLI Evidence

Captured locally:

```text
command -v shopify -> /opt/homebrew/bin/shopify
shopify version -> 3.92.1
shopify app deploy --help -> confirms --client-id, --allow-updates, --no-release, --path, and --message flags
shopify app webhook trigger --help -> confirms --topic, --address, --client-secret, --api-version, and --delivery-method flags
shopify app info --path . --client-id 197d01d6597c938c96b3b35fae6a087c --no-color -> confirms Modern Forestry Backstage, modernforestry.myshopify.com, broad TOML scope set, and extension components
```

Not captured:
- No `shopify app deploy` was run.
- No app version was created or released.
- No privacy webhook trigger was sent.

Required commands when an operator is ready:

```bash
shopify app deploy \
  --path . \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --allow-updates \
  --message "Everbranch privacy webhook and readiness evidence"

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

Never commit or paste the Shopify client secret. If `--client-secret` is needed, retrieve it from a secure secret manager during the operator run.

## Partner Dashboard Checklist

Pending screenshots or written verification for:
- App URL.
- Allowed redirection URLs.
- App proxy URL, prefix, and subpath.
- Compliance/privacy webhook subscriptions.
- Operational webhook expectations.
- Scopes.
- Embedded app setting.
- App name and handle.
- App icon, support email, privacy policy URL, terms URL, screenshots, and listing copy.
- Distribution/App Store listing status.
- Billing configuration status.
- Test/dev store install.
- Test/dev store uninstall/reinstall.

## Dev-Store Install/Reinstall/App Proxy Evidence

Captured app proxy evidence:
- Direct unsigned canonical route returns `401`: `https://app.theeverbranch.com/shopify/marketing/v1/health` returns `401` with `missing_signature_headers` when called directly, proving the route exists and rejects unsigned storefront requests.
- `https://modernforestry.myshopify.com/apps/forestry/health` redirects to the primary storefront domain `https://theforestrystudio.com/apps/forestry/health`.
- `https://theforestrystudio.com/apps/forestry/health` returns `200` JSON with `app_proxy_enabled=true`, signing secret present, app proxy secret present, and `integration_mode=shopify_app_proxy`.

Pending evidence:
- Install succeeds.
- OAuth callback reaches `https://app.theeverbranch.com/shopify/callback/{store}`.
- Callback creates or updates exactly one `shopify_stores` row.
- Embedded app opens at `https://app.theeverbranch.com/shopify/app`.
- Embedded `/shopify/app/start`, `/shopify/app/store`, and `/shopify/app/integrations` render.
- App proxy Partner Dashboard screenshot and browser evidence from the dev-store/primary storefront.
- Reinstall is idempotent.
- Billing/checkout controls remain absent from embedded module/App Store surfaces.

## Scope Review Status

Status: captured partial code-search evidence; final decision still pending.

Use `docs/operations/shopify-scope-branding-decision-record.md` as the current decision record. Do not change scopes until the record is approved and tests/evidence are ready.

## App Name / Handle Decision Status

Status: current internal/alpha identity confirmed; public Everbranch branding decision pending.

Current TOML identity:
- App name: `Modern Forestry Backstage`
- Handle: `modernforestrybackstage`
- Dev store: `modernforestry.myshopify.com`

Decision remains open between keeping the alpha/internal Modern Forestry identity, renaming before public Everbranch App Store submission, or creating separate internal/public Shopify apps.

## Evidence File References

Recommended files to add after manual capture:
- `shopify-cli-deploy-output.txt`
- `privacy-webhook-trigger-output.txt`
- `privacy-webhook-db-query.txt`
- `screenshots/`

Current packet has no screenshots, live Partner Dashboard output, live deploy output, live webhook delivery output, or dev-store install logs. It does include read-only CLI app-info evidence and live app-proxy health evidence.

## PR 19 Screenshot Pack

Before any deploy/release decision, use:
- `screenshot-manifest.md`
- `operator-checklist.md`

Required screenshot slots:
- `01-partner-app-overview.png`
- `02-partner-app-urls-redirects.png`
- `03-partner-app-proxy.png`
- `04-partner-app-scopes.png`
- `05-partner-webhooks-privacy.png`
- `06-partner-embedded-app-setting.png`
- `07-partner-billing-status.png`
- `08-dev-store-installed-apps.png`
- `09-embedded-app-open.png`
- `10-app-proxy-health-primary-domain.png`
- `11-privacy-webhook-event-row.png`
- `12-scope-review-notes.png`

Do not run `shopify app deploy`, `shopify app release`, `shopify app webhook trigger`, or `shopify app dev` until the operator explicitly approves the exact command and expected effect.
