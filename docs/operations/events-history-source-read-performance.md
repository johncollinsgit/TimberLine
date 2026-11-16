# Event history source reads

Receipt coverage loads one original ancestry graph per proof with a bounded,
tenant/type-scoped MySQL recursive query. The existing validator still checks
every head, parent and root: status, errors, query/window, location, exact binding,
original start date and null-cursor intent. Cycles, missing/foreign parents and
more than 10,000 nodes fail closed. Current source/policy admission remains fresh
for every proof. Other engines retain the original bounded traversal. No receipt
or access result is cached across requests.

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

The audience lookup regression separately counts broad model/body scans and the
per-purchase fresh metadata witnesses. Its original cold/warm total-query caps and
one-history-rebuild-per-buyer limits remain in force. Reviewer admission uses a
fresh combined user/membership existence query, preserving active-user,
platform-admin and exact owner/admin membership rules without caching authority.

Customer dependency frames select exact source/type identities through a union
of ID branches using the existing tenant/source key. The outer read retains its
tenant predicate, unique rows, ID ordering and single 50,000-row coverage bound.
Profile links and incoming aliases may use their existing leading-key indexes;
an index hint requires the expected name and column prefix and falls back when
missing or incompatible. No schema addition or authority cache is involved. The
commerce-event JSON anchor remains unchanged, including its existing coercion
and competing-claim semantics.

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
