# Everbranch Pay: customer-linked Square pilot

Source candidate, October 31, 2026. Modern Forestry is the first merchant. Square
is the payment/refund/settlement authority; Shopify owns catalog, prices, tax and
stock; Everbranch retains the selected customer and existing rewards ledger.
No live acceptance, distribution approval or deployment is implied by this file.

## Checkout contract

The register-authorized mobile POS API adds GET/POST `customers` and GET
`customer-requests/{uuid}` beneath its existing register path. Search is tenant
scoped; creating a customer requires an assigned owner/admin, reuses an opaque
request UUID and never grants marketing consent. A matching contact prompts an
explicit selection, without merging profiles. New online accounts remain
unconnected until the existing authorized account/source-linking flow verifies
an association.

POST `quotes` accepts an integer `customer_profile_id`. Shopify registers require
it; other registers can set `customer_required`. The server freezes the canonical
customer, explicitly linked Shopify customer, merchant/location, live basket and
reviewed tenant rewards policy. A changed identity or policy blocks collection.
The local catalog sends only variant IDs and integer quantities. It supplies no
prices, taxes, credentials or payment authority. Bundles remain browseable but
cannot enter live checkout until their provider pricing/component-stock mapping
is reviewed; do not silently charge their components at another price.

One confirmed original sale appears in the shared customer snapshot. Its exact
Square payment/order and Shopify mirror are suppressed, never matched by amount
or date. Conflicting identities require review. Sandbox payments do not enter
real purchase history or change rewards. Source coverage remains explicitly
linked records only, rather than a claim that all historical orders were found.

## Rewards

An owner configures the existing tenant-owned program for
`show_issued_online_redeemed` with `event_rewards_enabled=true`. No global defaults,
new earn rate, balance copy or automatic program publication is allowed. Preview
and bind the exact existing policy after configuring it:

```
php artisan pos:customer-program TENANT REGISTER --actor=OWNER
php artisan pos:customer-program TENANT REGISTER --actor=OWNER --expected-policy=HASH --apply
```

This binds the program and requires customer selection; it does not enable
collection. It applies existing first/second/third-order amounts and the frozen
membership multiplier. Order milestones use confirmed explicitly linked purchases
and retain one milestone claim; an existing awarded second-order task prevents
another award. When configured, spend tiers use the highest qualifying tier once
per purchase, in addition to an eligible milestone. The preview names that rule.
The original wallet cap limits the award once, without topping it up on retries.

The retained outbox posts to Candle Cash once. Correlated Shopify imports link
only from the retained original order reference and skip the legacy contact
matcher/reward dispatcher. Refunds recalculate against original terms: a full
refund reverses the earning; partial refunds retain an eligible fixed milestone
and recalculate spend tiers against proportionally retained pre-tax merchandise.
Already-spent credit goes to an auditable `review` effect, never a negative
balance or a blocked Square refund. Square Dashboard refunds are separately
retrieved and verified from the original seller account and de-duplicated by
provider refund ID. They update customer history and reward reconciliation.

## Onboarding and collection remain separate

Default-off `POS_NATIVE_ONBOARDING_ENABLED` allows only an assigned owner/admin
on `POS_NATIVE_ONBOARDING_BUILD` to complete SDK/Apple account linking after
merchant, entitlement and application-signature verification. It issues no sale
or collection descriptor. Cashiers cannot use this exception. This permits the
registered-device development onboarding required for Apple's review recordings.
The existing distribution, actual-rate, webhook, catalog/tax, exact-build and
physical-acceptance gates still apply to every collection request.

Follow `everbranch-pos-live.md` for provisioning, activation, bounded physical
acceptance and the protected GitHub-to-Forge release. Do not replace live source
or assets manually. Only the Modern Forestry owner, register and assigned phone
belong in the initial allowlist. The capability is included for every tenant;
other merchants still need their own account, program, device and acceptance.

## Observed external readiness (October 31)

- Square production application: `sq0idp-xr3lCz7Aa7shioJ4z5LNgQ`. Its existing
  `com.everbranch.pos` / `ACG3V4UATT` signature was verified in the console.
- Production OAuth callback is now
  `https://app.theeverbranch.com/pos/setup/square/callback`.
- Created the production payment/refund webhook subscription at
  `https://app.theeverbranch.com/api/pos/square/webhook`. Signed delivery is not
  verified merely by creating it; leave `POS_WEBHOOKS_VERIFIED=false` until tested.
- The live backend was still release `3cded37c` and had no POS schema when checked.
- Modern Forestry tenant 1 has its own published program: second-order $10,
  existing 2x Candle Club multiplier, no first/third-order amount, no spend tiers,
  and no wallet cap. Its show channel is selected but event earning was off.
  Enable only that channel flag through the existing audited policy controls;
  preserve the approved amounts and online redemption behavior.
- The retail connection is `modernforestry.myshopify.com`. A live locations query
  returned ACCESS_DENIED. The retail app needs `read_inventory` (also sufficient
  for Location reads) and merchant reauthorization before live stock/location
  review. Wholesale connection settings are unchanged.
- Apple's case 23096691 grants development access restricted to registered test
  devices. Distribution approval remains unverified. John’s phone was not
  reachable for the new installation when checked.

## Acceptance and Apple submission packet

Do not mark these complete without the real evidence:

1. Grant inventory access; confirm the actual show address, physical fulfillment
   location, selected variants, current stock, tax and actual Square rate.
2. Pass protected release gates; sign and verify build `2026103101` with the
   registered-device profile, exact team, bundle, entitlement and SDK application.
3. Record `01-new-user.mov`: owner sign-in, business/register selection, supported
   SDK Apple linking/terms, permissions and reader readiness. Record
   `02-existing-user.mov`: returning owner and interrupted-sale recovery.
4. Record `03-checkout.mov`: customer selection, live server-priced review and
   supported native Square Tap to Pay. Complete Apple's official checklist from
   https://apple.box.com/v/ttpoirequirements against the actual build. The new
   privacy-policy link does not replace POS-specific disclosure review.
5. Prepare the reply and upload package for Apple case 23096691. The owner submits
   it; this work does not send correspondence or certify an unobserved flow.
6. After all collection gates pass, run a consented bounded sale for a customer
   with verified online history, confirm Square/receipt/customer views, eligible
   earning once, refund/replay correctness and the real payout/settlement.
7. Keep normal collection off until that exact build is accepted. Prove a second
   tenant independently before claiming the complete flow has been tested there.
