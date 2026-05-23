# Partner Dashboard Evidence

Date: 2026-05-21.
Status: pending operator screenshot/manual verification.

Related operator docs:
- `screenshot-manifest.md`
- `operator-checklist.md`

## Confirmed Target

| Field | Expected value | Evidence status |
| --- | --- | --- |
| Shopify Partner/dev dashboard app | `Modern Forestry Backstage` | pending screenshot/manual verification |
| TOML handle | `modernforestrybackstage` | pending screenshot/manual verification |
| Dev store | `modernforestry.myshopify.com` | pending screenshot/manual verification |

Everbranch naming is not expected inside the Shopify Partner Dashboard yet. Public Everbranch Shopify app branding remains a later decision.

## Checklist To Capture

| Dashboard item | Expected value | Status | Screenshot filename |
| --- | --- | --- | --- |
| App overview/name | `Modern Forestry Backstage` | pending | `01-partner-app-overview.png` |
| Handle | `modernforestrybackstage` | pending | `01-partner-app-overview.png` |
| App URL | `https://app.theeverbranch.com/shopify/app` | pending | `02-partner-app-urls-redirects.png` |
| Redirect URL: retail | `https://app.theeverbranch.com/shopify/callback/retail` | pending | `02-partner-app-urls-redirects.png` |
| Redirect URL: wholesale | `https://app.theeverbranch.com/shopify/callback/wholesale` | pending | `02-partner-app-urls-redirects.png` |
| App proxy URL | `https://app.theeverbranch.com/shopify/marketing/v1` | pending | `03-partner-app-proxy.png` |
| App proxy prefix/subpath | `apps` / `forestry` | pending | `03-partner-app-proxy.png` |
| Compliance webhooks | `customers/data_request`, `customers/redact`, `shop/redact` | pending | `05-partner-webhooks-privacy.png` |
| Scopes | Match approved scope matrix or current TOML during alpha | pending | `04-partner-app-scopes.png` |
| Embedded app setting | enabled | pending | `06-partner-embedded-app-setting.png` |
| Billing status | disabled/not active | pending | `07-partner-billing-status.png` |
| Distribution status | internal/alpha or otherwise explicitly documented | pending | `01-partner-app-overview.png` or operator note |
| Dev-store install status | `modernforestry.myshopify.com` | pending | `08-dev-store-installed-apps.png` |

## Notes

- Codex does not have Partner Dashboard browser/screenshot access in this PR.
- Do not mark Partner Dashboard evidence complete until screenshots or written operator verification are stored in this dated evidence folder.
- Do not rename the Shopify app or handle during this evidence pass.
- Do not run deploy/release until the operator checklist is complete and the exact command is approved.
