# Forestry Backstage entry

The retired `backstage.theforestrystudio.com` hostname remains useful to people
with old bookmarks. This Cloudflare Worker serves a public welcome page at `/`
and hands four known entry paths to the current login or Shopify app. It runs
at the edge, as required by the canonical-only domain cutover contract; Laravel
does not accept the old hostname as an authenticated application origin.

The welcome page links to the wholesale staff app, retail store, wholesale store,
and existing support email. It stores no data and creates no session. Only GET
and HEAD are handled. Other hosts, unknown paths, callbacks, APIs, and mutations
retain their original origin behavior. Redirects drop query parameters and use
`Referrer-Policy: no-referrer` to avoid moving stale session or authentication
context across origins. The initial redirect status is 302.

## Verify and release

1. Run `node --test cloudflare/forestry-backstage-entry/entry.test.mjs`.
2. Run `npx wrangler@4.157.0 deploy --dry-run --config cloudflare/forestry-backstage-entry/wrangler.jsonc`.
3. Inspect the existing zone's Workers routes for conflicts. Deploy only this
   hostname's route using the configured Cloudflare account.
4. Run `npx wrangler@4.157.0 deploy --config cloudflare/forestry-backstage-entry/wrangler.jsonc`.
5. Confirm `/` returns 200; `/login`, `/forgot-password`,
   `/shopify/app/wholesale`, and `/shopify/app/wholesale/labels` return 302 to
   their intended destinations. Check the welcome page on desktop and mobile.
6. Confirm the canonical app is healthy, current Shopify app entry still works,
   and unknown old-host paths still return their origin 404.

Rollback removes only the new Worker route or restores its preceding deployment.
Do not disable the application's host guard, change DNS, edit Forge's live
release, or enable direct push-to-deploy for this entry repair.
