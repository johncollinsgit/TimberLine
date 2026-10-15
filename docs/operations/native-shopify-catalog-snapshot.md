# Preparing a native catalog from a reviewed Shopify snapshot

This explicit CLI adapter prepares drafts in an existing tenant-owned Website.
It consumes a complete local export and makes no provider requests. It never
creates a workspace, edits a Shopify connection, imports customers/orders,
publishes a site/product, enables commerce, sends mail, or buys postage.
Modern Forestry's Shopify catalog is an approved read-only source for this pilot;
its existing checkout and operational data stay in their separate lane.

Review the existing tenant/site, operator membership, source shop domain and Shop
GID before running. The actor must be active with an eligible tenant catalog role,
and the existing Managed Website editor and entitlement must be available. Checkout,
shipping, publishing and notification activation are independent of this command.

```sh
php artisan website:import-shopify-catalog-snapshot /private/reviewed-export.json \
  --tenant=approved-pilot --site=SITE_ID --actor=ACTOR_ID \
  --expected-shop=reviewed-shop.myshopify.com \
  --expected-shop-id=gid://shopify/Shop/REVIEWED_SHOP_ID \
  --report=/private/new-readiness-report.csv
```

The default is a dry run. Review its aggregate output and CSV readiness/skip/conflict
codes, then repeat with `--apply` only for the approved native draft target. Reports
must be new local files and are created with mode 0600 before any apply. Keep the source JSON and real catalog reports outside Git.
An apply with conflicts or any native orders/active holds makes no catalog changes.
The source checksum printed by the command identifies the exact bytes validated,
even if the export path is later replaced.

The version-1 contract requires the Shopify 2026-01 query-only exporter, an explicit
USD shop identity, credential-free provenance, final pagination completeness for
products/variants/images/media/collection links/typed measurement metadata/feature
flags, and equal exact before/after product and variant counts. Optional collection
capture may be unavailable; existing native memberships are then retained. This is
an export snapshot, not a transactionally consistent or continuously synchronized
view of Shopify.

Native identity comes from tenant/site/provider/shop/type/GID mappings. Titles,
handles and SKUs never identify an existing target. Handle collisions, deleted
source/target rows, an altered source Shop GID, or operator changes to managed
fields require review. No automatic deletion or identity reassignment occurs.
Original HTML, all captured images/options/metadata, source status and timestamps
stay in encrypted provenance; native descriptions use plain source text. Only
HTTPS Shopify CDN image URLs are accepted, and no images are downloaded by the
command. Supported variant options and images are retained on native variants.

Every imported product remains draft, including source archives; source status is
retained in encrypted provenance and non-active source variants stay unavailable.
Gift cards, selling plans, bundles, mixed physical/inventory behavior, and native
variant/field limits are explicit skips. Negative/unknown/out-of-bounds tracked
source quantities project to zero native sellable units with availability disabled;
the exact original quantity is preserved encrypted and the CSV requires allocation
review. This unavailable projection creates no stock units. Non-image media, tax
policy, collection display order and source overselling behavior are reported for review. Native limits
remain 100 variants and 12 display images. Larger galleries display their first
twelve validated images, retain the complete original gallery encrypted, and emit
an explicit gallery-review code; they are not silently discarded.

Positive measured weights convert from grams/kilograms/pounds/ounces and round up
to native whole ounces. Missing/zero source weights remain missing. Typed dimension
metadata alone does not identify measured item axes: length/width/height remain
unset until reviewed measurements are supplied. Existing operator edits, including
weights and dimensions, are protected by the last imported target fingerprint.

Inventory quantities aggregate Shopify locations and are only a point-in-time
snapshot. Subsequent Shopify sales do not decrement native stock. Every report
states `inventory_snapshot_only`; every mapping retains that provenance. An explicit
merchant-approved pilot stock allocation is required before any future activation.
Review ship-from origin, missing weights, item dimensions or tested package capacity,
packing tare, tax/settlement, provider accounts, delivery recovery, transactional
mail and actual printer calibration separately. This adapter establishes none of
those launch approvals and offers no inventory sync or Shopify write-back.

The mapping migration uses short named keys, restarts after a bare-table MySQL
interruption, and retains identity/provenance on code rollback. Its required MySQL
recovery case is registered in the existing release-gate suite. Generate and review
the combined disposable MySQL schema baseline before production deployment.
