# Verification — 2026-10-15

- `php vendor/bin/pest tests/Feature/Retail`: **41 passed, 223 assertions**.
- Website Commerce safety/operations, canonical onboarding draft store, and
  landlord prospect onboarding pipeline: **21 passed, 165 assertions**.
- `node --test tests/retail/commerce.test.mjs`: **6 passed**.
- `node scripts/retail/qa.mjs`: **18 scenario groups passed, zero page errors**.
  All seven homepages, product details, search, cart/shipping and staff tabs were
  inspected at 1280×800 and 800×1280. Also checked confirmation, customer history,
  private notes, prospect review, corrected pricing/weekly offer, offline reopening,
  offline role switching, Studio’s three books, autosave, synthetic microphone
  recording and recovery after reload. Screenshots are in `output/retail/screenshots`.
- `node scripts/retail/offline-and-studio-check.mjs`: **5 passed**. Checked
  network-failure fallback with Wi-Fi still online, refusal to bypass online
  permission failures, staff demonstrations in presentation mode, full Studio
  titles at 600/800/1280 pixels, and exclusion of authenticated Studio/API responses
  from service-worker caches.
- `npm run build` (backend): passed, Vite **2.73 seconds**.
- `php scripts/ci/lint-migrations.php --base=3238f469 --working-tree`: passed,
  **zero changed migrations**.
- `vendor/bin/pint` on new PHP implementation/tests: passed/fixed formatting.
- Field `npm run build`: passed. Existing bundle-size advisory remains.
- Field `npm test`: **19 files / 70 tests passed**.
- Field Android `assembleDebug` with JDK 21: passed, **461 tasks** on the final
  build with the existing matching Android Firebase configuration. APK is 2.3.29,
  build 45, debug signed. Gradle reports existing deprecation/flatDir advisories.

Shipping checks include $174/$175/$176 strict threshold behavior, quantity-based
ceramic protection, mixed candle/tea packages, oversized woodworking, rejected farm
shipping, invalid inputs, foreign cart/customer isolation, unique item limits,
checkout/rewards replay, blocked real commerce/notification side effects, test-only
carrier keys/origins, fixture USPS rate aggregation, and rejection of production
carrier responses. No actual live carrier transaction or postage purchase ran.

Physical tablet verification and installation status are recorded separately in
`output/retail/tablet-results.json`. Field and Showcase were installed on both
tablets. Galaxy Retail and Studio WebAPK packages are installed; Lenovo Retail
was added to its home screen. Final Lenovo Studio installation, home-screen
launches, physical offline reopening, and restoring the original 30-second screen
timeouts await reconnection after the tablets changed Wi-Fi networks. A physical
Galaxy microphone recording saved successfully. Physical live Collins authentication is not verified;
new installations need the user’s existing team credentials. Renfrow cloud pairing,
real merchant approval and production hosting are not completed by this local build.
