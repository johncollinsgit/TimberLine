## Pending payment navigation (October 31; source candidate)

Close returns to the register without erasing a pending payment; Account remains
accessible. After the SDK interaction ends, the original operator/device may
explicitly confirm no card was presented and leave that attempt for review.
Fresh Square retrieval is required. An empty result is not financial cancellation:
the original remains unknown, with its stock reservation and immutable request.
An audited parked event allows a different sale; the original can never receive
a replacement descriptor. Saved reviews remain accessible and late provider
confirmation still reconciles the original receipt once. The SDK watchdog treats
an active payment handle as presentation, including Apple's system scene.
Opening a warm basket reuses the chosen register/catalog, and known payment
recovery makes one fresh reconciliation request. Background work uses a compact
progress banner; unresolved status reads Payment not confirmed, never a permanent
Checking label. Server quote/payment verification and mutation locks remain.

# Everbranch Pay: finished goods and guest checkout

Source candidate, October 31, 2026. This supersedes the customer prerequisite and
Shopify order-mirroring lane for **new** sales on an explicitly configured
`everbranch_inventory_v1` register. Existing attempts keep their original adapter,
configuration, provider references and recovery. Source tests do not establish
physical payment, inventory cutover, settlement or Apple distribution approval.

## Authorities and scope

Everbranch owns customers, finished-goods stock, in-person sales, receipts and
rewards. Square owns payment, refund and settlement evidence. Shopify supplies
current catalog/prices and tax calculation and can receive reviewed availability
projections. A new in-person sale never creates, pays or fulfills a Shopify order.

This capability is included for every tenant. Each tenant connects its own
merchant account, reviews taxes/program terms and assigns registers and channels
to its own stock pools. Modern Forestry uses separate event pools. A second
merchant's shared-stock configuration needs separate acceptance.

The independent Enterprise runtime keeps its pinned native POS schema and stock
adapter; this release does not add legacy production or Shopify tables to it.

Native Website commerce, materials inventory and subscriptions retain their
existing domain adapters. This release does not migrate native `website_*`
stock. A dormant native Website stock binding prevents collection until its own
reservation contract is implemented and verified; do not activate it or claim it
as a proven shared-stock channel.

## Starting during an event that has already sold goods

The owner's production/event plans mean **to produce**. Publishing them, finishing
a pouring task or marking an order brought down does not establish physical
finished goods. `StockEventProduction` uses the expanded unit quantities on the
existing production order lines, without guessing box multipliers.

For Columbus, use the reviewed Asana task `1211666431964808`: Made in the South,
Columbus GA, October 30–November 1, 2026; Historic Iron Works, 801 Front Avenue,
Columbus GA 31901. The undated legacy event with a similar name is not proof of
this year's event. `StockEvents` creates a reviewed canonical event reference;
a future Everbranch Teams reference can attach to that same identity and pool.
No Asana event details or planned quantities add stock.

After substantial show sales, count **remaining physical units now**, including
units held for open sales. Do not seed original packing totals or subtract past
Square sales again. Begin with a few counted products if necessary. Owners/admins
can enter an actual count from an unavailable product's options sheet; the API
resolves the assigned tenant/register/pool and rejects stale balance versions.
Other active products remain visible as count needed or unavailable. Counts do
not charge a card. Keep the counted pilot units under this register's control:
direct Square POS sales do not currently feed this finished-goods ledger.

Web owners use `/pos/admin/stock?tenant=SLUG` to
connect an event, create an empty pool, import the live catalog, count remaining
units, review exact production mappings, confirm **new** ready units, transfer
packed goods or return leftovers, review bundle components and assign a register.
A physical count/transfer and a new-production receipt must not describe the same
units. Ready confirmations require an explicit not-previously-counted review.
Original production and materials records are not rewritten.

## Checkout and customer association

Sign in with Everbranch email/Google, choose an active-membership business and an
assigned register. Square initializes automatically; show provider terms,
permissions or corrective steps only when actually needed. Products/Favorites/
Keypad are native SwiftUI. Simple items add in one tap; variants use one sheet;
reviewed mix-and-match bundles use one component sheet. Labeled pills replace
small navigation arrows. Search must not zoom or allow the basket dock to cover
product controls while the keyboard is open.

The unused 57 MB bundled photo demo is excluded from application resources;
the installed register loads live catalog images. Catalog import paginates the
complete active catalog and images. It imports
**no physical quantities**. Exact variant/store revisions price the basket on the
server. Modern Forestry's October 31 read-only audit found 374 active non-gift
variants with images: Laurel & Fig Wax Melt is $3, most melts $7, three 16oz
cotton candles $75, three 8oz $50, five melts $30. The old photo's $80 is not an
instruction to change the live $75. A bundle's parent price is charged once;
reviewed exact component selections consume physical component stock. Unreviewed
bundles cannot collect. Custom amounts require explicit tax review and are not
rewardable merchandise by default; favorites currently save product choices.

The flow is products → basket → Square card entry → confirmed Everbranch receipt
→ optional Add customer → Next sale. Customer lookup is tenant-scoped by
normalized phone, name or email, with explicit creation/selection or skipping.
Receipt contact is encrypted and separate from verified customer association.
Never automatically merge accounts or identify customers by card/name/contact.
If fresh Square evidence includes a cardholder name, offer explicit receipt-only
confirmation. Retain receipt-appropriate card/EMV details, not fingerprints or
marketing profiles. No email/SMS receipt is sent automatically.

Quotes reserve stock atomically and freeze merchant/location, adapter, basket,
customer (if selected) and rewards policy. Shared-stock publication acknowledgments
are awaited during basket review. No Shopify inventory/order HTTP call belongs
in the Square collection handoff. The phone preflights capture/device/presenter
before requesting a persisted original payment attempt. Retain the SDK handle and
original reference through cancellation, interruption and watchdog failures.
Uncertainty must never invite a replacement charge. Fresh Square retrieval is
required for payment confirmation. Confirmed stock/publication follow-up must
not hide the receipt or block an otherwise valid financial refund.

Post-payment association is retry-safe and audited without editing financial
snapshots. Use the existing preview-first `pos:customer-program TENANT REGISTER --actor=OWNER`
and its `--expected-policy=HASH --apply` option to bind the approved program; it
does not enable collection and keeps guest checkout on inventory-owned registers.
Frozen eligible rewards post once after association through the
existing ledger. Historical membership/ordinal ambiguity goes to review. Owners
can confirm original-time membership, prior purchases and records reviewed from
the receipt; this retries historical-eligibility effects only. Spent-credit or
identity reviews remain independent. Partial refunds recalculate earning; full
refunds reverse it. A refund does not imply returned merchandise or restocking.

## Online stock cutover

Both `POS_OWNED_INVENTORY_ENABLED` and `POS_INVENTORY_PUBLICATION_ENABLED` default
off. Register/pool assignment and a reviewed baseline are separate gates.
Keep the publication flag off for Modern Forestry's separate-show rehearsal.
Its online warehouse has not been reconciled by counting show leftovers.

For a separately reviewed shared/warehouse channel, the owner first establishes
physical counts and exact provider mappings. Each active physical variant must
be tracked at the reviewed Shopify location with DENY overselling. Unknown
online bundle component behavior blocks cutover, even if native Pay bundles have
been reviewed. Do not bypass this guard for Modern Forestry's manual bundles.
The merchant connection needs read_products, read_inventory, read_orders and
write_inventory; older order reads may require Shopify's additional access.

`stock:open-online` previews by default. Supply a private regular mode-0600 JSON
file with `request_id` (UUID), `store_id`, `shop_id`, `location_id`, `pool_id`,
`physical_counts_reviewed: true`, `expected_versions` (canonical item ID → integer
balance version) and `opening_order_ids` (reviewed Shopify ID strings covering
current commitments). The command verifies an active verified owner/admin.

```
php artisan stock:open-online --tenant=ID --actor=OWNER --review=/private/opening.json
php artisan stock:open-online --tenant=ID --actor=OWNER --review=/private/opening.json --apply
```

It freshly reads original stock-only order evidence and provider inventory,
requires current committed quantities to equal recognized outstanding allocations,
then seeds their historical counters without deducting past shipments from today's
physical count. Preview rolls back all local changes and never publishes a
provider quantity. Apply is retry-safe. Mismatched counts, location, mappings,
commitments, policy or unsupported bundles require review, not a guessed balance.

Online orders reserve unshipped units; shipments consume on-hand units; explicit
cancellation/restock evidence releases allocations; explicit returned merchandise
restores physical goods. Financial refunds alone do neither. Duplicate, older,
conflicting, unmapped or pre-count events cannot restore stock by guesswork.
Encrypted inbox work persists before processing. Unresolved events block
publication. `stock:sync` queues bounded tenant drains every minute. Projection
requests persist their UUID and compare-from quantity before HTTP. Uncertain
responses retry that same key; a stale predecessor is never skipped. Availability
increases additionally require fresh matching provider commitments. Old ambiguous
mutations require operator review after one hour. Never manually reset unknown
publication rows to pending or copy Shopify quantities into physical balances.

## Original uncollected legacy attempt

First stop the old phone process, wait at least five minutes, observe that the
process is absent again, and retain the actual observation artifacts. The
owner must have confirmed no card was presented. Do not mark a missing Square
payment cancelled or invent a provider ID.

```
php artisan pos:close-uncollected --tenant=ID --sale=SALE --actor=OWNER --device-evidence=/private/device.json
# Add --apply only after the preview and fresh evidence pass.
```

Private device JSON requires actual `app_terminated_at`, fresh
`app_process_absent_at` (within 120 seconds), `owner_confirmed_no_card_presented`,
exact `app_device_id` and `process_evidence_sha256`. The command freshly verifies
Square and scans payments, then checks the exact original unpaid/unfulfilled
Shopify order. Apply retires that legacy register and cancels/restocks only that
verified unpaid order. Financial uncertainty and original recovery remain
retained. A late genuine Square payment still reconciles and surfaces for review.
Provision a new register identity using `pos:provision`; do not rewrite an old
merchant binding to force a new lane.

## Release and physical acceptance

### Owner-requested registered-device operation

The October 31 owner follow-up explicitly removes Everbranch's $10, two-hour and
single-sale rehearsal rules for the reviewed owner phone. This is separate from
Apple's registered-device distribution restriction, which remains in effect.
`pos:activate --registered-owner` still requires `--development-review` and the
exact installed build, device, owner, merchant, location and valid signing/grant
evidence. The private review additionally needs
`owner_requested_unrestricted_collection: true`, integer `owner_actor_id`, and
`owner_request_sha256` containing the actual direction's digest. The session has
no Everbranch amount or single-sale cap; it expires with the actual provisioning
profile. Tax validity, membership, device assignment and current provider
readiness still apply. Commercial/public distribution flags are not fabricated.
An already claimed rehearsal may upgrade only after its original payment journal
and sale agree on a terminal outcome; unknown attempts remain blocked.

The show's optional `catalog_item_ids` selects exact canonical variants without
deactivating the full tenant catalog or changing another register. Modern Forestry
uses only the pictured scents, 8oz/16oz cotton candles and wax melts, with reviewed
bundle parents. No mugs, wood wicks or 4oz variants belong in this register.
For the owner's explicitly untracked show inventory, remaining balances stay
unknown and the receipt records selected units. Bundle definitions may include
untracked merchandise; any counted component keeps its actual reservation limit.
Original packed boxes (4 × 16oz, 8 × 8oz, 8 melts per box) are source information,
never a replacement for today's remaining count. Restore tracking only through
an explicit reconciled physical count and reviewed configuration.

Native register photos use Shopify-generated 400px JPEG renditions. The original
mountain header is a small local asset, not the old 57 MB demo. The complete
keypad pill is tappable; refresh also reloads register capabilities so newly
enabled custom amounts do not remain disabled by stale client settings. Custom
taxable-merchandise amounts reuse the reviewed show address and calculate tax
server-side; they are not treated as rewardable catalog merchandise.

Use additive migration recovery, real MySQL contention tests, the reviewed
schema dump/fingerprint, native build/UI checks, a focused PR and protected Forge
release. Install one planned signed candidate on the approved phone. Inspect
its exact bundle/team/profile/device/signature and Square merchant/location.
The development entitlement case does not prove TestFlight/App Store approval.
Preserve existing onboarding, servicing and commercial collection gates.

The default bounded registered-device acceptance session still requires exact private
Apple/signing/installation evidence, approved owner/register/phone/build and the
existing time/amount/one-sale limits. Stop Mirroring before physical card entry.
Never activate normal collection from source tests or a no-card attempt.

Acceptance must prove real Square presentation/cancellation/interruption,
confirmation/receipt, no Shopify order, correct counted stock, explicit customer
with existing online history, exactly one eligible reward, partial/full refund
behavior, spent-credit review and settlement evidence. Record selection tap counts
and warm SDK presentation time on the physical phone. Verify shared history across
staff web, Field and authenticated customer accounts. Rehearse a second tenant's
shared pool separately before claiming both configurations proven.
