# Four-app tablet showroom

The Retail showroom is the fourth app. Twenty independent festival concepts share
Everbranch’s Website Commerce engine, plus a read-only preview of Sawyer Naturals’
62-product source catalog. This is a protected local build; no production website,
merchant account, payment, postage purchase or fulfillment was activated. Demo actions
do not send notifications. Separately approved external outreach is described below.

## Offer and presentation

The existing storefront headline is **Great Businesses. Your next chapter**.
The Retail sales section now uses the canonical **Launch Partner Offer**:
**$89/month for the first six months, then $149/month from month seven**, plus
**$499 one-time setup**, for the **first ten qualifying businesses**, subject to
operator review and written confirmation. A request neither reserves a place nor
activates billing. Both monthly rates appear together. The separate Managed Website
add-on remains $99/month; it was not repriced.

`EvergroveOffer` reads the named offer in `config/module_catalog.php`. Existing
website/workspace demonstrations and the weekly one-hour strategic partnership
remain included in the proposed scope. See `SALES_DEMO_UPGRADE.md` for signup,
OpenAI configuration, browser speech, and physical tablet verification.

## Source and branches

- Backend: `codex/woolly-worm-retail-showroom-2026`, based on reviewed Showcase
  presentation commit `3238f469`. The existing dirty `myapp` checkout was not edited.
- Field: `codex/field-showroom-2026`, based on native client `34af039`.
- Existing Showcase content and its bundled photography/films were reused; the
  Android APK was rebuilt through the standard workflow with refreshed vector logos.
- Studio copies the existing Level Foundations editor/IndexedDB/audio/revision
  engine into a separate deployment, with Renfrow’s three existing illustrative
  books and fourteen chapters. Original Ross source files and recordings were not
  changed. Generated cover photographs are illustrative, not actual author photos.
- ZIP instructions/research are preserved beside this runbook and in
  `resources/data/evergrove_woolly_worm_vendor_research.json`.

## Protected local preview

Start Laravel from this checkout:

```sh
php artisan serve --host=0.0.0.0 --port=8797
```

The local environment uses its own synthetic SQLite database, array mailer, and
fictional owner. Local test credentials are only in ignored
`storage/app/private/retail-local-access.json`; they do not authenticate live Collins
or Field services. To sign in for
review without copying passwords, run:

```sh
php artisan everbranch:retail-local-review
```

That command only works for a local `.invalid` owner. Its signed link expires in
five minutes, is consumed once, and cannot authenticate a production account.
Do not put the generated link in documentation or a commit.

| Preview | Local owner URL |
|---|---|
| Showroom | http://127.0.0.1:8797/retail/showroom |
| Pricing | http://127.0.0.1:8797/retail/showroom/pricing |
| Private outreach review | http://127.0.0.1:8797/retail/showroom/outreach |
| Joy Fudge Company | http://127.0.0.1:8797/retail/showroom/concepts/joy-fudge-company |
| Old Beech Mountain Farms | http://127.0.0.1:8797/retail/showroom/concepts/old-beech-mountain-farms |
| 4 Pelicans Woodworking | http://127.0.0.1:8797/retail/showroom/concepts/4-pelicans-woodworking |
| Leather Link Studio | http://127.0.0.1:8797/retail/showroom/concepts/leather-link-studio |
| McNeill’s Pottery | http://127.0.0.1:8797/retail/showroom/concepts/mcneills-pottery |
| Highland Croft Candle Company | http://127.0.0.1:8797/retail/showroom/concepts/highland-croft-candle-company |
| Mountain Momma Organics | http://127.0.0.1:8797/retail/showroom/concepts/mountain-momma-organics |
| Appalachian DiviniTEA | http://127.0.0.1:8797/retail/showroom/concepts/appalachian-divinitea |
| Avery KnifeWorks | http://127.0.0.1:8797/retail/showroom/concepts/avery-knifeworks |
| Boone Barndog Art & Books | http://127.0.0.1:8797/retail/showroom/concepts/boone-barndog |
| Britt’s Leather Co. | http://127.0.0.1:8797/retail/showroom/concepts/britts-leather |
| Datil Sensation | http://127.0.0.1:8797/retail/showroom/concepts/datil-sensation |
| GW Watts Sauce | http://127.0.0.1:8797/retail/showroom/concepts/gw-watts-sauce |
| Honest Elderberry Company | http://127.0.0.1:8797/retail/showroom/concepts/honest-elderberry-company |
| JC Wicks | http://127.0.0.1:8797/retail/showroom/concepts/jc-wicks |
| Little Elk Candle Company | http://127.0.0.1:8797/retail/showroom/concepts/little-elk-candle-company |
| Lonon Pottery | http://127.0.0.1:8797/retail/showroom/concepts/lonon-pottery |
| Michael’s Exotic Peppers | http://127.0.0.1:8797/retail/showroom/concepts/michaels-exotic-peppers |
| NoodleCakes | http://127.0.0.1:8797/retail/showroom/concepts/noodlecakes |
| PillowSak | http://127.0.0.1:8797/retail/showroom/concepts/pillowsak |
| Sawyer Naturals source preview | http://127.0.0.1:8797/retail/showroom/concepts/sawyer-naturals |
| Studio | http://127.0.0.1:8797/studio/ |

These are actual authenticated routes, not deployed public merchant domains.
Owner tools can generate a revocable 24-hour merchant-specific preview link.
Creating a link sends no message. Presentation mode hides internal tools and
closes private dialogs. Public bootstrap contains no private prospect or customer
notes. All private HTML/API responses use no-store and noindex.

## Data and conversion

`php artisan everbranch:prepare-retail-showroom --dry-run` lists the twenty concepts.
Remove `--dry-run` to create the isolated demo drafts after configuring the owner.
Seeding preserves edits, reuses case-insensitive existing prospects without
replacing their source/contact/notes, and links each prospect to its demo site.
It uses existing Website product/cart/customer services and the canonical draft
onboarding store. Drafts explicitly require a fresh production tenant; branding,
catalog, shipping and the offer are review inputs. Sample customers, orders,
users, notes, and rewards are excluded. No activation route exists.

Each sandbox has a dedicated `ww-demo-*` tenant and tenant-owned `website_*` data.
The simulated receipt/rewards ledger lives in that sandbox’s site settings and
sample Website customer notes, never in paid order/payment/shipment records.
Request keys prevent checkout/rewards replay; server totals use integer cents.
Real staff access requires active verified team membership in the correct tenant.
Offline roles and customer profiles are visibly fictional and stay on the device.

Sawyer’s production tenant, membership state, assets, and edits are not mutated.
The showroom uses the retained source catalog for owner demonstration; inactive
live access continues to follow the existing tenant guards.

## Additional candidates and outreach

The additional thirteen concepts use the published 2026 Woolly Worm Gazette roster,
linked merchant websites, and dated mobile-site observations. Original research is
preserved; additional provenance and unresolved questions live in
`resources/data/retail-additional-vendor-research.json`. Site assessments are private
prospect research and do not enter storefront bootstrap, presentations, or offline
packs. Unverified prices, stock, policies and imagery remain labeled examples.

The private outreach page contains twenty vendor-specific message templates and
research evidence. They introduce John as a fellow Modern Forestry vendor, show the
merchant-specific website opportunity and connect rewards/customer relationships
to repeat online sales. Point of sale is described as **coming soon**. The page
has no send action; storefront checkout, seeding and prospect saves never send
external outreach.

The owner reviewed and explicitly authorized external delivery, then directed the
channel to **Facebook and email**. Use the verified EverGrove Page or authorized
Evergrove email account and confirmed public business contacts for that approved
work. Existing Instagram links remain research evidence, not guessed recipients.
Honest Elderberry is excluded from outreach at the owner's request; its storefront
and private demo configuration remain in the twenty-concept showroom.

The ignored private `storage/app/private/retail-outreach-delivery.json` ledger is the
source for actual delivery status and tool receipts. Check that record before a
retry to prevent duplicates. The committed template's draft/status fields are
reusable copy and must not override actual sent receipts. The owner page reads
private delivery/exclusion information without placing it in public bootstrap or
service-worker caches. Do not commit private ledgers, email jobs, recipients'
private correspondence or tool receipts. No fixed count in this runbook represents
current delivery progress.

## Shipping

Offline estimates are deterministic examples with explicit labels and synthetic
package measurements. They are not actual USPS quotes. Examples cover fragile
packing, separate candle/tea packages, oversized quote-only items, pickup, farm
local-delivery eligibility, and heat-sensitive fudge handling.
Mountain Momma’s pending-confirmation rule is **eligible subtotal over $175**:
$174 and $175 do not qualify; $176 does. Farm consumer parcel shipping is disabled.

`ShippingQuoteProvider` has example and EasyPost sandbox implementations. With an
`EZTK` test key and an explicit origin for the merchant, online quotes use EasyPost
`POST /shipments`, require test-mode USD rates, intersect services across packages,
and aggregate integer cents plus merchant handling. No buy/label/pickup call is
implemented. Production-mode responses fail closed. Missing configuration retains
labeled examples; no live tenant/provider connection is borrowed.

Set these only in protected environment configuration:

- `RETAIL_SHOWROOM_ENABLED=true`
- `RETAIL_SHOWROOM_OWNER_EMAIL=<active verified owner>`
- `RETAIL_SHOWROOM_EASYPOST_TEST_KEY=<test key only>` (optional)
- `RETAIL_SHOWROOM_TEST_ORIGINS` = JSON map from merchant slug to approved synthetic
  US origin (`street1`, `city`, `state`, `zip`, `country`). Each merchant is explicit.

No real carrier credential was supplied for this build. Carrier behavior was
verified with HTTP fixtures, not a live USPS transaction. Provider references:
https://docs.easypost.com/docs/shipments/rates and
https://mountainmommaorganics.com/ (observed 2026-10-15).

## Android installation and offline use

Target devices explicitly. Galaxy: Samsung SM-T577U (Android 12, 1200×1920).
Additional tablet: Lenovo TB336FU. ADB uses the foreground server on port 5038;
background Mac ADB could not access the LAN. Pairing and codes are not stored here.
Use `adb -P 5038 devices -l` and the correct `-s` value for every device command.

Forward the local preview independently on each tablet:

```sh
adb -P 5038 -s <tablet> reverse tcp:8797 tcp:8797
```

Open the local sign-in link once in tablet Chrome. Localhost is a secure browser
context for PWA installation even though the development URL uses HTTP. Install
Retail and Studio through Chrome’s **Install app** / **Add to home screen** flow.
Their manifests have separate IDs/scopes/start URLs and permit tablet orientation.
PWA icons have separate regular/maskable exports and versioned URLs; retain the
installed identities and browser data when refreshing them. Open each installed
app online, reload once after an update, and use **Prepare offline demos** (or
**Refresh offline demos**) before leaving the connection.

Native installers:

- Field 2.3.29/build 45: sibling Field checkout’s
  `artifacts/Everbranch-Field-2.3.29-Android.apk`.
- Showcase: original Showcase project’s
  `artifacts/native/Everbranch-Showcase-Android.apk`.

Use `adb ... install -r <apk>` to preserve app data. Never uninstall an existing
app to bypass a signature conflict. Field keeps its existing live authentication,
workspace switching and Collins API behavior. The sign-in page and account screen
also offer **Explore a sample Field business**, which opens the bundled fictional
business without credentials. Its network policy blocks external requests.
An optional Firebase configuration absence no longer crashes startup. Final native
artifacts are standard npm/web/Capacitor/JDK 21 Gradle builds with matching installed
signatures. See `LOGO_CLARITY.md` for vector assets and exact build evidence.
Use the same final artifacts on both tablets; a prior installation does not prove
a subsequent rebuilt APK has been installed.

Retail’s `everbranch-retail-v3` service worker caches public shell/illustrative
assets only. Its upgrade removes v2 caches; offline preparation explicitly reloads
the public CORE shell, all five versioned manifest icons and merchant photographs.
This prevents a new twenty-concept data pack from retaining the old seven-concept
interface. Confirm twenty cards and twenty-one pack entries (including Sawyer)
after the refresh. No authenticated showroom HTML is precached. A sanitized
30-day demo pack and per-merchant sample carts are local; APIs, credentials, private
prospects, staff notes, and live data are excluded from service-worker caches.
Network failures can reopen the saved demo even when Wi-Fi remains on. An online
401/403 does not grant access to the offline fallback. Reconnecting requires a
reload and verified live sign-in before real tools return.

Studio writing, revisions, and recordings save locally in IndexedDB. Its private
Renfrow cloud book is not yet connected; the UI says so. Connecting requires the
book’s own credentials or owner provisioning in the existing Studio service.
The local book, three covers, and recordings work without cloud sign-in. Full
covers and wrapping titles remain visible at tablet and phone widths. Live speech
recognition/model downloads and cloud sync need their respective online setup.

Until an approved hosted release exists, live local preview/CRM/gateway access on
the tablet depends on this Mac’s server and ADB reverse connection. Prepared sample
storefronts and Studio’s local work survive offline. Do not clear browser storage.

## Release

Keep the default-disabled showroom configuration. Review the feature branches and
Showcase presentation dependency, run the GitHub test/build gate, then use the
existing protected Forge release hook/atomic deployment/readiness process. Do not
push directly to a live directory or enable push-to-deploy. Enable only the
verified owner; run the idempotent seed command in the approved environment.
Merchant approval and separate real commerce readiness remain required. No customer
activation, production publication, payment or postage is authorized by this local
preview. Approved external outreach has its own explicit owner instruction and
private delivery record; application demo actions do not authorize or send it.
Production publication still requires the owner’s authorization.

## Evidence

`output/retail/` is ignored local review evidence. It contains browser and tablet
screenshots, `browser-results.json`, and physical verification notes. Validation
commands and exact results, physical checks and remaining limits are in
`VERIFICATION.md`. The browser report records any resumed completed flows. Generated
merchant captures use compact JPEGs; eight `all-twenty-*.webp` sheets cover every
merchant in portrait/landscape. Capture-format/crop mappings preserve provenance
when older generated evidence is compressed. No private credentials,
cookies, or generated sign-in links belong in version control.

## Offline photo repair — 2026-10-17

Cache v5 includes all 75 public demo photographs in its install transaction. An
update cannot retire the previous cache until the complete replacement shell and
photos are saved. Existing public photos carry forward without another download.
Assets load from the device without waiting for weak Wi-Fi; initial navigation
and catalog connection attempts fall back after four seconds when unreachable.
`public/retail/photos.json` must list every bundled WebP, including Sawyer catalog
assets; add newly introduced files to that manifest. Offline preparation downloads
the same complete set and reports success only after all requests succeed.

Reopen the installed Retail app while the Mac server and wireless ADB reverse are
connected, sign in if required, and choose **Refresh offline demos**. Keep the app
open until it reports **Offline pack ready**. The saved storefronts, product
photos, carts and fictional staff tools then work if that connection drops. Live
AI, signup, CRM saves, real sign-ins and carrier calls still require their online
services. Never clear browser storage to repair photographs.

Run `node scripts/retail/photo-offline-check.mjs` to verify a v4-to-v5 cache upgrade
without another prepare click, all twenty concept storefronts and Sawyer's
62-product preview with networking disabled, and exclusion of private cache data.
