# Event history source reads

Receipt coverage loads one original ancestry graph per proof with a bounded,
tenant/type-scoped MySQL recursive query. The existing validator still checks
every head, parent and root: status, errors, query/window, location, exact binding,
original start date and null-cursor intent. Cycles, missing/foreign parents and
more than 10,000 nodes fail closed. Current source/policy admission remains fresh
for every proof. Other engines retain the original bounded traversal. No receipt
or access result is cached across requests.

The scoped allocation-facts helper keeps derived source IDs rather than decrypting
all event notes for every purchase group. Each use checks fresh record metadata
and complete ciphertext SHA-256 values. Changed records are loaded with their own
hash in the same SELECT; a second metadata collection must agree before the new
projection is retained. Missing changed rows cannot retain old decoded facts.
This detects insertions, deletion, withdrawal and same-version direct edits.

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
