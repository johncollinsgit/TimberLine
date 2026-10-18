# Standard Everbranch offer — October 17, 2026

The owner selected $299 USD per business per month plus $499 one-time standard
setup for future customers. Existing agreements, subscriptions, prices, discounts,
commitments and entitlements retain their recorded terms. This supersedes the old
public Starter/Growth/Launch Partner acquisition offers; it does not reprice add-ons.

Classification: shared commercial configuration. Tenant scope: existing contract
snapshots and access remain tenant-specific. Billing: a new versioned offer, not a
mutation of legacy plan definitions. Canonical contracts: module_catalog,
TenantCommercialExperienceService, CustomerAccessRequestService,
StripeHostedBillingService and existing signed webhook fulfillment. The offer works
for non-Forestry customers without tenant-specific code.

## Source and access boundary

`module_catalog.offers.standard` owns 29900 monthly cents and 49900 setup cents,
USD, monthly interval and new immutable provider lookup keys. Public Everbranch,
Evergrove and signup comparisons consume it through product_surfaces. Old plan IDs,
prices, usage and entitlement definitions remain internal. Add-on prices are unchanged.
Public submissions reject retired keys. Existing issued requests require operator
review before replacement; immutable accepted agreements are not regenerated.
The generic introductory agreement entry points are retired. Client-specific legacy
builders remain available to preserve existing snapshots and amendments.

Capacity is unresolved: former Base, Starter, Growth and Pro have different included
modules and channel/contact limits. The retired public copy also claimed different
user limits. No new unlimited capacity, access or add-on grants are inferred.
`fulfillment_plan_key` is deliberately null. Resolve the new offer's access policy
explicitly, then map it to reviewed existing entitlements before enabling checkout.
All existing checkout, mobile-store, Shopify, lifecycle and tenant gates remain.

## Provider verification

New checkout verifies exact active USD prices, licensed monthly interval/count=1,
29900 recurring amount and a 49900 one-time setup line. It rejects legacy offer
keys and existing contract/subscription records. A durable tenant-specific frozen
attempt and provider idempotency key prevent setup duplication. Completed/expired
sessions require review. Ambiguous attempts older than 23 hours stop rather than
reusing a provider idempotency key beyond its retention window. A failed provider
request never becomes successful payment or activation.

Test keys were available in the existing private local environment. The explicit
`tests/Integration/StandardOfferSandboxTest.php` creates/reuses test-only prices,
creates a real unpaid Stripe test Checkout, verifies $798, verifies retry uses the
same session, and expires the session. Normal test runs do not contact Stripe.
The synthetic test maps to Base only inside a disposable test database; it does not
resolve production capacity policy. Sanitized provider evidence is ignored under
output/pricing-299. No real payment or subscription was collected.

Live Stripe itself is configured for Evergrove Software LLC, with charges/payouts
enabled. That does not establish new-standard-price readiness: create and verify
NEW live prices `everbranch_standard_299_monthly_v1` and
`everbranch_standard_499_setup_v1`, resolve capacity, then use the normal release and
independent billing activation gates. Never replace existing provider prices/items.

## Arithmetic and delivery

$299 monthly; $499 setup once; $798 first month plus setup; $2,293 six months plus
setup; $3,588 twelve months service; $4,087 first twelve months plus setup. Totals
exclude tax, usage, processing and separately scoped services; no prepaid contract,
annual discount, new minimum term or unlimited development is introduced.

The Retail tablet branch also consumes this offer, ships a generated offline
presentation (`php scripts/retail/export-offer.php`) and updates the cache. Its
historical request snapshots remain unchanged. Native Field/Showcase applications
contain no retired base-price offer; their operational bundles do not require a
store release for this price change. Separate provider/store releases remain gated.

Deploy websites only through the GitHub/Forge atomic workflow and verify the exact
release at /ready. Tablet updates preserve app IDs, pairing, authentication, carts
and saved content; never clear browser storage or uninstall to force an update.


## Standard agreement and the Avery review invoice

New generic agreements use `everbranch_standard_services_v1`, server-owned $499
setup and $299 monthly pricing, no multi-month minimum, and separately approved
out-of-scope work (the existing $50/hour default unless a work order fixes another
price). Issued sent/viewed quotes are returned unchanged unless an operator
explicitly requests a revision. Accepted snapshots remain immutable. Manual-invoice
agreements block duplicate checkout; fulfillment requires confirmed Standard
capacity and the existing verified provider/ledger/settlement conditions.

At the owner's separate explicit request, Avery's review agreement and a live $798
manual invoice were prepared with two one-time lines: $499 setup and $299 first
month. The invoice was verified open/unpaid, with no automatic card charge or paid
subscription/access activation. A forward-ready review email went only to the
owner. This one-off operation did not enable general Avery checkout or change
legacy client agreements. Private links, access codes and credentials stay outside
source control. Subsequent months and the service start/renewal dates require the
normal agreed onboarding and provider authorization.

## Residual old-price audit

- `config/module_catalog.php`: retained Base/Starter/Growth/Pro IDs, amounts and
  entitlements are grandfathered/internal. Their public/purchase flags are false.
- `ManagedWebsiteAgreementTemplate`, `CollinsElectricAgreementTemplate`,
  `FrontYardFoodsAgreementTemplate` and their legacy edit/rebuild paths preserve
  historical client snapshots. New generic UI/CLI entry points use Standard;
  already-issued quotes require explicit revision rather than silent repricing.
- Messaging, SMS, bulk email, managed website and AI add-on amounts are unrelated
  optional charges, not contradictory new-customer base offers. Existing usages,
  caps and add-on billing gates remain unchanged.
- Historical changelogs, operations evidence, tests of legacy subscriptions and
  stored/issued offer snapshots intentionally retain old amounts and cycle rules.
- Retail's historical Launch Partner definition is retired for new requests;
  previously recorded quote snapshots retain their wording. Its generated offline
  offer replaces old cached prospective copy with Standard. Native Field/Showcase
  and workspace app source contained no active retired acquisition offer requiring
  a native bundle or store-listing release.
- Active prospective pages, request validation, new generic agreements and Standard
  sandbox checkout were verified at $299/$499; no blanket numeric replacements were
  applied to unrelated product prices or real transactions.

## Verification on October 17–18

Full Pest: 3,635 passed, one intentionally skipped integration test, 25,003
assertions. Modern Forestry guard: 156 passed, 847 assertions. Changed PHP lint,
production asset build, all 18 public visual checks, public link/button audit, and
real Stripe test Checkout/retry/expiry checks passed. Lighthouse hard assertions
passed; one performance warning was 0.79 against a suggested 0.80. The whole-tree
Pint run reports 598 pre-existing PHP 8.5 style issues; those files were not broadly
reformatted. Production release and capacity/live recurring-price activation remain
separate gated steps.
