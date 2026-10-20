# Guided onboarding and settings candidate

The existing first-workspace questionnaire lost progress on refresh, and settings
were split between personal, administration and branch entry points. This change
adds account-private resumable questionnaires, a three-step saved business
profile, a categorized searchable hub and a small record-derived setup guide.
Existing customer work, manual labels, agreements, grants and provider state stay
authoritative. Source base: `29703b4cbb82d67abc32c2f090a2551302979b39`.

The new owner path was exercised through actual feature HTTP requests: create one
workspace while skipping optional answers, complete the existing five-step
customer creation flow, view the result and observe reconciled guide progress.
Repeated creation is serialized and invitees retain their existing membership.
The business profile resumes after saved steps and rejects stale revisions.
Owner/member, foreign/inactive membership, multi-tenant context, metadata-only
search, existing-record progress and independent personal preferences are covered.
Confirmed context reaches existing authorized help without descriptions; absent
or unconfirmed context leaves the old stamp inputs unchanged. It cannot change
billing approval, caps, access or provider readiness.

Validation:

- 581 scoped PHP tests / 4,368 assertions pass; six deliberate export skips
  (five existing Branches exports and one new guided-settings export).
- Opt-in guided export: 15 cases / 236 assertions pass; existing Branches export:
  eight cases / 45 assertions pass.
- 14 new Chromium/WebKit browser cases pass at 390/1024/1440px, including both
  questionnaire variants, optional skipping, search focus, progressive steps,
  member boundaries and no operational writes. The combined prior pass also
  passed all 22 existing Branches browser checks (36 total). The new 14 were
  repeated after the final optional-question and privacy adjustments.
- Production build (typecheck, existing JavaScript tests, Vite) passes, with only
  existing Rollup dependency annotation warnings. Pint passes all 14 changed PHP
  files; whitespace validation passes. No schema or baseline change is needed.

Visual walkthrough reviewed desktop hub and phone goal-step screenshots. A broad
input selector initially enlarged radio controls; the corrected scoped selector
and latest rendered fixtures are retained here. Initial test setup failures
(missing new manifest entries before build, fixture-export environment/host, one
stale model read and a test import) were corrected and superseded by the passing
results above; they are not hidden production failures.

The browser layer uses actual rendered Blade and built assets, with fictional
contexts and intercepted account-draft saves. Actual server save/resume and
customer persistence are covered by feature requests. These are not physical
Galaxy/iPhone, persistent production-login or provider-acceptance claims. No real
messages, payments, provider connections, customer changes or public-site
publishing were performed.

Both `.github/workflows/tests.yml` and `.github/workflows/deploy.yml` add the
opt-in guided fixture export before the existing workspace browser configuration.
The full deployment gate therefore prepares these fixtures on a clean checkout,
including when verified-PR-tree reuse is unavailable. Coordinator review caught
the missing deployment export in the original candidate `abacc4e0`; this narrow
correction preserves that original commit. Both workflows retain the voice/browser/build
steps introduced by #442/#443. The entire required exact-head CI and protected
release remain pending coordinator review. Rollback uses the protected prior
release and retains additive JSON; no migration, destructive rollback or backfill
is involved. Prior source branches and all immutable audits remain unchanged.

See [the operating map](../../operations/workspace-settings-and-onboarding.md)
for settings scopes, existing route/service inventory and extension conventions.
The next separately reviewable task is the supplied Markets & Makers brief:
Teams-style navigation, useful role-specific operator/maker workspaces, coherent
fictional account-only preview, progressive detail, unchanged commerce/access
boundaries and honestly planned livestream. This candidate does not implement or
claim completion of that second task.

## Coordinator acceptance correction

Website/domain metadata discovery now reuses the existing authorized route.
Four additional feature cases cover native availability, separate domain rollout,
member/tenant/disabled-entitlement isolation, connected-editor distinctions and
a real retail draft-product journey. The retailer opens the existing editor,
saves a fictional draft, sees completion and returns to a list including drafts;
the site stays unpublished and HTTP/mail remain faked. This exposed and fixed
the completion link's active-only default. The focused corrected run passes
30 tests / 387 assertions with one intentional export skip (guided settings,
Website catalog and Website sales navigation). Pint passes on all three changed
PHP files. Full-suite/browser evidence above predates this bounded correction;
exact-head CI remains required.
