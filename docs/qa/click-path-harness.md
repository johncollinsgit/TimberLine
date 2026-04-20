# Click-path QA Harness (Phase 1)

This harness audits non-destructive click paths for routes controlled by this Laravel repo.

## Files

- Script: `scripts/qa/click-path-audit.mjs`
- Route config: `tests/e2e/click-path-routes.json`
- Authenticated route config: `tests/e2e/click-path-routes-auth.json`
- Machine report: `test-results/click-path/report.json`
- Human report: `test-results/click-path/report.md`
- Failure screenshots: `test-results/click-path/screenshots/`

## What it checks

- Visits a controlled route list.
- Captures `console.error` and runtime `pageerror` output.
- Captures failed requests and HTTP responses `>= 400`.
- Validates same-origin visible anchor targets.
- Clicks only non-destructive buttons.
- Skips dangerous controls using guard tokens and non-GET form guards.
- Flags buttons with no visible effect (no URL/UI/network change).
- Takes screenshots when a route has issues.

## Non-destructive guardrails

Controls are skipped when text/attributes include guarded terms (for example: `delete`, `remove`, `archive`, `disconnect`, `sync now`, `import`, `send`, `redeem`, `save`, `update`, `create`, `submit`, `run`, `retry`, `trigger`).

Buttons inside non-GET forms are also skipped.

## Run

```bash
CLICK_PATH_BASE_URL='https://app.theeverbranch.com' npm run qa:click-path
```

Authenticated operator sweep (form login bootstrap):

```bash
CLICK_PATH_BASE_URL='https://app.theeverbranch.com' \
CLICK_PATH_LOGIN_EMAIL='operator@example.com' \
CLICK_PATH_LOGIN_PASSWORD='your-password' \
npm run qa:click-path:auth
```

Optional environment overrides:

- `CLICK_PATH_CONFIG` (default: `tests/e2e/click-path-routes.json`)
- `CLICK_PATH_REPORT_JSON` (default: `test-results/click-path/report.json`)
- `CLICK_PATH_REPORT_MD` (default: `test-results/click-path/report.md`)
- `CLICK_PATH_SCREENSHOT_DIR` (default: `test-results/click-path/screenshots`)
- `CLICK_PATH_TIMEOUT_MS` (default: `30000`)
- `CLICK_PATH_MAX_LINKS` (default: `maxLinksPerPage` from config, fallback `80`)
- `CLICK_PATH_MAX_BUTTONS` (default: `maxButtonsPerPage` from config, fallback `35`)
- `CLICK_PATH_HEADLESS` (`false` to show browser)
- `CLICK_PATH_STORAGE_STATE` (load existing authenticated Playwright storage state)
- `CLICK_PATH_STORAGE_STATE_OUT` (write storage state after successful form login)
- `CLICK_PATH_LOGIN_EMAIL` + `CLICK_PATH_LOGIN_PASSWORD` (optional form-login bootstrap)
- `CLICK_PATH_LOGIN_PATH` (default: `/login`)

## Theme-side coverage gap (separate repo)

This harness can only test routes rendered by this repo. It cannot fully validate theme-side UI behavior because storefront templates/assets are in a separate theme repo.

Theme-side paths that need separate coverage include:

- `/apps/forestry/health`
- `/apps/forestry/funnel/event`
- `/apps/forestry/customer/status`
- `/apps/forestry/rewards/balance`
- `/apps/forestry/rewards/available`
- `/apps/forestry/rewards/history`
- `/apps/forestry/rewards/redeem`
- `/apps/forestry/wishlist/status`
- `/apps/forestry/wishlist/add`
- `/apps/forestry/wishlist/remove`
- `/apps/forestry/product-reviews/status`
- `/apps/forestry/product-reviews/submit`

These require a theme-repo E2E harness (product page, collection page, cart, checkout handoff) to verify true storefront event continuity.
