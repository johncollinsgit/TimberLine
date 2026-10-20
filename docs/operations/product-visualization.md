# Product visualization: Mac handoff

Prepared October 20, 2026. This is an implementation candidate and installation
handoff, not a live deployment or an accepted native app release.

## Intended experience

- Existing Everbranch Website products gain a tenant-managed visualization setup.
- Knives: rotate/zoom the actual product model; enable the phone camera; follow
  the shopper's hand; drag the grip, adjust tilt/turn/roll/scale and compare handles.
- Carolina Barrel furniture: rotate/zoom, then place the object in a room using
  supported phone AR. Room placement is separate from hand tracking.
- Use the same product assets in the Everbranch web app, mobile Safari/Chrome
  and the existing Capacitor mobile app. No duplicate product catalog.
- Hand previews can save an image or record up to 20 seconds locally where the
  browser supports it. Native sharing uses the supplied adapter, after integration.

## What is implemented

`ProductVisualizationController` and `ProductVisualizationService` add an
authenticated settings/upload screen under the existing tenant Website catalog.
Settings use `website_products.service_details.visualization`; model bytes reuse
`tenant_site_media` with tenant, site and product-specific ownership. No migration
is required. Existing catalog revision checks, active-product publishing freeze,
canonical Website access and audit events apply. No Shopify, Marketplace, orders,
customers, checkout, provider or commercial settings are changed.

The ordinary product editor links to `/website/products/{product}/visualization`.
Native Website public product pages mount the viewer. Verified hosts also have
`/products/{handle}/visualize`, preserving existing included-subdomain limits.
The connected catalog now returns `visualization` (null unless ready).
Selected models on published active products may be read anonymously; draft,
unselected and foreign-product models remain guarded. Only deliberately public
model bytes get anonymous CORS for use by a connected renderer/native viewer.

The isolated browser package pins model-viewer 4.4.0, Three 0.183.2 and MediaPipe
Tasks Vision 0.10.32. JS, Worker, WASM and a checksum-pinned hand task are
self-hosted. Hand inference runs off the UI thread, one frame at a time. Camera
permission follows a deliberate action; close/unmount/background/errors stop
capture. No microphone, frame upload, paid model service or AI billing is added.
Skeleton-based finger masking is approximate, not full hand surface segmentation.

## Backend install on Mac

1. Locate the current `johncollinsgit/TimberLine` checkout. Read its current
   AGENTS/orientation and preserve dirty work. Base for this candidate:
   `29703b4cbb82d67abc32c2f090a2551302979b39`. Work is isolated on
   `codex/product-visualization-20261020`. The final handoff records any review-branch push. No PR, merge or deployment is performed.
2. Review/apply the handoff patch on a feature branch. Run `git apply --check`
   first. If main advanced, use normal reviewed three-way conflict resolution.
   Do not copy older whole files over current work or reset/clean/stash another
   task's work. Coordinate shared routes, package build and orientation edits.
3. Use Node >=20.19 and the repo's PHP/Composer environment:

   ```sh
   composer install
   npm --prefix resources/product-visualization ci
   npm --prefix resources/product-visualization test
   npm --prefix resources/product-visualization run build
   php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/ManagedWebsite/ProductVisualizationTest.php tests/Feature/ManagedWebsite/WebsiteCatalogTest.php
   php scripts/ci/lint-migrations.php --base=HEAD --working-tree
   npm run build
   ```

   Root `npm run build` now includes the isolated feature build. Runtime setup
   copies the pinned package's WASM and fetches only the official hand task with
   SHA256 `fbc2a30080c3c557093b5ddfc334698132eb341044ccee322ccf8bcf3607cde1`.
   Do not remove checksum verification. Runtime/dependency directories are
   ignored. The handoff's prebuilt browser folder is available for inspection
   and app/renderer packaging; it does not replace checked builds or release gates.
4. New gates default off:

   ```dotenv
   PRODUCT_VISUALIZATION_ENABLED=false
   PRODUCT_VISUALIZATION_TENANT_IDS=
   PRODUCT_VISUALIZATION_HAND_ENABLED=false
   ```

   Use exact reviewed staging tenant IDs. Canonical Managed Website access and
   its rollout remain mandatory. Room setup does not enable hand mode, buy an
   entitlement or activate a tenant. Do not invent tenant IDs from demo names.
5. Open the saved product's visualization screen. Upload complete measured GLBs,
   set placement, default/optional handle models, dimensions and grip. Review the
   model and confirm dimensions before enabling/saving. Editor preview is private;
   public display additionally needs current product/site publication.

## Merchant assets

Use self-contained GLB 2.0, under 30 MB, with embedded textures. External files,
required extensions, Draco/Meshopt/Basis compression and excessive accessor
counts are rejected. Prefer a few MB, modest geometry and textures <=2048 px
for phones. The validator checks the container and supported resource contract;
it is not a complete glTF conformance or physical authenticity validator.

Enter the longest dimension in mm; inspect model proportions as well. Room
models are upright on +Y with a sensible floor origin. Hand models point along
+Y and use a grip point defined as fractions of the bounding box. Rotation fixes
authored orientation. Handle/finish variants can have different model IDs; this
first version shares dimensions. For variants with different physical sizes,
extend dimension overrides or use separate product setups before claiming scale.

A single image cannot supply hidden surfaces/real depth. The Avery source image
inspected is a handle close-up. A real complete knife model still must be obtained
or authored; the same applies to actual Carolina Barrel furniture. Synthetic QA
geometry and the AI concept video are not merchant models or true size proof.
Hand scale uses optional measured palm width but remains approximate. Room AR
accuracy also varies by device/surface. Comfort, weight and balance cannot be
assessed by this viewer.

## Connected renderer: Carolina Barrel and Avery

The connected storefront renderer is a separate app and is NOT edited by this
backend patch. Identify the sources owning the current Carolina Barrel website
and `everbranch-retail-demo.on-forge.com`; preserve their domain/publication setup.

Copy the built `public/product-visualization/` folder to each renderer's public
assets as part of its build. Use
`docs/integrations/product-visualization/connected-renderer.mjs` at the existing
product page/modal, passing the current product's `visualization` descriptor
from the authorized connected catalog. Null hides the feature. Never hardcode a
separate product list; keep variant selection aligned with the current product.
Unmount the component on navigation to stop capture. Supply the renderer's
shareable `/products/{handle}/visualize` route for native room-AR links, resolving
the same published product and verified host. This consumer/route wiring remains
Mac work and is not an already-live website claim.

Prefer same-origin JS/CSS/WASM. Product GLBs may use the backend's guarded public
asset route. Serve WASM as `application/wasm`, GLB as `model/gltf-binary`, and JS
as JavaScript. Verify HTTPS/Workers/WebAssembly/WebGL and the existing CSP; use
narrow same-origin worker/WASM allowances rather than broadly weakening script
policy. Do not load executable script URLs from arbitrary product descriptors.

## Native mobile app

The inspected `everbranch-mobile` source is React/Vite with Capacitor 8, not
React Native. Continue in the current `johncollinsgit/everbranch-mobile` Mac
checkout; do not overwrite current auth/offline work with an older PC checkout.

`docs/integrations/product-visualization/mobile/ProductVisualization.tsx` is a
supplied adapter; it is not yet wired, typechecked or built in the native app.

1. Mount it on the existing tenant product/catalog screen using current authorized
   data. If that app has no Website product feed, add a narrow read-only endpoint
   using `auth:sanctum`, `mobile:read`, `mobile.tenant`, canonical Website access
   and current public site/product checks. Do not substitute Field Service or
   legacy Shopify data. Do not persist descriptors across tenant changes/logout.
2. Copy the complete built viewer folder (chunks plus runtime) into mobile
   `public/product-visualization/` before Vite/Capacitor build. The adapter imports
   this packaged code, not downloaded executable code. Published model bytes
   remain read-only resources. Pass a verified HTTPS public viewer URL and an
   explicit backend/storefront host allowlist.
3. Room mode opens that verified page via Capacitor Browser to reach the phone's
   AR viewer. Quick Look/Scene Viewer may leave the app WebView. If the tested
   in-app browser cannot launch AR, use the app's approved system-browser opener
   for the same verified URL. Do not claim room AR stays in the native WebView.
4. Pin a compatible v8 `@capacitor/share` (the other used plugins already exist),
   run `npx cap sync`, the existing release doctor/build workflow and TypeScript
   checks. Cache file sharing must be verified on both native platforms. The
   adapter uses native App/Camera/Filesystem/Browser/Share and no automatic sends.
5. Verify existing iOS `NSCameraUsageDescription` and Android camera permission
   plus WebView grants. A Camera plugin permission request does not prove
   continuous getUserMedia support. No microphone/extra Photos permission is
   needed just to preview. WKWebView Workers/WASM/WebGL and background/foreground
   behavior need real-device testing. The adapter stops capture on app background
   and unmount. If tracking cannot run in a WebView, show a clear browser fallback.

## Verification and remaining work

PC: 17 affected Laravel tests / 122 assertions; four projection/configuration
tests; full root production build (including type checks and 24 Node tests); changed-migration lint (zero new
migrations) pass. Browser inspection rendered the synthetic barrel, opened/closed
both modes, and initialized the self-hosted Worker+hand model without camera
access. The separate 24-second 720x1280 H.264 concept MP4 fully decodes.

Still required: protected CI; actual connected-renderer wiring;
native screen/API wiring and TypeScript/native builds; real measured merchant
models; physical iPhone Safari and Android Chrome/native checks; open/closed
grips and both hands, loss/reacquisition, denial/revocation and camera switching;
no camera indicator after close/background/unmount; model/handle switching;
save/share; room floor detection, movement/rotation/scale and Quick Look/Scene
Viewer behavior. Try low light and slower phones. A convincing closed grip may
need hand mesh/segmentation work after device evaluation; skeleton masking is
only an approximate depth occluder.

Release coordination: the Mac owner is reconciling guided settings PR #444 and
a separate Marketplace redesign. Keep this feature isolated; no independent
merge/deploy or production tenant/model activation is authorized by this handoff.
Retain normal GitHub test/build and Forge atomic release gates. Rollback turns
off the visualization flag (or hand-only switch) and preserves all media/settings.
No destructive cleanup/database rollback is needed.

Primary references: [model-viewer AR](https://modelviewer.dev/examples/augmentedreality/),
[MediaPipe](https://developers.google.com/edge/mediapipe/solutions/vision/hand_landmarker/web_js),
[Capacitor Camera](https://capacitorjs.com/docs/apis/camera),
[Browser](https://capacitorjs.com/docs/apis/browser), [Share](https://capacitorjs.com/docs/apis/share).
