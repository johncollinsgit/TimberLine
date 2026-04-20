# Phase 1: Click-path Smoke Findings

Run command:

```bash
CLICK_PATH_BASE_URL='https://app.theeverbranch.com' npm run qa:click-path
```

Latest summary (from `test-results/click-path/report.json`):

- Routes tested: 13
- Routes with issues: 1
- Broken anchors: 0
- Dead buttons: 0
- Guard-skipped buttons: 25
- Auth redirects: 11
- Auth bootstrap: not attempted (`credentials_not_provided`)

## Broken click paths / dead controls

- No dead controls were detected in the non-destructive click subset.
- No broken same-origin visible anchor targets were detected in the audited subset.

## Errors detected

1. Shopify Embedded Messaging Analytics
   - Route: `/shopify/app/messaging/analytics?analytics_tab=home`
   - Issue: runtime React crash (`Minified React error #185`)
   - Evidence: console error + pageerror captured by harness
   - Screenshot path: `test-results/click-path/screenshots/shopify-app-messaging-analytics-analytics-tab-home.png`

## Access constraints observed

- 11 of 13 routes redirected to `/login` without an authenticated operator session.
- This run validates route reachability and guard-safe controls, but not deep authenticated workflows.

## What this unlocks

- We now have a reusable, non-destructive baseline harness for every release.
- We can gate Phase 2 event-integrity work on objective click-path health checks.
- Next run should include an authenticated session profile to expand control coverage across marketing and analytics surfaces.

## Immediate low-risk fixes applied

- Click guard logic now treats anchors separately from form-submit controls, so safe links are no longer skipped just because they are inside a POST form.
- Config-level route limits (`maxLinksPerPage`, `maxButtonsPerPage`) are now honored by default in the harness, with environment overrides still available.
