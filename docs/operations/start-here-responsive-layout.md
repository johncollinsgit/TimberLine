# Start Here responsive layout

The tenant `/start` setup guide belongs inside the existing `flux:main` and
workflow column. Flux turns any direct parent of `[data-flux-main]` into a shell
grid with a `min-content` left column. Adding the guide as a sibling of main
placed it in that left column, stacked its label and shifted/clipped the setup
card on phones. Keep future Start Here actions inside the workflow column.

The guide uses page-scoped styles: a minimum 44px target, natural label wrapping,
full width below 640px and a visible keyboard focus ring. Its tenant-bound URL,
setup form, status content and authorization remain unchanged. Shared shell CSS
and other setup surfaces are not overridden.

Focused checks:

```sh
php -d memory_limit=768M vendor/bin/pest -c phpunit.team.xml tests/Feature/Onboarding/StartHereLayoutTest.php
node tests/e2e/start-here-layout.browser.mjs
```

The browser check uses real Fortify login, fictional demo/production workspaces
and a private disposable SQLite database. It checks Chromium and WebKit at 320,
390, 403, 430 and 1440px, the long workspace title, keyboard access and the
existing guide destination. Provider HTTP and outgoing mail are blocked. Set
`START_HERE_EVIDENCE` for screenshots/reports. If this checkout deliberately has
no frontend dependencies, `PLAYWRIGHT_MODULE_PATH` can point to an existing local
Playwright `index.mjs`; no dependency installation is required for this check.
