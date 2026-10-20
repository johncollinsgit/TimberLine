# Staff-only quality-report agent — proposal, not implemented

Build an on-demand landlord report inside the existing Developer Control Center,
behind its host/operator policy. A background runner consumes exact-release CI,
read-only health/config metadata and approved isolated synthetic browser results.
It stores an append-only audit with release SHA/tree, rubric hash, timestamps,
criterion ratings, source layer, flow status and redacted evidence hashes.

The existing health probe must retain known/unknown distinctions. A missing check
never becomes a successful zero or a passed criterion. A deterministic calculator
owns weighted half-up arithmetic; rubric changes create a new version rather than
rewriting scores. Show current vs historical evidence and unverified acceptance
beside every headline score. Preserve provider/restore/device unknowns explicitly.

Use tabs for Overview, Journeys, Evidence and Suggested fixes, with one finding
and short next steps at a time. A staff reviewer can open a redacted artifact,
compare releases and export the report. Proposed fixes have effort/acceptance and
are separate work items. An optional AI narrative may summarize the evidence;
it cannot execute actions, invent evidence, approve changes or grade its own patch.

Never grant this runner customer-write, payment, messaging, provider credential,
flag-changing, merge or deployment powers. Use existing tenant/object/financial
policies and synthetic fixtures; do not enumerate real records. If recurring runs
are later requested, use a bounded job and notify only meaningful changes, failures
or required actions. Do not create such scheduling as part of this proposal.

Prototype estimate: 3–5 engineer-days after permissions/runner design. Acceptance:
operator-only negative tests, immutable versioned evidence, null unknowns, checked
arithmetic, no-effect synthetic runner, cancellation/time limits and audit export.
The prototype is optional and does not replace the genuine acceptance work in
R4/I2/X3. No in-app agent or automation was built by this audit.
