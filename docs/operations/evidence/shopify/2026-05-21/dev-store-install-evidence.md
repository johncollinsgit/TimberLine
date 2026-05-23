# Dev-Store Install And Reinstall Evidence

Date: 2026-05-21.
Status: pending operator execution.

Related operator docs:
- `screenshot-manifest.md`
- `operator-checklist.md`

## Target

| Item | Value |
| --- | --- |
| Shopify app | `Modern Forestry Backstage` |
| App handle | `modernforestrybackstage` |
| Dev store | `modernforestry.myshopify.com` |

## Evidence Needed

| Check | Status | Required artifact |
| --- | --- | --- |
| Install succeeds from Partner/dev flow | pending | screenshot or notes with timestamp |
| OAuth callback reaches canonical URL | pending | callback log showing `https://app.theeverbranch.com/shopify/callback/{store}` |
| `shopify_stores` row is created or updated once | pending | DB query or sanitized log |
| Embedded app opens | pending | screenshot of `https://app.theeverbranch.com/shopify/app` inside Shopify Admin |
| Embedded start/store/integrations render | pending | screenshots or HTTP/log evidence |
| Reinstall is idempotent | pending | before/after DB query or sanitized log |
| Billing/checkout controls absent | pending | screenshot or test evidence from embedded surfaces |

## Manual Steps

1. In Shopify Partner/dev dashboard, open app `Modern Forestry Backstage`.
2. Use dev store `modernforestry.myshopify.com`.
3. Install or reinstall the app from the Partner/dev flow.
4. Capture OAuth callback logs without secrets.
5. Open the embedded app and capture screenshots.
6. Reinstall and verify the same store record is updated rather than duplicated.
7. Save screenshots/log snippets in this folder.

No install/reinstall was performed by Codex in PR 18.

PR 19 screenshot slots:
- `08-dev-store-installed-apps.png`
- `09-embedded-app-open.png`

Do not run `shopify app dev`, change app scopes, or reinstall the app unless the operator has decided that the dev-store action is safe for this evidence pass.
