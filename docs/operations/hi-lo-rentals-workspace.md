# Shared property workspace and Hi-Lo

Owner direction, November 11, 2026: build a native, persistent shared-property
workspace matching the reviewed Hi-Lo demo. Keep the Hi-Lo public/domain cutover
on hold. The separate public demo has Jordan's corrected household label,
seasonal month banners and an icon-only ownership key. It remains fictional and
browser-local; none of its records are production opening data.

## Native candidate

`/rentals?tenant=<workspace>` is an authenticated application, with multiple
properties inside the existing tenant. `RentalAccess` rechecks active, verified
tenant membership, the canonical `property_rentals` module, global availability
and an explicit tenant allowlist. Non-administrators additionally need a property
household linked to their current user. Global platform roles do not substitute
for a property tenant membership. Viewer roles cannot write.

`RentalRepository` serializes each property's commands under a MySQL row lock.
The current property version rejects conflicting edits; immutable actor-bound
request receipts make an unchanged original retry safe. Household assignments,
private team access and module state are resolved again inside the transaction.
Property state and event payloads are encrypted. Events retain original command
inputs and before/after hashes; the UI shows the latest 200 while storage retains
the complete event history. Explicit capacity limits reject new writes rather
than discarding history or financial evidence.

Properties start empty. Administrators enter names, location/timezone, households
and account links, ownership slots, seven-night allocations and house-care holds.
Allocation dates may not overlap. Free releases and claims change availability
or the current occupant; accepted bilateral swaps keep permanent and original
ownership. Permanent changes need an administrator and reject changed temporary
occupancy. Original allocation remains recorded. No unreviewed rotation is
automatically treated as a real allocation.

Maintenance, notices, stay journals and per-stay conversations persist on the
property. Comment ownership uses actual user IDs. Private team notes and files
are projected only to assigned households and administrators, with authorization
rechecked on every download. Removing a team or tenant membership revokes future
reads. Private files cannot be attached to shared entries.

Dues use integer cents. Administrators may record a check or cash receipt already
received, with date/reference and immutable original evidence. A reasoned void
retains that evidence. Members see only their own household's dues. No card
charge, provider settlement, tax conclusion, subscription or paid booking is
created. Messaging is saved drafts only, with no email/SMS dispatch or reminders.
No Shopify, Square, Website Commerce or existing customer/rewards records change.

Files use the non-public `rentals_private` disk, MIME/size and property quota
checks, the existing trusted `TeamFileScanner`, authenticated routes, opaque
paths and integrity hashes. An unavailable or rejecting scanner never publishes
bytes. A response failure after commit must not delete admitted bytes; unresolved
private orphans require operator reconciliation. This is not public file hosting.

## Release and cutover hold

Both `RENTALS_ENABLED` and `RENTALS_TENANT_ALLOWLIST` default off/empty. The
canonical module defaults disabled, is not marketed and does not appear on a
public or mobile-store surface. Navigation only exposes it after all admission
checks. Shipping source or additive schema does not activate Hi-Lo. Do not
change production environment flags, module entitlements, default landing pages,
DNS, Forge aliases/certificates or merge demo-host PR #599 as part of this work.

Before later activation: owner review of the real property, household/account
links, ownership calendar and dues opening records; private upload/scanner and
member/revocation acceptance; exact-SHA protected GitHub/Forge release. No seeded
fictional contacts, ownership, balances or payments belong in the live tenant.
Provider collection and outbound messaging need separate implementation and
readiness evidence.

## Checks

- `tests/Feature/Rentals/RentalWorkspaceTest.php` covers tenant/rollout/module
  isolation, persistence, claim conflicts/replay, swaps, receipt evidence,
  private file/team revocation, scanner failure and viewer restrictions.
- `tests/Integration/RentalWorkspaceMySqlRecoveryTest.php` reconstructs durable
  partial rental DDL and uses separate processes for simultaneous claims. It
  refuses any database except explicitly disposable local `everbranch_ci`.
- New migration `2026_11_11_190000_create_rental_workspaces.php` is additive and
  resumes missing tables/indexes; both indexes and data-free baseline require
  protected MySQL validation. The schema-baseline workflow supplies the actual
  MySQL-generated dump/fingerprint; never hand-edit them or use production data.
