# Inventory-owned Everbranch Pay: source verification

October 31, 2026; this is engineering evidence, not a live readiness certificate.

- Disposable MySQL POS/finished-goods/provider/recovery/concurrency run: **101
  tests, 696 assertions passed**. This includes guest checkout, fresh Square
  confirmation, no new Shopify order, exact bundle components, post-purchase
  contact/profile separation, historical reward review/one earning, full/partial
  financial refunds without restocking, duplicate/reordered online events,
  opening-preview rollback, publication uncertainty and last-unit contention.
- The final opening-mapping concurrency fence was then checked with its targeted
  opening preview/apply/retry test: **1 test, 6 assertions passed**.
- MySQL clean schema and committed dump both match the approved fingerprint;
  prior-production migration rehearsal from `96a01343212fb03f08a4fdfa0916dfbdeb953cad`
  passed. The new migration's real durable partial-DDL/index recovery and
  non-destructive down were exercised. Migration safety lint passed.
- Native Swift core: **12 tests passed**. Native simulator UI: **4 tests passed**,
  covering sign-in, Products/Favorites/Keypad, search geometry, variant selection
  and the exact-quantity mix-and-match sheet. The UI test found and fixed the
  keyboard/basket-dock overlap. Simulator fixtures are fictional and cannot pay.
- Independent Enterprise POS and Google sign-in regression assertions passed;
  five local Enterprise cases warn because its optional private `.env` is absent.
  The pinned Enterprise schema and adapter are not expanded by this release.
- Shopify catalog and inventory-level GraphQL operations were validated against
  the pinned 2026-10 schema. Production catalog was read only: 374 active
  non-gift variants, all with images. No physical quantities were imported.

Still required: protected PR checks/deployment, exact signed phone installation,
original-attempt operational cleanup with fresh device/provider evidence,
Columbus remaining physical counts, approved owner/device/build acceptance gate,
actual SDK/card presentation and consented payment, receipt/customer history,
rewards/refunds and settlement evidence. Stop Mirroring for card collection.
A second tenant's shared-stock physical acceptance, Shopify warehouse/bundle
cutover and Apple distribution approval remain separate and unproven. Normal
commercial collection remains gated; a source test never activates it.
