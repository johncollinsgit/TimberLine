# F05 — integration-health unknown state

Tested candidate held for coordinated integration after marketplace. Parent is
fb0a6cfa89fb5d785baf9b6b5ce72cf18ee4acde, independent of settings and F04 commits.
No merge or deployment performed here.

Integration-health count, severity and recent-event reads now form one observation.
Any failed read returns known=false, a null total, no partial severity/recent data,
a fixed error code and check time. A failed probe never becomes zero or All clear.
Successful zero and nonzero results retain their existing count/severity/recent
semantics. The operator view shows Unknown and Integration health unavailable,
with amber status and recovery guidance in its existing detail card.

A successful check stores only its timestamp in the application cache for seven
days. Failure preserves that last-success time when available; absence or expiry
is shown as no successful check recorded. No stale issue count is presented as a
current result. Cache-read/write failure does not invalidate a successful database
observation. Diagnostics log only a fixed event/code and exception class; SQL,
bindings, exception messages and credentials are excluded. The original landlord
host/operator gate is unchanged. No migration, provider, entitlement, customer
message or security setting changed.

Focused validation: 31 cases passed, 156 assertions across the developer dashboard,
integration recorder, maintenance commands and landlord host foundation. Fault
injection separately fails the count, grouped-severity and recent-list reads;
each returns unknown and renders Unknown rather than All clear. Tests verify
last-success provenance, first-failure absence, sanitized log/HTML evidence,
successful zero/nonzero/resolved-event handling, cache failure and non-operator
403. Pint and whitespace checks pass. Full regression evidence accompanies the
candidate: 4,018 passing cases, one intentional fixture-export skip and two
DNS-blocked cases in the network sandbox; both inspected fake-provider cases
pass on their DNS-enabled rerun (11 assertions), yielding 4,020 unique passing
normal cases. Original full-run failures and passing rerun XML are preserved.
Real production database failure was not induced.

Acceptance: unknown is distinct from a healthy zero, successful data remains
accurate and authorized staff retain a usable dashboard if the health source
fails. Exact integration CI and live smoke verification belong to the coordinator.
No new overall audit score is claimed before coordinated deployment and fresh
live review. Original unknowns R4/I2/X3 remain unknown.
