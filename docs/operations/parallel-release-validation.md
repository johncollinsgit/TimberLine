# Parallel release validation

Protected CI chooses validation from the exact diff. Small, proven presentation
edits avoid unrelated regression matrices. Everything else retains full coverage
on parallel runners. Local verification remains proportional to the change.

## Pull requests

### Presentation validation

Only up-to-date PRs to `main` can qualify. The classifier is loaded from the
trusted base commit, so changing policy/tests/workflows in a PR cannot approve
that PR for the fast path. There is no manual label or agent override.

`scripts/ci/presentation-policy.py` is the executable allowlist. It accepts at
most 12 modified existing regular files and 200 changed lines in selected public
and Teams views/styles, optionally with `docs/ui/UI_CHANGELOG.md`. It proves:

- Blade edits change visible literal text or known visual/layout utility classes.
  Markup, links, handlers, other attributes, PHP/Blade expressions and directives
  stay identical. Numeric/currency copy and script/template content stay full.
- CSS edits keep selectors, rules, conditions, declaration order/property names
  and `!important` flags. Only listed visual values can change. Visibility,
  display, pointer handling, custom properties, imports and URL edits stay full.
- New/deleted/renamed files, JavaScript, routes, backend code, dependencies,
  configuration, permissions, payments, schema and uncertain edits stay full.

Presentation validation retains the production build for both frontends,
TypeScript and source/protocol checks, compiled Blade views, focused auth,
tenant isolation, IDOR and embedded-surface tests, plus desktop/mobile Chromium
and WebKit smoke for login, public navigation, Teams controls and guest denial.
Browser fixtures use disposable SQLite and fictional data; they perform no live
provider or Teams mutations. Failed smoke retains diagnostic artifacts.

### Full validation

All other diffs, manual/reusable runs and the nightly 08:00 UTC schedule run:


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

The stable required check `ci (8.4)` waits for the plan, build and PHP/browser
matrix; `mysql migration recovery` waits for the plan, build and MySQL matrix.
Both aggregate jobs always evaluate exact prerequisites through
`scripts/ci/require-release-results.py`. Full plans require every job to succeed.
Presentation plans require the trusted reason, matching head SHA and successful
build/safety smoke; only their deliberately unused full matrices may be skipped.
The summary explicitly reports that unchanged backend/schema did not need those
suites. Missing, failed, cancelled, unexpected skipped or malformed results fail
closed. Matrix fail-fast is disabled so other lanes retain useful evidence;
no validation lane uses continue-on-error.

Superseded revisions cancel within the same PR's validation group.
Nightly/manual CI and reusable production validation have separate cancellation
groups, so scheduled work cannot cancel a production release gate.
Enterprise browser checks retain all scenarios and their established twelve-runner limit.
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

Package preparation on disposable build/PHP/browser runners uses two APT retries
and 20-second HTTP/HTTPS timeouts. APT updates fail on incomplete indexes rather
than silently accepting stale metadata. Fixed-host DNS and HTTPS probes log only
status codes, with bounded waits and no environment or response dumps. The PHP
Ghostscript step has a 12-minute limit; browser installation retains its existing
45-minute limit. If both canonical Ubuntu HTTPS probes fail, the runner checks
DNS and HTTPS for the known official `azure.archive.ubuntu.com` mirror. Only if
both checks succeed does it select that HTTPS mirror for its Ubuntu URI fields
and mirror list, preserving suites, components, signing keys and unrelated fields.
Unknown source layouts fail closed. This fallback applies only to the disposable
runner; TLS/signature verification, test commands, matrices and required checks
remain intact. Failed fallback probes leave the normal sources in place and the
required package install still fails closed.

The release verification plan and serialized Forge activation retain their gates.
The hook now supplies an immutable custom release pin to the installed
`scripts/ops/forge-pinned-deploy.sh` script. That script checks out only the
approved SHA inside an inactive release and checks source integrity before
migration/activation. A stale workflow does not send a hook; a branch advancing
after admission cannot change the source built. Forge's commit-history label is
not source authority. See `forge-atomic-release-runbook.md` for installation.
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
behavioral regression coverage behind an optional/nightly-only workflow.
Presentation eligibility is a narrow proof of unchanged behavior, not a label
that substitutes nightly tests for required checks.

Local checks for workflow changes:

```sh
python3 -m unittest discover -s tests/ci -p 'test_parallel_release.py' -v
python3 -m unittest discover -s tests/ci -p 'test_presentation_policy.py' -v
actionlint .github/workflows/tests.yml .github/workflows/deploy.yml
git diff --check
```

The Python contract tests need PyYAML 6.0.3 and tinycss2 1.4.0. A full protected PR run is required
before merging a workflow change. A live deploy must still report the exact
merged revision before it is described as complete.
