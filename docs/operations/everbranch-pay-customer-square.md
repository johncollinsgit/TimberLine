# Everbranch Pay: original customer-linked Square pilot

**New inventory-owned registers:** follow [the current runbook](everbranch-pay-owned-inventory.md). The customer prerequisite, pending Shopify order and mirror below describe the retained legacy adapter only; never apply them to a new `everbranch_inventory_v1` sale.

## Registered-device development acceptance

The first mirrored checkout exposed a request-envelope defect before any sale
was created: Shopify rejects `variables: []` for the no-variable shop identity
query. The adapter now omits absent variables, matching Shopify's documented
HTTP request format. Provider fixtures validate serialized JSON so this failure
cannot be hidden by PHP array handling. Verify the actual quote again after the
protected server release; no native reinstall is required.

Apple's case 23096691 grants development use restricted to registered devices.
Square documents production payments with registered debug-build signatures.
Publishing remains a separate milestone. Development acceptance therefore has
its own `POS_DEVELOPMENT_ACCEPTANCE_ENABLED` switch (default false), while
`POS_LIVE_COLLECTION_ENABLED` and `POS_DISTRIBUTION_VERIFIED` remain false.

Use `pos:activate` with its existing merchant review, owner, exact build,
assigned admin device, time/amount caps and `--development-review=/private/file.json`.
Preview first, then `--apply` only after source tests/protected release and actual
evidence inspection. The mode requires production merchant/app/team/bundle/build
and device bindings; a current registered-device development profile; the Apple
case ID; and SHA-256 evidence references for the grant, signature report,
installed-build report, IPA and registered hardware ID. Private files must be
mode 0600. This operator review records facts; it creates no provider grants.

At most two hours and $10 are allowed; the initial Modern Forestry test uses
60 minutes and $5 including tax. New quotes freeze the session ID. Under the
existing shared register lock, collection claims exactly one original sale
before external stock/payment work. A second sale, older session/quote,
different owner/device/build, expired profile/session or excess amount is denied.
An ambiguous order or payment keeps that original claim. Activation cannot
replace a claimed development session. No extra descriptor is issued on retries.

The first bounded sale establishes the still-missing physical and Shopify
acceptance evidence. It must pass the existing real catalog/tax recalculation,
customer/policy freeze, pending-order stock reservation and fulfillment-location
verification before Square credentials are issued for collection. Only fresh
Square confirmation can mark it paid/finalize it. Development mode does not set
`shopify_acceptance_verified`, physical acceptance or publishing approval true.
Normal collection still requires those original checks. Keep independent
servicing enabled for original-sale recovery/refunds after the session ends.
Stop iPhone Mirroring before physically collecting a card payment: the Square
SDK rejects screen capture. No new phone installation is needed for this gate.

Source: [Square iOS application signatures](https://developer.squareup.com/docs/mobile-payments-sdk/ios#application-signature).

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
  `https://app.theeverbranch.com/api/pos/square/webhook`. Square Console signed
  `payment.created` and `payment.updated` test deliveries each returned 200 after
  the protected backend deployment; `POS_WEBHOOKS_VERIFIED` is now true. These
  synthetic deliveries are not real-payment or refund acceptance evidence.
- Backend PR #534 passed all 31 checks and deployed through Forge as
  `89edf6ab84e0c4ee9fbff9cf422e26206de92467`, verified at `/ready`; the POS schema
  is present. Commercial collection, servicing and distribution remain disabled.
- Modern Forestry tenant 1 has its own published program: second-order $10,
  existing 2x Candle Club multiplier, no first/third-order amount, no spend tiers,
  and no wallet cap. Its show channel is selected but event earning was off.
  Enable only that channel flag through the existing audited policy controls;
  preserve the approved amounts and online redemption behavior.
- The retail connection is `modernforestry.myshopify.com`. A live locations query
  returned ACCESS_DENIED. Granting `read_inventory` succeeded, but a fresh live
  lookup then denied `Location.name`, explicitly requiring `read_locations` or
  `read_markets_home`. The retail pilot therefore needs both `read_inventory`
  and the narrower location permission `read_locations`, followed by merchant
  reauthorization before live stock/location review. The general Location type's
  alternative-scope listing did not establish access to every selected field.
  The targeted `everbranch-pay-locations-20261031` app version is active; the
  location grant and successful live lookup still need verification. Wholesale
  connection settings are unchanged.
- The owning Modern Forestry Dev Dashboard was reached and the exact client ID
  confirmed. Its version form rejects three existing scope names:
  `read_discounts_allocator_functions`, `write_discounts_allocator_functions`
  and `unauthenticated_read_collection_listings`. The retail candidate and its
  reconnect requests omit only those rejected names while adding read-only
  inventory and location access. Current Storefront documentation grants Collection access through
  the retained `unauthenticated_read_product_listings`; discount access remains
  `read_discounts` / `write_discounts`. Reauthorize the matching permission set
  after this backend reaches production. Do not redeploy all
  extensions or change the wholesale app as part of this repair.
- The organizer's Columbus setup letter confirms October 30-November 1 at
  801 Front Ave, Columbus, GA 31901. It lists 9% tax; Georgia DOR's general chart
  effective October 1, 2026 also lists Muscogee at 9%. The reviewed Shopify
  calculation, product taxability and fulfillment location still need verification.
  Sources: https://madeinthesouthshows.com/columbus-setup-letter and
  https://dor.georgia.gov/sales-tax-rates-general.
- Apple's case 23096691 grants development access restricted to registered test
  devices. Distribution approval remains unverified. Build 2026103101 was installed
  over Wi-Fi on John's registered phone and launched; device app metadata confirms
  the exact bundle, version and build. This is installation evidence, not payment
  acceptance.

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


## October 31 phone experience and reviewed setup follow-up

The phone UX candidate separates Account & Tap to Pay from customer checkout,
uses the Modern Forestry paper/evergreen theme, and keeps the selected register
between presentations. Stored workspace/register IDs are revalidated against
current membership and device assignments. Changing scope clears customer and
catalog state. Payment uncertainty still locks the original sale into recovery.
Live available catalog IDs/prices/quantity limits update the local selling
surface; unapproved bundle mappings remain blocked.

Subsequent owner setup resolved several earlier evidence gaps above:

- The correct Modern Forestry LLC Square merchant was authorized. The existing
  active John Collins location was selected, and the account's dashboard showed
  2.6% plus 15 cents for tap/dip/swipe, with no Everbranch processing markup.
- Read-only inventory and location permissions were reauthorized. A fresh review
  returned the existing Easley fulfillment location and 37 eligible ordinary
  variants under the current strict stock rules. This is not proof of reservation,
  cancellation, paid-order finalization or inventory acceptance.
- The existing published rewards policy's event-earning channel was enabled
  through the audited service and bound to the register; approved earning amounts
  and online redemption rules were preserved.
- Georgia's public registry and current account correspondence confirmed the
  merchant's existing active registration. That registration was added to the
  existing Shopify Tax configuration. A calculation-only request for taxable
  $3 and $7 products at 801 Front Ave returned $0.90 tax and $10.90 total, with
  no order or payment created. This verifies that calculation context only;
  customer-specific exemptions and actual stock/provider acceptance remain open.
- The registered phone requested native Square onboarding on build 2026103102.
  Server authorization is not proof that Apple linking and reader setup finished.

Build 2026103107 is the revised phone interface candidate. Its signed development
archive is distinct from App Store/TestFlight publishing approval. Installation,
protected-release checks and actual phone interaction must be recorded separately;
normal payment collection remains off until the original acceptance gates pass.


### Sign-in and business entry

Everbranch Pay now starts with native Google/email sign-in, then the signed-in
account's active business memberships and device-assigned registers. Google uses
the existing Everbranch OAuth route and POS S256/code exchange; there is no new
Google application, widened callback allowlist or separate POS user database.
Revoked and unrelated memberships are excluded by the shared workspace API.
The source test follows the existing Google user through native-code exchange
and checks its scoped abilities, one-time use and active-membership list. This
fixture is distinct from observing the real Google consent/browser flow.

Only a selected, authorized Modern Forestry register opens that merchant's photo
catalog. Other businesses use the same native customer/basket controls with their
own register items. Restoring a previous register rechecks current membership and
device assignment. No business selection enables collection or publishing.
