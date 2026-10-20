# Branch settings candidate — 20 October 2026

Status: tested local increment; held for coordinated integration after marketplace.
Parent: public offer candidate fb0a6cfa89fb5d785baf9b6b5ce72cf18ee4acde.
No merge or deployment performed by this task.

The existing authenticated Branches screen now presents Your plan, Your branches,
Add software and Setup. Current package/readiness comes from the canonical tenant
catalog and resolver. Pending requests are tenant scoped, labeled Awaiting review
and open guidance without repeating the request. Ready requires active access;
setup and pending counts remain separate. Financial links honor the existing
financial and route-role policies. Read-only members can review guides but cannot
activate or request branches. Existing server mutation gates remain authoritative.

Short three-step guides support Back, Cancel, Escape, keyboard focus cycling and
return to their opener, including Safari pointer behavior. Tab arrows/Home/End
and selected-tab URLs work. Search and categories remain available within each
view. Settings links to this existing screen. The premium list names Teams,
Trajectory and Markets & Makers with separate, unquoted pricing and assisted
review. Disabled hosted billing offers assisted setup instead of a purchase CTA.
Viewing guides and tabs performs no activation, payment or provider connection.

Messaging now quotes its exact configured product ($19.99/month, no setup fee)
in existing tenant catalog contexts, instead of accidentally quoting the SMS
bundle ($99/month plus $99 setup). A disabled exact product does not fall back to
the bundle. These are existing catalog terms, not new Simple offer pricing.
The prospective public Simple offer remains $499 setup + $299/month with core
branch selection and conditional usage fees. Existing agreements/grants are not
silently converted to Simple. Commercial migration/activation remains a separate
reviewed release responsibility.

Verification:

- Final PHP full suite: 4,015 passed, 3 intentional fixture-export skips, 2 failures
  caused by the network sandbox denying DNS. Both inspected fake-mail/fake-HTTP
  cases pass on their DNS-enabled isolated rerun (11 assertions). Thus 4,017
  unique normal cases pass across full run and the two reruns. Full XML and rerun
  XML are included, preserving the original failures rather than hiding them.
- Final focused settings/controller/productization/custom-request/billing-safety/
  blueprint/navigation group: 46 passed, 681 assertions, including fixture export.
  A subsequent final role-link/custom-request/brand check: 16 passed, 112 assertions.
  Brand-language suite: 4 passed, 29 assertions. New fixtures alone: 5 passed,
  38 assertions. A/B pending isolation and owner/member denials are exercised.
- Chromium and WebKit: 8 browser cases pass. Tabs, 390/820/1440px layouts,
  premium separation, pending guidance, all three steps, Back, native Escape,
  keyboard focus cycling/return, member guides, zero POSTs and zero page errors.
- Browser pages are synthetic authenticated Blade exports served by interception
  with real built assets on loopback. This verifies rendered UI behavior, not
  real production login/session/provider/billing completion. External requests
  and all non-GET requests are intercepted. PHP tests exercise server gates and
  existing handlers separately.
- Production build, Pint, YAML parse and git diff whitespace checks pass.
  CI gates now export synthetic fixtures and run the same browser cases.

Reproduce after dependencies/build:

    BRANCH_SETTINGS_FIXTURES=1 php vendor/bin/pest --compact tests/Feature/Everbranch/BranchSettingsTest.php
    npx playwright test -c playwright.branches.config.ts

Acceptance: the four tabs, current access/readiness, isolated pending review,
separate premium software, short guides, cancellation, member safety and responsive
keyboard flows pass locally. The release coordinator must verify the exact final
integration head in CI and smoke-test the live authenticated flow after release.

No new overall audit score is claimed before coordinated deployment and the fresh
live audit. The preceding public candidate remains 81.8/100 provisional under the
unchanged original rubric. R4 recovery/export, I2 real provider verification and
X3 production/device performance remain unknown. This increment does not justify
marking them passed or claiming the entire settings area is 100/100.
