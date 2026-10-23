# Private Financial planning cart

Financial can compare up to three private browser-session scenarios against the exact saved proposal. Each scenario has one to three labeled planning departments, editable AI text/token assumptions, voice minutes, SMS segments, email volume/attachment data and separately indicated management/payment preferences. Light, Moderate and Busy are illustrative starting assumptions, not measured Lees-McRae or empirical typical usage. Department labels do not create or identify authorized departments.

The cart does not change proposal selections, terms, catalog items, drafts, published versions, reviews, comments, memberships, provider settings, payments or messages. Reviewer legal access remains read only. Current owner membership or exact recipient host/version/password-unlock/expiry/revocation is rechecked for every request. Native POSTs retain CSRF and the Financial panel; the existing restrictive response CSP is unchanged. Planning data is private to the browser session, context-bound to exact owner/user/draft revision or recipient version/content hash and the rate fingerprint. A changed context hides old scenarios and rejects stale forms409. It is not persistent institutional memory or an agreed budget.

## Commercial boundary

Only the exact saved Lees-McRae native One workspace and Three-workspace bundle offers are recognized for a combined software-plus-usage subtotal: client reference and client ID, item client ID, exclusive group, exact name, exact two-charge labels/cadences/amounts must all match. Charges and allowed terms are always read through the native read-only selection validator. One workspace requires one modeled row; the fully active three-workspace bundle requires three. Two rows can calculate usage but do not create a two-department package price. Unknown or altered offers retain their quoted fees separately; combined scope stays pending. The staged second-department amount is not treated as a separately priced package. One-time setup remains separate from monthly charges.

All-in totals remain pending because provider readiness, registration, selected-account extras and taxes are not priced. Unknown phone-number count remains pending, not zero. Pooling changes management responsibility only; department quantities are retained and usage arithmetic is identical. Prepaid versus actual-usage preference is separate from university-wide versus departmental management; neither assumes an allowance, spend cap or new fee. Evergrove usage markup is zero.

## Arithmetic and provenance

Rates and source/date are pinned in `docs/operations/evidence/proposal-financial-rates-2026-10-23.json`; its SHA is included in the server rate fingerprint. The UI links the official OpenAI, Twilio and SES pages and lists units/exclusions. These are illustrative vendor baselines, not selected or connected account rates. SES readiness is unverified and a prior denial is reported. Voice session cost excludes its backend text/model/tools and any telephony; backend text must be modeled separately. SMS segments differ from messages. Email includes volume and attachment GB but no optional deliverability/account extras.

Production arithmetic uses bounded 64-bit integer nano-USD, with rounding at display only. Positive sub-cent values display `<$0.01`. Compared scenarios store normalized assumptions only and are recalculated on render. A scenario's save ID is server-issued; an exact save retry is idempotent, changed assumptions under a saved ID refuse409. Remove/add a new scenario for changed assumptions. Up to three comparisons and three department rows are accepted. No external rate lookup or provider request is made.

## Verification

Run from the repository root with empty local environment placeholders only:

```
php vendor/bin/pest -c phpunit.team.xml tests/Feature/Proposals --compact --display-warnings
php vendor/bin/phpunit -c phpunit.enterprise.xml --display-warnings
ENTERPRISE_TEST_PHP=php PROPOSAL_BROWSER_ENGINE=chromium node scripts/proposals/financial-browser.mjs
ENTERPRISE_TEST_PHP=php PROPOSAL_BROWSER_ENGINE=webkit node scripts/proposals/financial-browser.mjs
```

The browser smoke uses the existing guarded disposable loopback proposal kernel, actual Eloquent/session/CSRF/controllers, fictional encrypted SQLite proposal data and native POSTs. It refuses external browser requests and never uses a production session. It verifies one/full-three/two-scope-pending comparisons, per-department profile application, nullable preferences, reload persistence, separate-browser isolation, keyboard actions, desktop/iPad/mobile/reflow geometry, unchanged encrypted proposal/access rows and unchanged CSP. Published PDF downloads remain the saved proposal, not this private cart. The legacy custom-dollar estimate endpoint and exact-version flash result remain compatible; the unit cart is the visible primary planner.
