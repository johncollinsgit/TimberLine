# Markets & Makers management candidate

This implements the supplied Markets & Makers brief on
`codex/markets-makers-premium-console-20261020`, based on the preserved onboarding
candidate `abacc4e08143cfaaa2d7a0a2e3585b29d65056cf`. It is a reviewable source
increment, not a production release or updated overall quality score.

## Result

The old account preview used thirteen wrapping tabs and mostly explanatory
panels. Its replacement has a persistent forest sidebar, compact header,
read-only status, useful attention queue and a linked fictional scenario. Search,
status filters, details and browser history work across maker/product/order/event/
pickup/statement examples. No sample implies provider readiness or actual sales.
The missing original conversation screenshot was not available here; the before
comparison is source-grounded, not a claim to have inspected that image.

The real operator uses the same presentation shell, grouped destinations, bounded
loaded-list search and compact expandable records. Existing product, stock,
shipping, refund, terms and statement commands retain their original fields and
server authority. Market/event forms remain real commands in focused details.
Payment setup leads with current blockers and progressively discloses occasional
shipping/policy/provider controls. Reports preserve Eastern dates, live/test
separation, refunds and the latest-2,000 matching-order bound. Attention queues
state their separate 100-maker/product, 50-order and 100-pickup limits.

The independent maker has role-specific navigation, searchable own products,
focused stock/editing and order fulfillment, earnings, agreements, participation,
private business details and shared storefronts. The selected section survives a
normal form redirect. No staff membership or paid subscription is introduced.
The shopper layout/CSS and Teams implementation are byte-preserved.

Actual operator branding reads an existing profile without initializing one, and
uses confirmed business specialty from the existing blueprint service. Preview
receives static fictional context only. Livestream remains explicitly planned;
no video, chat, stream scheduling or checkout was invented.

## Verification

| Check | Result |
| --- | --- |
| Marketplace suite with opt-in rendered fixture export | 99 passed / 1,141 assertions |
| Teams and navigation regression | 212 passed / 2,085 assertions |
| Tenant experience and preview scope checks (overlaps above) | 35 passed / 269 assertions |
| Chromium + WebKit browser checks | 32 passed |
| Production build, typecheck and existing JS tests | Pass; existing Rollup annotation warnings |
| Pint | 8 affected PHP files pass |
| Whitespace | `git diff --check` passes |
| Schema | No changes |

Browser checks cover all thirteen preview and operator destinations, all eight
maker hash destinations, 390/1024/1440 widths, actual row expansion, filtering,
Back/Forward, empty search, mobile keyboard trap/Escape/close/opener restoration,
review-held maker fulfillment, business forms, add-product disclosure and public
shopper separation. Screenshots in this directory show the three roles at all
three widths, the WebKit phone preview and the focused operator maker profile. Desktop/phone/tablet renders were
inspected; the shipping button's stretched grid treatment was corrected.

The first browser run exposed control-label targeting and the second caught
WebKit's clicked-button focus behavior. Explicit accessible labels and an explicit
drawer opener fixed those failures. A later form-return test exposed fixture
harness limits: redirected authenticated GETs reached an empty local Laravel DB,
and WebKit cannot fulfill a synthetic redirect through Playwright. The final
loopback-only fixture server serves actual rendered HTML without bootstrapping
Laravel, touching a database or writing files; one header-guarded fictional POST
returns a real 303 solely to test fragment return. Both engines pass that test.
Earlier failed runs were superseded, not counted as passes.

PHP feature requests prove the real authorization/record boundaries; browser
fixtures prove presentation and interaction. Preview tests retain no-form,
no-store/noindex, exact ID grant, inactive/unverified/unlisted rejection, tenant/
store parameter rejection and no provider/mail/notification/write guarantees.
Additional SQL observations reject tenant/merchant/Website/Shopify reads during
preview detail requests. `verification.json` records 21 unchanged authority files
or regions, including all operator commerce command methods, public/Teams styles,
commerce services, module/AI/Marketplace configuration and routes. The original
user checkout's tracked diff and immutable audit/source refs remain unchanged.

## Delivery and remaining limits

The integration coordinator owns shared routes/workflows/Vite/docs reconciliation,
review, merge and protected deployment across the active machines. This source
branch intentionally does not merge the frozen #444 corrections at
`b769733e123d3047021a9f7ec5f4cfc8b331a08d` or current main. **Before release, preserve
those corrections**, including #444's full-deployment guided-fixture export and
Website/domain search/retail draft-return fixes. This candidate adds dedicated
Marketplace fixture/browser steps to both workflows; integration must retain
both guided and Marketplace exports, all existing voice/recovery steps and the
Vite inputs. The distinct candidate diff is from `abacc4e0`; do not treat the
stacked branch as permission to bypass its dependency or deploy independently.

No persistent production-login, real merchant/provider/device checkout, physical
Galaxy/iPhone, real bank transfer, DNS, public publishing or provider-acceptance
claim is made. Existing rollout, commercial terms, independent maker fees and
payment gates remain authoritative. The existing $499 setup + $299/month Simple
offer and “Additional text, email, and AI usage fees may apply” remain unchanged;
no premium software rate is invented. The prior quality rubric/audits are untouched.

## Focused profile follow-up

The original Marketplace candidate `590edd42` is preserved. Final self-review
found the maker detail page still used six wrapping local section anchors. A
narrow follow-up makes that row horizontally scrollable, retains every anchor
and adds six viewport/engine checks for one-row geometry, the final Connections
anchor, reachability and no page overflow. The full browser suite now passes
28/28; Marketplace remains 98/1,127. The follow-up changes no commerce, shared
workflow, Vite, route or integration behavior.


## Independent review corrections

Two distinct P2 issues identified against the original source candidate are fixed
in a separate preserved follow-up:

- Maker fulfillment pagination dropped its Orders hash, so the next page selected
  Products. The existing paginator now adds `fragment('orders')`. A 21-line real
  feature request proves20/1 page membership and both href fragments; both engines
  exercise Next/Previous, preserve the Orders pane and check overflow. Scoped
  paginator styles provide one visible control set, restrained SVGs,44px targets
  and distinct disabled states without relying on Tailwind being loaded.
- Maker profile links used only the initial server query, losing search/status/
  selected-row changes made in the browser. Explicit same-origin profile and back
  links now synchronize bounded permitted UI/report parameters whenever current
  history state changes or restores. A browser case changes search/status, opens
  a row, visits its real profile path and returns to the same filtered open row.
  No object path, route or server authorization changes.

Final acceptance:99 Marketplace tests /1,141 assertions and32 browser cases pass;
build/typecheck/existing JS checks and Pint pass. The original590edd42 and focused
profile follow-up fa4c3674 remain preserved. Shared workflow/Vite/base integration
is still reserved to the coordinator. The supplemental pagination screenshots
show the actual Blade paginator on its second page in both engines.
