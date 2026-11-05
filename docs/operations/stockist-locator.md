# Store locator operations

The native locator is an included Shopify capability. It uses the existing
`stockist_locations` and `stockist_locator_settings` tables, not Website Commerce,
orders, customers, rewards or the unpublished broader Replacement Readiness Center.
See `docs/architecture/stockist-locator.md` for scope and permission decisions.

## Management

Open Store locator from the retail embedded Dashboard navigation (or `/stockists`
within the installed app). Active tenant owners/admins can add/edit retailer name,
public address, verified coordinates, order, featured state and draft inclusion.
Hide a location by clearing inclusion, then publish. This retains the draft and
previous publication. Export JSON contains business directory fields only.
Changes never reach shoppers until Publish changes. Concurrent stale edits fail
with a reload instruction. Restore previous publication changes only the published
snapshot; it preserves current drafts. All writes use the existing operator audit.

The Maps connection accepts a restricted Google Maps browser API key. It is
stored encrypted and used only in the public/private map renderer. Staff JSON,
exports and audit payloads omit it. Saving a connection does not create a new
Google key/account or alter provider billing. Maps usage remains on that existing
merchant project. The directory list and directions work when map loading fails.
Private draft links expire after 15 minutes and stop working after a draft change.

Search matches retailer names and visible city/ZIP/address text locally first.
Other explicit address searches use Google Geocoder and return stores within 50
miles. Nearby searches require the visitor's button click and browser permission;
coordinates stay in the visitor's browser. No auto-complete calls or automatic
location prompt. Markers are native keyboard buttons; cluster buttons zoom in.

## Import and publication

Deploy the isolated release through protected GitHub CI/Forge first. Never copy
application code into an active release. The migration preserves source and
publication data on code rollback and is checked with disposable MySQL recovery.

Keep private, checksummed current Omnium exports outside source control. The
November 5 source has 38 locations and five featured locations. The old September
44-location file has eight removed retailers and lacks two current additions.

Review without writes:

```
php artisan stockists:manage import --shop=modernforestry.myshopify.com --file=/private/current-omnium-export.json --expected-count=38
```

After matching the signed-in owner's active tenant membership, import with
`--actor=OWNER_EMAIL --apply`. An optional `--maps-key-file=/private/shop-info.json`
reads the existing restricted `api_key` without printing it. Imports reconcile
all rows before writes, hide stale source rows without deleting them, preserve
an existing publication and treat a repeated source checksum as a no-op.

`stockists:manage inspect --shop=...` reports counts/state/revision without keys.
Publish requires `stockists:manage publish --shop=... --expected-count=38
--revision=EXACT_REVIEWED_REVISION --actor=OWNER_EMAIL --apply`. Omitting `--apply`
is read-only. Review counts and a fresh private store-hosted preview first.
A rollback uses `rollback` with the current reviewed revision and expected count.
Publication changes the native directory only; it never changes a Shopify page.

## Storefront cutover and recovery

1. Verify desktop and mobile navigation reach `/pages/store-locator`, presently
   Shop > Shop Near You. Preserve its handle and menu destinations.
2. Save current page body, template suffix and updatedAt before mutation. Refetch
   immediately before applying and reject intervening changes. Keep Omnium installed.
3. Verify native preview counts, all source coordinates/directions, search,
   empty/reset/denied-location/map-failure behavior, markers/list, mobile layout,
   keyboard focus, asset sizes and current storefront/app health.
4. Publish the reviewed native snapshot, then replace only that page's Omnium
   mount with a titled same-store iframe pointing to `/apps/forestry/stockists`.
   Retain a direct link to the server-rendered retailer list; do not duplicate mutable retailer data in the Shopify page body.
   The iframe needs no cookies or customer login. No theme publishing is required.
5. Immediately verify the published page, first-party proxy, both navigation
   paths, map/search/directions, mobile/keyboard, and representative retail and
   wholesale page status codes. Save dated evidence and exact active release.
6. On any failed smoke, immediately restore the saved page body/template through
   Shopify's normal page update. The prior native snapshot is also available.
   Do not cancel/uninstall Omnium as part of this cutover. Its current plan is free.

No other app replacement, shipping, subscription or commerce gate is changed.

## Verification

Run focused PHP permission/import/snapshot tests, stockist-search Node tests,
MySQL partial-state recovery and the disposable schema baseline refresh/verification.
Register the new recovery case in both PR and deploy migration gates. Also run
Pint, Blade compilation, the full PHP suite, the normal asset build and
`composer test:modern-forestry`. Protected CI remains the production authority.
