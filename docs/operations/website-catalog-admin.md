# Shared website catalog administration

All entitled Managed Website workspaces expose separate Products, Collections,
Customers and Orders destinations. The website editor manages static page content.
`WebsiteCatalogController` and `WebsiteCatalogService` own dedicated product and
collection forms. Existing native customer/order controllers remain separate.

## Data and gates

- Product records, variants, inventory movements and collections are tenant/site
  scoped. Variant IDs are retained for order history; retire availability instead
  of deleting used variants. Product archival preserves records and disables options.
- Existing `tenant_site_media` stores uploaded image metadata. Bytes use shared
  local storage. Draft images require active authorized membership; anonymous
  images require an active referencing product and public rendering/rollout/module
  gates. Failed uploads roll back their records and newly written files.
- Editor and active-product publishing freezes apply. Product revision counters
  and collection revision counters reject stale forms. Product saves retain
  before/after audit records; inventory adjustments record the acting user.
- Customer editing uses the editor/membership gate independently of checkout.
  Existing order operations, payment, shipping and fulfillment safeguards remain.
- No Shopify records, provider integrations, legacy orders or entitlements change.

## Release

1. Run migration lint, MySQL recovery/baseline checks, focused and full tests, and
   frontend build. The additive collection migration has explicit short indexes,
   a partial-state recovery case and a workflow-generated MySQL schema baseline.
2. Deploy via the normal protected GitHub/Forge pipeline and confirm `/ready`.
3. For the existing connected Carolina site, run
   `php artisan website:initialize-connected-catalog --actor=1` as a dry run,
   review, then repeat with `--apply`. It backfills missing descriptions/alt text
   and the existing two collection memberships from the published snapshot once.
   It does not recreate products or overwrite title, price, status or variants.
4. Deploy the matching Carolina renderer to the existing Worker and private Sites
   preview. Its content response requires `catalog` and `collections` arrays;
   an empty array is authoritative, never a request to restore baseline products.
5. Read-only verify both authorized accounts' four workspace destinations, product
   thumbnails, editor controls, public catalog and collection routes. Do not seed
   production test customers/products/orders or send welcome messages.

## Connected storefront behavior

The backend's `publicCatalog` includes only active products and available variants;
stock availability is explicit. Page preview uses the current active catalog.
Product photos, descriptions, prices, SEO and collection memberships take effect
without a static-page publish. New product and collection handles resolve through
dynamic storefront routes. Quote requests validate current scoped records/options
and save inquiries only; they never create orders or take payment.

## Rollback

Preserve the additive collection/media/product records and published page snapshots.
An editor or publishing freeze stops edits without erasing live records. Public
rendering disablement blocks the feed and public media. Coordinate renderer/backend
rollback; do not restore old page-publish synchronization, which would overwrite
catalog edits. Existing published product values can be reviewed from catalog
before/after audit events. No destructive migration rollback is part of release.
