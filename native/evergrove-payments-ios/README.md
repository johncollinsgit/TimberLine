# Evergrove Payments iPhone rehearsal

This is an isolated native SwiftUI iPhone app with two deliberately distinct surfaces:

- **Register demo** bundles the existing Alpha Printing browser register. Its JavaScript and CSS are copied unchanged from TimberLine, preserving Register, Customers, Inventory and Demo setup, the four exact catalog SKUs/prices, guest and sample-customer checkout, card/cash/change, uncertain result recovery, partial refunds, history and receipt preview. This is the same fictional flow installed as a home-screen demo on the Android tablet, not evidence of an Android APK or payment SDK.
- **Native checkout** reads a bounded SKU/quantity selection after an explicit native button tap, obtains a fresh typed quote from a separate durable local server fixture, shows the fixture catalog's product name/SKU and server-resolved money, and requires a second native confirmation. Unknown payment/refund responses preserve their original UUIDs across relaunch. There is no live API connection or PSP adapter.

The application cannot collect a live payment. Neither surface accepts card details, merchant credentials or a provider/account choice. Fixture success is always labelled simulation and never yields a paid receipt. Wren/Watershed's merchant account is not used or referenced.

## Reused register and security boundary

`scripts/sync-register-demo.py` resolves the canonical Blade template into bundled HTML, copies its exact scripts, CSS, logo and existing illustration, changes shell branding to Evergrove Payments, and makes receipt printing explicitly unavailable in this iPhone preview. `App/RegisterDemo/PROVENANCE.json` records SHA-256 hashes of canonical source inputs. The read-only check detects source/bundle drift, generated HTML changes, unexpected bundle files, unresolved Blade and remote resource references:

```sh
python3 scripts/sync-register-demo.py --check
```

When this standalone checkout is outside TimberLine, pass `--repo-root /path/to/TimberLine`. Regenerate deliberately by omitting `--check`.

The WKWebView uses a nonpersistent website data store, no script-message handlers, no credentials/cookies shared with operational apps, and no native payment bridge. It may read only its `RegisterDemo` bundle folder. Navigation allows only the exact encoded path of the bundled `index.html`, optionally with its `#main` anchor, and denies queries and popup/new-window actions. The local document's CSP prohibits network connections, forms, objects and frames. No remote demo page is loaded. This remains a browser register hosted in a native app; the actual card-present SDK integration is pending.

The **Review basket natively** button is outside the WebView. Before running a constant read-only script, it verifies the current document is the exact allowed bundled page. The script reads the actual canonical renderer's `small` SKU text and `.quantity-control > span`, refuses empty/locked baskets, and returns only SKU and integer quantity. Native decoding rejects extra keys, unknown/duplicate SKUs, booleans, fractional/oversized quantities and oversized payloads. The four exact catalog SKUs are the only accepted handoff identities. No script-message handler is added, and the browser cannot submit a payment, amount, paid flag, UUID, provider, card or customer information to native code. The independent native quote must still be reviewed and confirmed.

The original register keeps fictional state in its WebView tab session; closing the app may reset that register session. The native client and independent local server fixture have separate private files. Their sample stock and receipts do not synchronize with the browser demonstration or business records. Do not treat browser session storage or either local fixture file as production payment authority.

## Typed API and recovery invariants

`PaymentAPITransport` defines catalog, quote, start, pure status GET, explicit reconciliation POST and original-payment refund POST. Wire requests carry server selection IDs/revisions and UUIDs; unit price, tax, kind, merchant/provider and sale identity are response authority. Strict bounded version-1 decoding accepts only `fixture` or `unavailable` mode, validates integer totals/line tax, original request/sale/money identity and canonical confirmation/version consistency, and rejects unknown fields. The shared backend fixture `tests/PaymentsCore/fixtures/mobile-inventory-checkout.json` is copied into Swift tests and byte-compared by the provenance check.

`PaymentCheckoutSession` persists the quote/start/refund request before awaiting transport. Reentrant taps are blocked while work is in flight. Missing responses retain the original request/quote/version/sale and workspace/register scope; replacements remain blocked. If the attempt ID was lost, an explicit recovery POST replays the original start UUID; the fixture server checks existing requests before quote expiry. With an ID, status refresh is a pure GET and never invokes reconciliation. Reconciliation and refund recovery are separate explicit original-operation actions. Backend processing-to-unknown transitions may keep provider version zero; terminal confirmation requires a positive evidence version. Older versions, changed immutable identity and terminal contradictions fail closed. Restore revalidates journal scope, quote/operation identity and refund reservations; storage failure stops work before dispatch.

`FixtureMobilePaymentTransport` is an independent durable local server fixture, with the same four canonical fictional merchandise SKUs, illustrative tax, integer stock holds/one-time commit, no automatic refund restock and original-request value reservations. Its controlled lost-response scenarios exist only in local fixture code; no HTTP body accepts a simulated outcome. `FilePaymentClientStore` and fixture storage use atomic replacement, iOS complete file protection, private permissions and best-effort backup exclusion. They are single-process rehearsal stores without cross-process locking or authenticated production records. The backend owns real durable stock/payment authority.

`HTTPPaymentTransport` implements typed HTTP roundtrips through an injected `PaymentHTTPExecutor`, with catalog/quote/start/status/reconcile/refund decoding, original request/attempt checks, JSON MIME/size/status validation and fixed safe error codes. `PaymentHTTPRequestPolicy` constructs only exact canonical HTTPS paths and strict methods/bodies, with no token in URLs. The call requires redirects disabled and a bounded response. The adapter rejects all 3xx responses without another request and rejects changed response URLs; a future live executor must enforce the deny-all redirect policy **before forwarding any credential**. Tests inject a deterministic executor for all six methods, spoofed responses, status failures, redirects, timeout and cancellation with durable UUID retention. They do not establish runtime TLS or URLSession redirect behavior.

Both default `HTTPPaymentTransport` and `UnavailablePaymentTransport` refuse networking. The app uses only `FixtureMobilePaymentTransport`. No URLSession dispatcher, real endpoint activation, credential reader, Keychain token, sign-in UI, OAuth grant or account configuration is implemented. The injected HTTP tests use a memory-only redacted fictional credential, never a real token. Actual tokens must use the official system-browser/Keychain path when that separately reviewed integration is authorized. No real server connection is claimed.

The earlier `Checkout.swift`/`FixtureCheckoutEngine` and old native views remain reference fixtures for prior tests; the active app uses `ServerCheckoutView` and the typed API session. Their old client-priced rehearsal DTO is not a production request contract. Printing/service/postage remain unavailable unless the backend supplies an explicitly supported authoritative quote; the current local server fixture offers merchandise only.

## Build and verification

The project uses Apple frameworks only; there are no external dependencies. Swift core tests run on macOS; app and UI targets require Xcode. The generated Xcode project is committed and uses relative paths. Its unique rehearsal bundle ID is `com.evergrove.payments.rehearsal`, with no selected Apple team, no payment entitlement and no automatic provisioning/update flag.

```sh
swift test --scratch-path build/swift-tests --cache-path build/swift-cache
python3 scripts/sync-register-demo.py --check
node --test scripts/basket-selection.test.mjs
xcodebuild -project EvergrovePayments.xcodeproj -scheme EvergrovePayments \
  -configuration Debug -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build/DerivedData CODE_SIGNING_ALLOWED=NO build
xcodebuild -project EvergrovePayments.xcodeproj -scheme EvergrovePayments \
  -configuration Release -destination 'generic/platform=iOS' \
  -derivedDataPath build/DeviceDerivedData CODE_SIGNING_ALLOWED=NO build
```

In a nested executor sandbox, SwiftPM manifest isolation may fail with `sandbox_apply: Operation not permitted`. In that environment only, `swift test --disable-sandbox` allows the dependency-free local test manifest to run inside the executor's existing sandbox. This flag does not grant filesystem/network access.

The handoff Node test loads the actual canonical renderer through its existing deterministic DOM harness, renders all four real fixture SKUs/quantities and runs the constant native selection script against those rendered nodes. It proves selectors and selection-only behavior without controlling a browser. Swift tests cover strict wire fixtures, authority/URL/credential adversarial cases, persistence-before-await, 40 concurrent confirmations, storage failure, response loss/restart, expiry-safe original retries and unknown partial refunds. `scripts/generate-project.py` recreates the dependency-free relative-path project.

Current verification uses noninteractive Swift tests, generic unsigned app builds and generic `build-for-testing` compilation only. UI runtime tests and new visual/physical acceptance have **not** run because the user prohibited Mac desktop/browser/simulator control. No simulator or app was opened or controlled for this milestone. The updated UI test source is compiled, not claimed passing at runtime. Its DEBUG-only test journal namespace never changes payment authorization. Build products and logs stay outside version control.

## Existing Mac apps and release gates

Read-only inspection found `/Users/johncollins/Code/everbranch-mobile`, canonical repository `johncollinsgit/everbranch-mobile`, with an existing packaged Capacitor app, `com.everbranch.app`, local version 2.3.7/build 18. Local release documents record the unlisted Everbranch Field App Store app and team `ACG3V4UATT`; those documents distinguish Evergrove branding from the then-recorded Modern Forestry LLC legal account. Current App Store Connect availability and roles were not verified. Its app entitlement file contains APNs only, with no Tap to Pay entitlement. The Android project is the generic Everbranch Capacitor companion, not an Alpha payment adapter. Existing checkouts, release lanes and Jarvis were not modified.

The Mac has Xcode 26.3 and iOS SDK 26.2. Parent read-only inventory confirmed available Apple Development identities, but no Evergrove Payments App ID/profile or payment entitlement is established. The previously paired iPhone is currently unavailable. Cached model/OS information is not proof of present device support, pairing, provisioning or installation. Physical iPhone checkout, payment, peripheral and remote-update behavior remain unverified.

For a selected Stripe route, use the official Terminal iOS SDK and an authenticated server connection-token endpoint scoped to Alpha's approved merchant. Existing online Stripe Connect is not a Terminal integration. Tap to Pay requires a supported iPhone and currently supported non-beta iOS; consult the provider's current matrix at pilot time. Apple requires the Tap to Pay entitlement, with distribution approval for TestFlight/App Store. Do not silently substitute Stripe for a merchant-supported Dejavoo route. [Stripe setup](https://docs.stripe.com/terminal/payments/setup-integration?terminal-sdk-platform=ios), [Tap to Pay requirements](https://docs.stripe.com/terminal/payments/setup-reader/tap-to-pay?platform=ios), [Apple entitlement](https://developer.apple.com/documentation/proximityreader/setting-up-the-entitlement-for-tap-to-pay-on-iphone).

TestFlight supports remote beta delivery after signing, upload and any required review; builds expire after 90 days. The owner must install TestFlight/accept the invitation and may need to approve updates, sign-in, permission prompts and merchant terms. Development installs require device/profile/Developer Mode and do not establish durable remote distribution. An App Store/unlisted app follows Apple's submission and distribution process. Installing this rehearsal alone does not activate payments. [Apple TestFlight](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview).

No upload, account/entitlement change, production merge/deploy, live transaction or physical installation was performed by this milestone.
