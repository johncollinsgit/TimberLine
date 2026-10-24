# Unwired read-only legacy sample capture primitive

This source packet performs no actual browser capture. It adds no route, UI,
bootstrap, download, upload, initializer, publication, import or permission
bridge. A future caller must authenticate the actual owner and obtain explicit
confirmation BEFORE invoking the named ES-module export. A self-selected review
name is not owner authority. The helper cannot authenticate or verify that caller;
its context shape checks and SHA are not signed authority, authorship or approval.

`captureLegacySample(storage, captureBinding)` reads exactly one supplied storage
handle with one `getItem('everbranch-enterprise-reviewer-v1:sample-campus')` call
per explicit invocation. A future caller must supply the actual same-tab legacy
sessionStorage object after confirmation; the helper cannot verify that handle's
identity. It never looks up browser globals/sessionStorage automatically, lists
keys, reads cookies/auth/other storage, writes/deletes/resets, loads/executes the
sample adapter, upgrades a fixture, sends network data or saves a file. Module
import/definition has no capture side effect. Do not call it automatically or
navigate through a sample bootstrap before observation: existing adapters can
upgrade or reset browser state. Future owner wiring requires separate review.

The closed binding has schema_version1, capture_id UUID, observed_at UTC seconds,
observed_version_id UUID, observed_version_hash SHA256, observed_source_hash
SHA256 and observed_build_id (1..120 ASCII identifier characters). These are
caller-provided observations of a page/context, not proof that the captured
value belongs to that Version. The generic sample-campus key was shared across
fictional presentations. Provenance, authorship and completeness across other
tabs/devices remain unknown. This stage neither finds every legitimate capture
nor resolves conflicts, deletions, ordering, assignments or last-writer intent.

The fixed cap is4MiB of actual UTF8 bytes. Storage strings are UTF16: actual
unpaired high/low surrogates refuse before TextEncoder can replace them. Well-
formed scalar strings are encoded WITHOUT normalization, trimming, newline or
JSON rewriting; visually identical NFC/NFD values retain distinct bytes/SHA.
An exact bounded original contains immutable text, UTF8 base64, byte_length and
SHA256. SHA is a content checksum only; any future export/import must independently
verify the bytes and obtain its own reviewed authority and reconciliation proof.
Returned data is private untrusted content; do not log it or render it as HTML.

Only native JSON syntax and top-level-object shape are checked. Parsed state is
never returned, traversed, merged into configuration, reserialized or executed.
Duplicate keys and unsupported fields are NOT detected or schema-validated;
original text/bytes preserve them, metadata says not_checked, and conversion is
always disallowed. Escaped JSON surrogate sequences are ASCII raw text and may
appear in otherwise valid JSON; parsed string-value scalar validity remains
unvalidated. Prototype/script/HTML/URL-looking text confers no action or grants.
A later nonexecuting conversion/parser must explicitly address duplicate keys,
unsupported fields and joins before any closed shared graph is constructed.

Statuses are explicit: captured_opaque means only a bounded exact original of a
syntactically valid JSON object, never schema/data/import approval. Malformed or
nonobject JSON is refused but its bounded scalar original and SHA remain in the
returned observation; keep it private. Missing means not_observed, not zero work.
Storage exceptions/unavailable encoder/digest produce sanitized unavailable,
without fallback/retry. Oversized or invalid-scalar values refuse without a false
UTF8 SHA or returning an oversized/replaced original; source storage is untouched.
No function promise can prevent another script/person from changing storage.
One call hashes the string it observed, not an atomic multi-store snapshot; it
never rereads or chooses a latest value. No record counts or continuity claim
is inferred from a refusal, missing key or successful checksum.

At base6cca205c1bd7a5c6ff00e4ddb77d9e2f46a9b50b, reviewer-adapter.blade.php loads
explicit named classic-script paths, and workspace.tsx/Vite have explicit entry
imports. They do not glob-execute every reviewer JS file. CampusReviewManifest
only hashes the reviewer glob; future import will change source closure, not
automatically run this ES module. No wiring or source-manifest/cap relaxation is
included. Tests are focused local Node/fake-storage/WebCrypto contracts only;
no browser/user data, real authentication, current-edit preservation, conversion,
server reconciliation, hosted acceptance or release is claimed. Root must review
and integrate separately; the existing release candidate remains unchanged.
