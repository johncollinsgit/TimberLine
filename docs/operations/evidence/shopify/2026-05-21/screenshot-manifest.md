# Shopify Screenshot Manifest

Date: 2026-05-21.
Status: pending operator screenshot capture.

Use this manifest for the remaining non-mutating Shopify evidence. Save screenshots in this dated evidence folder unless a later evidence packet supersedes it.

## Screenshot Slots

| Screenshot filename | Status | Source location | Expected values / proof | Required before deploy/release? | Required before App Store submission? | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `01-partner-app-overview.png` | pending | Shopify Partner Dashboard app overview for `Modern Forestry Backstage` | App name `Modern Forestry Backstage`, client ID `197d01d6597c938c96b3b35fae6a087c`, handle `modernforestrybackstage` if visible. | yes | yes | Confirms the operator is looking at the correct current app. |
| `02-partner-app-urls-redirects.png` | pending | Partner Dashboard app setup / URLs / redirection URLs | App URL `https://app.theeverbranch.com/shopify/app`; redirects include `https://app.theeverbranch.com/shopify/callback/retail` and `https://app.theeverbranch.com/shopify/callback/wholesale`. | yes | yes | Do not deploy if dashboard identity differs from TOML. |
| `03-partner-app-proxy.png` | pending | Partner Dashboard app proxy settings | App proxy URL `https://app.theeverbranch.com/shopify/marketing/v1`, prefix `apps`, subpath `forestry`. | yes | yes | Compare to `app-proxy-evidence.md`. |
| `04-partner-app-scopes.png` | pending | Partner Dashboard API scopes / configuration | Current dashboard scopes match current app version or approved scope decision. | yes | yes | Final public scope decision remains pending. |
| `05-partner-webhooks-privacy.png` | pending | Partner Dashboard compliance/privacy webhook settings or app configuration version | Privacy topics `customers/data_request`, `customers/redact`, `shop/redact` point to canonical `app.theeverbranch.com` endpoints. | yes | yes | Deployment evidence may also be needed after TOML release. |
| `06-partner-embedded-app-setting.png` | pending | Partner Dashboard embedded app setting | Embedded app is enabled. | yes | yes | Confirms app can open inside Shopify Admin. |
| `07-partner-billing-status.png` | pending | Partner Dashboard billing / pricing / distribution settings | Shopify Billing/App Pricing is disabled or explicitly not active for this internal/alpha evidence pass. | yes | yes | Billing must not be activated in this evidence pass. |
| `08-dev-store-installed-apps.png` | pending | `modernforestry.myshopify.com` admin installed apps area | `Modern Forestry Backstage` is installed or install state is documented. | no | yes | Capture before/after reinstall if testing reinstall. |
| `09-embedded-app-open.png` | pending | Shopify Admin embedded app frame for `Modern Forestry Backstage` | Embedded app opens without OAuth/callback failure; no checkout controls are introduced. | no | yes | Capture `/shopify/app`, `/shopify/app/start`, or `/shopify/app/store` as appropriate. |
| `10-app-proxy-health-primary-domain.png` | pending | Browser at `https://theforestrystudio.com/apps/forestry/health` | JSON health response shows `ok=true`, `app_proxy_enabled=true`, and `integration_mode=shopify_app_proxy`. | no | yes | PR 18 curl evidence already captured this; screenshot is for operator packet completeness. |
| `11-privacy-webhook-event-row.png` | pending | DB admin/log view after approved live privacy webhook trigger | A `shopify_privacy_webhook_events` row shows topic, shop domain, status, `action_required=true`, and handled timestamp. | no | yes | Do not include raw sensitive payloads. |
| `12-scope-review-notes.png` | pending | Scope review notes, Partner Dashboard scopes, or final decision doc | Broad scopes are reduced or justified before public submission. | no | yes | Screenshot can be notes/export if dashboard cannot show all scopes cleanly. |

## Completion Rule

Do not mark Partner Dashboard evidence, dev-store evidence, privacy webhook delivery, or public App Store readiness complete until the relevant screenshot or written operator verification is stored in this evidence packet.

Command guardrails:
- Do not run `shopify app deploy` as part of screenshot capture unless a separate operator approval explicitly authorizes the exact command.
- Do not run `shopify app release` as part of screenshot capture unless a separate operator approval explicitly authorizes the exact command.
- Do not run `shopify app webhook trigger` as part of screenshot capture unless a separate operator approval explicitly authorizes the exact command.
- Do not run `shopify app dev` as part of screenshot capture unless a separate operator approval explicitly authorizes the exact command.
