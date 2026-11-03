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

## Native register and inventory-owned checkout

The shared native Products/Favorites/Keypad register loads the selected merchant's
complete live photo catalog. Simple products add in one tap; variants use one
options sheet; reviewed mix-and-match bundles use one component sheet. Large
labeled pills provide basket, payment and next-sale actions. Search does not zoom
and the basket dock stays out of the keyboard's way. `App/RegisterDemo` is retained
as a legacy reference and excluded from application resources (57 MB removed).

For explicitly configured `everbranch_inventory_v1` registers, the server prices
and taxes the basket, reserves counted Everbranch stock and persists the original
attempt before Square collection. New in-person sales never create Shopify
orders. Guest checkout precedes optional phone/name/email customer lookup and an
audited association. Receipt contact does not verify account identity. Frozen
eligible rewards use the existing ledger; historical ambiguity requires owner
review. Other tenants use the same register with their own merchant and program.

Existing attempts and the independent Enterprise/native Website domains retain
their original adapters. Pending sale/customer identifiers survive logout;
current memberships and device assignments are rechecked before restoration.
Callbacks never prove payment: fresh original-account Square retrieval does.
The phone checks capture, actual Tap to Pay reader readiness and its visible
presentation controller before collection; unknown outcomes cannot start a
replacement payment. Stop Mirroring for actual card entry.

Read [the inventory-owned runbook](../../docs/operations/everbranch-pay-owned-inventory.md)
for counted event stock, default-off inventory/collection gates, exact signing,
original-attempt cleanup and physical acceptance. No successful live payment,
settlement or Apple distribution approval is implied by this source.

## Identity before business selection

Everbranch Pay opens native Google/email sign-in before any merchant catalog.
Both choices reuse `/mobile/authorize`, the existing Google OAuth connection,
S256 PKCE, the exact POS callback and the same scoped token exchange. The server
supplies only active business memberships. Register lists remain device-assigned;
selecting a business does not activate its processor or grant collection rights.

A saved business/register is restored only after fresh membership and assignment
checks. New sign-ins choose their business and register. Each business's photo catalog appears only in its selected workspace; all
businesses use the same native register against their own authorized catalog. The app's name is
Everbranch Pay; the selected merchant supplies the business identity inside it.

Catalog-only UI tests use a Debug-only launch argument. That preview path is
compiled out of Release archives and never supplies authentication or payment
readiness. The normal launch test verifies that sign-in precedes all catalogs.

## Register follow-up

Restore the original mountain art from the legacy demo as the small local
`RegisterMountains` asset. The demo itself remains excluded. Product cards and
keypad pills use complete button hit areas, including when search is open.
Shopify supplies 400px JPEG catalog renditions; refreshing products also reloads
register capabilities. Show-specific catalog selection and reviewed bundles are
server-owned. Explicitly untracked components remain selectable without invented
remaining balances; counted components keep their actual limits.

Owner-requested registered-device operation removes Everbranch rehearsal amount,
time and single-sale rules only with the separately reviewed exact owner/device/
build evidence. It does not establish public distribution approval.
