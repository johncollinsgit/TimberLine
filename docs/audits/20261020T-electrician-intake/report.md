# F04 — supported electrician intake

Tested candidate, held for coordinated integration after marketplace. Parent is
fb0a6cfa89fb5d785baf9b6b5ce72cf18ee4acde; this commit has no settings dependency.
No merge or deployment performed here.

Configured supported industries now come directly from the canonical public
intake configuration. The optional electrician tutorial flag still controls its
preview/tutorial surfaces, independently of whether a prospect can accurately
select Electrician on the demo or production request form. The existing request
receipt explains review of business needs and chosen branches in plain language.

Verification: 32 public PHP cases pass (321 assertions), including the four
combinations of demo/production and tutorial on/off. They verify selected industry,
Simple interest and field-service choice are preserved, request remains pending,
no tenant/access profile/entitlement or tenant membership is created, and the
existing request identity remains inactive. Tutorial-preview visibility still
matches its flag. Unsupported industry keys still fail validation. Notifications
are faked and no provider HTTP is sent. Another 21 onboarding/intake cases pass
(140 assertions), preserving customer tutorial gates. Four Chromium/WebKit
browser cases select Electrician on demo/production pages, retain the visible
canonical conditional-usage-fee offer on production intake and make no submission. Pint and whitespace
checks pass. No migration, provider, entitlement or security setting changed.

Acceptance: supported prospects can select their industry with the optional
tutorial disabled, while actual workspace access remains subject to existing
approval and setup. Production submission/provider acceptance was not tested.
The coordinator owns exact integration CI and live verification. No fresh score
is claimed before the coordinated releases and live audit; original unknowns
R4/I2/X3 remain unknown.
