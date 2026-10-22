# Evergrove Payments — Alpha Printing iPhone plan

Prepared 2026-10-22. This is a readiness and owner-action plan, not evidence of a live merchant or installed payments app. Credential-free native/backend work can proceed now. Real collection remains **NO-GO** until every financial gate below is verified.

Mac inventory reported by the workstream owner: Xcode 26.3 with iOS SDK 26.2; an existing Everbranch Capacitor app at version 2.3.7/build 18 with an APNs entitlement and no Tap to Pay entitlement. Three valid Apple Development identities are visible in the authorized keychain read, but distribution signing/authority and App Store Connect status are unverified. Known iPhones are currently unavailable to developer tooling; a cached iPhone 15 Pro Max record reports iOS 27.0.1, which needs current toolchain/PSP eligibility verification before use. No physical install or payment check has run. Personal Context was unavailable. Later filesystem inspection found historical repository/infrastructure and Modern Forestry iOS memory notes under `.claude/projects`; they identify the monolith and a separate canonical SwiftUI Modern Forestry app, not an Evergrove Payments entitlement or current merchant approval. Device identifiers are intentionally omitted here.

The user's existing Galaxy Tab Alpha checkout is a home-screen browser demonstration, not a verified APK. The new iPhone milestone reuses its canonical register/catalog/customer/guest/cash/change/receipt/refund journey as a bundled offline reference inside an Evergrove-branded SwiftUI shell, with a separate native recovery lab. That reuse is not a production payment bridge: no browser JavaScript is permitted to supply real paid status or card data. A native provider SDK and authenticated backend remain required for real collection.

## Recommended route, subject to merchant choice

Use a separate Evergrove Payments native iPhone app, with a provider-neutral backend and one explicitly selected PSP adapter. If Alpha chooses Stripe and its country/account are supported, use the official Stripe Terminal iOS SDK for Tap to Pay. Online Stripe Connect alone does not provide Terminal integration. The receiving merchant, reader location, currency and original transaction routing must all be server-owned and frozen per attempt. Alpha sales must never route to Wren/Watershed tenant 13 or its Stripe account.

An alternative is Dejavoo iPOSgo: its native SDK keeps collection inside the app but requires the applicable Apple entitlement and provider onboarding; its deep-link route requires both Evergrove and iPOSgo apps and switches between them. Confirm the exact processor/ISO, merchant provisioning, current SDK, supported iOS and commercial terms with Dejavoo before selecting it. The existing Z8/VEGA3000 does not establish iPOSgo, SPIn, merchant or iPhone compatibility. Its repair/reprovisioning is not on the iPhone critical path.

Official sources: [Apple integration overview](https://developer.apple.com/tap-to-pay/), [Stripe Tap to Pay](https://docs.stripe.com/terminal/payments/setup-reader/tap-to-pay), [Stripe regional rules](https://docs.stripe.com/terminal/payments/regional), [Dejavoo options](https://docs.ipospays.com/tap-to-pay-on-iphone).

## Before going on site

1. Confirm the real Alpha owner, legal business/country, chosen PSP and who has authority to accept provider/Apple terms. Do not activate fictional tenant 16 Demo/Inactive or turn its simulated history into real sales. Establish the approved real workspace and owner/staff roles through the normal authorized process.
2. Ask for the target iPhone model, current iOS version and whether it is a beta. Stripe currently documents iPhone XS or later, on a supported recent non-beta iOS; verify the exact OS against current PSP/Apple eligibility before the visit. Confirm reliable shop Wi-Fi and cellular fallback. Contactless-only acceptance needs an agreed fallback for unsupported cards/PIN cases; manual card entry is not part of this milestone.
3. Identify the app's actual Apple developer team, bundle ID, App Store Connect record, provisioning and requested Tap to Pay development/distribution entitlements. Existing signing for a different app does not prove those permissions for Evergrove Payments. Do not change the shared Apple account or signing configuration without the appropriate approval.
4. Prefer an approved TestFlight build for an on-site install that can receive remote updates. A development install may be useful for a bounded rehearsal, but free Personal Team profiles expire after seven days and require rebuilding/reinstalling. TestFlight builds expire after 90 days. External beta testing starts after required Apple review. Tap to Pay in TestFlight requires the **distribution** entitlement, not merely the development entitlement.
5. Bring a reviewed app build, precise source/build identifier, installation instructions, a rehearsal checklist, support contact and a written list of remaining gates. No secrets should be embedded in the build or exchanged in chat.

Official sources: [device/OS eligibility](https://docs.stripe.com/terminal/payments/setup-reader/tap-to-pay), [Personal Team limits](https://developer.apple.com/help/account/basics/about-your-developer-account), [TestFlight overview](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview), [Tap to Pay entitlement](https://developer.apple.com/documentation/proximityreader/setting-up-the-entitlement-for-tap-to-pay-on-iphone.md).

TestFlight controls app distribution, not the PSP's payment mode. The future integration must independently enforce test/live merchant bindings and collection authorization; a beta app label is not a safeguard against real provider charges. This milestone has no live collection implementation.

## On-site steps with the owner

1. Confirm the device is available for this work and preserve every existing app, pairing and account. The separate Jarvis phone work is not permission to take over its device.
2. Have the owner unlock her iPhone. With the approved distribution route, she installs TestFlight, accepts the invitation, and installs the identified Evergrove Payments build. If only a development rehearsal is available, explain the profile expiration and any required Trust/Developer Mode prompts before she chooses it. Do not present a simulator build as installable on a physical phone.
3. Verify the Evergrove app name/build, its explicit test/setup state, correct workspace and signed-in role. Practice merchandise, printing/service and postage lines with integer-cent totals and clear tax-policy status. No default tax rate is evidence of the shop's actual tax treatment.
4. Let the owner sign in to her chosen PSP through its official secure flow and personally enter business identity, bank, password and two-factor information. Do not copy, collect or store these details. A customer merchant account, new OAuth grant, Apple entitlement/signing account change, or financial/legal agreement needs its applicable explicit approval; this engineering work has not authorized those actions.
5. Confirm the provider reports the correct legal merchant, country, currency, account mode, location, payouts/readiness and required capabilities. Bind that identity to Alpha on the server. Reconfirm no Wren/Watershed account is selected.
6. If the native provider build and Apple approvals are ready, the owner accepts any required Tap to Pay terms on her own phone and authorizes the device permissions used by the selected SDK. Do not sign her out of Apple accounts or alter device dates to work around testing requirements. Use a separate authorized test device if provider instructions require a different sandbox setup.
7. Run provider test-mode scenarios only after the separately authorized test configuration is available: success, decline, cancellation, timeout after authorization, app restart, network loss/reconnect, repeated checkout, original-attempt recovery, receipt and refund/reconciliation. A local simulator is useful evidence for UI/domain behavior but is not physical Tap to Pay or PSP proof.
8. Leave a concise handoff: installed build/distribution expiry, owner support contact, how to update in TestFlight, current checkout status, remaining tasks and a clear instruction that live collection is unavailable until the launch review passes. Installation alone does not make payments live.

Official sources: [merchant device setup](https://developer.apple.com/tap-to-pay/), [Stripe SDK setup and trusted connection-token endpoint](https://docs.stripe.com/terminal/payments/setup-integration.md?terminal-sdk-platform=ios), [TestFlight installation/updates](https://testflight.apple.com/).

## What can be completed remotely after the visit

Engineering can update native/backend code, run automated and simulator checks, build/review a new version, prepare App Store/TestFlight submission material, and prepare immutable merchant-binding, reconciliation, receipt and refund tooling. After authorized deployment and owner/provider setup, backend configuration and approved releases can be completed remotely without collecting the owner's passwords.

The owner still controls phone unlock, invitation acceptance, installation/update prompts, device permissions, account/identity/bank verification, two-factor challenges and legal terms. Native SDK or entitlement changes require a new correctly signed binary; they cannot be supplied as a web-page update. TestFlight can deliver later builds remotely, with automatic updates optional on the phone; builds must continue to be renewed before expiry. App Store distribution has its own approval and update process.

## Launch checklist

| Gate | GO evidence | Current status |
| --- | --- | --- |
| Real Alpha business/workspace | Verified owner/legal entity, active authorized workspace; demo/history isolated | Unverified; NO-GO |
| Provider route | Alpha explicitly chooses PSP; exact country/currency/account approved | Unverified; NO-GO |
| Merchant funds | Account/location/routing belong to Alpha; payouts/capabilities verified | Unverified; NO-GO |
| Device | Actual phone supported, non-beta OS, connectivity/permissions tested | Unverified; NO-GO |
| App distribution | Correct bundle/team, signed physical build; approved distribution and required Tap to Pay entitlement | Unverified; NO-GO |
| Native/backend trust | Authenticated short-lived session, scoped connection tokens, server-owned quote/binding; no raw card entry/storage/logging | Test-only reference core checked; production auth/API/PSP integration pending |
| Checkout correctness | Prices, quantities, service/postage semantics and confirmed tax/receipt/return policy | Real shop acceptance pending |
| Durable servicing | Idempotency/concurrency, original-provider refunds, signed/canonical evidence, unknown recovery and missed-event reconciliation | Reference-domain tests pass; real provider and production repository acceptance pending |
| Provider/physical acceptance | Test-mode real-device scenarios pass; correct provider dashboard evidence retained without secrets | Never run |
| Release | Exact-head security/tests/build, reviewed draft, approved production merge/deploy | Not authorized/performed |
| Financial pilot | Separate explicit authorization for exact live charge/refund and merchant verification | Not authorized/performed |

## Costs and approvals to surface early

- Apple lists the Developer Program at US$99 per membership year, region-dependent. First verify whether the correct organization already has membership; no enrollment or purchase was performed. [Apple enrollment](https://developer.apple.com/programs/enroll/)
- Stripe's current US standard Terminal price is 2.7% + 5¢ per successful domestic-card transaction, plus $0.10 per Tap to Pay authorization; international cards add 1.5%. The merchant's actual country, negotiated fees, Connect/platform pricing, disputes and refund treatment may differ. Confirm the actual approved contract before activation; no agreement or fee was accepted. [Stripe pricing](https://stripe.com/pricing)
- Dejavoo's exact TPN onboarding, processor/ISO, licensing/SDK and transaction fees are unverified and need a provider quote. Its current public SDK guide contains inconsistent OS/entitlement-purchase wording; do not treat it as an approval timetable or price commitment. [Dejavoo SDK guide](https://docs.ipospays.com/tap-to-pay-on-iphone/in-app-sdk)
- Apple and provider approval timing is outside engineering control. No entitlement/account/credential action, production release, paid purchase or real charge/refund has been performed by this workstream.
