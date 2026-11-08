# Parallel release validation

The protected release retains full test coverage while executing independent
checks on separate runners. A scoped local change needs proportionate local
verification; protected CI remains the authoritative full regression run.

## Pull requests

`.github/workflows/tests.yml` runs:

- A single frontend build and shared source/protocol checks. Only public build
  outputs and the checksum-verified pinned label tracer are shared as artifacts
  within the same workflow run. PHP/Node dependencies and credentials are not
  bundled into artifacts.
  Input artifact names are scoped to the immutable workflow run rather than its
  attempt, so a failed-job retry can reuse a successful build. A full retry may
  overwrite that same run's inputs; it cannot consume another run's artifacts.
  Both `public/build` and `apps/enterprise/public/build` are retained and restored
  at their original paths. Workers verify both manifests and the Node image
  renderer before starting PHP tests, so missing inputs fail immediately.
- Six PHP 8.4 file partitions generated from every directory/file configured in
  `phpunit.xml`, each retaining Pest's `--parallel`. Explicit file discovery keeps
  legacy unnamespaced test classes in the suite. The generated configuration
  preserves the original bootstrap, environment and source settings. Together
  the partitions execute the unchanged default suite; new files are included
  automatically and unsupported discovery definitions fail closed.
  Each worker retains Node 22/npm dependencies, Ghostscript and the pinned
  tracer because PHP's real label-conversion tests invoke those tools.
- Three browser groups: Teams, commerce, and other workspaces. Chromium and
  WebKit coverage, fixture-specific PHP checks, TypeScript checks, and Shopify
  Function validation remain in their original commands.
- Four MySQL 8.4 groups: schema/recovery, durable subscription workflows, platform
  email outbox, and concurrency. Each runner owns a separate disposable MySQL
  service. Durable subscription/concurrency groups initialize their own schema
  before running; they do not depend on the schema group. Tests inside a group remain sequential because many deliberately
  rebuild or modify their database and test real commit/concurrency semantics.

The stable required check `ci (8.4)` waits for the build and every PHP/browser
matrix member. `mysql migration recovery` waits for all four MySQL members.
Both aggregate jobs run after failures and explicitly require `success` for all
expected dependencies. Missing, failed, cancelled, skipped, or malformed results
fail closed. Matrix fail-fast is disabled so other lanes retain useful evidence;
no validation lane uses continue-on-error.

Superseded revisions cancel within the same PR's validation group. Enterprise
browser checks retain all scenarios and their established twelve-runner limit.
They remain isolated from the main PHP/browser/MySQL jobs; the shared runner pool
and actual queue time determine overall capacity.
Production deployment remains serialized and is never cancelled by a newer push.

The coverage fixture `tests/Fixtures/ci/required-release-checks.json` records the
pre-split validation commands and safety environment flags. Contract tests ensure
these remain present, check all shard indexes and group admission, and exercise
aggregate failure behavior. They also keep the agent entry guide short and
linked from root instructions so historical append-only notes cannot silently
replace the scoped workflow. Do not remove checks merely to reduce runtime.

## Production

The release verification plan and serialized Forge activation are unchanged.
Successful `quality`, `ci (8.4)`, and `mysql migration recovery` checks can be
reused only when the final merged tree matches the tested PR tree exactly.
Direct pushes and unverifiable releases run the parallel test/build pipeline
again. That reusable call omits its PR MySQL matrix only because deploy.yml runs
its separate mandatory production migration safety gate alongside it.

Schema, migration tooling, and CI workflow/action changes require the production
MySQL rehearsal even for a verified PR tree. The existing audited emergency path
still requires migration safety. Failed validation never triggers Forge.
Activation is followed by exact-SHA `/ready` verification; retained releases,
shared storage, provider/tenant permissions, and live acceptance remain intact.

## Measuring and extending

Compare workflow creation-to-completion time, not just the final Forge step.
The November 6 logo PR spent about 29 minutes validating and 85 seconds deploying;
the November 8 refresh fix spent about 32 minutes validating and 86 seconds
deploying. The former serial browser/setup prefix and long PHP/MySQL jobs were
the dominant delay. Parallel wall-clock time is determined by the slowest group
and available GitHub runner capacity; this is not a guaranteed deployment SLA.

Inspect actual job timings before increasing shard count. Preserve real commit,
partial migration, original-attempt, tenant-isolation, and provider safety tests.
Investigate slow fixtures separately instead of weakening assertions or moving
required regression coverage behind an optional/nightly-only workflow.

Local checks for workflow changes:

```sh
python3 -m unittest discover -s tests/ci -p 'test_parallel_release.py' -v
actionlint .github/workflows/tests.yml .github/workflows/deploy.yml
git diff --check
```

The Python contract tests need PyYAML 6.0.3. A full protected PR run is required
before merging a workflow change. A live deploy must still report the exact
merged revision before it is described as complete.
