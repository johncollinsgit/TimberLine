# Event history source reads

Receipt coverage loads one original ancestry graph per proof with a bounded,
tenant/type-scoped MySQL recursive query. The existing validator still checks
every head, parent and root: status, errors, query/window, location, exact binding,
original start date and null-cursor intent. Cycles, missing/foreign parents and
more than 10,000 nodes fail closed. Current source/policy admission remains fresh
for every proof. Other engines retain the original bounded traversal. No receipt
or access result is cached across requests.

Full customer dependency builds keep their cohort's original purchase witnesses
in one database-enforced MySQL READ ONLY view. The narrowly owned tenant/PDO
context omits implicit Square configuration and reviewer row locks only within
that view. Normal source-sync, identity and payment writers retain their locks.
All attempted authority inputs are compared after commit; successful connection,
policy and reviewer checks are freshly admitted with fresh permission resolvers.
Stable inadmissible sources keep their existing conservative exclusion behavior.
This removes reporting row-lock contention while retaining coherent originals
and revocation checks. It does not shorten the full witness pass or remove the
metadata locks held until commit; full build and published endpoint timings must
still be measured separately from dependency-frame component benchmarks.
Withheld customer evidence emits a server-side phase and, for a caught 409, a
SHA-256 message fingerprint. It does not log the exception message, original
payload, customer identity or source keys. These diagnostics distinguish a
witness failure from a final dependency/access change without manually running
a report builder or weakening its publication checks.

The scoped allocation-facts helper keeps derived source IDs and request-local
copies of original models rather than reloading all event notes for every purchase
group. Every model reuse first checks fresh complete row metadata (including
tenant, kind, title, dates, status, version, connection, assignment and visibility)
and complete ciphertext SHA-256 values. Callers receive independent model copies;
decoration cannot modify a retained witness. Changed records load with their own
hash in the same SELECT; a second metadata collection must agree before the new
projection is retained. Missing changed rows cannot retain old decoded facts.
This detects insertions, deletion, withdrawal and same-version direct edits.
MySQL returns only the ciphertext hash for unchanged records; SQLite's fallback
reads ciphertext and hashes it locally. Only the structural schema-column list
is reused without an additional database read inside this request scope.

On a cold or changed MySQL inventory outside a caller-owned transaction, the
helper reads its metadata, changed ciphertext bodies and final inventory in one
short explicit REPEATABLE READ transaction. It starts a new metadata witness
inside that view; the preceding hot-cache check is never paired with newer
bodies. This permits a coherent allocation projection during unrelated encrypted
observation updates, including when the session default is READ COMMITTED.
The helper performs no provider I/O or locking reads, does not change the
session default, and ends its own transaction before money/source normalization
continues. Failed owned frames discard their retained model projection.
Unchanged reuse still performs exactly one fresh complete byte witness with no
new transaction. Caller-owned transactions retain their existing isolation and
conservative retry/failure behavior. The final scoped normalization fence remains
fresh: allocation, reviewer or membership changes after this short view discard
the purchase result rather than extending the read transaction over the report.

The audience lookup regression separately counts broad model/body scans and the
per-purchase fresh metadata witnesses. Its original cold/warm total-query caps and
one-history-rebuild-per-buyer limits remain in force. Reviewer admission uses a
fresh combined user/membership existence query, preserving active-user,
platform-admin and exact owner/admin membership rules without caching authority.
Empty explicit-order arrays skip only an intersection that is already false;
commerce mappings, source selection and reviewer evidence remain in the effective
revision even when an event has no explicit orders.

Customer dependency frames select exact source/type identities through a union
of ID branches using the existing tenant/source key. The outer read retains its
tenant predicate, unique rows, ID ordering and single 50,000-row coverage bound.
Profile links and incoming aliases may use their existing leading-key indexes;
an index hint requires the expected name and column prefix and falls back when
missing or incompatible. No schema addition or authority cache is involved. The
commerce-event JSON anchor remains unchanged, including its existing coercion
and competing-claim semantics.

The additive customer-anchor migration installs two MySQL virtual, invisible
path-presence flags with visible tenant/source-type/presence/ID BTREE indexes.
These are candidate indexes, not generated event IDs: the original
JSON_UNQUOTE/IN comparisons remain unchanged. JSON null stays a candidate.
Runtime use requires the exact generated type, path expression and invisibility,
plus the complete visible, nonunique, unprefixed index definition. Missing or
incompatible schema retains the original predicate. SQLite uses verified
expression indexes with a guarded CASE: SQL null is absent, valid JSON path
presence is a candidate, and malformed JSON remains a candidate so the original
comparison still fails closed. Literal path case is significant in verification.
Only these verified redundant fields may be removed from a requested content
digest shape; the covering hash still includes every original semantic field.
Ordinary or drifted extra fields retain the full-hash fallback. Column-only,
partially indexed, repeated and incompatible recovery states are covered on
disposable MySQL; rollback preserves original source rows and derived indexes.

On supported MySQL 8.4, ASCII string source keys use a bound JSON_TABLE input
relation and covered point lookups after fresh session-encoding and exact
column/index checks. Other types, Unicode, overlength values, unsupported engines
and unverified schema retain native IN predicates. Each input branch deduplicates
physical IDs; the combined union, tenant predicates, global ID order and coverage
bound remain unchanged. The derived-first physical-row lookup requires the exact
PRIMARY(id) shape. Optional invisible source indexes are never forced by fallback
queries. Source hashes retain the same formula and row-count fence even when an
ordinary lookup computes the generated value instead of reading a visible index.

Length checks are independent of strict SQL modes: JSON_TABLE truncation can warn
without failing even with ERROR ON ERROR. The input relation pins the original
column collation and does not cast stored source IDs. See the primary MySQL
[JSON_TABLE documentation](https://dev.mysql.com/doc/refman/8.4/en/json-table-functions.html)
and [collation rules](https://dev.mysql.com/doc/refman/8.4/en/charset-collation-coercibility.html).

Local 63,000/126,000-payment components measured p95 88.7/142.1 ms over five
samples at each scale under the unchanged 250 ms bound. Same-command whole-frame
fixtures measured 468/1,083 ms at 1,442/2,884 canonical roots. These are local
component/fixture checks, not deployed endpoint timing. A separate boundary test
retains 50,000 distinct rows despite single-lane case/pad duplicate inputs and
rejects 50,001. Runtime acceptance records fast-path activation as a count without
printing source values.

Repeated CI workload failures measured about 305–309 ms against the unchanged
250 ms bound. An isolated query profile showed tenant-only plans for precise
source/profile lookups. The corrected plans reduced local doubled-directory p95
from about 115 ms to 59–65 ms, including four concurrent fixture workloads. These
local measurements are separate from protected CI and deployed endpoint timing.

The normalizer receives its revision and conflict answers from one projection,
then freshly verifies that revision before returning. Customer dependency callers
can ask for an effective revision covering relevant across-area allocations,
commerce mappings, source selections and reviewers. Unrelated notes or observation
updates refresh the source projection without invalidating the effective cohort.
These are source facts, not permission grants; original money, identity, actor,
provider and connection admission remain authoritative.

The measured baseline was one 3.30-second history read with 915 SQL queries,
including 512 repeated receipt lookups and four tenant-wide allocation scans.
The new implementation requires protected release and deployed timing checks;
local regression results do not establish the one-second detail target.
