# Shopify Evidence Summary

Date: 2026-05-21.
Status: partial external evidence captured; PR 19 screenshot/operator checklist prepared; several required items remain pending.

## Target

| Item | Value |
| --- | --- |
| Shopify app | `Modern Forestry Backstage` |
| TOML handle | `modernforestrybackstage` |
| Client ID | `197d01d6597c938c96b3b35fae6a087c` |
| Dev store | `modernforestry.myshopify.com` |
| Platform/product direction | Everbranch |

## Captured Evidence

| Area | Status | Evidence file |
| --- | --- | --- |
| Shopify CLI version/help | captured | `cli-evidence.md` |
| Shopify CLI app info | captured | `cli-evidence.md` |
| App identity via CLI | captured | `cli-evidence.md` |
| App proxy health route, direct canonical host | captured | `app-proxy-evidence.md` |
| Storefront app proxy health via primary storefront domain | captured | `app-proxy-evidence.md` |
| Initial scope/runtime code-search evidence | captured_partial | `scope-review-evidence.md` |
| Screenshot manifest | prepared_pending | `screenshot-manifest.md` |
| Operator checklist | prepared_pending | `operator-checklist.md` |

## Pending Evidence

| Area | Status | Next action |
| --- | --- | --- |
| Partner Dashboard screenshots | pending_operator | Capture screenshots listed in `screenshot-manifest.md` and `partner-dashboard-evidence.md`. |
| Shopify CLI deploy/release output | pending_operator_approval | Approve exact command before running; prefer draft `--no-release` first if appropriate. |
| Dev-store install/reinstall | pending_operator | Install/reinstall on `modernforestry.myshopify.com` and capture screenshots/logs. |
| Embedded app open evidence | pending_operator | Open app in Shopify Admin and capture screenshots/logs. |
| Live privacy webhook delivery rows | pending_operator_approval | Trigger webhooks only after approval and secret handling plan. |
| Partner Dashboard scope screenshot | pending_operator | Capture current scopes and compare to decision record. |
| Public Everbranch app branding decision | pending_product_decision | Decide whether to rename this app later or create a separate public Everbranch Shopify app. |

## Blocked Evidence

| Area | Status | Blocker |
| --- | --- | --- |
| Public App Store readiness completion | blocked | Partner Dashboard, deploy/release, install/reinstall, privacy delivery, scope review, billing lane, and public branding evidence remain incomplete. |
| Automated privacy deletion/redaction | blocked | No approved data deletion/anonymization policy exists yet. Current handling is manual-review only. |
| Shopify Billing/App Pricing activation | blocked | Future billing PR and Shopify App Store lane decision required. |

## No-Change Confirmations

- Shopify app name was not changed.
- Shopify handle was not changed.
- Shopify scopes were not changed.
- Shopify OAuth behavior was not changed.
- Shopify Billing was not activated.
- Stripe billing was not activated.
- Checkout, charges, subscriptions, module installs, and entitlement changes were not activated.
- No destructive privacy deletion/redaction was automated.

## PR 19 Operator Pack

- `screenshot-manifest.md` defines screenshot slots `01-partner-app-overview.png` through `12-scope-review-notes.png`.
- `operator-checklist.md` gives the operator a step-by-step sequence for identity confirmation, Partner Dashboard screenshots, dev-store evidence, app-proxy screenshot, privacy webhook delivery planning, and deploy/release approval.
- Deploy/release remains blocked until the operator explicitly approves the exact command.
- Partner Dashboard evidence remains pending until screenshots or written verification are attached.

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
