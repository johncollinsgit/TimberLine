# Evergrove Payments iPhone rehearsal

This is an isolated native SwiftUI iPhone app with two deliberately distinct surfaces:

- **Register demo** bundles the existing Alpha Printing browser register. Its JavaScript and CSS are copied unchanged from TimberLine, preserving Register, Customers, Inventory and Demo setup, the four exact catalog SKUs/prices, guest and sample-customer checkout, card/cash/change, uncertain result recovery, partial refunds, history and receipt preview. This is the same fictional flow installed as a home-screen demo on the Android tablet, not evidence of an Android APK or payment SDK.
- **Recovery lab** exercises a separate native Swift actor and durable local fixture journal, including explicit merchandise, printing-service and postage lines. These new line semantics are not claimed to exist in the original merchandise-only register. It has no production HTTP endpoint or PSP adapter.

The application cannot collect a live payment. Neither surface accepts card details, merchant credentials or a provider/account choice. Fixture success is always labelled simulation and never yields a paid receipt. Wren/Watershed's merchant account is not used or referenced.

## Reused register and security boundary

`scripts/sync-register-demo.py` resolves the canonical Blade template into bundled HTML, copies its exact scripts, CSS, logo and existing illustration, changes shell branding to Evergrove Payments, and makes receipt printing explicitly unavailable in this iPhone preview. `App/RegisterDemo/PROVENANCE.json` records SHA-256 hashes of canonical source inputs. The read-only check detects source/bundle drift, generated HTML changes, unexpected bundle files, unresolved Blade and remote resource references:

```sh
python3 scripts/sync-register-demo.py --check
```

When this standalone checkout is outside TimberLine, pass `--repo-root /path/to/TimberLine`. Regenerate deliberately by omitting `--check`.

The WKWebView uses a nonpersistent website data store, no script-message handlers, no credentials/cookies shared with operational apps, and no native payment bridge. It may read only its `RegisterDemo` bundle folder. Navigation allows only the exact encoded path of the bundled `index.html`, optionally with its `#main` anchor, and denies queries and popup/new-window actions. The local document's CSP prohibits network connections, forms, objects and frames. No remote demo page is loaded. This remains a browser register hosted in a native app; the actual card-present SDK integration is pending.

The original register keeps fictional state in its WebView tab session; closing the app may reset that register session. The native recovery lab persists its separate sample IDs, amounts and states across app relaunch. Do not treat the browser session as a production durable payment journal.

## Native attempt invariants

The shared rehearsal DTO uses `request_id`, `sale_id`, lowercase `usd`, and strict line kinds `merchandise`, `printing_service` and `postage`; line fields are `line_id`, `kind`, `quantity`, `unit_amount_minor` and `tax_amount_minor`. Limits align with the backend milestone: 1–50 lines, unique line IDs, quantities 1–99, positive unit minor amounts, nonnegative total tax per line and total at most 100,000,000 minor units. Checked integer arithmetic rejects overflow. Production must resolve prices, taxes, authorization, tenant/register and immutable merchant/provider bindings on the server.

An actor serializes repeated taps and preserves the complete request for idempotency comparison. It saves the reservation before collection and saves `processing` before the simulated result. Relaunch restores either an unfinished reservation or processing attempt as `unknown`. Uncertain results block replacement sales; recovery uses the original attempt/request IDs and never starts another collection. Decoding a saved journal revalidates money, identifiers and state invariants. A storage error fails closed. Fixture success remains `evidence_source=local_fixture`. The mock-only `FixtureCheckoutTransport` protocol is the injection seam; no production transport exists.

`FileFixtureJournalStore` is a **single-engine, single-process rehearsal store**, with atomic replacement, iOS complete file protection and best-effort backup exclusion. It does not implement cross-process file locking or authenticate stored records. The backend owns production payment durability, concurrency, canonical status, reconciliation, receipt and refund servicing. This local store must not be promoted to production authority.

## Build and verification

The project uses Apple frameworks only; there are no external dependencies. Swift core tests run on macOS; app and UI targets require Xcode. The generated Xcode project is committed and uses relative paths. Its unique rehearsal bundle ID is `com.evergrove.payments.rehearsal`, with no selected Apple team, no payment entitlement and no automatic provisioning/update flag.

```sh
swift test --scratch-path build/swift-tests --cache-path build/swift-cache
python3 scripts/sync-register-demo.py --check
xcodebuild -project EvergrovePayments.xcodeproj -scheme EvergrovePayments \
  -configuration Debug -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build/DerivedData CODE_SIGNING_ALLOWED=NO build
xcodebuild -project EvergrovePayments.xcodeproj -scheme EvergrovePayments \
  -configuration Release -destination 'generic/platform=iOS' \
  -derivedDataPath build/DeviceDerivedData CODE_SIGNING_ALLOWED=NO build
```

In a nested executor sandbox, SwiftPM manifest isolation may fail with `sandbox_apply: Operation not permitted`. In that environment only, `swift test --disable-sandbox` allows the dependency-free local test manifest to run inside the executor's existing sandbox. This flag does not grant filesystem/network access.

For UI testing, create a dedicated iPhone simulator, pass its identifier as the `-destination`, use `-parallel-testing-enabled NO`, a separate derived-data path and `-resultBundlePath`. The UI test journal namespace is a DEBUG-only UUID supplied by the test runner; it never changes payment authorization. `scripts/generate-project.py` recreates the dependency-free project if source paths change. Build products and logs stay outside version control.

## Existing Mac apps and release gates

Read-only inspection found `/Users/johncollins/Code/everbranch-mobile`, canonical repository `johncollinsgit/everbranch-mobile`, with an existing packaged Capacitor app, `com.everbranch.app`, local version 2.3.7/build 18. Local release documents record the unlisted Everbranch Field App Store app and team `ACG3V4UATT`; those documents distinguish Evergrove branding from the then-recorded Modern Forestry LLC legal account. Current App Store Connect availability and roles were not verified. Its app entitlement file contains APNs only, with no Tap to Pay entitlement. The Android project is the generic Everbranch Capacitor companion, not an Alpha payment adapter. Existing checkouts, release lanes and Jarvis were not modified.

The Mac has Xcode 26.3 and iOS SDK 26.2. Parent read-only inventory confirmed available Apple Development identities, but no Evergrove Payments App ID/profile or payment entitlement is established. The previously paired iPhone is currently unavailable. Cached model/OS information is not proof of present device support, pairing, provisioning or installation. Physical iPhone checkout, payment, peripheral and remote-update behavior remain unverified.

For a selected Stripe route, use the official Terminal iOS SDK and an authenticated server connection-token endpoint scoped to Alpha's approved merchant. Existing online Stripe Connect is not a Terminal integration. Tap to Pay requires a supported iPhone and currently supported non-beta iOS; consult the provider's current matrix at pilot time. Apple requires the Tap to Pay entitlement, with distribution approval for TestFlight/App Store. Do not silently substitute Stripe for a merchant-supported Dejavoo route. [Stripe setup](https://docs.stripe.com/terminal/payments/setup-integration?terminal-sdk-platform=ios), [Tap to Pay requirements](https://docs.stripe.com/terminal/payments/setup-reader/tap-to-pay?platform=ios), [Apple entitlement](https://developer.apple.com/documentation/proximityreader/setting-up-the-entitlement-for-tap-to-pay-on-iphone).

TestFlight supports remote beta delivery after signing, upload and any required review; builds expire after 90 days. The owner must install TestFlight/accept the invitation and may need to approve updates, sign-in, permission prompts and merchant terms. Development installs require device/profile/Developer Mode and do not establish durable remote distribution. An App Store/unlisted app follows Apple's submission and distribution process. Installing this rehearsal alone does not activate payments. [Apple TestFlight](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview).

No upload, account/entitlement change, production merge/deploy, live transaction or physical installation was performed by this milestone.
