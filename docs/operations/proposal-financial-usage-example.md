# Financial usage example presentation

The Financial tab starts with one illustrative month for one department, using the existing Moderate profile: 500 AI requests, 120 paid voice minutes, 500 outbound SMS segments, 5,000 emails and 1 GB of attachments. It explicitly assumes one SMS number. The pinned 23 October 2026 model gives $14.12 to $14.87 in provider usage, including number rental. This is an example, not measured campus use, a software package selection or an all-in quote. Software fees remain separate. No allowance, overage rule, provider activation or new rate is introduced.

`usage-example.blade.php` contains the fixed readable example. Its displayed subtotal and cost lines are checked against the unchanged production `FinancialScenario` Moderate calculation. A future pricing/profile change must update that reference example deliberately and pass the same test. Do not relabel these dated illustrative rates as current connected-account prices.

The existing setup form is collapsed under **Adjust this example**. Explicit preparation opens the original draft editor under **Your scenario assumptions**. Setup validation errors reopen setup, while draft inputs and errors retain their existing `old()` values. The original names, IDs, input bounds, channel toggles, explicit profile-replacement buttons, POST actions, CSRF and context binding are unchanged. University-wide and departmental preferences, payment preferences, unresolved terms, saved comparisons and explicit remove/clear actions remain available.

Initial display prepares or saves nothing. It does not choose a software offer or contract term, alter an existing session cart, submit a service selection or request an agreement. The existing integer nano-USD calculations, display rounding, package recognizer, shared number rental, unknown costs, terms and selection validation remain authoritative and unchanged.

The shared Financial presentation can appear on an existing ordinary proposal without changing its immutable payload or feedback. This does not replace an archived campus renderer. Old campus editions keep their original manifest, renderer, token/password grants, expiry and comments. A new current-source campus/workspace edition still needs the separate reviewed preservation, preparation, publication and initialization path in the existing release runbooks. This view change performs none of those actions.

## Focused verification

From an isolated checkout with empty local environment files and disposable SQLite:

```
php vendor/bin/pest -c phpunit.team.xml tests/Feature/Proposals/FinancialUsageExamplePresentationTest.php tests/Feature/Proposals/FinancialScenarioTest.php --compact --display-warnings
```

The five new cases cover initial no-write display, exact reference arithmetic, saved cart and management preservation, validation disclosure/input recovery, unresolved commercial choices and unchanged encrypted reviewer payload/comments. All ten original FinancialScenario cases remain unchanged.

The existing `scripts/proposals/financial-browser.mjs` business assertions remain relevant. Its setup-input loop explicitly opens `[data-financial-setup] > summary` before filling each newly collapsed setup form. This is an intentional presentation transition, not a reason to remove assertions, change pacing, bypass access or increase waits. After integration, verify the initial card and closed controls at desktop and 390px, open adjustments, prepare/edit/save a comparison, reload and check error visibility and keyboard access. Native browser/CSP, exact-head CI and hosted acceptance are separate from the local feature-test result.
