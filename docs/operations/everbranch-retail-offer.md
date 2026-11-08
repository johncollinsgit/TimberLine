# Everbranch retail public offer

Source candidate, November 2026. The public page at /platform/retail belongs to
the canonical theeverbranch.com marketing site. It reuses the Laravel public
controller, studio design, shared navigation, existing approved public images,
catalog and unchanged contact/start intake. It is separate from tenant Websites,
Modern Forestry, Shopify embeds and authenticated workspaces.

## Connected retail story

The page follows one business journey: sell, build loyalty, organize the crew
and employee time, review event pay/costs, understand event profit and bring
customers back. Keeping Square and a phased move are equally explicit starting
paths. This does not require a forced checkout or source migration.

- Keeping Square starts with an authorized, reviewed import and configured
  refresh of supported customer/purchase records. Sources, locations, explicit
  customer/product links and missing coverage stay visible. Original Square
  payment/order authority is retained.
- Moving in phases starts with the website/customer experience and reviews
  catalog, accounts, pickup/shipping and each payment route before launch.
  Managed Website's public catalog remains beta/assisted; retail commerce and
  native Wallet are separately gated.
- Rewards use each tenant's own program name, rules and eligibility. Birthday
  gifts, timing, expiry and messages need reviewed program/customer choices.
  A branded customer app is scoped during setup; the page promises no universal
  store listing or app approval.
- Teams is separately priced. The story includes scheduling, employee
  timekeeping, company pay profiles and event calculations: hourly/commission,
  reviewed minimums, setup/breakdown, per diem, mileage and optional hotel,
  meals, gifts and other custom costs. Current workforce/profile configuration
  is assisted setup, not a newly public self-activation module. Approved hours,
  pay estimates and frozen budgets are distinct from actual payroll processing.
- Event results/comparisons require reviewed sources and sufficient cost
  coverage. No causal marketing lift, complete lifetime value or guaranteed
  return is claimed.

## Availability boundaries and evidence

config/module_catalog.php remains the canonical catalog. Related module links
come from TenantModuleCatalogService.publicCatalogPayload, which intersects
current public-safe visibility and Simple core inclusion. No hidden pos,
team_work or time_tracking module is exposed as an activation/catalog link.
Teams is described through the existing public premium-product offer. Pay is
identified as a controlled registered-device development pilot, with only a
consultation CTA. No catalog visibility, plan, price or rollout flag changes.

The current code/readiness evidence does not support promises that importing
Square history enables automatic Everbranch purchase earning or redemption
inside an unmodified Square POS. Native Website rewards earn/spend is unfinished.
Generic non-Shopify browser QR is not assumed from the Shopify signed-proxy
customer QR flow. Pay's tenant wallet/contact-code work remains independently
gated. A successful import, a card tap, a source name match or reading a public
page cannot establish consent, identity, merchant activation or paid evidence.

Source references:

- docs/architecture/unified-customer-commerce.md
- docs/operations/shared-customer-profiles.md
- docs/operations/pay-tenant-rewards-and-events.md
- docs/operations/everbranch-pay-birthday-channel-fence.md
- docs/operations/birthday-club-consolidation.md
- docs/operations/retail-shop-pay-wallet.md
- docs/operations/teams-studio-foundation.md
- docs/operations/teams-event-schedule.md
- docs/operations/everbranch-current-state.md
- README.md timekeeping/mobile and retail sections
- config/module_catalog.php canonical capability/visibility definitions

## Public visual examples

Only existing public-site retail and studio photographs are used, through
page-specific 640/1080px WebP derivatives; the approved originals are retained.
The fictional
customer is illustrative HTML, not a private app capture. No source/customer
records, Asana/Macon figures, private workbook values, endorsements or tenant
screenshots enter public assets.

The event calculator is a local illustration with explicit editable assumptions:
net sales, booth fees, total paid staff hours, company pay and mileage rates,
product/processing percentages and optional hotel. It uses integer cents and
shows an estimated break-even, costs and surplus/shortfall. Blank/invalid or
100%-plus variable costs produce unknown results. It makes no provider request,
stores nothing and does not reuse the production pay engine or issue payroll.
Its simplified hourly example does not implement every actual policy/rule.

The second graph/table is a clearly fictional two-year comparison. Graphs have
semantic SVG title/description and visible table alternatives. No customer
results or guaranteed ROI are implied. A retail-only Vite entry loads the
existing public motion helper and calculator, with no new chart dependency,
application bootstrap or Shopify client. It reuses the existing preflight,
forestry, studio and approved presentation CSS. A server-owned head option is
set by this view alone; other public and authenticated routes keep their asset
entry. Font, color, spacing and input baseline checks matched the original
shared entry, and the native FAQ/nav/keyboard behavior is retained.

## Verification and release

Focused public PHP tests protect visibility, navigation, no activation/source
calls, existing offer/intake and availability language. Public Chromium and
mobile WebKit checks cover responsive widths, keyboard FAQs, the existing
fictional demo handoff, calculator edits/unknowns and absence of writes.
Read docs/operations/public-site-media-runbook.md for visual/keyboard/contrast,
click-path and Lighthouse checks. Collect Lighthouse locally without its
temporary-public-storage upload when producing private review artifacts.

November 2026 source acceptance: the focused public PHP suite passed 31 tests /
268 assertions; the complete desktop Chromium and mobile WebKit public suite
passed 52 tests, including 14 retail checks. The final retail-only rerun passed
after the responsive photos and complete graph table were added. The public
click audit covered three routes with no failed routes, broken anchors or dead
buttons. Vite built successfully. Desktop/phone screenshots were visually
reviewed; 320/375/390/820/1440px checks found no horizontal overflow.

Local Lighthouse found full accessibility, best-practices and SEO scores after
contrast and inline-link fixes, with zero layout shift. Responsive photo
derivatives reduced the hero from 334KB to 44/86KB. The retail-only asset entry
also reduces this page's CSS from about 700KB to 345KB and its initial scripts
from about 300KB to 2.5KB (uncompressed built sizes). Treat local throttled
performance observations as measurements of the Artisan test setup, not
production Core Web Vitals or a completed production performance acceptance.
The phone Lighthouse sample improved from 60 performance / 7.50s LCP after
photo optimization to 80 / 4.09s with the scoped entry; accessibility,
best-practices and SEO remained 100, with zero layout shift. Production
compression, caching and physical-device behavior still need release checks.

Source/browser tests and an updated page are not a protected deployment or
physical-device/provider acceptance. No provider account, customer message,
program publication, tenant Website, Square import or production flag is
activated by this work.
