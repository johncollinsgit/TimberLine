# Everbranch POS for iPhone

Dedicated native Square Tap to Pay target; iOS 17.1 minimum. The SwiftPM package
contains pure recovery contracts. Square's binary SDK is pinned to 2.7.0. It is
fetched through SwiftPM and is not vendored in this repository.

```
python3 scripts/generate-project.py
swift test
xcodebuild -project EverbranchPOS.xcodeproj -scheme EverbranchPOS \
  -destination 'generic/platform=iOS Simulator' CODE_SIGNING_ALLOWED=NO build
```

The default build is sandbox, with a blank Square public application ID and no
selected signing team. It cannot collect a real payment. See
[the rollout runbook](../../docs/operations/everbranch-pos-live.md) for the exact
build, signing, merchant authorization and physical acceptance gates.

No offline collection or later queued charge is implemented. Recovery retains
identifiers and queries the original payment. Native callbacks never confirm
paid status. The original field application remains independent.

Future release: offline collection and explicit provider-level idempotency for
queued submission/retries. Current online duplicate-payment safeguards stay in place.

## Customer-linked Modern Forestry pilot

The merchant catalog hands only variant IDs and quantities to native checkout.
The server supplies live prices/tax/stock and freezes a selected canonical customer
and reviewed rewards policy. New customer requests retain only recovery IDs in
Keychain, with explicit owner/admin creation and no marketing enrollment. Bundles
are blocked from live checkout pending their provider pricing/stock mapping.
Owner onboarding can run behind its separate exact-build gate while collection
remains disabled; the server reports collection readiness on each sale.
See `../../docs/operations/everbranch-pay-customer-square.md`. No successful live
payment, Apple distribution approval or device acceptance is implied by this source.

## Phone register experience

Setup and customer checkout are separate, merchant-themed native screens. The
session keeps its selected register in memory and stores only workspace/register
identifiers in Keychain. Returning sessions recheck membership and current device
assignments before restoring that selection; changing businesses clears customer,
product and SDK-readiness state. Pending sale/customer recovery journals remain
independent and are never discarded by switching screens or signing out.

A connected register supplies the catalog's available product IDs, current prices
and quantity limits. The local catalog is still a preview before connection;
server quoting remains authoritative. An unavailable basket must be reviewed
explicitly, with no silently removed lines. Mix-and-match bundles stay excluded
from collection until their provider mapping is approved.

The catalog uses a fixed phone frame with a separate scrolling content area,
16-point inputs and larger controls. Native setup uses inline titles, merchant
cards, a docked primary action and optional account/device diagnostics. UI tests
exercise search focus without zoom, pinned navigation, the basket handoff,
separate setup/checkout entry and returning to the preserved basket. A completed
sale clears that basket before returning to products for the next customer. The
current register is native SwiftUI, with live catalog metadata and variant/bundle
sheets covered by the core and UI tests. `App/RegisterDemo` is a retained legacy
reference and is excluded from the application resources; it cannot collect.


## Identity before business selection

Everbranch Pay opens native Google/email sign-in before any merchant catalog.
Both choices reuse `/mobile/authorize`, the existing Google OAuth connection,
S256 PKCE, the exact POS callback and the same scoped token exchange. The server
supplies only active business memberships. Register lists remain device-assigned;
selecting a business does not activate its processor or grant collection rights.

A saved business/register is restored only after fresh membership and assignment
checks. New sign-ins choose their business and register. Modern Forestry's photo
catalog appears only in its selected workspace; other businesses use the shared
native customer/basket view against their own register catalog. The app's name is
Everbranch Pay; the selected merchant supplies the business identity inside it.

Catalog-only UI tests use a Debug-only launch argument. That preview path is
compiled out of Release archives and never supplies authentication or payment
readiness. The normal launch test verifies that sign-in precedes all catalogs.
