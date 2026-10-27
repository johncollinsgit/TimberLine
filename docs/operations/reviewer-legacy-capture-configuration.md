# Owner capture configuration metadata seam — source only

This source seam supports a FUTURE explicitly approved owner-runtime configuration/
cache procedure. It does not install configuration, enable capture, register a
legacy edition, create an account/grant/anchor, read a human tab, convert/import
data, publish or activate any provider. No actual host IDs, Version pins,
credentials or activation values are supplied here.

The ordinary root config always keeps legacy_capture literal disabled/empty and
never consumes capture ENV. Only the Enterprise overlay consumes
SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED and
SERVICE_PROPOSALS_LEGACY_CAPTURE_APPROVALS_JSON. The flag is enabled only by
literal PHP true returned by the existing env() parser; no truthy cast is used.
Default is false. Missing/empty object metadata stays disabled even with a flag.

Metadata is at most4096 raw bytes and JSON depth4. A nonempty object must contain
exactly one existing Version UUID key and exactly these ten row fields:
tenant_id, client_id, silo_id, storage_id, version_hash, manifest_sha256,
source_hash, build_id, module_sha256, expires_at. Tenant is a positive integer;
IDs are lowercase RFC UUIDs; hashes are lowercase64hex; build is bounded ASCII;
expiry is a real-calendar exact UTC seconds string. Arrays, unknown/missing fields,
wrong types, invalid UTF8, multiple rows, duplicate decoded keys (including escaped
aliases) and other malformed input return invalid/null, never a prior approval.
No provider key, token, recipient password, name, raw edit or path field is accepted.

The full module_sha256 retains the reviewed screen meaning: SHA of unchanged
pure helper + newline + UI + fixed mount tail, using the one checked script snapshot.
This config parser does not read source files to calculate/approve it. It only
validates nonsecret metadata shape. Root must review exact values separately.

Valid metadata is retained while the independent flag is false. Enterprise
composition requires purpose proposal_owner, exactly one integer tenant=>enterprise
workspace and matching fixed owner/runtime client+silo+storage values. It uses
existing fixed ENTERPRISE_CLIENT_ID, ENTERPRISE_SILO_ID and
ENTERPRISE_CLIENT_STORAGE_ID entries. Any missing/invalid/mismatched owner binding
closes admission. Nonowner purpose ignores capture metadata and stays empty/off.
No other AI/workspace/preparation/initialization/legacy/provider gate is changed.

Parsing has no application/container, DB, HTTP, source/private filesystem or clock
permission dependency. Calendar-valid past timestamps may remain cached metadata;
they are NOT fresh permission. Every actual capture GET/authorize still checks
current expiry, active exact Version/hash, immutable edition/manifest, full checked
script SHA, genuine ClientSilo and fresh locking real-owner membership. Configuration
strings cannot substitute for actual runtime/identity checks. No capture-core,
CSP, pure helper, old renderer,256 source cap or existing grant logic changed.

A future host procedure must follow the existing release/config-cache boundary,
keep the flag off while the exact nonsecret one-Version map is independently
reviewed, and obtain only the specific outstanding activation/real-capture approval.
Do not use these tests' config overrides or fictional IDs as real setup evidence.
Do not infer a new legacy registry entry or deploy/publication permission. No
installer/CLI/scheduler or live config-cache command runs as part of this packet.
The seam is source support; secure host installation/activation and genuine owner
capture acceptance remain separate and unperformed.

Focused tests independently load ordinary and Enterprise config entry points in
child PHP with only synthetic ENV shims and no Laravel application boot. The pure
parser also runs under php -n without Composer/framework helper access. Tests cover
closed types, duplicate aliases, exact bounds/calendar, literal flag, mismatched
runtime, preservation while off, serializable output and unchanged other gates.
No browser/native matrix repeat is needed for this pure non-UI seam; root must
still run appropriate exact-head CI after integration. No source-cap increase,
old case/engine/permission assertion removal or runtime action is authorized.
