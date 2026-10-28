# Wholesale custom labels and post-checkout requests

This is the Shopify wholesale lane, separate from retail and native Website Commerce.
Buyer submissions default off (`WHOLESALE_LABELS_ENABLED=false`). Artwork management
requires an active tenant owner/admin or an explicitly assigned `wholesale_label_manager`.
Application-review permission does not grant artwork access. To add artwork access
without replacing an existing review role, an authorized operator can set
`WHOLESALE_LABEL_MANAGERS` to a JSON array of explicit integer `tenant_id`, integer
`user_id`, and exact `shop_domain` assignments, then rebuild config. Confirm the
staff assignment with the owner first and record the grant in the staff audit.
Inactive users or tenant memberships remain denied. An empty array revokes these
additional grants; it does not change existing tenant roles.

## Ordering and private evidence

One design per order is the default. New setup costs $20 once; reuse or locating
an old staff-held design costs $5 once. Service quantity is one. Candle sizes and
wax melt scents use multiples of three; products marked `SingleQuantity` retain
single-unit ordering unless they are regular candles/melts. The theme resolves
this in `mf-order-increment`; the checkout Function repeats the rules for regular
and accelerated checkout. Catalog handles identify the two fee products, while
server configuration resolves their specific Shopify variant IDs and fee amounts.

The signed proxy resolves the persisted wholesale store, confirmed tenant mapping,
and signed-in approved Shopify customer. Customer/store IDs in JSON are never
ownership authority. Receipts are persisted before cart changes; cart edits retain
unrelated properties and use current line keys. The order ingestor captures label
requests before discarding service lines. Standard and custom groups stay separate.
Ownership, fee, receipt, revision and quantity mismatches hold the order for review.

Original bytes and checksums live on the private shared `team_private` disk.
Revisions, receipts, assignments and staff events are additive tenant/store/customer
scoped records. Originals and proofs are never shared Shopify product media.
Immutable purchase revision references stay unchanged; staff resolution is recorded
in a separate production revision. Later proof changes cannot alter an old order.

## Artwork preparation and Backstage

Custom Labels appears in MF Wholesale Backstage and links from customers/orders.
Staff imports require a selected Shopify customer and explicit ownership confirmation.
The browser sends up to 50 selected files individually, retaining completed entries
through partial failures. Filenames never assign ownership. Previous-label requests
wait for staff to locate an original; importing alone cannot release production.

The queue uses Node/Sharp, Ghostscript in SAFER mode, and pinned VTracer 0.6.5.
Install the tracer with `scripts/labels/install-vtracer.sh SHARED_STORAGE/app/label-tools`
using Cargo on a build host of the production architecture. Keep the executable
outside atomic release directories. `WHOLESALE_LABEL_VTRACER`,
`WHOLESALE_LABEL_NODE`, and `WHOLESALE_LABEL_GS` can specify reviewed absolute paths.
Queue workers must use the new release and have private shared storage access.

PNG/JPEG/WebP, plain sanitized SVG and single-page PDF originals are accepted up
to 20 MB. `public/.user.ini` sets 20 MB per file and 24 MB per request; the proxy,
Nginx and FPM limits must also permit these sizes. Verify using an actual upload.
No external PDF/SVG resources or generative editing of buyer artwork is permitted.
Black-only path SVGs and transparent PNGs are checked for real nonempty ink and
transparency. Failed conversions retain originals and require a new proof/retry.
Staff compares safe raster previews on white/checkerboard, adjusts crop/threshold
or supplies a black SVG, then approves a particular revision. Downloads/previews
use verified staff requests. Production remains held until valid artwork approval.

## Release and acceptance

1. Run backend/wholesale regression, real converter, desktop/mobile cart wizard,
   Function, theme, migration recovery, rehearsal and MySQL fingerprint checks.
   Release additive backend through protected GitHub/Forge gates. Never replace
   live code directly or bypass migration safety.
2. Install/verify the pinned tracer, private storage, PDF rendering and processing
   worker. Keep new submissions disabled until the complete development-store
   upload, checkout, ingestion, approval and retrieval flow passes.
3. Deploy the complete extension set in `shopify.app.wholesale.toml`. App versions
   replace the previous snapshot, so retain the existing marketing pixel, bundle
   scent validation and marketing embed along with the two label extensions.
   Review the release preview and use `--allow-updates`; never approve unrelated
   removals. The Function's
   custom-label requirement activates only when shop metafield
   `forestry.custom_labels_enabled` is `true`. Quantity protection is independent.
4. Publish only the reviewed files in `resources/shopify/wholesale-labels/manifest.json`
   after a fresh live pull/concurrency check. `config/settings_data.json` is excluded;
   merge feature settings into the current theme, never overwrite merchant settings.
   Enable `mf_custom_labels_enabled` only when the proxy/worker/Function are ready.
5. The public setup product shows the generic Your Company example first, then
   lettering variants and blank kraft label. Returning buyers' private label art
   is rendered per customer and never published as product media.

Use a separate development store and database. Configure `WHOLESALE_LABEL_SHOP`
and setup/reuse variant IDs only in that development environment, with its own
verified Shopify credentials and tenant mapping. Never point development migration
or fixture commands at production. Keep the customer's live cart untouched.

## Post-checkout special requests

The Thank you app block asks: "Is there anything we can do to make this order just
right for you?" It is optional. Session tokens are checked with Shopify's official
PHP package; Shopify order ownership is rechecked against the verified customer.
Thank-you `OrderIdentity` GIDs are normalized to Admin `Order` GIDs before that
ownership check. CORS headers cover validation/authentication failures so buyers
can retry with their text preserved. The request is stored on the Shopify order and in its dedicated metafield, while
preserving prior staff notes. Retries do not duplicate it. Order-creation delays
retain the buyer's text and invite retry. Cancelled/older orders direct to staff.

Enable `WHOLESALE_ORDER_NOTES_ENABLED` after backend acceptance, place the
"Make your order just right" Thank you block, then enable theme setting
`mf_post_checkout_notes_enabled` to remove the former cart note field. Keep the
old cart field until that replacement is working. This standard Shopify plan does
not allow an app block inside the hosted payment steps; the custom-label choice
runs immediately before checkout and the request form runs after completion.

## Rollback

Disable submissions and the corresponding theme/custom-label metafield gates;
leave ordinary wholesale quantity validation enabled. For request-form rollback,
restore the cart-note theme setting before removing the Thank you block. Retain all
originals, derivatives, revisions, assignments, requests and audit events. Do not
remove artwork history or alter production assignments during rollback.
