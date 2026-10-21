# Avery-only illustrative knife visualizer

This candidate extends the existing Avery-only preview at `/avery`. Its protected
base is `573ee0c4f3036fdcfe2b97b1743ae070f1b8102b`; the original checkout's two
later unrelated terms/docs commits are excluded. This is not a main application,
merchant, product-model, billing, provider, or Android release.

## Existing authority, unchanged

The current Avery access form, configured hash, 12-hour versioned grant,
request-local unprivileged identity, staging environment, and exact presenter
host are reused. No new code, gate, account, secret, tenant grant, or environment
setting is introduced. The concept page adds an Avery-guest-only entry to
`/avery/visualizer`. Presenter and other merchant content keep their existing flow.

Only two exact GETs are added to `RestrictAveryGuest`: the page and
`/avery/visualizer/model.glb`. Both independently check the existing gate/grant;
the page redirects to `/avery` without a grant, and the model fails closed.
All query inputs and other model paths are rejected. A deactivated/unverified
guest remains denied by the existing identity check. No visitor is logged into
the platform guard. The middleware uses the equivalent response HeaderBag API
to support the protected binary response as well as HTML/JSON.

The fictional model is in `resources/retail-visualizer/`, outside `public/`.
Both protected responses are private/no-store/noindex; the model is not an
offline pack. These routes are outside the existing `/retail/` service worker.
Generic executable libraries and their licensed tracking model are public static
assets; they contain no grant, tenant/customer data, or merchant model.

## Model and UI provenance

Existing public Avery logo/catalog photography is reused, with no new remote
image fetch. The entire knife GLB is original deterministic fictional geometry:
tapered blade/tip, bolster, complete contoured handle, and pins. Rebuild it with
`node scripts/retail/build-illustrative-knife.mjs`. Its nominal 300 mm preset is
illustrative, not Avery product measurements. The page, dialog, and caption
explicitly distinguish the sample from the public catalog photograph and make
no actual Avery fit, comfort, sizing, safety, or tracking-accuracy promise.

Standalone runtime is copied from the reviewed package at TimberLine source
`a5cf35775e2594ffa5a39e0a9972da2e6184accf`, tree
`60f47dfa679871b4c2961641349e1daa53c94fa2`. Exact upstream hashes, derivative
hashes, licenses, and the sole runtime correction are recorded in
`public/avery-visualizer/build-manifest.json` and `THIRD_PARTY_LICENSES.txt`.
Versions: model-viewer 4.4.0, Three 0.183.2, MediaPipe 0.10.32; the package had
already been built with Vite 7.3.7. No new runtime download or root Vite/Tailwind
entry is needed. The existing root `public/build` can be retained unchanged.

The one derivative corrects the WASM base `runtime + 'wasm/'` to
`runtime + 'wasm'`: MediaPipe appends the separator itself. This avoids the
double-slash path that failed on the independently tested Android asset server.
It does not modify the held app/APK. `node scripts/retail/check-avery-visualizer.mjs`
independently verifies every copied hash and reconstructs the exact original
bundle hash by reversing that one substitution, and checks the private GLB.

## Camera, capture, and failure behavior

Opening the page or 3D dialog does not request a camera. The separate **Start
camera** button requests video only. Permissions-Policy prohibits microphone
and geolocation. Camera frames and hand processing stay in the browser; no
provider is contacted. Denied/missing camera and missing model/runtime report a
truthful failure; 3D remains a fallback when the camera cannot start.

The reviewed viewer stops tracks, Worker, scene, and pending capture intent on
close, pagehide, disconnection, or background visibility. A cancelled recording
or pending photo does not later export. Explicit Save image/Record clip are local
browser downloads only; no uploads, native Share hook, purchase, merchant/model
activation, or real message exists in this page. The demo needs a connection and
the current Avery grant; it is not presented as an offline application.

## Verification and reproduction

Focused PHP checks include actual unlock through a fictional test code, private
model bytes/header/identity handling, expiry/hash rotation, disabled/prod/host
boundaries, foreign inputs/mutation rejection, zero database writes and zero
provider HTTP. Run with an isolated compiled-view/temp path:

```sh
VIEW_COMPILED_PATH=/tmp/avery-visualizer-tests/views \
TMPDIR=/tmp/avery-visualizer-tests/tmp \
vendor/bin/pest tests/Feature/Retail/AveryVisualizerTest.php \
  tests/Feature/Retail/AveryGuestAccessTest.php \
  tests/Feature/Retail/RetailShowroomTest.php
node scripts/retail/check-avery-visualizer.mjs
```

The headless browser harness uses a disposable loopback SQLite staging fixture,
an explicitly fictional code, synthetic video, and no physical camera. It proves
the real Laravel grant/entry/model path, phone/tablet layout, full silhouette,
permission denial/device unavailable, actual self-hosted Worker/WASM/tracking-model
startup, single-slash paths, teardown, return navigation, and offline model error.
It rejects all foreign HTTP origins and records any non-gate mutation requests.
Artifacts are written to ignored `output/retail/avery-visualizer-qa/`.

```sh
AVERY_VISUALIZER_QA_ENGINES=chromium \
AVERY_VISUALIZER_QA_CHROMIUM_EXECUTABLE='/path/to/installed/headless-compatible/Chromium' \
node scripts/retail/avery-visualizer-browser-check.mjs
```

For a repeat against the **same** local fictional fixture, set
`AVERY_VISUALIZER_QA_REUSE=1`. The fixture builder refuses any non-loopback/non-test
database path or existing database. An optional `PLAYWRIGHT_PACKAGE` points at
an already installed compatible package. No browser download is implicit.

Release owner only: the same harness accepts
`AVERY_VISUALIZER_QA_BASE_URL=https://everbranch-retail-demo.on-forge.com` and
reads the existing access code from hidden standard input, never an argument,
file, log, screenshot, or public source. This mode does not seed or start a local
server and permits only that exact preview host. It performs the existing gate
POST plus GET/model/browser checks; no provider, other mutation, or real camera
is used. Respect the existing access-form rate limit; do not reset it for QA.

Headless evidence is bounded. WebKit engines were unavailable in the current
local cache, so they are not claimed. Real iPhone/Android hand fit, occlusion,
camera quality, AR, and sharing remain separate acceptance work. The independent
Android task owns any subsequent APK build/device rerun.

## Publication and rollback

The coordinator owns exact-SHA GitHub checks and the isolated preview release.
Retain the existing versioned environment and shared storage; no configuration
cache, code/hash rotation, schema migration, main merge, or production Forge hook
is needed. Verify the existing access flow, protected page/model, Avery-only
bootstrap, denied foreign merchant/Studio, and tracker lifecycle after switching
the preview pointer. Roll back only to the recorded prior preview release if
those checks fail. Older checkpoints, app work, credentials, and other releases
remain outside this candidate.
