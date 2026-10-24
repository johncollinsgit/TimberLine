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
