# Workspace AI acceptance record — October 16, 2026

## Scope and environment

The local implementation extends pilot `ae180bfc` in three reviewable increments:
workspace context and projections; metered attention briefings and navigation;
then regression validation, guarded fictional preview and operating documentation.
All runtime and commercial rollout defaults remain off. No new tables are added.

Acceptance used a disposable local MySQL 8.4 database restored from the committed
schema, PHP 8.5, SQLite application tests, and headless Chromium/WebKit. Provider
responses were fake. No actual provider, Stripe, Shopify, SMS, telephone or customer
account operations were performed. The preview was loopback-only and checked its
fake-provider header before posting any AI request.

Fictional scenarios: **Fictional Field Services** (New York timezone, overdue task,
blocked lighting job, private field-work memory) and **Fictional Client Studio**
(Los Angeles timezone, overdue Customer Loop action, private client-drafting
memory, Field Service disabled). These are fixtures, not approved production tenants.

## Local evidence

| Check | Result |
| --- | --- |
| Full application suite | 3,239 passed; one existing skip |
| AI feature tests | 77 passed, including 33 new workspace cases |
| MySQL recovery/concurrency | 8 passed; independent-process chat quota, briefing quota, duplicate briefing UUID and note approval |
| Modern Forestry regression protection | 156 passed |
| Fleet browser regression | 4 passed across Chromium/WebKit |
| Fictional workspace browser acceptance | Passed at 1440px and 390px, with no overflow or browser errors |
| Frontend production build | Passed |
| Formatting on changed PHP files | Passed (17 files) |
| Migration safety lint | Passed; zero new/changed migrations |
| Repository-wide formatting | Blocked by 604 pre-existing style issues; all 604 reproduce from `ae180bfc` |

The fictional browser flow confirms zero provider calls on context/page viewing,
one fake generation per requested briefing, settled replay without another call,
server evidence links, allowlisted launcher origin, private memory and record
isolation when switching workspaces. The second briefing was requested at mobile
width. Feature tests cover output escaping, module/membership/record revocation
in flight, protected projections, shared quotas and caps, uncertain reservations,
truncation, snoozes, timezone midnight/DST and UTC billing boundaries.

The 604 formatting failures are outside this feature's PHP changes. Baseline
comparison extracted their exact committed starting versions and reproduced all
604 failures under the same formatter. They were not reformatted as part of this
feature. The full-format release check remains red until normal release review
resolves or dispositions that existing debt.

## Remaining release gates

Local fictional acceptance does not establish remote staging deployment, actual
model quality or real provider billing reconciliation. Record a remote staging
release SHA, environment, named fictional tenants, operator and acceptance evidence
before declaring staging accepted.

Production activation requires a separately named, approved tenant; current AI
billing evidence; dedicated provider credentials/model/verified rates; and an
owner-approved cap. Add its tenant ID to both allowlists only after that approval.
Preserve the normal GitHub/Forge release gate and keep tier purchases disabled
until separate commercial approval. Financial summaries, autonomous workflows,
voice and phone remain later phases.
