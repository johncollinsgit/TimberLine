# Shopify Operator Evidence Checklist

Date: 2026-05-21.
Status: pending operator execution.

This checklist captures the remaining non-mutating Shopify evidence for the current internal/alpha app. It does not authorize deploy, release, webhook trigger, app rename, scope changes, billing activation, or OAuth changes.

## A. Confirm App Identity

- [ ] Open the Shopify Partner/dev dashboard app named `Modern Forestry Backstage`.
- [ ] Confirm client ID `197d01d6597c938c96b3b35fae6a087c`.
- [ ] Confirm handle `modernforestrybackstage`.
- [ ] Confirm dev store `modernforestry.myshopify.com`.
- [ ] Save `01-partner-app-overview.png`.

Stop if the Partner Dashboard app identity does not match these values. Do not deploy or release.

## B. Capture Partner Dashboard Screenshots

- [ ] Capture app URL and redirect URLs as `02-partner-app-urls-redirects.png`.
- [ ] Capture app proxy settings as `03-partner-app-proxy.png`.
- [ ] Capture current scopes as `04-partner-app-scopes.png`.
- [ ] Capture privacy/compliance webhook settings as `05-partner-webhooks-privacy.png`.
- [ ] Capture embedded app setting as `06-partner-embedded-app-setting.png`.
- [ ] Capture billing/pricing status as `07-partner-billing-status.png`.

Expected values are listed in `screenshot-manifest.md`.

## C. Capture Dev-Store Evidence

- [ ] Use dev store `modernforestry.myshopify.com`.
- [ ] Capture installed app state as `08-dev-store-installed-apps.png`.
- [ ] Open the embedded app and capture `09-embedded-app-open.png`.
- [ ] If reinstall is safe and intentionally tested, record before/after install state and relevant sanitized callback/store-row evidence.

Do not change app scopes, app name, handle, billing, modules, or tenant behavior during this evidence step.

## D. Capture App Proxy Evidence

- [ ] Open `https://theforestrystudio.com/apps/forestry/health`.
- [ ] Confirm JSON includes `ok=true`, `app_proxy_enabled=true`, and `integration_mode=shopify_app_proxy`.
- [ ] Save `10-app-proxy-health-primary-domain.png`.
- [ ] Note that `https://modernforestry.myshopify.com/apps/forestry/health` redirects to the primary domain.

PR 18 already captured curl evidence in `app-proxy-evidence.md`; this screenshot is for final operator evidence.

## E. Privacy Webhook Delivery

- [ ] Do not trigger privacy webhooks until the operator is ready and secret handling is planned.
- [ ] If approved, run the exact `shopify app webhook trigger` commands from `privacy-webhook-delivery-evidence.md`.
- [ ] If a client secret is required, retrieve it from a secure secret manager and do not commit or paste it into this repo.
- [ ] Verify `shopify_privacy_webhook_events` rows for `customers/data_request`, `customers/redact`, and `shop/redact`.
- [ ] Save sanitized DB/log proof as `11-privacy-webhook-event-row.png` or a text artifact.

Privacy webhook evidence remains pending until rows exist.

## E2. Scope Review Notes

- [ ] Review `scope-review-evidence.md`.
- [ ] Capture Partner Dashboard scopes or scope review notes as `12-scope-review-notes.png`.
- [ ] Do not change scopes during screenshot capture.
- [ ] Record final keep/reduce/justify decisions separately before public App Store submission.

## F. Deploy/Release Decision

- [ ] Do not run `shopify app deploy`.
- [ ] Do not run `shopify app release`.
- [ ] Do not run `shopify app webhook trigger`.
- [ ] Do not run `shopify app dev`.
- [ ] First capture screenshots `01` through `07`.
- [ ] Review scope evidence in `scope-review-evidence.md`.
- [ ] Decide whether to do a draft deploy with `--no-release`, a real release, or no deployment.
- [ ] Operator must explicitly approve the exact command before it is run.

Recommended approval format:

```text
Approved command:
shopify app deploy --path . --client-id 197d01d6597c938c96b3b35fae6a087c --no-release --message "Modern Forestry Backstage evidence draft"
Expected effect:
Creates a draft Shopify app version and does not release it to merchants.
```

## G. Completion Criteria

Evidence is complete only when:

- Screenshot manifest statuses are updated from `pending` to `captured` or `not_applicable` with notes.
- Partner Dashboard values match the current TOML or documented decision.
- Dev-store install/reinstall and embedded app behavior are documented.
- Privacy webhook delivery rows are captured or explicitly deferred.
- Scope and public branding decisions are recorded.
- Billing remains disabled.
