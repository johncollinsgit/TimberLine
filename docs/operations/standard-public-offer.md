# Everbranch Simple — October 20, 2026

The owner selected **$499 one-time setup + $299/month per business** for new
Everbranch Simple offers, with **“Additional text, email, and AI usage fees may
apply.”** Core branches are included; the customer chooses which branches they
need. **Teams, Trajectory, and Markets & Makers are separate premium software.**
Their prices have not been selected here; public copy says “Separate pricing”
and directs the customer to a conversation before activation. Custom development
is scoped separately. No usage rate, allowance, tax or commitment is invented.

`module_catalog.offers.standard` owns the cents-based base price, explicit core
branch keys and premium product names. The public catalog intersects those core
keys with the existing safe/public/live-or-beta module visibility rules. A future
public module is not silently included or sold. Premium/internal module keys,
placeholder modules and legacy paid add-ons are not public signup choices.
Existing tenant catalog prices, agreements and provider subscriptions retain
their recorded terms. Public packaging does not mutate those contracts.

`product_surfaces.public_plans` separates acquisition copy from legacy tenant
plan cards. Landing, Plans, signup, the Branch Explorer and Evergrove parent
pricing consume the shared offer. Signup shows the disclosure outside its hidden
Pricing tab. The $798 setup-plus-one-month illustration excludes usage and
premium/custom services and does not specify collection timing.

A Branch Explorer choice opens signup with that branch selected. Public requests
validate `branches_interest` against the current safe included catalog, preserve
it on duplicate pending requests and record interest without granting access.
Retired introductory tiers and paid-add-on selections are rejected with field
feedback. The request is reviewed before access instructions are sent.

`standard` is prospective commercial metadata, not a legacy entitlement plan or
provider price mapping. It has no automated checkout/fulfillment mapping. Even
with legacy hosted checkout enabled, it fails closed rather than substituting
an old price. Staff must finalize the agreement, exact usage terms and billing
setup before activating selected branches. No live $499/$299 collection path
has been enabled or proven by this presentation change.

Verify using `npm run build`, `vendor/bin/pint --dirty --test`, public/billing PHP
tests and `npx playwright test -c playwright.public.config.ts`. Public functional
browser checks now also run in the protected PR and production build gates.
Snapshot baselines use blocked external fonts for repeatability; Linux CI skips
Darwin screenshot comparisons. For Lighthouse use local JSON output or collect
and assert separately; do not invoke its public evidence upload target.

The release must use the protected GitHub test/build and Forge readiness gates.
See `../audits/20261020T-public-quality/report.md` for measured scores, release
verification and remaining unknowns. This task also authorizes subsequent fixes,
one at a time, and a tab-based plan/branches/premium/setup experience reusing the
canonical tenant catalog, role policy and audited action services.
